<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Wiring check: a real failed sign-in through the firewall reaches the `auth` channel logger. */
final class LoginFailureLoggingTest extends WebTestCase
{
    public function testWrongPasswordIsLoggedOnTheAuthChannel(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Sign-in failed.', self::arrayHasKey('username_hash'));
        self::getContainer()->set('monolog.logger.auth', $logger);

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Zaloguj')->form(['_username' => 'nobody@example.com', '_password' => 'wrong']));

        self::assertResponseRedirects('/login');
    }
}
