<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserSerializationTest extends TestCase
{
    private const HASH = '$2y$13$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ0123456';

    public function testSessionPayloadDoesNotContainThePasswordHash(): void
    {
        $user = (new User())->setEmail('anna@example.com')->setPassword(self::HASH);

        $payload = serialize($user);

        self::assertStringNotContainsString(self::HASH, $payload);
        self::assertStringContainsString(hash('crc32c', self::HASH), $payload);
    }

    public function testUserSurvivesTheSessionRoundTripWithItsIdentity(): void
    {
        $user = (new User())->setEmail('anna@example.com')->setPassword(self::HASH)->setRoles(['ROLE_ADMIN']);

        $restored = unserialize(serialize($user));

        self::assertInstanceOf(User::class, $restored);
        self::assertSame('anna@example.com', $restored->getUserIdentifier());
        self::assertContains('ROLE_ADMIN', $restored->getRoles());
        // The restored password is the short hash that Symfony compares against the refreshed user.
        self::assertSame(hash('crc32c', self::HASH), $restored->getPassword());
    }
}
