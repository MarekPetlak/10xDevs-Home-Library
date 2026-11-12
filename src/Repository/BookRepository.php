<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\BookSearch;
use App\Dto\RecommendationView;
use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\User;
use App\Enum\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Book>
 */
class BookRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 20;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Book::class);
    }

    /**
     * One page of the book list, newest first, authors loaded in the same query. Text filters are
     * "contains", ignoring case but not accents (the title and author columns are *_as_ci); % and _ in
     * the input match literally. The author filter is an EXISTS subquery, so a matching book still
     * lists all of its authors. The single place where a future soft-delete filter (S-08) belongs.
     *
     * @return Paginator<Book>
     */
    public function search(BookSearch $search): Paginator
    {
        $qb = $this->createQueryBuilder('b')
            ->addSelect('a')
            ->leftJoin('b.authors', 'a')
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->setFirstResult(($search->page - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE);

        if (null !== $search->title) {
            $qb->andWhere("b.title LIKE :title ESCAPE '!'")
                ->setParameter('title', self::contains($search->title));
        }
        if (null !== $search->author) {
            $qb->andWhere($qb->expr()->exists(
                'SELECT fb.id FROM '.Book::class.' fb JOIN fb.authors fa WHERE fb.id = b.id'
                ." AND (fa.firstName LIKE :author ESCAPE '!' OR fa.lastName LIKE :author ESCAPE '!'"
                ." OR CONCAT(fa.firstName, ' ', fa.lastName) LIKE :author ESCAPE '!')",
            ))->setParameter('author', self::contains($search->author));
        }
        if (null !== $search->category) {
            $qb->andWhere('b.category = :category')
                ->setParameter('category', $search->category);
        }

        return new Paginator($qb->getQuery(), fetchJoinCollection: true);
    }

    private static function contains(string $text): string
    {
        return '%'.strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
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
