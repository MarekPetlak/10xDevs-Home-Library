<?php

declare(strict_types=1);

namespace App\Tests\Sentry;

use App\Tests\Support\SpyTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\Event;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SentryReportingTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAnUncaughtExceptionIsReportedExactlyOnce(): void
    {
        $this->client->request('GET', '/_probe/throw/0');

        self::assertResponseStatusCodeSame(500);
        $events = $this->events();
        self::assertCount(1, $events, 'The error listener and the Monolog handler must not both report one exception.');
        self::assertSame(\RuntimeException::class, $events[0]->getExceptions()[0]->getType());
    }

    public function testAServerSideHttpExceptionIsReported(): void
    {
        $this->client->request('GET', '/_probe/throw/503');

        self::assertResponseStatusCodeSame(503);
        self::assertCount(1, $this->events());
    }

    #[DataProvider('clientErrorStatuses')]
    public function testClientErrorsAreNotReported(int $status): void
    {
        $this->client->request('GET', '/_probe/throw/'.$status);

        self::assertResponseStatusCodeSame($status);
        self::assertSame([], $this->events(), sprintf('A %d must not open a Sentry issue.', $status));
    }

    /** @return iterable<string, array{int}> */
    public static function clientErrorStatuses(): iterable
    {
        yield 'not found' => [404];
        yield 'method not allowed' => [405];
        yield 'forbidden' => [403];
        yield 'bad request' => [400];
    }

    public function testALoggedErrorIsReported(): void
    {
        $this->client->request('GET', '/_probe/log-error');

        self::assertResponseIsSuccessful();
        $events = $this->events();
        self::assertCount(1, $events);
        self::assertSame('probe logged error', $events[0]->getMessage());
    }

    public function testEventsCarryNoPersonalData(): void
    {
        $this->client->request('GET', '/_probe/throw/0', server: ['HTTP_COOKIE' => 'PHPSESSID=secret', 'REMOTE_ADDR' => '203.0.113.9']);

        $event = $this->events()[0];
        self::assertNull($event->getUser()?->getEmail());
        self::assertNull($event->getUser()?->getIpAddress());
        $request = $event->getRequest();
        self::assertArrayNotHasKey('cookies', $request);
        self::assertStringNotContainsString('secret', json_encode($request, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('203.0.113.9', json_encode($request, JSON_THROW_ON_ERROR));
    }

    /** @return list<Event> */
    private function events(): array
    {
        $spy = self::getContainer()->get(SpyTransport::class);
        \assert($spy instanceof SpyTransport);

        return $spy->events();
    }
}
