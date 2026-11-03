<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NameList;
use PHPUnit\Framework\TestCase;

final class NameListTest extends TestCase
{
    public function testParseTrimsEntries(): void
    {
        self::assertSame(['Jan Kowalski', 'Anna Nowak'], NameList::parse("  Jan Kowalski ,\tAnna Nowak  "));
    }

    public function testParseCollapsesInnerWhitespace(): void
    {
        self::assertSame(['Jan Maria Kowalski'], NameList::parse("Jan   Maria \t Kowalski"));
    }

    public function testParseDropsEmptyEntries(): void
    {
        self::assertSame(['a', 'b'], NameList::parse(',, a , ,b,'));
        self::assertSame([], NameList::parse(' , ,'));
        self::assertSame([], NameList::parse(''));
    }

    public function testParseDeduplicatesIgnoringCaseAndKeepsFirstSpelling(): void
    {
        self::assertSame(['Stanisław Lem', 'jan kowalski'],NameList::parse('Stanisław Lem, jan kowalski, STANISŁAW LEM, Jan Kowalski'));
    }

    public function testSplitAuthorWithTwoWords(): void
    {
        self::assertSame(['firstName' => 'Jan', 'lastName' => 'Kowalski'], NameList::splitAuthor('Jan Kowalski'));
    }

    public function testSplitAuthorWithThreeWordsSplitsAtLastSpace(): void
    {
        self::assertSame(['firstName' => 'Jan Maria', 'lastName' => 'Kowalski'], NameList::splitAuthor('Jan Maria Kowalski'));
    }

    public function testSplitAuthorWithSingleWordLeavesFirstNameEmpty(): void
    {
        self::assertSame(['firstName' => '', 'lastName' => 'Homer'], NameList::splitAuthor('Homer'));
    }
}
