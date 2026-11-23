<?php

declare(strict_types=1);

namespace App\Tests\Sentry;

use App\Entity\User;
use App\Security\SentryUserContext;
use PHPUnit\Framework\TestCase;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Unit-level proof of the "never touches the scope for anonymous requests, never sends the e-mail" criterion. */
final class SentryUserContextScopeTest extends TestCase
{
    public function testAnAnonymousRequestNeverTouchesTheScope(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('configureScope');

        (new SentryUserContext(new TokenStorage(), $hub))($this->controllerEvent());
    }

    public function testASubRequestNeverTouchesTheScope(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('configureScope');

        (new SentryUserContext($this->storageFor($this->user(5)), $hub))($this->controllerEvent(HttpKernelInterface::SUB_REQUEST));
    }

    public function testASignedInRequestSetsOnlyTheNumericId(): void
    {
        $scope = new Scope();
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('configureScope')->willReturnCallback(static function (callable $callback) use ($scope): void {
            $callback($scope);
        });

        (new SentryUserContext($this->storageFor($this->user(42)), $hub))($this->controllerEvent());

        $event = $scope->applyToEvent(\Sentry\Event::createEvent());
        $sentryUser = $event?->getUser();
        self::assertNotNull($sentryUser);
        self::assertSame('42', $sentryUser->getId());
        self::assertNull($sentryUser->getEmail());
        self::assertNull($sentryUser->getUsername());
        self::assertNull($sentryUser->getIpAddress());
        self::assertSame([], $sentryUser->getMetadata());
    }

    public function testTheListenerNeverReadsOrLogsTheEmail(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Security/SentryUserContext.php');
        // Strip comments so the doc block (which says "no e-mail") does not count.
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

        foreach (['getEmail', 'getUserIdentifier', 'getUsername', 'getUserName', 'getClientIp', 'LoggerInterface', 'error_log'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, (string) $code, sprintf('SentryUserContext must not use %s.', $forbidden));
        }
    }

    private function user(int $id): User
    {
        $user = (new User())->setEmail('anna@example.com');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function storageFor(User $user): TokenStorage
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return $storage;
    }

    private function controllerEvent(int $type = HttpKernelInterface::MAIN_REQUEST): ControllerEvent
    {
        return new ControllerEvent($this->createStub(HttpKernelInterface::class), static fn (): null => null, new Request(), $type);
    }
}
