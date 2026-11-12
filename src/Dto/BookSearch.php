<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Category;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * The book list filters and page number, normalised from the query string.
 * Blank text, an unknown category and a page below 1 never cause an error: they mean "no filter" and "page 1".
 */
final readonly class BookSearch
{
    public function __construct(
        public ?string $title = null,
        public ?string $author = null,
        public ?Category $category = null,
        public int $page = 1,
    ) {
    }

    /**
     * @param InputBag<string> $query
     */
    public static function fromQuery(InputBag $query): self
    {
        // all() instead of get(): get() throws a 400 for array values such as ?title[]=x.
        $values = $query->all();
        $page = $values['page'] ?? null;

        return new self(
            self::text($values['title'] ?? null),
            self::text($values['author'] ?? null),
            Category::tryFrom(self::text($values['category'] ?? null) ?? ''),
            \is_string($page) ? max(1, (int) filter_var($page, \FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'max_range' => 100000]])) : 1,
        );
    }

    public function isFiltered(): bool
    {
        return null !== $this->title || null !== $this->author || null !== $this->category;
    }

    /**
     * The active filters as query parameters (no page), for links that keep the search.
     *
     * @return array<string, string>
     */
    public function filters(): array
    {
        return array_filter([
            'title' => $this->title,
            'author' => $this->author,
            'category' => $this->category?->value,
        ], static fn (?string $value): bool => null !== $value);
    }

    private static function text(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
