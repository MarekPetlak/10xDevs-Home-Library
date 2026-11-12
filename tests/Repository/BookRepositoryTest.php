<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Dto\RecommendationView;
use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\User;
use App\Enum\Category;
use App\Repository\BookRepository;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BookRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private BookRepository $repository;
    private User $anna;
    private User $ola;
    private User $ewa;
    private User $jan;
    private int $isbnSeq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(BookRepository::class);
        $users = $container->get(UserManager::class);
        $this->anna = $users->create('anna@example.com', 'secret-pass');
        $this->ola = $users->create('ola@example.com', 'secret-pass');
        $this->ewa = $users->create('ewa@example.com', 'secret-pass');
        $this->jan = $users->create('jan@example.com', 'secret-pass');
    }

    public function testCandidatesAreRankedByAverageRatingDescending(): void
    {
        $low = $this->book('Niska', Category::Novel);
        $high = $this->book('Wysoka', Category::Novel);
        $mid = $this->book('Srednia', Category::Novel);
        $this->rate($low, $this->ola, 2);
        $this->rate($high, $this->ola, 5);
        $this->rate($mid, $this->ola, 4);

        $result = $this->repository->findRecommendationCandidates($this->anna, Category::Novel, 10);

        self::assertSame(['Wysoka', 'Srednia', 'Niska'], $this->titles($result));
    }

    public function testViewCarriesAverageCountCategoryAndId(): void
    {
        $book = $this->book('Solaris', Category::Crime);
        $this->rate($book, $this->ola, 5);
        $this->rate($book, $this->ewa, 4);
        $this->rate($book, $this->jan, 4);

        $result = $this->repository->findRecommendationCandidates($this->anna, Category::Crime, 10);

        self::assertCount(1, $result);
        self::assertInstanceOf(RecommendationView::class, $result[0]);
        self::assertSame($book->getId(), $result[0]->bookId);
        self::assertSame('Solaris', $result[0]->title);
        self::assertSame(Category::Crime, $result[0]->category);
        self::assertEqualsWithDelta(13 / 3, $result[0]->averageRating, 0.0001);
        self::assertSame(3, $result[0]->ratingCount);
    }

    public function testEqualAveragesAreOrderedByRatingCountDescending(): void
    {
        $few = $this->book('A Mało ocen', Category::Novel);
        $many = $this->book('Z Dużo ocen', Category::Novel);
        $this->rate($few, $this->ola, 4);
        $this->rate($many, $this->ola, 4);
        $this->rate($many, $this->ewa, 4);

        $result = $this->repository->findRecommendationCandidates($this->anna, Category::Novel, 10);

        self::assertSame(['Z Dużo ocen', 'A Mało ocen'], $this->titles($result));
    }

    public function testEqualAverageAndCountAreOrderedByTitleAscending(): void
    {
        $zeta = $this->book('Zeta', Category::Novel);
        $alfa = $this->book('Alfa', Category::Novel);
        $beta = $this->book('Beta', Category::Novel);
        foreach ([$zeta, $alfa, $beta] as $book) {
            $this->rate($book, $this->ola, 4);
        }

        $result = $this->repository->findRecommendationCandidates($this->anna, Category::Novel, 10);

        self::assertSame(['Alfa', 'Beta', 'Zeta'], $this->titles($result));
    }

    public function testIdenticalTitlesFallBackToIdAscending(): void
    {
        $first = $this->book('Ta sama', Category::Novel);
        $second = $this->book('Ta sama', Category::Novel);
        $this->rate($second, $this->ola, 4);
        $this->rate($first, $this->ola, 4);

        $result = $this->repository->findRecommendationCandidates($this->anna, Category::Novel, 10);

        self::assertSame([$first->getId(), $second->getId()], $this->ids($result));
    }

    public function testBooksTheUserReadAreExcludedEvenWhenOthersRatedThem(): void
    {
        $read = $this->book('Przeczytana', Category::Novel);
        $unread = $this->book('Nieprzeczytana', Category::Novel);
        $this->rate($read, $this->anna, 5);
        $this->rate($read, $this->ola, 5);
        $this->rate($unread, $this->ola, 3);

        $result = $this->repository->findRecommendationCandidates($this->anna, Category::Novel, 10);

        self::assertSame(['Nieprzeczytana'], $this->titles($result));
    }

    public function testOtherUsersReadingsDoNotExcludeBooks(): void
    {
        $book = $this->book('Cudza lektura', Category::Novel);
        $this->rate($book, $this->ola, 4);
        $this->rate($book, $this->ewa, 4);

        $result = $this->repository->findRecommendationCandidates($this->anna, Category::Novel, 10);

        self::assertSame([(int) $book->getId()], $this->ids($result));
        self::assertSame(2, $result[0]->ratingCount);
    }

    public function testUnratedBooksAreNeverReturned(): void
    {
        $this->book('Nikt nie ocenił', Category::Novel);
        $rated = $this->book('Oceniona', Category::Novel);
        $this->rate($rated, $this->ola, 1);

        $result = $this->repository->findRecommendationCandidates($this->anna, Category::Novel, 10);

        self::assertSame(['Oceniona'], $this->titles($result));
    }

    public function testBookAddedByTheUserButNotReadIsIncluded(): void
    {
        $own = $this->book('Moja dodana', Category::Novel, $this->anna);
        $this->rate($own, $this->ola, 5);

        $result = $this->repository->findRecommendationCandidates($this->anna, Category::Novel, 10);

        self::assertSame(['Moja dodana'], $this->titles($result));
    }

    public function testOnlyTheRequestedCategoryIsReturned(): void
    {
        $novel = $this->book('Powieść', Category::Novel);
        $crime = $this->book('Kryminał', Category::Crime);
        $this->rate($novel, $this->ola, 3);
        $this->rate($crime, $this->ola, 5);

        $result = $this->repository->findRecommendationCandidates($this->anna, Category::Novel, 10);

        self::assertSame(['Powieść'], $this->titles($result));
    }

    public function testLimitKeepsTheBestOnes(): void
    {
        foreach ([['Czwarta', 1], ['Pierwsza', 5], ['Trzecia', 3], ['Druga', 4]] as [$title, $rating]) {
            $this->rate($this->book($title, Category::Novel), $this->ola, $rating);
        }

        $result = $this->repository->findRecommendationCandidates($this->anna, Category::Novel, 2);

        self::assertSame(['Pierwsza', 'Druga'], $this->titles($result));
    }

    private function book(string $title, Category $category, ?User $addedBy = null): Book
    {
        $book = (new Book())
            ->setTitle($title)
            ->setIsbn(sprintf('97883%08d', ++$this->isbnSeq))
            ->setPublicationYear(2000)
            ->setCategory($category)
            ->setAddedBy($addedBy ?? $this->ola);
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        return $book;
    }

    private function rate(Book $book, User $user, int $rating): void
    {
        $this->entityManager->persist(
            (new ReadBook())
                ->setUser($user)
                ->setBook($book)
                ->setRating($rating)
                ->setReadOn(new \DateTimeImmutable('2026-09-15')),
        );
        $this->entityManager->flush();
    }

    /**
     * @param list<RecommendationView> $views
     *
     * @return list<string>
     */
    private function titles(array $views): array
    {
        return array_map(static fn (RecommendationView $v): string => $v->title, $views);
    }

    /**
     * @param list<RecommendationView> $views
     *
     * @return list<int>
     */
    private function ids(array $views): array
    {
        return array_map(static fn (RecommendationView $v): int => $v->bookId, $views);
    }
}
