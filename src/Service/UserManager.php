<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Exception\UserManagementException;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserManager
{
    public const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function create(string $email, string $plainPassword): User
    {
        $email = $this->normaliseEmail($email);
        $this->assertPassword($plainPassword);

        if (null !== $this->userRepository->findOneByEmail($email)) {
            throw new UserManagementException(sprintf('A user with e-mail "%s" already exists.', $email));
        }

        $user = (new User())->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    public function changePassword(string $email, string $plainPassword): User
    {
        $email = $this->normaliseEmail($email);
        $this->assertPassword($plainPassword);

        $user = $this->userRepository->findOneByEmail($email);
        if (null === $user) {
            throw new UserManagementException(sprintf('No user with e-mail "%s" exists.', $email));
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->entityManager->flush();

        return $user;
    }

    private function normaliseEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180) {
            throw new UserManagementException(sprintf('"%s" is not a valid e-mail address.', $email));
        }

        return $email;
    }

    private function assertPassword(string $plainPassword): void
    {
        if (mb_strlen($plainPassword) < self::MIN_PASSWORD_LENGTH) {
            throw new UserManagementException(sprintf('The password must be at least %d characters long.', self::MIN_PASSWORD_LENGTH));
        }
    }
}
