<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\NewBook;
use App\Entity\Author;
use App\Entity\Book;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\BookConflictException;
use App\Repository\AuthorRepository;
use App\Repository\TagRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates a Book from already validated input.
 */
final class BookManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthorRepository $authorRepository,
        private readonly TagRepository $tagRepository,
        private readonly TagSlugger $tagSlugger,
    ) {
    }

    /**
     * @throws BookConflictException when a concurrent request created the same author or tag first
     */
    public function add(NewBook $input, User $user): Book
    {
        try {
            return $this->save($input, $user);
        } catch (UniqueConstraintViolationException $e) {
            // Doctrine closed the EntityManager, so nothing may use it after this point.
            throw new BookConflictException(previous: $e);
        }
    }

    private function save(NewBook $input, User $user): Book
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $user): Book {
            $book = (new Book())
                ->setTitle((string) $input->title)
                ->setIsbn((string) $input->isbn)
                ->setPublicationYear((int) $input->publicationYear)
                ->setCategory($input->category)
                ->setDescription($input->description)
                ->setAddedBy($user);

            foreach (NameList::parse($input->authors ?? '') as $entry) {
                $book->addAuthor($this->findOrCreateAuthor($entry));
            }

            foreach (NameList::parse($input->tags ?? '') as $name) {
                $book->addTag($this->findOrCreateTag($name));
            }

            $this->entityManager->persist($book);
            $this->entityManager->flush();

            return $book;
        });
    }

    private function findOrCreateAuthor(string $entry): Author
    {
        $name = NameList::splitAuthor($entry);
        $author = $this->authorRepository->findOneByFullName($name['firstName'], $name['lastName']);

        if (null === $author) {
            $author = (new Author())
                ->setFirstName($name['firstName'])
                ->setLastName($name['lastName']);
            $this->entityManager->persist($author);
            // Flush now so a later entry that the collation treats as equal finds this row.
            $this->entityManager->flush();
        }

        return $author;
    }

    private function findOrCreateTag(string $name): Tag
    {
        $tag = $this->tagRepository->findOneByName($name);

        if (null === $tag) {
            $tag = (new Tag())->setName($name)->setSlug($this->tagSlugger->slugFor($name));
            $this->entityManager->persist($tag);
            $this->entityManager->flush();
        }

        return $tag;
    }
}
