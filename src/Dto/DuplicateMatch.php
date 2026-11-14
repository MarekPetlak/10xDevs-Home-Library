<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Book;

/**
 * An existing book that looks like the one being added, with the typed reasons why (both can be true).
 */
final readonly class DuplicateMatch
{
    public function __construct(
        public Book $book,
        public bool $sameIsbn,
        public bool $sameTitleAndAuthor,
    ) {
    }
}
