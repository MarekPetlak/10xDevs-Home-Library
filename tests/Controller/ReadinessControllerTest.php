<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReadinessControllerTest extends WebTestCase
{
    public function testReadyzReturnsOkWhenTheDatabaseAnswers(): void
    {
        $client = static::createClient();
        $client->request('GET', '/readyz');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('ok', $client->getResponse()->getContent());
    }

    public function testReadyzReturns503AndLogsTheCauseWhenTheDatabaseIsDown(): void
    {
        $client = static::createClient();

        $failure = new ConnectionException(new class('connection refused') extends \Exception implements DriverException {
            public function getSQLState(): ?string
            {
                return null;
            }
        }, null);
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException($failure);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('Readiness check failed'), self::callback(static fn (array $context): bool => ($context['exception'] ?? null) === $failure));

        $client->disableReboot();
        self::getContainer()->set(Connection::class, $connection);
        self::getContainer()->set(LoggerInterface::class, $logger);

        $client->request('GET', '/readyz');

        self::assertResponseStatusCodeSame(503);
        self::assertSame('unavailable', $client->getResponse()->getContent());
    }

    public function testHealthzStaysUpWhenTheDatabaseIsDown(): void
    {
        $client = static::createClient();
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException(new \RuntimeException('down'));
        $client->disableReboot();
        self::getContainer()->set(Connection::class, $connection);

        $client->request('GET', '/healthz');

        self::assertResponseStatusCodeSame(200);
    }
}
