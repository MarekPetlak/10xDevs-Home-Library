<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\MarkRead;
use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\User;
use App\Exception\AlreadyReadException;
use App\Repository\ReadBookRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates and removes a user's own read record; never touches another user's.
 */
final class ReadingManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReadBookRepository $readBookRepository,
    ) {
    }

    /**
     * @throws AlreadyReadException when the user already marked this book as read
     */
    public function markRead(User $user, Book $book, MarkRead $input): ReadBook
    {
        if (null !== $this->readBookRepository->findOneBy(['user' => $user, 'book' => $book])) {
            throw new AlreadyReadException();
        }

        try {
            return $this->entityManager->wrapInTransaction(function () use ($user, $book, $input): ReadBook {
                $comment = null === $input->comment ? '' : trim($input->comment);

                $readBook = (new ReadBook())
                    ->setUser($user)
                    ->setBook($book)
                    ->setRating((int) $input->rating)
                    ->setReadOn($input->readOn ?? MarkRead::today())
                    ->setComment('' === $comment ? null : $comment);

                $this->entityManager->persist($readBook);
                $this->entityManager->flush();

                return $readBook;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request won the race. Doctrine closed the EntityManager, so do not use it again.
            throw new AlreadyReadException(previous: $e);
        }
    }

    public function remove(User $user, Book $book): void
    {
        $readBook = $this->readBookRepository->findOneBy(['user' => $user, 'book' => $book]);

        if (null === $readBook) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($readBook): void {
            $this->entityManager->remove($readBook);
            $this->entityManager->flush();
        });
    }
}
