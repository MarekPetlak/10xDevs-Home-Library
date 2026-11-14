<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\NewBook;
use App\Entity\Author;
use App\Entity\Book;
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
