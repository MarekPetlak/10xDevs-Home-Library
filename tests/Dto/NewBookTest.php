<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\NewBook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NewBookTest extends TestCase
{
    #[DataProvider('descriptions')]
    public function testDescriptionIsTrimmedAndBlankBecomesNull(?string $given, ?string $expected): void
    {
        $input = new NewBook();
        $input->description = $given;

        self::assertSame($expected, $input->description);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function descriptions(): iterable
    {
        yield 'text is trimmed' => ['  Poemat dramatyczny.  ', 'Poemat dramatyczny.'];
        yield 'inner spaces stay' => [' a  b ', 'a  b'];
        yield 'blank becomes null' => ['   ', null];
        yield 'empty becomes null' => ['', null];
        yield 'null stays null' => [null, null];
    }

    public function testNewInputHasNoDescription(): void
    {
        self::assertNull((new NewBook())->description);
    }
}
