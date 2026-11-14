<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Author;
use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\Category;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BookListControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private int $isbnCounter = 0;
    private ?Author $author = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = static::getContainer()->get(UserManager::class)->create('anna@example.com', 'secret-pass');
        $this->client->loginUser($this->user);
    }

    public function testAnonymousCanOpenListAndDetailWithCatalogueDataOnly(): void
    {
        $other = static::getContainer()->get(UserManager::class)->create('ola@example.com', 'secret-pass');
        $book = $this->createBook('Solaris', $this->user);
        $this->createReadBook($other, $book, 5);
        $this->client->restart();

        $this->client->request('GET', '/books');
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('table', 'Solaris');
        self::assertSelectorTextContains('table', 'Stanisław Lem');
        self::assertSelectorTextNotContains('table', 'Przeczytana');
        self::assertSelectorTextNotContains('table', 'Twój status');
        self::assertStringNotContainsString('5/5', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/books/'.$book->getId());
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('h1', 'Solaris');
        self::assertSelectorNotExists('form[name="mark_read"]');
        self::assertSelectorNotExists('#comments');
        self::assertStringNotContainsString('5/5', $this->client->getResponse()->getContent());
    }

    public function testAddBookButtonIsVisibleToAnonymousVisitorsAndLoginAlwaysLandsOnTheBookList(): void
    {
        // Login throttling state is shared through the cache between tests; start from a clean slate.
        static::getContainer()->get('cache.rate_limiter')->clear();
        $this->client->restart();

        $this->client->request('GET', '/books');
        self::assertSelectorExists('main a.btn[href="/books/new"]');
        self::assertSelectorExists('nav a[href="/books/new"]');
        self::assertSelectorExists('nav a[href="/login"]');
        self::assertSelectorNotExists('nav a[href*="/logout"]');

        $this->client->request('GET', '/books/new');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        $form = $this->client->getCrawler()->selectButton('Zaloguj')->form([
            '_username' => 'anna@example.com',
            '_password' => 'secret-pass',
        ]);
        $this->client->submit($form);
        // The saved /books/new target is ignored on purpose: login always lands on the main page.
        self::assertResponseRedirects('/books');
    }

    public function testLoggedInUserSeesTheAddBookButtonToo(): void
    {
        $this->client->request('GET', '/books');

        self::assertSelectorExists('main a.btn[href="/books/new"]');
        self::assertSelectorExists('nav a[href="/books/new"]');
    }

    public function testListShowsEveryBookIncludingOnesAddedByOthers(): void
    {
        $other = static::getContainer()->get(UserManager::class)->create('ola@example.com', 'secret-pass');
        $this->createBook('Solaris', $this->user);
        $this->createBook('Dziady', $other, Category::Poetry);

        $this->client->request('GET', '/books');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('table', 'Solaris');
        self::assertSelectorTextContains('table', 'Dziady');
        self::assertSelectorTextContains('table', 'Stanisław Lem');
        self::assertSelectorTextContains('table', 'Poezja');
        self::assertSelectorTextContains('nav', 'Książki');
    }

    public function testEmptyListShowsMessageWithLinkToNewBook(): void
    {
        $this->client->request('GET', '/books');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorExists('a[href="/books/new"]');
        self::assertSelectorNotExists('table');
    }

    public function testListMarksOnlyTheCurrentUsersReadBooksAndNeverShowsOthersRatings(): void
    {
        $other = static::getContainer()->get(UserManager::class)->create('ola@example.com', 'secret-pass');
        $mine = $this->createBook('Moja przeczytana', $this->user);
        $theirs = $this->createBook('Przeczytana przez Olę', $other);
        $this->createReadBook($this->user, $mine, 4);
        $this->createReadBook($other, $theirs, 5);

        $crawler = $this->client->request('GET', '/books');

        $mineRow = $crawler->filter('tr:contains("Moja przeczytana")')->text();
        $theirsRow = $crawler->filter('tr:contains("Przeczytana przez Olę")')->text();
        self::assertStringContainsString('Przeczytana', $mineRow);
        self::assertStringContainsString('4/5', $mineRow);
        self::assertStringNotContainsString('5/5', $theirsRow);
        self::assertStringNotContainsString('Przeczytana (', $theirsRow);
        self::assertStringNotContainsString('5/5', $this->client->getResponse()->getContent());
    }

    public function testListQueryCountDoesNotGrowWithBookCount(): void
    {
        $this->createBook('Pierwsza', $this->user);
        $this->createReadBook($this->user, $this->createBook('Druga', $this->user), 3);
        $this->client->enableProfiler();
        $this->client->request('GET', '/books');
        $few = $this->queryCount();

        for ($i = 0; $i < 8; ++$i) {
            $book = $this->createBook('Kolejna '.$i, $this->user);
            $this->createReadBook($this->user, $book, 2);
        }
        $this->client->enableProfiler();
        $this->client->request('GET', '/books');
        $many = $this->queryCount();

        self::assertGreaterThan(0, $few);
        self::assertSame($few, $many);
    }

    public function testDetailShowsAllBookFields(): void
    {
        $book = $this->createBook('Solaris', $this->user, Category::Novel);
        $book->setDescription('Planeta-ocean.')->addTag((new Tag())->setName('sci-fi')->setSlug('sci-fi'));
        $this->entityManager()->persist($book->getTags()->first());
        $this->entityManager()->flush();

        $this->client->request('GET', '/books/'.$book->getId());

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('h1', 'Solaris');
        $text = $this->client->getCrawler()->filter('main')->text();
        foreach (['Stanisław Lem', $book->getIsbn(), '1961', 'Powieść', 'sci-fi', 'Planeta-ocean.'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
    }

    public function testDetailLinksEachTagToItsTagPage(): void
    {
        $book = $this->createBook('Solaris', $this->user);
        $this->addTags($book, ['science fiction' => 'science-fiction', 'klasyka' => 'klasyka']);

        $crawler = $this->client->request('GET', '/books/'.$book->getId());

        self::assertResponseStatusCodeSame(200);
        self::assertCount(2, $crawler->filter('dd a.badge'));
        self::assertSame('science fiction', trim($crawler->filter('a.badge[href="/tags/science-fiction"]')->text()));
        self::assertSame('klasyka', trim($crawler->filter('a.badge[href="/tags/klasyka"]')->text()));
    }

    public function testDetailEscapesTagNames(): void
    {
        $book = $this->createBook('Solaris', $this->user);
        $this->addTags($book, ['<b>x</b>' => 'b-x-b']);

        $crawler = $this->client->request('GET', '/books/'.$book->getId());

        self::assertStringNotContainsString('<b>x</b>', $this->client->getResponse()->getContent());
        self::assertSame('<b>x</b>', trim($crawler->filter('a.badge[href="/tags/b-x-b"]')->text()));
    }

    public function testDetailOfABookWithoutTagsShowsNoTagsRow(): void
    {
        $book = $this->createBook('Solaris', $this->user);

        $crawler = $this->client->request('GET', '/books/'.$book->getId());

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $crawler->filter('a.badge'));
        self::assertSelectorTextNotContains('main dl', 'Tagi');
    }

    public function testMissingBookReturns404(): void
    {
        $this->client->request('GET', '/books/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNewRouteIsNotCapturedByTheDetailRoute(): void
    {
        $this->client->request('GET', '/books/new');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorExists('form[name="book"]');
    }

    public function testTitleAuthorAndCategoryFiltersNarrowTheList(): void
    {
        $this->createBook('Solaris', $this->user);
        $this->createBook('Dziady', $this->user, Category::Poetry, (new Author())->setFirstName('Adam')->setLastName('Mickiewicz'));

        $this->client->request('GET', '/books?title=sol');
        self::assertSelectorTextContains('table', 'Solaris');
        self::assertSelectorTextNotContains('table', 'Dziady');

        $this->client->request('GET', '/books?author=mickie');
        self::assertSelectorTextContains('table', 'Dziady');
        self::assertSelectorTextNotContains('table', 'Solaris');

        $this->client->request('GET', '/books?category=poetry');
        self::assertSelectorTextContains('table', 'Dziady');
        self::assertSelectorTextNotContains('table', 'Solaris');

        $this->client->request('GET', '/books?title=sol&category=poetry');
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorNotExists('table');
    }

    public function testSearchWithNoMatchReturns200(): void
    {
        $this->createBook('Solaris', $this->user);

        $this->client->request('GET', '/books?title=zzz');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorNotExists('table');
    }

    public function testFiltersWorkForAnonymousVisitors(): void
    {
        $this->createBook('Solaris', $this->user);
        $this->createBook('Dziady', $this->user, Category::Poetry);
        $this->client->restart();

        $this->client->request('GET', '/books?category=poetry');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('table', 'Dziady');
        self::assertSelectorTextNotContains('table', 'Solaris');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badQueries(): iterable
    {
        yield 'negative page' => ['page=-3'];
        yield 'zero page' => ['page=0'];
        yield 'text page' => ['page=abc'];
        yield 'unknown category' => ['category=bogus'];
        yield 'array title' => ['title[]=x'];
        yield 'array page' => ['page[]=2'];
        yield 'percent' => ['title=%25&author=_'];
    }

    #[DataProvider('badQueries')]
    public function testHandEditedQueriesNeverBreakThePage(string $query): void
    {
        $this->createBook('Solaris', $this->user);

        $this->client->request('GET', '/books?'.$query);

        self::assertResponseStatusCodeSame(200);
    }

    public function testListIsPagedByTwentyAndPageTwoShowsTheRest(): void
    {
        for ($i = 1; $i <= 21; ++$i) {
            $this->createBook(sprintf('Książka %02d', $i), $this->user);
        }

        $crawler = $this->client->request('GET', '/books');
        self::assertCount(20, $crawler->filter('tbody tr'));
        self::assertStringNotContainsString('Książka 01', $crawler->filter('tbody')->text());

        $crawler = $this->client->request('GET', '/books?page=2');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'Książka 01');

        $this->client->request('GET', '/books?page=3');
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorNotExists('tbody tr');
    }

    public function testSearchFormExistsForAnonymousAndLoggedInUsers(): void
    {
        $this->createBook('Solaris', $this->user);

        foreach ([true, false] as $loggedIn) {
            if (!$loggedIn) {
                $this->client->restart();
            }
            $this->client->request('GET', '/books');

            self::assertSelectorExists('form[method="get"][action="/books"] input[name="title"]');
            self::assertSelectorExists('form[method="get"] input[name="author"]');
            self::assertSelectorExists('form[method="get"] select[name="category"]');
            self::assertSelectorExists('label[for="search-title"]');
            self::assertSelectorExists('label[for="search-author"]');
            self::assertSelectorExists('label[for="search-category"]');
            self::assertCount(12, $this->client->getCrawler()->filter('select[name="category"] option'));
            self::assertSelectorExists('form a[href="/books"]');
        }
    }

    public function testSearchFormIsHiddenWhenTheLibraryIsEmptyAndUnfiltered(): void
    {
        $this->client->request('GET', '/books');

        self::assertSelectorNotExists('form[method="get"]');
        self::assertSelectorExists('main a[href="/books/new"]');
    }

    public function testSubmittedValuesAreRefilledAndEscaped(): void
    {
        $this->createBook('Solaris', $this->user);

        $crawler = $this->client->request('GET', '/books?title='.rawurlencode('"><script>x</script>').'&author=Lem&category=novel');

        self::assertStringNotContainsString('<script>x</script>', $this->client->getResponse()->getContent());
        self::assertSame('"><script>x</script>', $crawler->filter('input[name="title"]')->attr('value'));
        self::assertSame('Lem', $crawler->filter('input[name="author"]')->attr('value'));
        self::assertSame('novel', $crawler->filter('select[name="category"] option[selected]')->attr('value'));
    }

    public function testPagerKeepsFiltersAndMarksCurrentPage(): void
    {
        for ($i = 1; $i <= 21; ++$i) {
            $this->createBook(sprintf('Książka %02d', $i), $this->user);
        }

        $crawler = $this->client->request('GET', '/books?title=Ksi&author=Lem&category=novel&page=2');

        // Previous, page 1 and the current page 2 are links; "next" is disabled on the last page.
        $links = $crawler->filter('ul.pagination a.page-link');
        self::assertCount(3, $links);
        foreach ($links as $link) {
            $query = [];
            parse_str((string) parse_url($link->getAttribute('href'), \PHP_URL_QUERY), $query);
            self::assertSame('Ksi', $query['title']);
            self::assertSame('Lem', $query['author']);
            self::assertSame('novel', $query['category']);
        }
        self::assertCount(1, $crawler->filter('ul.pagination a[aria-current="page"]'));
        self::assertSame('2', trim($crawler->filter('ul.pagination a[aria-current="page"]')->text()));
    }

    public function testPagerShowsAWindowAroundTheCurrentPageNotEveryPage(): void
    {
        $em = $this->entityManager();
        $author = $this->author ??= (new Author())->setFirstName('Stanisław')->setLastName('Lem');
        $em->persist($author);
        for ($i = 1; $i <= 141; ++$i) { // 8 pages of 20
            $em->persist((new Book())->setTitle('Tom '.$i)->setIsbn(sprintf('97883090%05d', $i))->setPublicationYear(2000)->setCategory(Category::Novel)->setAddedBy($this->user)->addAuthor($author));
        }
        $em->flush();

        $labels = fn (string $query): array => array_map(
            static fn (string $text): string => trim($text),
            $this->client->request('GET', '/books?'.$query)->filter('ul.pagination .page-item')->each(static fn ($item): string => $item->text()),
        );

        self::assertSame(['Poprzednia', '1', '2', '3', '4', '5', '6', '…', '8', 'Następna'], $labels('page=4'));
        self::assertSame(['Poprzednia', '1', '2', '3', '…', '8', 'Następna'], $labels('page=1'));
        self::assertSame(['Poprzednia', '1', '…', '6', '7', '8', 'Następna'], $labels('page=8'));

        $this->client->request('GET', '/books?page=99');
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorExists('ul.pagination');
    }

    public function testPagerIsAbsentOnASinglePage(): void
    {
        $this->createBook('Solaris', $this->user);

        $this->client->request('GET', '/books');

        self::assertSelectorNotExists('ul.pagination');
    }

    public function testNoMatchShowsNoResultsMessageNotTheAddFirstBookMessage(): void
    {
        $this->createBook('Solaris', $this->user);

        $this->client->request('GET', '/books?title=zzz');

        self::assertSelectorTextContains('main', 'Żadna książka nie pasuje do wyszukiwania.');
        self::assertSelectorTextNotContains('main', 'Dodaj pierwszą książkę');
        self::assertSelectorNotExists('table');
        self::assertSelectorExists('form[method="get"]');

        $this->client->request('GET', '/books?page=9');
        self::assertSelectorTextContains('main', 'Żadna książka nie pasuje do wyszukiwania.');
        self::assertSelectorTextNotContains('main', 'Dodaj pierwszą książkę');
    }

    public function testEmptyLibraryShowsAddFirstBookMessage(): void
    {
        $this->client->request('GET', '/books');

        self::assertSelectorTextContains('main', 'Dodaj pierwszą książkę');
        self::assertSelectorTextNotContains('main', 'Żadna książka nie pasuje');
    }

    public function testFilteredListQueryCountDoesNotGrowWithBookCount(): void
    {
        $this->createBook('Pierwsza', $this->user);
        $this->client->enableProfiler();
        $this->client->request('GET', '/books?title=a&author=lem&category=novel');
        $few = $this->queryCount();

        for ($i = 0; $i < 10; ++$i) {
            $this->createBook('Kolejna '.$i, $this->user);
        }
        $this->client->enableProfiler();
        $this->client->request('GET', '/books?title=a&author=lem&category=novel');
        $many = $this->queryCount();

        self::assertGreaterThan(0, $few);
        self::assertSame($few, $many);
    }

    public function testSearchFormAndTableAreLandmarks(): void
    {
        $this->createBook('Solaris', $this->user);

        $this->client->request('GET', '/books');

        self::assertSelectorExists('form[method="get"][role="search"][aria-label="Wyszukiwanie książek"]');
        self::assertSelectorExists('table caption');
    }

    public function testEmptyLibraryAndNoResultsStatesDifferInHeadingAndAction(): void
    {
        $this->client->request('GET', '/books');
        self::assertSelectorTextContains('main .text-center h2', 'W kolekcji nie ma jeszcze żadnych książek.');
        self::assertSelectorExists('main .text-center a.btn-primary[href="/books/new"]');

        $this->createBook('Solaris', $this->user);
        $this->client->request('GET', '/books?title=zzz');
        self::assertSelectorTextContains('main .text-center h2', 'Żadna książka nie pasuje do wyszukiwania.');
        self::assertSelectorExists('main .text-center a.btn-outline-secondary[href="/books"]');
        self::assertSelectorNotExists('main .text-center a[href="/books/new"]');
    }

    public function testLayoutIconsAreDecorative(): void
    {
        $this->client->request('GET', '/books');

        self::assertSelectorExists('nav svg[aria-hidden="true"]');
        self::assertSelectorNotExists('nav svg:not([aria-hidden="true"])');
    }

    private function createBook(string $title, User $addedBy, Category $category = Category::Novel, ?Author $author = null): Book
    {
        // One shared author by default, so this helper issues no SELECT that would skew the query count.
        $author ??= $this->author ??= (new Author())->setFirstName('Stanisław')->setLastName('Lem');
        $book = (new Book())
            ->setTitle($title)
            ->setIsbn(sprintf('97883080%05d', ++$this->isbnCounter))
            ->setPublicationYear(1961)
            ->setCategory($category)
            ->setAddedBy($addedBy)
            ->addAuthor($author);
        $this->entityManager()->persist($author);
        $this->entityManager()->persist($book);
        $this->entityManager()->flush();

        return $book;
    }

    /**
     * @param array<string, string> $tags name => slug
     */
    private function addTags(Book $book, array $tags): void
    {
        foreach ($tags as $name => $slug) {
            $tag = (new Tag())->setName((string) $name)->setSlug($slug);
            $this->entityManager()->persist($tag);
            $book->addTag($tag);
        }
        $this->entityManager()->flush();
    }

    private function createReadBook(User $user, Book $book, int $rating): void
    {
        $this->entityManager()->persist(
            (new ReadBook())->setUser($user)->setBook($book)->setRating($rating)->setReadOn(new \DateTimeImmutable('2026-10-01')),
        );
        $this->entityManager()->flush();
    }

    private function queryCount(): int
    {
        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        // Only SELECTs: the setup inserts made through the same connection are logged too.
        $selects = 0;
        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $query) {
                if (str_starts_with(ltrim($query['sql']), 'SELECT')) {
                    ++$selects;
                }
            }
        }

        return $selects;
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
