<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Book;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Book>
 */
class BookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Book::class);
    }

    /**
     * Every book for the list page, newest first, authors loaded in the same query.
     * The single place where a future soft-delete filter (S-08) belongs.
     *
     * @return list<Book>
     */
    public function findAllForList(): array
    {
        /** @var list<Book> $books */
        $books = $this->createQueryBuilder('b')
            ->addSelect('a')
            ->leftJoin('b.authors', 'a')
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $books;
    }

    /**
     * One book for the detail page with authors and tags loaded. Soft-delete filter (S-08) goes here too.
     */
    public function findForDetail(int $id): ?Book
    {
        return $this->createQueryBuilder('b')
            ->addSelect('a', 't')
            ->leftJoin('b.authors', 'a')
            ->leftJoin('b.tags', 't')
            ->where('b.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
