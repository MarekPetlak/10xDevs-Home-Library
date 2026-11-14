<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\NewBook;
use App\Entity\Author;
use App\Entity\Book;
use App\Entity\User;
use App\Enum\Category;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Service\DuplicateFinder;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DuplicateFinderTest extends KernelTestCase
{
    private DuplicateFinder $finder;
    private EntityManagerInterface $entityManager;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        // Built by hand: the service is private and unused (so removed from the container) until wired in.
        $this->finder = new DuplicateFinder($container->get(BookRepository::class), $container->get(AuthorRepository::class));
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->user = $container->get(UserManager::class)->create('anna@example.com', 'secret-pass');
    }

    public function testSameIsbnInTheOtherFormFlagsOnlyTheIsbn(): void
    {
        $this->stored('Dziady', 'Adam Mickiewicz', '9788373271890');

        $matches = $this->finder->find($this->input('Zupełnie inna', 'Jan Kowalski', '8373271899'));

        self::assertCount(1, $matches);
        self::assertTrue($matches[0]->sameIsbn);
        self::assertFalse($matches[0]->sameTitleAndAuthor);
    }

    public function testSameTitleAndSharedAuthorFlagsOnlyTitleAndAuthor(): void
    {
        $this->stored('Dziady', 'Adam Mickiewicz', '9788373271890');

        $matches = $this->finder->find($this->input(' dziady ', 'adam mickiewicz, Jan Kowalski', '9780306406157'));

        self::assertCount(1, $matches);
        self::assertFalse($matches[0]->sameIsbn);
        self::assertTrue($matches[0]->sameTitleAndAuthor);
    }

    public function testBothFlagsCanBeTrueTogether(): void
    {
        $book = $this->stored('Dziady', 'Adam Mickiewicz', '9788373271890');

        $matches = $this->finder->find($this->input('Dziady', 'Adam Mickiewicz', '9788373271890'));

        self::assertCount(1, $matches);
        self::assertSame($book, $matches[0]->book);
        self::assertTrue($matches[0]->sameIsbn);
        self::assertTrue($matches[0]->sameTitleAndAuthor);
    }

    public function testUnknownAuthorNeverMatchesByTitle(): void
    {
        $this->stored('Dziady', 'Adam Mickiewicz', '9788373271890');

        self::assertSame([], $this->finder->find($this->input('Dziady', 'Nieznany Autor', '9780306406157')));
    }

    public function testDifferentAccentInAuthorDoesNotMatch(): void
    {
        $this->stored('Ensaio', 'José Saramago', '9788373271890');

        self::assertSame([], $this->finder->find($this->input('Ensaio', 'Jose Saramago', '9780306406157')));
    }

    public function testTitleMatchedByCollationOnlyStillGetsItsReason(): void
    {
        // The column collation treats Greek capital and small sigma spellings as equal, PHP's mb_strtolower does not
        // (final sigma): the hit must still say why.
        $this->stored('ΟΔΟΣ', 'Adam Mickiewicz', '9788373271890');

        $matches = $this->finder->find($this->input('οδοσ', 'Adam Mickiewicz', '9780306406157'));

        self::assertCount(1, $matches);
        self::assertFalse($matches[0]->sameIsbn);
        self::assertTrue($matches[0]->sameTitleAndAuthor);
    }

    public function testNothingStoredMeansNoMatches(): void
    {
        self::assertSame([], $this->finder->find($this->input('Dziady', 'Adam Mickiewicz', '9788373271890')));
    }

    private function stored(string $title, string $authorName, string $isbn): Book
    {
        [$first, $last] = explode(' ', $authorName, 2);
        $author = (new Author())->setFirstName($first)->setLastName($last);
        $this->entityManager->persist($author);
        $book = (new Book())
            ->setTitle($title)
            ->setIsbn($isbn)
            ->setPublicationYear(1822)
            ->setCategory(Category::Poetry)
            ->setAddedBy($this->user)
            ->addAuthor($author);
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        return $book;
    }

    private function input(string $title, string $authors, string $isbn): NewBook
    {
        $input = new NewBook();
        $input->title = $title;
        $input->authors = $authors;
        $input->isbn = $isbn;
        $input->publicationYear = 1822;
        $input->category = Category::Poetry;

        return $input;
    }
}
