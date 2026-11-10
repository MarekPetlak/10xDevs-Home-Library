<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\CommentView;
use App\Entity\Book;
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

    /**
     * Comments of one book, newest first, in one query that selects only e-mail, rating and text
     * (no User entity is hydrated). The viewer's own record is left out: it is already shown in
     * their read state. Ratings are exposed only through records that have a comment.
     *
     * @return list<CommentView>
     */
    public function findCommentsForBook(Book $book, User $viewer): array
    {
        /** @var list<CommentView> $views */
        $views = $this->createQueryBuilder('r')
            ->select(sprintf('NEW %s(u.email, r.rating, r.comment)', CommentView::class))
            ->join('r.user', 'u')
            ->where('r.book = :book')
            ->andWhere('r.comment IS NOT NULL')
            ->andWhere('r.user != :viewer')
            ->setParameter('book', $book)
            ->setParameter('viewer', $viewer)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $views;
    }
}
