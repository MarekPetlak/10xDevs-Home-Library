<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\RecommendationView;
use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\User;
use App\Enum\Category;
use App\Repository\BookRepository;
use App\Repository\ReadBookRepository;
use App\Service\RecommendationService;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class RecommendationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private RecommendationService $service;
    private User $anna;
    /** @var list<User> */
    private array $raters;
    private int $isbnSeq = 0;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-12 12:00:00', 'Europe/Warsaw'));
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->service = new RecommendationService(
            $container->get(BookRepository::class),
            $container->get(ReadBookRepository::class),
        );
        $users = $container->get(UserManager::class);
        $this->anna = $users->create('anna@example.com', 'secret-pass');
        $this->raters = [
            $users->create('ola@example.com', 'secret-pass'),
            $users->create('ewa@example.com', 'secret-pass'),
            $users->create('jan@example.com', 'secret-pass'),
        ];
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testRankingStaysInTheTopCategoryEvenWhenAnotherHasHigherAverages(): void
    {
        $this->readBy($this->anna, Category::Novel, '2026-03-01');
        $this->readBy($this->anna, Category::Novel, '2026-03-02');
        $this->readBy($this->anna, Category::Crime, '2026-10-01');
        $this->candidate('Powieść słaba', Category::Novel, [2]);
        $this->candidate('Powieść średnia', Category::Novel, [3]);
        $this->candidate('Kryminał świetny', Category::Crime, [5]);

        $result = $this->service->forUser($this->anna);

        self::assertSame(['Powieść średnia', 'Powieść słaba', 'Kryminał świetny'], $this->titles($result));
    }

    public function testFillsFromTheSecondCategoryUpToThree(): void
    {
        $this->readBy($this->anna, Category::Novel, '2026-03-01');
        $this->readBy($this->anna, Category::Novel, '2026-03-02');
        $this->readBy($this->anna, Category::Crime, '2026-10-01');
        $this->candidate('Powieść 1', Category::Novel, [4]);
        $this->candidate('Powieść 2', Category::Novel, [3]);
        $this->candidate('Kryminał 1', Category::Crime, [2]);
        $this->candidate('Kryminał 2', Category::Crime, [5]);

        $result = $this->service->forUser($this->anna);

        self::assertSame(['Powieść 1', 'Powieść 2', 'Kryminał 2'], $this->titles($result));
    }

    public function testSecondCategoryIsNotUsedWhenTheFirstFillsAllThree(): void
    {
        $this->readBy($this->anna, Category::Novel, '2026-03-01');
        $this->readBy($this->anna, Category::Novel, '2026-03-02');
        $this->readBy($this->anna, Category::Crime, '2026-10-01');
        foreach ([1, 2, 3] as $i) {
            $this->candidate('Powieść '.$i, Category::Novel, [2]);
        }
        $this->candidate('Kryminał', Category::Crime, [5]);

        $result = $this->service->forUser($this->anna);

        self::assertSame(['Powieść 1', 'Powieść 2', 'Powieść 3'], $this->titles($result));
    }

    public function testSingleCategoryYieldsFewerThanThree(): void
    {
        $this->readBy($this->anna, Category::Novel, '2026-03-01');
        $this->candidate('Powieść 1', Category::Novel, [4]);
        $this->candidate('Powieść 2', Category::Novel, [3]);
        $this->candidate('Kryminał', Category::Crime, [5]);
        $this->candidate('Fantasy', Category::Fantasy, [5]);

        $result = $this->service->forUser($this->anna);

        self::assertSame(['Powieść 1', 'Powieść 2'], $this->titles($result));
    }

    public function testNoReadsInTheWindowGivesAnEmptyList(): void
    {
        $this->readBy($this->anna, Category::Novel, '2020-01-01');
        $this->candidate('Powieść', Category::Novel, [5]);

        self::assertSame([], $this->service->forUser($this->anna));
    }

    public function testUserWithoutAnyReadsGetsAnEmptyList(): void
    {
        $this->candidate('Powieść', Category::Novel, [5]);

        self::assertSame([], $this->service->forUser($this->anna));
    }

    public function testReadExactlyTwelveMonthsAgoCounts(): void
    {
        $this->readBy($this->anna, Category::Novel, '2025-11-12');
        $this->readBy($this->anna, Category::Crime, '2025-11-11');
        $this->readBy($this->anna, Category::Crime, '2025-11-10');
        $this->candidate('Powieść', Category::Novel, [3]);
        $this->candidate('Kryminał', Category::Crime, [5]);

        $result = $this->service->forUser($this->anna);

        self::assertSame(['Powieść'], $this->titles($result));
    }

    public function testReadTwelveMonthsAndADayAgoDoesNotCount(): void
    {
        $this->readBy($this->anna, Category::Novel, '2025-11-11');
        $this->candidate('Powieść', Category::Novel, [5]);

        self::assertSame([], $this->service->forUser($this->anna));
    }

    public function testWindowIsMeasuredInTheLibraryTimeZone(): void
    {
        Clock::set(new MockClock('2026-11-11 23:30:00 UTC', 'UTC'));
        $this->readBy($this->anna, Category::Novel, '2025-11-12');
        $this->readBy($this->anna, Category::Crime, '2025-11-11');
        $this->readBy($this->anna, Category::Crime, '2025-11-11');
        $this->candidate('Powieść', Category::Novel, [3]);
        $this->candidate('Kryminał', Category::Crime, [5]);

        $result = $this->service->forUser($this->anna);

        self::assertSame(['Powieść'], $this->titles($result));
    }

    public function testCategoryTieIsBrokenByTheMostRecentRead(): void
    {
        $this->readBy($this->anna, Category::Novel, '2026-05-01');
        $this->readBy($this->anna, Category::Fantasy, '2026-06-01');
        $this->candidate('Powieść', Category::Novel, [5]);
        $this->candidate('Fantasy', Category::Fantasy, [3]);

        $result = $this->service->forUser($this->anna);

        self::assertSame(['Fantasy', 'Powieść'], $this->titles($result));
    }

    public function testCategoryTieOnCountAndDateFollowsEnumDeclarationOrder(): void
    {
        $this->readBy($this->anna, Category::Fantasy, '2026-06-01');
        $this->readBy($this->anna, Category::Crime, '2026-06-01');
        $this->readBy($this->anna, Category::Novel, '2026-06-01');
        $this->candidate('Fantasy', Category::Fantasy, [5]);
        $this->candidate('Kryminał', Category::Crime, [5]);
        $this->candidate('Powieść', Category::Novel, [5]);

        $result = $this->service->forUser($this->anna);

        self::assertSame(['Powieść', 'Kryminał'], $this->titles($result));
    }

    public function testMoreReadsBeatAMoreRecentRead(): void
    {
        $this->readBy($this->anna, Category::Novel, '2026-01-01');
        $this->readBy($this->anna, Category::Novel, '2026-01-02');
        $this->readBy($this->anna, Category::Crime, '2026-10-30');
        $this->candidate('Powieść', Category::Novel, [3]);
        $this->candidate('Kryminał', Category::Crime, [5]);

        $result = $this->service->forUser($this->anna);

        self::assertSame(['Powieść', 'Kryminał'], $this->titles($result));
    }

    public function testEqualAveragesAreOrderedByRatingCountThenTitle(): void
    {
        $this->readBy($this->anna, Category::Novel, '2026-03-01');
        $this->candidate('B mało', Category::Novel, [4]);
        $this->candidate('A mało', Category::Novel, [4]);
        $this->candidate('Z dużo', Category::Novel, [4, 4]);

        $result = $this->service->forUser($this->anna);

        self::assertSame(['Z dużo', 'A mało', 'B mało'], $this->titles($result));
    }

    public function testReadAndUnratedBooksAreNeverReturned(): void
    {
        $this->readBy($this->anna, Category::Novel, '2026-03-01');
        $read = $this->candidate('Przeczytana', Category::Novel, [5]);
        $this->rate($read, $this->anna, 5, '2026-04-01');
        $this->candidate('Nieoceniona', Category::Novel, []);
        $this->candidate('Dobra', Category::Novel, [3]);

        $result = $this->service->forUser($this->anna);

        self::assertSame(['Dobra'], $this->titles($result));
    }

    public function testNeverMoreThanThreeResults(): void
    {
        $this->readBy($this->anna, Category::Novel, '2026-03-01');
        $this->readBy($this->anna, Category::Crime, '2026-03-02');
        foreach ([1, 2, 3, 4, 5] as $i) {
            $this->candidate('Powieść '.$i, Category::Novel, [$i]);
            $this->candidate('Kryminał '.$i, Category::Crime, [$i]);
        }

        $result = $this->service->forUser($this->anna);

        self::assertCount(3, $result);
        self::assertSame(['Kryminał 5', 'Kryminał 4', 'Kryminał 3'], $this->titles($result));
    }

    public function testNoThirdCategoryIsUsed(): void
    {
        $this->readBy($this->anna, Category::Novel, '2026-03-01');
        $this->readBy($this->anna, Category::Novel, '2026-03-02');
        $this->readBy($this->anna, Category::Crime, '2026-03-03');
        $this->readBy($this->anna, Category::Crime, '2026-03-04');
        $this->readBy($this->anna, Category::Fantasy, '2026-03-05');
        $this->candidate('Powieść', Category::Novel, [4]);
        $this->candidate('Kryminał', Category::Crime, [4]);
        $this->candidate('Fantasy', Category::Fantasy, [5]);

        $result = $this->service->forUser($this->anna);

        self::assertSame(['Kryminał', 'Powieść'], $this->titles($result));
    }

    /**
     * A book the user read in the given category on the given day.
     */
    private function readBy(User $user, Category $category, string $day): void
    {
        $book = $this->book('Przeczytana '.++$this->isbnSeq, $category);
        $this->rate($book, $user, 4, $day);
    }

    /**
     * An unread book rated once by each of the given ratings (one rater per rating).
     *
     * @param list<int> $ratings
     */
    private function candidate(string $title, Category $category, array $ratings): Book
    {
        $book = $this->book($title, $category);
        foreach ($ratings as $i => $rating) {
            $this->rate($book, $this->raters[$i], $rating, '2026-09-15');
        }

        return $book;
    }

    private function book(string $title, Category $category): Book
    {
        $book = (new Book())
            ->setTitle($title)
            ->setIsbn(sprintf('97883%08d', ++$this->isbnSeq))
            ->setPublicationYear(2000)
            ->setCategory($category)
            ->setAddedBy($this->raters[0]);
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        return $book;
    }

    private function rate(Book $book, User $user, int $rating, string $day): void
    {
        $this->entityManager->persist(
            (new ReadBook())
                ->setUser($user)
                ->setBook($book)
                ->setRating($rating)
                ->setReadOn(new \DateTimeImmutable($day)),
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
}
