<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\MarkRead;
use App\Entity\Author;
use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\User;
use App\Enum\Category;
use App\Exception\AlreadyReadException;
use App\Repository\ReadBookRepository;
use App\Service\ReadingManager;
use App\Service\UserManager;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReadingManagerTest extends KernelTestCase
{
    private ReadingManager $manager;
    private EntityManagerInterface $entityManager;
    private User $user;
    private Book $book;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->manager = $container->get(ReadingManager::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->user = $container->get(UserManager::class)->create('anna@example.com', 'secret-pass');

        $author = (new Author())->setFirstName('Stanisław')->setLastName('Lem');
        $this->book = (new Book())
            ->setTitle('Solaris')
            ->setIsbn('9788308000011')
            ->setPublicationYear(1961)
            ->setCategory(Category::Novel)
            ->setAddedBy($this->user)
            ->addAuthor($author);
        $this->entityManager->persist($author);
        $this->entityManager->persist($this->book);
        $this->entityManager->flush();
    }

    public function testMarkReadStoresTrimmedCommentRatingDateAndUser(): void
    {
        $readBook = $this->manager->markRead($this->user, $this->book, $this->input(4, '  Dobra  '));

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(ReadBook::class)->find($readBook->getId());
        self::assertNotNull($stored);
        self::assertSame(4, $stored->getRating());
        self::assertSame('2026-10-01', $stored->getReadOn()->format('Y-m-d'));
        self::assertSame('Dobra', $stored->getComment());
        self::assertSame('anna@example.com', $stored->getUser()->getEmail());
    }

    public function testEmptyCommentIsStoredAsNull(): void
    {
        $this->manager->markRead($this->user, $this->book, $this->input(3, "  \n"));

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(ReadBook::class)->findOneBy([])?->getComment());
    }

    public function testSecondMarkThrowsAndKeepsOneRow(): void
    {
        $this->manager->markRead($this->user, $this->book, $this->input(4, null));

        try {
            $this->manager->markRead($this->user, $this->book, $this->input(1, null));
            self::fail('Expected AlreadyReadException');
        } catch (AlreadyReadException) {
        }

        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(ReadBook::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame(4, $rows[0]->getRating());
    }

    public function testRemoveDeletesOnlyTheGivenUsersRecord(): void
    {
        $other = self::getContainer()->get(UserManager::class)->create('ola@example.com', 'secret-pass');
        $this->manager->markRead($this->user, $this->book, $this->input(4, 'Komentarz'));
        $this->manager->markRead($other, $this->book, $this->input(2, null));

        $this->manager->remove($other, $this->book);

        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(ReadBook::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame('anna@example.com', $rows[0]->getUser()->getEmail());
        self::assertSame('Komentarz', $rows[0]->getComment());
    }

    public function testRemoveWithoutRecordReturnsQuietly(): void
    {
        $this->manager->remove($this->user, $this->book);

        $this->entityManager->clear();
        self::assertCount(0, $this->entityManager->getRepository(ReadBook::class)->findAll());
    }

    public function testUniqueViolationDuringInsertBecomesAlreadyReadException(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('wrapInTransaction')->willThrowException(
            new UniqueConstraintViolationException($this->createStub(DriverException::class), null),
        );
        $repository = $this->createStub(ReadBookRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $this->expectException(AlreadyReadException::class);

        (new ReadingManager($entityManager, $repository))->markRead($this->user, $this->book, $this->input(4, null));
    }

    private function input(int $rating, ?string $comment): MarkRead
    {
        $input = new MarkRead();
        $input->rating = $rating;
        $input->readOn = new \DateTimeImmutable('2026-10-01');
        $input->comment = $comment;

        return $input;
    }
}
