<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Makes failed and throttled sign-ins visible. Symfony logs them at `info`, which the production
 * `fingers_crossed` handler drops, so a password-guessing run left no trace.
 *
 * Written to the `auth` channel (its own always-on handler in config/packages/monolog.yaml). The e-mail is
 * logged only as a keyed hash, so repeated attempts can be correlated without storing the address or password.
 */
#[AsEventListener]
final class LoginFailureLogger
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.auth')]
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $username = $request->request->get('_username');

        $this->logger->warning('Sign-in failed.', [
            'username_hash' => \is_string($username) && '' !== $username
                ? substr(hash_hmac('sha256', mb_strtolower(trim($username)), $this->secret), 0, 16)
                : null,
            'ip' => $request->getClientIp(),
            'throttled' => $event->getException() instanceof TooManyLoginAttemptsAuthenticationException,
            'reason' => $event->getException()::class,
            'firewall' => $event->getFirewallName(),
        ]);
    }
}
