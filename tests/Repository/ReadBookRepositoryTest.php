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
