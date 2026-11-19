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
use Symfony\Component\Clock\Clock;

/**
 * Creates, updates and soft-deletes a Book from already validated input.
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

    /**
     * Replaces the editable fields, authors and tags; addedBy and createdAt stay as they are.
     *
     * @throws BookConflictException when a concurrent request created the same author or tag first
     */
    public function update(Book $book, NewBook $input): Book
    {
        try {
            return $this->entityManager->wrapInTransaction(function () use ($book, $input): Book {
                $this->applyFields($book, $input);
                $book->setUpdatedAt(Clock::get()->now());
                $this->entityManager->flush();

                return $book;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Doctrine closed the EntityManager, so nothing may use it after this point.
            throw new BookConflictException(previous: $e);
        }
    }

    /**
     * Soft delete: stamps deletedAt. Authors, tags and other users' read records are left untouched.
     * Deleting an already deleted book is a no-op, so the first deletion time is kept.
     */
    public function delete(Book $book): void
    {
        if ($book->isDeleted()) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($book): void {
            $book->setDeletedAt(Clock::get()->now());
            $this->entityManager->flush();
        });
    }

    private function save(NewBook $input, User $user): Book
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $user): Book {
            $book = (new Book())->setAddedBy($user);
            $this->applyFields($book, $input);

            $this->entityManager->persist($book);
            $this->entityManager->flush();

            return $book;
        });
    }

    /**
     * Copies the form fields onto the book and makes its authors and tags exactly the listed ones.
     */
    private function applyFields(Book $book, NewBook $input): void
    {
        $book
            ->setTitle((string) $input->title)
            ->setIsbn((string) $input->isbn)
            ->setPublicationYear((int) $input->publicationYear)
            ->setCategory($input->category)
            ->setDescription($input->description);

        $authors = [];
        foreach (NameList::parse($input->authors ?? '') as $entry) {
            $authors[] = $this->findOrCreateAuthor($entry);
        }
        foreach ($book->getAuthors()->toArray() as $existing) {
            if (!in_array($existing, $authors, true)) {
                $book->removeAuthor($existing);
            }
        }
        foreach ($authors as $author) {
            $book->addAuthor($author);
        }

        $tags = [];
        foreach (NameList::parse($input->tags ?? '') as $name) {
            $tags[] = $this->findOrCreateTag($name);
        }
        foreach ($book->getTags()->toArray() as $existing) {
            if (!in_array($existing, $tags, true)) {
                $book->removeTag($existing);
            }
        }
        foreach ($tags as $tag) {
            $book->addTag($tag);
        }
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
