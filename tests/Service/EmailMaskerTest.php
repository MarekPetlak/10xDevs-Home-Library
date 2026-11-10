<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EmailMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailMaskerTest extends TestCase
{
    #[DataProvider('examples')]
    public function testMasksTheLocalPartAndKeepsTheDomain(string $email, string $expected): void
    {
        self::assertSame($expected, (new EmailMasker())->mask($email));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        yield 'regular address' => ['anna@example.com', 'a***@example.com'];
        yield 'one-character local part' => ['a@example.com', '*@example.com'];
        yield 'two-character local part' => ['ab@x.pl', 'a***@x.pl'];
        yield 'multibyte first character' => ['łukasz@example.pl', 'ł***@example.pl'];
        yield 'plus tag is hidden' => ['anna+books@example.com', 'a***@example.com'];
    }

    public function testValueWithoutAtSignIsFullyMasked(): void
    {
        self::assertSame('***', (new EmailMasker())->mask('not-an-email'));
    }
}
