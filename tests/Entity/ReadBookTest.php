<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ReadBook;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class ReadBookTest extends TestCase
{
    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    public function testCreatedAtComesFromTheClock(): void
    {
        Clock::set(new MockClock('2026-01-02 03:04:05 Europe/Warsaw'));

        $readBook = new ReadBook();

        self::assertSame(
            (new \DateTimeImmutable('2026-01-02 03:04:05 Europe/Warsaw'))->getTimestamp(),
            $readBook->getCreatedAt()->getTimestamp(),
        );
    }
}
