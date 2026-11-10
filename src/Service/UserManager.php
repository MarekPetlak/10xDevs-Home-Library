<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Exception\UserManagementException;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserManager
{
    public const MIN_PASSWORD_LENGTH = 8;
    public const MAX_PASSWORD_BYTES = 72;

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

        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent run inserted the same e-mail after our check. Doctrine closed the EntityManager.
            throw new UserManagementException(sprintf('A user with e-mail "%s" already exists.', $email), previous: $e);
        }

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

        // bcrypt silently ignores everything after 72 bytes, so refuse such passwords instead.
        if (\strlen($plainPassword) > self::MAX_PASSWORD_BYTES) {
            throw new UserManagementException(sprintf('The password must be at most %d bytes long.', self::MAX_PASSWORD_BYTES));
        }
    }
}
