<?php

declare(strict_types=1);

namespace App\Sentry;

use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * `before_send` filter: HTTP errors below 500 (404, 405, 403, a bad request) are caller mistakes, not server faults,
 * so they never become Sentry issues. 5xx and every non-HTTP exception pass through.
 *
 * A class-based `ignore_exceptions` entry cannot do this: a 503 is the same exception class as a 404.
 */
final class DropClientErrors
{
    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $exception = $hint?->exception;

        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return null;
        }

        return $event;
    }
}
