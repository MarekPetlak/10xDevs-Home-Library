<?php

declare(strict_types=1);

namespace App\Tests\Sentry;

use App\Service\UserManager;
use App\Tests\Support\SpyTransport;
use Sentry\Event;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SentryUserContextTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testASignedInFailureCarriesOnlyTheAccountId(): void
    {
        $user = static::getContainer()->get(UserManager::class)->create('anna@example.com', 'secret-pass');
        $this->client->loginUser($user);

        $this->client->request('GET', '/_probe/throw/0', server: ['REMOTE_ADDR' => '203.0.113.9']);

        self::assertResponseStatusCodeSame(500);
        $events = $this->events();
        self::assertCount(1, $events);
        $sentryUser = $events[0]->getUser();
        self::assertNotNull($sentryUser);
        self::assertSame((string) $user->getId(), $sentryUser->getId());
        self::assertNull($sentryUser->getEmail());
        self::assertNull($sentryUser->getUsername());
        self::assertNull($sentryUser->getIpAddress());
        self::assertStringNotContainsString('anna@example.com', json_encode($events[0]->getUser(), JSON_THROW_ON_ERROR));
    }

    public function testAnAnonymousFailureCarriesNoUser(): void
    {
        $this->client->request('GET', '/_probe/throw/0');

        self::assertResponseStatusCodeSame(500);
        $events = $this->events();
        self::assertCount(1, $events);
        self::assertNull($events[0]->getUser());
    }

    /** @return list<Event> */
    private function events(): array
    {
        $spy = self::getContainer()->get(SpyTransport::class);
        \assert($spy instanceof SpyTransport);

        return $spy->events();
    }
}
