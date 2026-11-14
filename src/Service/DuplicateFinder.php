<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\DuplicateMatch;
use App\Dto\NewBook;
use App\Entity\Author;
use App\Entity\Book;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;

/**
 * Finds existing books that look like the one being added (FR-005): same ISBN in either form, or same
 * title and a shared author. Only informs; the caller decides what to do.
 */
final class DuplicateFinder
{
    public function __construct(
        private readonly BookRepository $bookRepository,
        private readonly AuthorRepository $authorRepository,
    ) {
    }

    /**
     * @return list<DuplicateMatch>
     */
    public function find(NewBook $input): array
    {
        $title = trim((string) $input->title);
        $isbns = '' === trim((string) $input->isbn) ? [] : IsbnForms::of(strtoupper(trim((string) $input->isbn)));
        $authors = $this->resolveAuthors((string) $input->authors);

        $matches = [];
        foreach ($this->bookRepository->findDuplicates($title, $authors, $isbns) as $book) {
            $sameIsbn = in_array($book->getIsbn(), $isbns, true);
            // A hit that is not an ISBN hit came from the title rule, so trust it: PHP case folding can disagree
            // with the column collation (ß/ss) and would leave the warning without any reason.
            $matches[] = new DuplicateMatch(
                $book,
                $sameIsbn,
                !$sameIsbn || $this->sameTitleAndAuthor($book, $title, $authors),
            );
        }

        return $matches;
    }

    /**
     * Typed authors that already exist; unknown ones cannot be on any stored book.
     *
     * @return list<Author>
     */
    private function resolveAuthors(string $text): array
    {
        $authors = [];
        foreach (NameList::parse($text) as $entry) {
            $name = NameList::splitAuthor($entry);
            $author = $this->authorRepository->findOneByFullName($name['firstName'], $name['lastName']);
            if (null !== $author) {
                $authors[] = $author;
            }
        }

        return $authors;
    }

    /**
     * @param list<Author> $authors
     */
    private function sameTitleAndAuthor(Book $book, string $title, array $authors): bool
    {
        if ('' === $title || mb_strtolower(trim($book->getTitle())) !== mb_strtolower($title)) {
            return false;
        }

        $ids = array_map(static fn (Author $a): ?int => $a->getId(), $authors);
        foreach ($book->getAuthors() as $author) {
            if (in_array($author->getId(), $ids, true)) {
                return true;
            }
        }

        return false;
    }
}
