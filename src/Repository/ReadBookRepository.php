<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReadBook;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReadBook>
 */
class ReadBookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReadBook::class);
    }

    /**
     * The user's own read records keyed by book id, in one query.
     *
     * @return array<int, ReadBook>
     */
    public function findByUserIndexedByBookId(User $user): array
    {
        /** @var list<ReadBook> $records */
        $records = $this->createQueryBuilder('r')
            ->addSelect('b')
            ->join('r.book', 'b')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($records as $record) {
            $indexed[(int) $record->getBook()->getId()] = $record;
        }

        return $indexed;
    }
}
