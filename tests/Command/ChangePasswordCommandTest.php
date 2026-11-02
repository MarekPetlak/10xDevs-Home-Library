<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ChangePasswordCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('DELETE FROM `user`');
        $this->entityManager->clear();

        $this->tester = new CommandTester((new Application($kernel))->find('app:user:change-password'));
    }

    public function testChangesPassword(): void
    {
        self::getContainer()->get(UserManager::class)->create('anna@example.com', 'secret-pass');

        $this->tester->setInputs(['new-secret-pass', 'new-secret-pass']);
        $exit = $this->tester->execute(['email' => 'anna@example.com']);

        self::assertSame(Command::SUCCESS, $exit);

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'anna@example.com']);
        self::assertNotNull($stored);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($stored, 'new-secret-pass'));
        self::assertFalse($hasher->isPasswordValid($stored, 'secret-pass'));
    }

    public function testFailsForUnknownUser(): void
    {
        $this->tester->setInputs(['secret-pass', 'secret-pass']);
        $exit = $this->tester->execute(['email' => 'ghost@example.com']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('No user', $this->tester->getDisplay());
    }

    public function testFailsWhenPasswordsDoNotMatch(): void
    {
        self::getContainer()->get(UserManager::class)->create('anna@example.com', 'secret-pass');

        $this->tester->setInputs(['new-secret-pass', 'different-pass']);
        $exit = $this->tester->execute(['email' => 'anna@example.com']);

        self::assertSame(Command::FAILURE, $exit);
    }

    public function testFailsOnShortPassword(): void
    {
        self::getContainer()->get(UserManager::class)->create('anna@example.com', 'secret-pass');

        $this->tester->setInputs(['short', 'short']);
        $exit = $this->tester->execute(['email' => 'anna@example.com']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('at least 8 characters', $this->tester->getDisplay());
    }
}
