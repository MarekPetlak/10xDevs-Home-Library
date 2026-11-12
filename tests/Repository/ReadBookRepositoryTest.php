<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Dto\CommentView;
use App\Entity\Author;
use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\User;
use App\Enum\Category;
use App\Repository\ReadBookRepository;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReadBookRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ReadBookRepository $repository;
    private User $anna;
    private User $ola;
    private Book $book;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(ReadBookRepository::class);
        $users = $container->get(UserManager::class);
        $this->anna = $users->create('anna@example.com', 'secret-pass');
        $this->ola = $users->create('ola@example.com', 'secret-pass');

        $author = (new Author())->setFirstName('Stanisław')->setLastName('Lem');
        $this->book = (new Book())
            ->setTitle('Solaris')
            ->setIsbn('9788308000011')
            ->setPublicationYear(1961)
            ->setCategory(Category::Novel)
            ->setAddedBy($this->anna)
            ->addAuthor($author);
        $this->entityManager->persist($author);
        $this->entityManager->persist($this->book);
        $this->entityManager->flush();
    }

    public function testCommentsAreReturnedAsViewsWithoutUserEntities(): void
    {
        $this->read($this->ola, 4, 'Świetna książka.');
        $this->entityManager->clear();

        $comments = $this->repository->findCommentsForBook($this->entityManager->getReference(Book::class, $this->book->getId()), $this->entityManager->getReference(User::class, $this->anna->getId()));

        self::assertCount(1, $comments);
        self::assertInstanceOf(CommentView::class, $comments[0]);
        self::assertSame('ola@example.com', $comments[0]->email);
        self::assertSame(4, $comments[0]->rating);
        self::assertSame('Świetna książka.', $comments[0]->comment);
        // Nothing but the book and the viewer references may be managed: no User row was hydrated.
        self::assertFalse($this->entityManager->getUnitOfWork()->isInIdentityMap($this->ola));
    }

    public function testRecordsWithoutACommentAndTheViewersOwnAreLeftOut(): void
    {
        $this->read($this->anna, 5, 'Mój komentarz');
        $this->read($this->ola, 3, null);

        $comments = $this->repository->findCommentsForBook($this->book, $this->anna);

        self::assertSame([], $comments);
    }

    public function testReadsSinceAreGroupedByCategoryWithCountAndLatestDay(): void
    {
        $crimeA = $this->bookIn('Kryminał A', Category::Crime, '9788308000028');
        $crimeB = $this->bookIn('Kryminał B', Category::Crime, '9788308000035');
        $this->readOn($this->anna, $this->book, '2026-03-01');
        $this->readOn($this->anna, $crimeA, '2026-05-10');
        $this->readOn($this->anna, $crimeB, '2026-07-20');

        $rows = $this->repository->countByCategorySince($this->anna, new \DateTimeImmutable('2026-01-01'));
        usort($rows, static fn (array $a, array $b): int => $a['category']->value <=> $b['category']->value);

        self::assertCount(2, $rows);
        self::assertSame(Category::Crime, $rows[0]['category']);
        self::assertSame(2, $rows[0]['count']);
        self::assertSame('2026-07-20', $rows[0]['lastReadOn']->format('Y-m-d'));
        self::assertSame(Category::Novel, $rows[1]['category']);
        self::assertSame(1, $rows[1]['count']);
        self::assertSame('2026-03-01', $rows[1]['lastReadOn']->format('Y-m-d'));
    }

    public function testWindowBoundaryIsInclusive(): void
    {
        $onBoundary = $this->bookIn('Na granicy', Category::Crime, '9788308000028');
        $before = $this->bookIn('Przed granicą', Category::Fantasy, '9788308000035');
        $this->readOn($this->anna, $onBoundary, '2025-11-12');
        $this->readOn($this->anna, $before, '2025-11-11');

        $rows = $this->repository->countByCategorySince($this->anna, new \DateTimeImmutable('2025-11-12'));

        self::assertCount(1, $rows);
        self::assertSame(Category::Crime, $rows[0]['category']);
        self::assertSame(1, $rows[0]['count']);
    }

    public function testOtherUsersReadsAreIgnored(): void
    {
        $this->readOn($this->ola, $this->book, '2026-03-01');
        $this->readOn($this->ola, $this->bookIn('Inna', Category::Crime, '9788308000028'), '2026-04-01');
        $this->readOn($this->anna, $this->book, '2026-02-01');

        $rows = $this->repository->countByCategorySince($this->anna, new \DateTimeImmutable('2026-01-01'));

        self::assertCount(1, $rows);
        self::assertSame(Category::Novel, $rows[0]['category']);
        self::assertSame(1, $rows[0]['count']);
        self::assertSame('2026-02-01', $rows[0]['lastReadOn']->format('Y-m-d'));
    }

    public function testNoReadsInWindowGivesEmptyList(): void
    {
        $this->readOn($this->anna, $this->book, '2020-01-01');

        self::assertSame([], $this->repository->countByCategorySince($this->anna, new \DateTimeImmutable('2026-01-01')));
    }

    private function bookIn(string $title, Category $category, string $isbn): Book
    {
        $book = (new Book())
            ->setTitle($title)
            ->setIsbn($isbn)
            ->setPublicationYear(2000)
            ->setCategory($category)
            ->setAddedBy($this->anna);
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        return $book;
    }

    private function readOn(User $user, Book $book, string $day): void
    {
        $this->entityManager->persist(
            (new ReadBook())
                ->setUser($user)
                ->setBook($book)
                ->setRating(4)
                ->setReadOn(new \DateTimeImmutable($day)),
        );
        $this->entityManager->flush();
    }

    private function read(User $user, int $rating, ?string $comment): void
    {
        $this->entityManager->persist(
            (new ReadBook())
                ->setUser($user)
                ->setBook($this->book)
                ->setRating($rating)
                ->setReadOn(new \DateTimeImmutable('2026-09-15'))
                ->setComment($comment),
        );
        $this->entityManager->flush();
    }
}
