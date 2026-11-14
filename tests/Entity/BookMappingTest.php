<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Author;
use App\Entity\Book;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\Category;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BookMappingTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testBookIsPersistedWithAuthorsTagsAndAddedBy(): void
    {
        $user = (new User())->setEmail('mapping@example.com')->setPassword('hash');
        $first = (new Author())->setFirstName('Stanisław')->setLastName('Lem');
        $second = (new Author())->setFirstName('Olga')->setLastName('Tokarczuk');
        $fiction = (new Tag())->setName('sci-fi')->setSlug('sci-fi');
        $classic = (new Tag())->setName('klasyka')->setSlug('klasyka');

        $book = (new Book())
            ->setTitle('Solaris')
            ->setIsbn('9788308049532')
            ->setPublicationYear(1961)
            ->setDescription('Planeta-ocean.')
            ->setCategory(Category::Children)
            ->setAddedBy($user)
            ->addAuthor($first)
            ->addAuthor($second)
            ->addAuthor($first)
            ->addTag($fiction)
            ->addTag($classic);

        self::assertCount(2, $book->getAuthors());

        foreach ([$user, $first, $second, $fiction, $classic, $book] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $bookId = $book->getId();
        $userId = $user->getId();
        $this->entityManager->clear();

        $stored = $this->entityManager->find(Book::class, $bookId);

        self::assertNotNull($stored);
        self::assertSame('Solaris', $stored->getTitle());
        self::assertSame('9788308049532', $stored->getIsbn());
        self::assertSame(1961, $stored->getPublicationYear());
        self::assertSame('Planeta-ocean.', $stored->getDescription());
        self::assertSame(Category::Children, $stored->getCategory());
        self::assertSame('book.category.children', $stored->getCategory()->getTranslationKey());
        self::assertSame($userId, $stored->getAddedBy()->getId());
        self::assertEqualsCanonicalizing(
            ['Stanisław Lem', 'Olga Tokarczuk'],
            $stored->getAuthors()->map(static fn (Author $a): string => $a->getFullName())->toArray(),
        );
        self::assertEqualsCanonicalizing(
            ['sci-fi', 'klasyka'],
            $stored->getTags()->map(static fn (Tag $t): string => $t->getName())->toArray(),
        );
        self::assertEqualsWithDelta($book->getCreatedAt()->getTimestamp(), $stored->getCreatedAt()->getTimestamp(), 1);
    }

    public function testAuthorNameIsUniqueIgnoringCase(): void
    {
        $this->entityManager->persist((new Author())->setFirstName('Olga')->setLastName('Tokarczuk'));
        $this->entityManager->flush();

        $this->entityManager->persist((new Author())->setFirstName('OLGA')->setLastName('tokarczuk'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testAuthorFullNameHasNoStraySpace(): void
    {
        self::assertSame('Lem', (new Author())->setLastName('Lem')->getFullName());
    }
}
