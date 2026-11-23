<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\LoginFailureLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final class LoginFailureLoggerTest extends TestCase
{
    public function testFailedSignInIsLoggedWithAHashedUsernameAndTheClientIp(): void
    {
        $context = $this->logOne(new BadCredentialsException(), ' Anna@Example.com ', 'secret-password');

        self::assertSame(16, \strlen($context['username_hash']));
        self::assertSame('203.0.113.9', $context['ip']);
        self::assertFalse($context['throttled']);
        self::assertSame(BadCredentialsException::class, $context['reason']);
        self::assertStringNotContainsStringIgnoringCase('anna', json_encode($context, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret-password', json_encode($context, JSON_THROW_ON_ERROR));
    }

    public function testTheSameAddressInAnyCaseGivesTheSameHash(): void
    {
        $a = $this->logOne(new BadCredentialsException(), 'anna@example.com');
        $b = $this->logOne(new BadCredentialsException(), '  ANNA@example.com');

        self::assertSame($a['username_hash'], $b['username_hash']);
    }

    public function testThrottledSignInIsFlagged(): void
    {
        $context = $this->logOne(new TooManyLoginAttemptsAuthenticationException(1), 'anna@example.com');

        self::assertTrue($context['throttled']);
    }

    public function testMissingUsernameIsLoggedWithoutAHash(): void
    {
        self::assertNull($this->logOne(new BadCredentialsException(), null)['username_hash']);
    }

    /** @return array<string, mixed> */
    private function logOne(\Symfony\Component\Security\Core\Exception\AuthenticationException $exception, ?string $username, string $password = 'x'): array
    {
        $captured = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Sign-in failed.', self::callback(static function (array $context) use (&$captured): bool {
            $captured = $context;

            return true;
        }));

        $request = Request::create('/login', 'POST', null === $username ? [] : ['_username' => $username, '_password' => $password], server: ['REMOTE_ADDR' => '203.0.113.9']);
        (new LoginFailureLogger($logger, 'test-secret'))(new LoginFailureEvent($exception, $this->createStub(AuthenticatorInterface::class), $request, null, 'main'));

        \assert(\is_array($captured));

        return $captured;
    }
}
