<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\BookSearch;
use App\Enum\Category;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class BookSearchTest extends TestCase
{
    public function testEmptyQueryMeansNoFiltersAndFirstPage(): void
    {
        $search = BookSearch::fromQuery(new InputBag());

        self::assertNull($search->title);
        self::assertNull($search->author);
        self::assertNull($search->category);
        self::assertSame(1, $search->page);
        self::assertFalse($search->isFiltered());
        self::assertSame([], $search->filters());
    }

    public function testTextIsTrimmedAndBlankMeansNoFilter(): void
    {
        $search = BookSearch::fromQuery(new InputBag(['title' => '  Solaris ', 'author' => '   ', 'category' => 'poetry']));

        self::assertSame('Solaris', $search->title);
        self::assertNull($search->author);
        self::assertSame(Category::Poetry, $search->category);
        self::assertTrue($search->isFiltered());
        self::assertSame(['title' => 'Solaris', 'category' => 'poetry'], $search->filters());
    }

    public function testUnknownCategoryMeansNoFilter(): void
    {
        self::assertNull(BookSearch::fromQuery(new InputBag(['category' => 'bogus']))->category);
    }

    #[DataProvider('pages')]
    public function testPageFallsBackToOneWhenInvalid(mixed $raw, int $expected): void
    {
        self::assertSame($expected, BookSearch::fromQuery(new InputBag(['page' => $raw]))->page);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function pages(): iterable
    {
        yield 'valid' => ['3', 3];
        yield 'zero' => ['0', 1];
        yield 'negative' => ['-3', 1];
        yield 'text' => ['abc', 1];
        yield 'decimal' => ['2.5', 1];
        yield 'huge' => ['99999999999999999999', 1];
        yield 'array' => [['1'], 1];
    }

    public function testArrayValuesNeverThrow(): void
    {
        $search = BookSearch::fromQuery(new InputBag(['title' => ['x'], 'author' => ['y'], 'category' => ['poetry']]));

        self::assertNull($search->title);
        self::assertNull($search->author);
        self::assertNull($search->category);
    }
}
