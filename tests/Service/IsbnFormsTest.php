<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\IsbnForms;
use PHPUnit\Framework\TestCase;

final class IsbnFormsTest extends TestCase
{
    public function testIsbn10GetsItsIsbn13(): void
    {
        self::assertSame(['0306406152', '9780306406157'], IsbnForms::of('0306406152'));
    }

    public function testIsbn13GetsItsIsbn10(): void
    {
        self::assertSame(['9780306406157', '0306406152'], IsbnForms::of('9780306406157'));
    }

    public function testIsbn10CheckDigitXIsProduced(): void
    {
        self::assertSame(['9780804429573', '080442957X'], IsbnForms::of('9780804429573'));
    }

    public function testIsbn10WithXCheckDigitConvertsToIsbn13(): void
    {
        self::assertSame(['080442957X', '9780804429573'], IsbnForms::of('080442957X'));
    }

    public function testPolishIsbnConvertsBothWays(): void
    {
        self::assertSame(['9788373271890', '8373271899'], IsbnForms::of('9788373271890'));
        self::assertSame(['8373271899', '9788373271890'], IsbnForms::of('8373271899'));
    }

    public function testIsbn13CheckDigitIsRecomputed(): void
    {
        // 9,7,8 then nine zeros: 9*1 + 7*3 + 8*1 = 38, so the check digit is 2.
        self::assertSame(['0000000000', '9780000000002'], IsbnForms::of('0000000000'));
    }

    public function test979HasNoIsbn10(): void
    {
        self::assertSame(['9791032305690'], IsbnForms::of('9791032305690'));
    }

    public function testInputIsAlwaysFirst(): void
    {
        foreach (['0306406152', '9780306406157', '9791032305690', '080442957X'] as $isbn) {
            self::assertSame($isbn, IsbnForms::of($isbn)[0]);
        }
    }
}
