<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Sentry\Event;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/** Keeps Sentry events in memory so tests can inspect what would have been sent, with no network access. */
final class SpyTransport implements TransportInterface
{
    /** @var list<Event> */
    private array $events = [];

    public function send(Event $event): Result
    {
        $this->events[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }

    /** @return list<Event> */
    public function events(): array
    {
        return $this->events;
    }
}
