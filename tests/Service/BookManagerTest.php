<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\NewBook;
use App\Entity\Author;
use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\Category;
use App\Exception\BookConflictException;
use App\Repository\AuthorRepository;
use App\Repository\TagRepository;
use App\Service\BookManager;
use App\Service\TagSlugger;
use App\Service\UserManager;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class BookManagerTest extends KernelTestCase
{
    private BookManager $bookManager;
    private EntityManagerInterface $entityManager;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->bookManager = $container->get(BookManager::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->user = $container->get(UserManager::class)->create('anna@example.com', 'secret-pass');
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAddStoresBookAuthorsTagsAndAddedBy(): void
    {
        $book = $this->bookManager->add($this->input(authors: 'Jan Maria Kowalski, Anna Nowak', tags: 'kryminał, polska'), $this->user);

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(Book::class)->find($book->getId());

        self::assertNotNull($stored);
        self::assertSame('Dziady', $stored->getTitle());
        self::assertSame(1822, $stored->getPublicationYear());
        self::assertSame(Category::Poetry, $stored->getCategory());
        self::assertSame('anna@example.com', $stored->getAddedBy()->getEmail());
        self::assertEqualsCanonicalizing(['Jan Maria Kowalski', 'Anna Nowak'], $stored->getAuthors()->map(static fn (Author $a): string => $a->getFullName())->toArray());
        self::assertEqualsCanonicalizing(['kryminał', 'polska'], $stored->getTags()->map(static fn (Tag $t): string => $t->getName())->toArray());
    }

    public function testAuthorIsReusedIgnoringCaseAndStoredAsTwoNonEmptyParts(): void
    {
        $this->bookManager->add($this->input(authors: 'Stanisław Lem'), $this->user);
        $this->bookManager->add($this->input(title: 'Solaris', authors: 'stanisław lem'), $this->user);

        $this->entityManager->clear();
        $authors = $this->entityManager->getRepository(Author::class)->findAll();

        self::assertCount(1, $authors);
        self::assertSame('Stanisław', $authors[0]->getFirstName());
        self::assertSame('Lem', $authors[0]->getLastName());
    }

    public function testTagIsReusedIgnoringCase(): void
    {
        $this->bookManager->add($this->input(tags: 'Klasyka'), $this->user);
        $this->bookManager->add($this->input(title: 'Pan Tadeusz', tags: 'klasyka'), $this->user);

        $this->entityManager->clear();

        self::assertCount(1, $this->entityManager->getRepository(Tag::class)->findAll());
    }

    public function testAuthorsAndTagsDifferingOnlyInAccentsAreKeptApart(): void
    {
        $this->bookManager->add($this->input(authors: 'Jose Kowalski', tags: 'zolw'), $this->user);
        $this->bookManager->add($this->input(title: 'Inna', authors: 'José Kowalski', tags: 'żółw'), $this->user);

        $this->entityManager->clear();

        self::assertCount(2, $this->entityManager->getRepository(Author::class)->findAll());
        self::assertCount(2, $this->entityManager->getRepository(Tag::class)->findAll());
    }

    public function testLostRaceOnANewAuthorOrTagBecomesABookConflictException(): void
    {
        // Two requests both missed the lookup; the unique index rejects the second insert.
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('wrapInTransaction')->willThrowException(
            new UniqueConstraintViolationException($this->createStub(DriverException::class), null),
        );
        $manager = new BookManager(
            $entityManager,
            $this->createStub(AuthorRepository::class),
            $this->createStub(TagRepository::class),
            new TagSlugger($this->createStub(TagRepository::class)),
        );

        $this->expectException(BookConflictException::class);

        $manager->add($this->input(), $this->user);
    }

    public function testLostRaceOnASlugAloneBecomesABookConflictException(): void
    {
        // A concurrent request just stored "Zolw" (slug "zolw"); this request still sees neither the name nor the
        // slug as taken, so it picks "zolw" for "żółw" and the unique slug index rejects the insert.
        $this->entityManager->persist((new Tag())->setName('Zolw')->setSlug('zolw'));
        $this->entityManager->flush();
        $manager = new BookManager(
            $this->entityManager,
            self::getContainer()->get(AuthorRepository::class),
            $this->createStub(TagRepository::class), // findOneByName() finds nothing
            new TagSlugger($this->createStub(TagRepository::class)), // slugExists() says false
        );

        $this->expectException(BookConflictException::class);

        $manager->add($this->input(tags: 'żółw'), $this->user);
    }

    public function testNewTagsGetSlugsAndAccentVariantsGetDistinctOnes(): void
    {
        $this->bookManager->add($this->input(tags: 'Science Fiction, Zolw'), $this->user);
        $this->bookManager->add($this->input(title: 'Inna', tags: 'żółw, science fiction'), $this->user);

        $this->entityManager->clear();
        $slugs = array_map(
            static fn (Tag $t): string => $t->getSlug(),
            $this->entityManager->getRepository(Tag::class)->findBy([], ['id' => 'ASC']),
        );

        self::assertSame(['science-fiction', 'zolw', 'zolw-2'], $slugs);
    }

    public function testNoTagsIsAllowed(): void
    {
        $book = $this->bookManager->add($this->input(tags: ''), $this->user);

        self::assertCount(0, $book->getTags());
    }

    public function testIsbnIsStored(): void
    {
        $input = $this->input();
        $input->isbn = '9788373271890';

        $book = $this->bookManager->add($input, $this->user);

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(Book::class)->find($book->getId());

        self::assertSame('9788373271890', $stored?->getIsbn());
    }

    public function testDescriptionIsStoredWhenGivenAndNullWhenBlank(): void
    {
        $with = $this->bookManager->add($this->input(description: '  Poemat dramatyczny.  '), $this->user);
        $blank = $this->bookManager->add($this->input(title: 'Inna', description: '   '), $this->user);
        $none = $this->bookManager->add($this->input(title: 'Jeszcze inna', description: null), $this->user);

        self::assertSame('Poemat dramatyczny.', $with->getDescription());
        self::assertNull($blank->getDescription());
        self::assertNull($none->getDescription());
    }

    public function testUpdateReplacesAuthorsAndTagsAndKeepsAddedByAndCreatedAt(): void
    {
        Clock::set(new MockClock('2026-11-17 10:00:00', 'Europe/Warsaw'));
        $book = $this->bookManager->add($this->input(authors: 'Adam Mickiewicz, Anna Nowak', tags: 'poezja, klasyka'), $this->user);
        $createdAt = $book->getCreatedAt();
        $bookId = $book->getId();

        $edit = $this->input(title: 'Pan Tadeusz', authors: 'Anna Nowak, Jan Kowalski', tags: 'Klasyka, epopeja', description: 'Epopeja.');
        $edit->publicationYear = 1834;
        $edit->category = Category::Novel;
        $this->bookManager->update($book, $edit);

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(Book::class)->find($bookId);

        self::assertNotNull($stored);
        self::assertSame('Pan Tadeusz', $stored->getTitle());
        self::assertSame(1834, $stored->getPublicationYear());
        self::assertSame(Category::Novel, $stored->getCategory());
        self::assertSame('Epopeja.', $stored->getDescription());
        self::assertEqualsCanonicalizing(['Anna Nowak', 'Jan Kowalski'], $stored->getAuthors()->map(static fn (Author $a): string => $a->getFullName())->toArray());
        self::assertEqualsCanonicalizing(['klasyka', 'epopeja'], $stored->getTags()->map(static fn (Tag $t): string => $t->getName())->toArray());
        self::assertSame('anna@example.com', $stored->getAddedBy()->getEmail());
        self::assertSame($createdAt->getTimestamp(), $stored->getCreatedAt()->getTimestamp());
        // The Tag "poezja" and Author "Adam Mickiewicz" are left orphaned on purpose, not deleted.
        self::assertCount(3, $this->entityManager->getRepository(Author::class)->findAll());
        self::assertCount(3, $this->entityManager->getRepository(Tag::class)->findAll());
    }

    public function testUpdateSetsUpdatedAtFromTheClockOnEverySave(): void
    {
        $book = $this->bookManager->add($this->input(), $this->user);
        $neverEdited = $book->getUpdatedAt();
        self::assertNull($neverEdited);

        Clock::set(new MockClock('2026-11-17 10:00:00', 'Europe/Warsaw'));
        $this->bookManager->update($book, $this->input());
        self::assertSame((new \DateTimeImmutable('2026-11-17 10:00:00 Europe/Warsaw'))->getTimestamp(), $book->getUpdatedAt()?->getTimestamp());

        Clock::set(new MockClock('2026-11-18 08:30:00', 'Europe/Warsaw'));
        $this->bookManager->update($book, $this->input());

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(Book::class)->find($book->getId());
        self::assertSame((new \DateTimeImmutable('2026-11-18 08:30:00 Europe/Warsaw'))->getTimestamp(), $stored?->getUpdatedAt()?->getTimestamp());
    }

    public function testUpdateWithEmptyTagsRemovesAllTags(): void
    {
        $book = $this->bookManager->add($this->input(tags: 'poezja'), $this->user);

        $this->bookManager->update($book, $this->input(tags: ''));

        self::assertCount(0, $book->getTags());
    }

    public function testLostRaceDuringUpdateBecomesABookConflictException(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('wrapInTransaction')->willThrowException(
            new UniqueConstraintViolationException($this->createStub(DriverException::class), null),
        );
        $manager = new BookManager(
            $entityManager,
            $this->createStub(AuthorRepository::class),
            $this->createStub(TagRepository::class),
            new TagSlugger($this->createStub(TagRepository::class)),
        );

        $this->expectException(BookConflictException::class);

        $manager->update(new Book(), $this->input());
    }

    public function testDeleteStampsDeletedAtAndLeavesEverythingElseUnchanged(): void
    {
        $book = $this->bookManager->add($this->input(authors: 'Adam Mickiewicz, Anna Nowak', tags: 'poezja, klasyka'), $this->user);
        $bookId = $book->getId();
        $other = self::getContainer()->get(UserManager::class)->create('jan@example.com', 'secret-pass');
        $read = (new ReadBook())
            ->setUser($other)
            ->setBook($book)
            ->setRating(4)
            ->setReadOn(new \DateTimeImmutable('2026-11-10'))
            ->setComment('Dobra.');
        $this->entityManager->persist($read);
        $this->entityManager->flush();
        $readId = $read->getId();

        Clock::set(new MockClock('2026-11-19 09:15:00', 'Europe/Warsaw'));
        $this->bookManager->delete($book);

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(Book::class)->find($bookId);

        self::assertNotNull($stored);
        self::assertTrue($stored->isDeleted());
        self::assertSame((new \DateTimeImmutable('2026-11-19 09:15:00 Europe/Warsaw'))->getTimestamp(), $stored->getDeletedAt()?->getTimestamp());
        self::assertSame('Dziady', $stored->getTitle());
        self::assertSame(1822, $stored->getPublicationYear());
        self::assertNull($stored->getUpdatedAt());
        self::assertSame('anna@example.com', $stored->getAddedBy()->getEmail());
        self::assertEqualsCanonicalizing(['Adam Mickiewicz', 'Anna Nowak'], $stored->getAuthors()->map(static fn (Author $a): string => $a->getFullName())->toArray());
        self::assertEqualsCanonicalizing(['poezja', 'klasyka'], $stored->getTags()->map(static fn (Tag $t): string => $t->getName())->toArray());
        self::assertCount(2, $this->entityManager->getRepository(Author::class)->findAll());
        self::assertCount(2, $this->entityManager->getRepository(Tag::class)->findAll());

        $storedRead = $this->entityManager->getRepository(ReadBook::class)->find($readId);
        self::assertNotNull($storedRead);
        self::assertSame(4, $storedRead->getRating());
        self::assertSame('Dobra.', $storedRead->getComment());
        self::assertSame($bookId, $storedRead->getBook()->getId());
        self::assertSame('jan@example.com', $storedRead->getUser()->getEmail());
    }

    private function input(
        string $title = 'Dziady',
        string $authors = 'Adam Mickiewicz',
        string $tags = '',
        ?string $description = null,
    ): NewBook {
        $input = new NewBook();
        $input->title = $title;
        $input->authors = $authors;
        $input->isbn = '9788373271890';
        $input->publicationYear = 1822;
        $input->category = Category::Poetry;
        $input->tags = $tags;
        $input->description = $description;

        return $input;
    }
}
