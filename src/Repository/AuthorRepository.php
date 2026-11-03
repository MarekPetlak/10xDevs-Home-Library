<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Author;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Author>
 */
class AuthorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Author::class);
    }

    /**
     * Case-insensitive thanks to the utf8mb4_unicode_ci column collation.
     */
    public function findOneByFullName(string $firstName, string $lastName): ?Author
    {
        return $this->findOneBy([
            'firstName' => trim($firstName),
            'lastName' => trim($lastName),
        ]);
    }
}
