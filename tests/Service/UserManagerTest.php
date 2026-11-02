<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Exception\UserManagementException;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserManagerTest extends KernelTestCase
{
    private UserManager $userManager;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $hasher;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->userManager = $container->get(UserManager::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get(UserPasswordHasherInterface::class);

        $this->entityManager->getConnection()->executeStatement('DELETE FROM `user`');
        $this->entityManager->clear();
    }

    public function testCreateStoresHashedPasswordAndDefaultRole(): void
    {
        $user = $this->userManager->create('anna@example.com', 'secret-pass');

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'anna@example.com']);

        self::assertNotNull($stored);
        self::assertNotNull($user->getId());
        self::assertNotSame('secret-pass', $stored->getPassword());
        self::assertTrue($this->hasher->isPasswordValid($stored, 'secret-pass'));
        self::assertContains('ROLE_USER', $stored->getRoles());
    }

    public function testCreateNormalisesEmail(): void
    {
        $user = $this->userManager->create('  Anna@Example.COM ', 'secret-pass');

        self::assertSame('anna@example.com', $user->getEmail());
    }

    public function testCreateRejectsDuplicateEmailIgnoringCase(): void
    {
        $this->userManager->create('anna@example.com', 'secret-pass');

        $this->expectException(UserManagementException::class);
        $this->userManager->create('ANNA@example.com', 'other-secret');
    }

    public function testCreateRejectsShortPassword(): void
    {
        $this->expectException(UserManagementException::class);
        $this->userManager->create('anna@example.com', 'short');
    }

    public function testCreateRejectsInvalidEmail(): void
    {
        $this->expectException(UserManagementException::class);
        $this->userManager->create('not-an-email', 'secret-pass');
    }

    public function testChangePasswordReplacesHash(): void
    {
        $this->userManager->create('anna@example.com', 'secret-pass');

        $this->userManager->changePassword('Anna@example.com', 'new-secret-pass');

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'anna@example.com']);

        self::assertNotNull($stored);
        self::assertTrue($this->hasher->isPasswordValid($stored, 'new-secret-pass'));
        self::assertFalse($this->hasher->isPasswordValid($stored, 'secret-pass'));
    }

    public function testChangePasswordRejectsUnknownUser(): void
    {
        $this->expectException(UserManagementException::class);
        $this->userManager->changePassword('ghost@example.com', 'secret-pass');
    }

    public function testChangePasswordRejectsShortPassword(): void
    {
        $this->userManager->create('anna@example.com', 'secret-pass');

        $this->expectException(UserManagementException::class);
        $this->userManager->changePassword('anna@example.com', 'short');
    }
}
