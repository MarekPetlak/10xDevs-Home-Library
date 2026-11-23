<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\BookConflictException;
use App\Service\DomainFailureLogger;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class DomainFailureLoggerTest extends TestCase
{
    public function testBookConflictIsOneErrorRecordWithIdsAndTheOriginalException(): void
    {
        $logger = new RecordingLogger();
        $cause = new \RuntimeException('duplicate entry');
        $conflict = new BookConflictException(previous: $cause);

        (new DomainFailureLogger($logger))->bookConflict('edit', 7, 42, $conflict);

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame(['action', 'book_id', 'exception', 'user_id'], $this->sortedKeys($logger));
        self::assertSame('edit', $logger->records[0]['context']['action']);
        self::assertSame(7, $logger->records[0]['context']['user_id']);
        self::assertSame(42, $logger->records[0]['context']['book_id']);
        self::assertSame($conflict, $logger->records[0]['context']['exception']);
        self::assertSame($cause, $logger->records[0]['context']['exception']->getPrevious());
    }

    public function testBookConflictOnAddHasNoBookId(): void
    {
        $logger = new RecordingLogger();

        (new DomainFailureLogger($logger))->bookConflict('add', 7, null, new BookConflictException());

        self::assertCount(1, $logger->records);
        self::assertSame('add', $logger->records[0]['context']['action']);
        self::assertNull($logger->records[0]['context']['book_id']);
    }

    public function testInvalidCsrfTokenIsOneErrorRecordWithRouteAndIdsOnly(): void
    {
        $logger = new RecordingLogger();

        (new DomainFailureLogger($logger))->invalidCsrfToken('book_delete', 7, 42);

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame(['book_id', 'route', 'user_id'], $this->sortedKeys($logger));
        self::assertSame('book_delete', $logger->records[0]['context']['route']);
        self::assertSame(7, $logger->records[0]['context']['user_id']);
        self::assertSame(42, $logger->records[0]['context']['book_id']);
    }

    /**
     * @return list<string>
     */
    private function sortedKeys(RecordingLogger $logger): array
    {
        $keys = array_keys($logger->records[0]['context']);
        sort($keys);

        return $keys;
    }
}
