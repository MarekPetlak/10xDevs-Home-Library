<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Author;
use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\User;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReadBookMappingTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testReadBookIsPersistedWithRatingDateAndComment(): void
    {
        [$user, $book] = $this->persistUserAndBook('reader@example.com');

        $readBook = (new ReadBook())
            ->setUser($user)
            ->setBook($book)
            ->setRating(4)
            ->setReadOn(new \DateTimeImmutable('2026-10-01'))
            ->setComment('Świetna książka.');
        $this->entityManager->persist($readBook);
        $this->entityManager->flush();
        $id = $readBook->getId();
        $this->entityManager->clear();

        $stored = $this->entityManager->find(ReadBook::class, $id);

        self::assertNotNull($stored);
        self::assertSame(4, $stored->getRating());
        self::assertSame('2026-10-01', $stored->getReadOn()->format('Y-m-d'));
        self::assertSame('Świetna książka.', $stored->getComment());
        self::assertSame($user->getId(), $stored->getUser()->getId());
        self::assertSame($book->getId(), $stored->getBook()->getId());
        self::assertEqualsWithDelta($readBook->getCreatedAt()->getTimestamp(), $stored->getCreatedAt()->getTimestamp(), 1);
    }

    public function testCommentIsOptional(): void
    {
        [$user, $book] = $this->persistUserAndBook('nocomment@example.com');

        $readBook = (new ReadBook())
            ->setUser($user)
            ->setBook($book)
            ->setRating(5)
            ->setReadOn(new \DateTimeImmutable('2026-10-02'));
        $this->entityManager->persist($readBook);
        $this->entityManager->flush();
        $id = $readBook->getId();
        $this->entityManager->clear();

        $stored = $this->entityManager->find(ReadBook::class, $id);

        self::assertNotNull($stored);
        self::assertNull($stored->getComment());
    }

    public function testSecondRecordForTheSameUserAndBookIsRejected(): void
    {
        [$user, $book] = $this->persistUserAndBook('duplicate@example.com');
        $this->entityManager->persist($this->makeReadBook($user, $book));
        $this->entityManager->flush();

        $this->entityManager->persist($this->makeReadBook($user, $book));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testBookWithAReadRecordCannotBeRemoved(): void
    {
        [$user, $book] = $this->persistUserAndBook('keepbook@example.com');
        $this->entityManager->persist($this->makeReadBook($user, $book));
        $this->entityManager->flush();

        $this->entityManager->remove($book);

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testUserWithAReadRecordCannotBeRemoved(): void
    {
        // A second user added the book, so only the ReadBook row references the reader.
        [$adder, $book] = $this->persistUserAndBook('adder@example.com');
        $reader = (new User())->setEmail('keepuser@example.com')->setPassword('hash');
        $this->entityManager->persist($reader);
        $this->entityManager->persist($this->makeReadBook($reader, $book));
        $this->entityManager->flush();

        $this->entityManager->remove($reader);

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->entityManager->flush();
    }

    /**
     * @return array{0: User, 1: Book}
     */
    private function persistUserAndBook(string $email): array
    {
        $user = (new User())->setEmail($email)->setPassword('hash');
        $author = (new Author())->setFirstName('Stanisław')->setLastName('Lem');
        $book = (new Book())
            ->setTitle('Solaris')
            ->setIsbn('9788308049532')
            ->setPublicationYear(1961)
            ->setAddedBy($user)
            ->addAuthor($author);

        foreach ([$user, $author, $book] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return [$user, $book];
    }

    private function makeReadBook(User $user, Book $book): ReadBook
    {
        return (new ReadBook())
            ->setUser($user)
            ->setBook($book)
            ->setRating(3)
            ->setReadOn(new \DateTimeImmutable('2026-10-03'));
    }
}
