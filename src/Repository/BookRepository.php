<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\RecommendationView;
use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\User;
use App\Enum\Category;
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
     * Recommendable books of one category, best first: rated by anyone, not read by the user.
     * Ranked by average rating, then rating count, then title, then id (determinism only).
     * Books nobody rated never appear. The single place where the soft-delete filter (S-08) belongs
     * for recommendations.
     *
     * @return list<RecommendationView>
     */
    public function findRecommendationCandidates(User $user, Category $category, int $limit): array
    {
        $qb = $this->createQueryBuilder('b');

        /** @var list<RecommendationView> $views */
        $views = $qb
            ->select(sprintf('NEW %s(b.id, b.title, b.category, AVG(r.rating), COUNT(r.id))', RecommendationView::class))
            ->join(ReadBook::class, 'r', 'WITH', 'r.book = b')
            ->where('b.category = :category')
            ->andWhere($qb->expr()->notIn('b.id', 'SELECT IDENTITY(own.book) FROM '.ReadBook::class.' own WHERE own.user = :user'))
            ->setParameter('category', $category)
            ->setParameter('user', $user)
            ->groupBy('b.id, b.title, b.category')
            ->orderBy('AVG(r.rating)', 'DESC')
            ->addOrderBy('COUNT(r.id)', 'DESC')
            ->addOrderBy('b.title', 'ASC')
            ->addOrderBy('b.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $views;
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
