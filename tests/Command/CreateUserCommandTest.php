<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateUserCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('DELETE FROM `user`');
        $this->entityManager->clear();

        $this->tester = new CommandTester((new Application($kernel))->find('app:user:create'));
    }

    public function testCreatesUserWithHashedPassword(): void
    {
        $this->tester->setInputs(['secret-pass', 'secret-pass']);
        $exit = $this->tester->execute(['email' => 'Anna@Example.com']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('anna@example.com', $this->tester->getDisplay());

        $stored = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'anna@example.com']);
        self::assertNotNull($stored);
        self::assertNotSame('secret-pass', $stored->getPassword());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($stored, 'secret-pass'));
    }

    public function testFailsWhenPasswordsDoNotMatch(): void
    {
        $this->tester->setInputs(['secret-pass', 'different-pass']);
        $exit = $this->tester->execute(['email' => 'anna@example.com']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertNull($this->entityManager->getRepository(User::class)->findOneBy(['email' => 'anna@example.com']));
    }

    public function testFailsOnShortPassword(): void
    {
        $this->tester->setInputs(['short', 'short']);
        $exit = $this->tester->execute(['email' => 'anna@example.com']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('at least 8 characters', $this->tester->getDisplay());
    }

    public function testFailsOnDuplicateEmail(): void
    {
        $this->tester->setInputs(['secret-pass', 'secret-pass']);
        $this->tester->execute(['email' => 'anna@example.com']);

        $this->tester->setInputs(['other-secret', 'other-secret']);
        $exit = $this->tester->execute(['email' => 'anna@example.com']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('already exists', $this->tester->getDisplay());
    }

    public function testHasNoPasswordArgumentOrOption(): void
    {
        $definition = (new Application(self::$kernel))->find('app:user:create')->getDefinition();

        self::assertFalse($definition->hasArgument('password'));
        self::assertFalse($definition->hasOption('password'));
    }
}
