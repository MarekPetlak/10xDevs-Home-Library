<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Ties a Sentry event to a household member without personal data: only the numeric account id is attached.
 *
 * Runs on `kernel.controller`, after the firewall has authenticated the request and before the action can throw.
 * Anonymous requests leave the scope untouched. No e-mail, username or IP is ever set.
 */
#[AsEventListener]
final class SentryUserContext
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly HubInterface $hub,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User || null === $user->getId()) {
            return;
        }

        $id = (string) $user->getId();
        $this->hub->configureScope(static function (Scope $scope) use ($id): void {
            $scope->setUser(new UserDataBag($id));
        });
    }
}
