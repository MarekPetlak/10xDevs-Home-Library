<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\BookConflictException;
use Psr\Log\LoggerInterface;

/**
 * Logs domain failures that the user sees as an ordinary form error or a 400, so they still reach the error stream.
 * Context carries ids and names only: never an e-mail address, a password or a CSRF token value.
 */
final class DomainFailureLogger
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param 'add'|'edit' $action
     */
    public function bookConflict(string $action, ?int $userId, ?int $bookId, BookConflictException $exception): void
    {
        $this->logger->error('Book write conflict: a concurrent request created the same author or tag first.', [
            'action' => $action,
            'user_id' => $userId,
            'book_id' => $bookId,
            // Keeps the database error as the previous exception.
            'exception' => $exception,
        ]);
    }

    public function invalidCsrfToken(string $route, ?int $userId, ?int $bookId): void
    {
        $this->logger->error('Invalid CSRF token on a state-changing request.', [
            'route' => $route,
            'user_id' => $userId,
            'book_id' => $bookId,
        ]);
    }
}
