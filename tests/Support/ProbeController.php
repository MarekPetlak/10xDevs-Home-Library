<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Test-only routes (loaded from config/routes/test) that fail on purpose so tests can see what reaches Sentry. */
final class ProbeController
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /** Status 0 throws a plain exception (a bug); any other status throws an HTTP exception with that code. */
    #[Route('/_probe/throw/{status}', requirements: ['status' => '\d+'])]
    public function throw(int $status): never
    {
        throw 0 === $status ? new \RuntimeException('probe failure') : new HttpException($status, 'probe http failure');
    }

    #[Route('/_probe/log-error')]
    public function logError(): Response
    {
        $this->logger->error('probe logged error', ['user_id' => 7]);

        return new Response('logged');
    }
}
