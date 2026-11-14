<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Dto\BookSearch;
use App\Dto\RecommendationView;
use App\Entity\Author;
use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\Tag;
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

    public function testSearchWithoutFiltersReturnsEveryBookNewestFirst(): void
    {
        $this->book('Pierwsza', Category::Novel);
        $this->book('Druga', Category::Crime);

        $result = $this->repository->search(new BookSearch());

        self::assertSame(['Druga', 'Pierwsza'], $this->searchTitles($result));
        self::assertCount(2, $result);
    }

    public function testTitleFilterIsContainsAndIgnoresCase(): void
    {
        $this->book('Solaris', Category::Novel);
        $this->book('Dziady', Category::Poetry);

        self::assertSame(['Solaris'], $this->searchTitles($this->repository->search(new BookSearch(title: 'SOL'))));
        self::assertSame(['Solaris'], $this->searchTitles($this->repository->search(new BookSearch(title: 'ola'))));
        self::assertSame([], $this->searchTitles($this->repository->search(new BookSearch(title: 'zzz'))));
    }

    public function testTitleFilterIsAccentSensitiveBecauseOfThePinnedCollation(): void
    {
        $this->book('José w podróży', Category::Novel);

        self::assertSame([], $this->searchTitles($this->repository->search(new BookSearch(title: 'jose'))));
        self::assertSame(['José w podróży'], $this->searchTitles($this->repository->search(new BookSearch(title: 'JOSÉ'))));
        self::assertSame(['José w podróży'], $this->searchTitles($this->repository->search(new BookSearch(title: 'jos'))));
    }

    public function testAuthorFilterMatchesFirstNameLastNameAndFullName(): void
    {
        $this->book('Solaris', Category::Novel, authors: [$this->author('Stanisław', 'Lem')]);
        $this->book('Dziady', Category::Poetry, authors: [$this->author('Adam', 'Mickiewicz')]);

        foreach (['stan', 'LEM', 'Stanisław Lem', 'ław Le'] as $text) {
            self::assertSame(['Solaris'], $this->searchTitles($this->repository->search(new BookSearch(author: $text))), $text);
        }
        self::assertSame([], $this->searchTitles($this->repository->search(new BookSearch(author: 'Lem Stanisław'))));
    }

    public function testAuthorFilterIsAccentSensitive(): void
    {
        $this->book('Ensaio', Category::Novel, authors: [$this->author('José', 'Saramago')]);

        self::assertSame([], $this->searchTitles($this->repository->search(new BookSearch(author: 'jose'))));
        self::assertSame(['Ensaio'], $this->searchTitles($this->repository->search(new BookSearch(author: 'josé'))));
    }

    public function testPercentAndUnderscoreAndEscapeCharacterMatchLiterally(): void
    {
        $this->book('100% pewne', Category::Novel);
        $this->book('Sto procent', Category::Novel);
        $this->book('a_b', Category::Novel);
        $this->book('axb', Category::Novel);
        $this->book('Hej! Hej', Category::Novel);

        self::assertSame(['100% pewne'], $this->searchTitles($this->repository->search(new BookSearch(title: '%'))));
        self::assertSame(['a_b'], $this->searchTitles($this->repository->search(new BookSearch(title: 'a_b'))));
        self::assertSame(['Hej! Hej'], $this->searchTitles($this->repository->search(new BookSearch(title: '!'))));
    }

    public function testCategoryFilterAloneAndCombinedWithText(): void
    {
        $this->book('Solaris', Category::Novel);
        $this->book('Dziady', Category::Poetry);
        $this->book('Pan Tadeusz', Category::Poetry);

        self::assertSame(['Pan Tadeusz', 'Dziady'], $this->searchTitles($this->repository->search(new BookSearch(category: Category::Poetry))));
        self::assertSame(['Pan Tadeusz'], $this->searchTitles($this->repository->search(new BookSearch(title: 'tad', category: Category::Poetry))));
        self::assertSame([], $this->searchTitles($this->repository->search(new BookSearch(title: 'sol', category: Category::Poetry))));
    }

    public function testMatchingBookStillListsAllItsAuthors(): void
    {
        $this->book('Piknik', Category::Novel, authors: [$this->author('Arkadij', 'Strugacki'), $this->author('Boris', 'Strugacki')]);

        $books = iterator_to_array($this->repository->search(new BookSearch(author: 'arkadij')), false);

        self::assertCount(1, $books);
        self::assertCount(2, $books[0]->getAuthors());
    }

    public function testResultsArePagedByTwenty(): void
    {
        for ($i = 1; $i <= 21; ++$i) {
            $this->book(sprintf('Książka %02d', $i), Category::Novel);
        }

        $first = $this->repository->search(new BookSearch(page: 1));
        $second = $this->repository->search(new BookSearch(page: 2));
        $beyond = $this->repository->search(new BookSearch(page: 3));

        self::assertCount(21, $first);
        self::assertCount(20, iterator_to_array($first, false));
        self::assertSame(['Książka 01'], $this->searchTitles($second));
        self::assertSame([], $this->searchTitles($beyond));
    }

    public function testPagingCountsBooksNotBookAuthorRows(): void
    {
        for ($i = 1; $i <= 21; ++$i) {
            $this->book(sprintf('Wielu autorów %02d', $i), Category::Novel, authors: [$this->author('Jan'.$i, 'Nowak'), $this->author('Ewa'.$i, 'Nowak')]);
        }

        $first = $this->repository->search(new BookSearch());

        self::assertCount(21, $first);
        self::assertCount(20, iterator_to_array($first, false));
        self::assertCount(1, iterator_to_array($this->repository->search(new BookSearch(page: 2)), false));
    }

    public function testFindByTagReturnsOnlyBooksWithThatTag(): void
    {
        $klasyka = $this->tag('Klasyka');
        $other = $this->tag('Inny');
        $this->book('Solaris', Category::Novel, tags: [$klasyka]);
        $this->book('Dziady', Category::Poetry, tags: [$other]);
        $this->book('Bez tagu', Category::Novel);

        $result = $this->repository->findByTag($klasyka, 1);

        self::assertSame(['Solaris'], $this->searchTitles($result));
        self::assertCount(1, $result);
    }

    public function testFindByTagListsABookWithTwoTagsOnceWithAllItsAuthors(): void
    {
        $klasyka = $this->tag('Klasyka');
        $this->book('Piknik', Category::Novel, authors: [$this->author('Arkadij', 'Strugacki'), $this->author('Boris', 'Strugacki')], tags: [$klasyka, $this->tag('Sci-fi')]);
        $this->entityManager->clear();

        $books = iterator_to_array($this->repository->findByTag($klasyka, 1), false);

        self::assertCount(1, $books);
        self::assertCount(2, $books[0]->getAuthors());
    }

    public function testFindByTagIsPagedByTwentyNewestFirst(): void
    {
        $tag = $this->tag('Klasyka');
        for ($i = 1; $i <= 21; ++$i) {
            $this->book(sprintf('Książka %02d', $i), Category::Novel, tags: [$tag]);
        }

        $first = $this->repository->findByTag($tag, 1);

        self::assertCount(21, $first);
        self::assertCount(20, iterator_to_array($first, false));
        self::assertSame('Książka 21', $this->searchTitles($first)[0]);
        self::assertSame(['Książka 01'], $this->searchTitles($this->repository->findByTag($tag, 2)));
        self::assertSame([], $this->searchTitles($this->repository->findByTag($tag, 3)));
    }

    public function testFindByTagLoadsAuthorsEagerlySoQueryCountDoesNotGrowWithBookCount(): void
    {
        $tag = $this->tag('Klasyka');
        for ($i = 0; $i < 5; ++$i) {
            $this->book('Książka '.$i, Category::Novel, authors: [$this->author('Jan'.$i, 'Nowak'), $this->author('Ewa'.$i, 'Nowak')], tags: [$tag]);
        }
        $this->entityManager->clear();

        $books = iterator_to_array($this->repository->findByTag($tag, 1), false);

        // An initialised collection means no lazy load per book, hence no N+1 whatever the number of books.
        self::assertCount(5, $books);
        foreach ($books as $book) {
            self::assertTrue($book->getAuthors()->isInitialized());
            self::assertCount(2, $book->getAuthors());
        }
    }

    /**
     * @param list<Author> $authors
     * @param list<Tag>    $tags
     */
    private function book(string $title, Category $category, ?User $addedBy = null, array $authors = [], array $tags = []): Book
    {
        $book = (new Book())
            ->setTitle($title)
            ->setIsbn(sprintf('97883%08d', ++$this->isbnSeq))
            ->setPublicationYear(2000)
            ->setCategory($category)
            ->setAddedBy($addedBy ?? $this->ola);
        foreach ($authors as $author) {
            $book->addAuthor($author);
        }
        foreach ($tags as $tag) {
            $book->addTag($tag);
        }
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        return $book;
    }

    private function tag(string $name): Tag
    {
        $tag = (new Tag())->setName($name)->setSlug(strtolower($name));
        $this->entityManager->persist($tag);

        return $tag;
    }

    private function author(string $firstName, string $lastName): Author
    {
        $author = (new Author())->setFirstName($firstName)->setLastName($lastName);
        $this->entityManager->persist($author);

        return $author;
    }

    /**
     * @param \Traversable<int, Book> $books
     *
     * @return list<string>
     */
    private function searchTitles(\Traversable $books): array
    {
        return array_values(array_map(static fn (Book $b): string => $b->getTitle(), iterator_to_array($books, false)));
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
