<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Readiness: the app can serve requests only while the database answers (sessions and all data live there).
 * /healthz stays a dependency-free liveness check; this route is the one a platform check should use.
 */
final class ReadinessController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/readyz', name: 'readyz', methods: ['GET'])]
    public function __invoke(): Response
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable $e) {
            // The body stays generic (public route); the cause goes to the log with the exception attached.
            $this->logger->error('Readiness check failed: the database is not reachable.', ['exception' => $e]);

            return new Response('unavailable', Response::HTTP_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain']);
        }

        return new Response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }
}
