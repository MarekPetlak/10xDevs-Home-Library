<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\NewBook;
use App\Entity\Author;
use App\Entity\Book;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\Category;
use App\Service\BookManager;
use App\Service\UserManager;
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
