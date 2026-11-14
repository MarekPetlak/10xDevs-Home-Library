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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TagControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private Tag $tag;
    private int $isbnCounter = 0;
    private ?Author $author = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = static::getContainer()->get(UserManager::class)->create('anna@example.com', 'secret-pass');
        $this->client->loginUser($this->user);
        $this->tag = (new Tag())->setName('Klasyka')->setSlug('klasyka');
        $this->entityManager()->persist($this->tag);
        $this->entityManager()->flush();
    }

    public function testAnonymousSeesTheTagsBooksWithCatalogueDataOnly(): void
    {
        $other = static::getContainer()->get(UserManager::class)->create('ola@example.com', 'secret-pass');
        $book = $this->createBook('Solaris', $this->tag);
        $this->createBook('Dziady', null, Category::Poetry);
        $this->createReadBook($other, $book, 5, 'Tajny komentarz');
        $this->client->restart();

        $this->client->request('GET', '/tags/klasyka');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('title', 'Klasyka');
        self::assertSelectorTextContains('h1', 'Książki z tagiem: Klasyka');
        self::assertSelectorTextContains('table', 'Solaris');
        self::assertSelectorTextContains('table', 'Stanisław Lem');
        self::assertSelectorTextContains('table', 'Powieść');
        self::assertSelectorTextNotContains('table', 'Dziady');
        self::assertSelectorTextNotContains('table', 'Przeczytana');
        self::assertSelectorTextNotContains('table', 'Twój status');
        self::assertSelectorExists('main a[href="/books"]');
        $content = $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('5/5', $content);
        self::assertStringNotContainsString('Tajny komentarz', $content);
    }

    public function testSignedInUserSeesTheReadBadgeOnlyForOwnReadings(): void
    {
        $other = static::getContainer()->get(UserManager::class)->create('ola@example.com', 'secret-pass');
        $mine = $this->createBook('Moja przeczytana', $this->tag);
        $theirs = $this->createBook('Cudza przeczytana', $this->tag);
        $this->createReadBook($this->user, $mine, 4);
        $this->createReadBook($other, $theirs, 5);

        $crawler = $this->client->request('GET', '/tags/klasyka');

        self::assertSelectorTextContains('th:last-child', 'Twój status');
        self::assertStringContainsString('Przeczytana', $crawler->filter('tr:contains("Moja przeczytana")')->text());
        self::assertStringContainsString('4/5', $crawler->filter('tr:contains("Moja przeczytana")')->text());
        self::assertStringNotContainsString('Przeczytana', $crawler->filter('tr:contains("Cudza przeczytana")')->text());
        self::assertStringNotContainsString('5/5', $this->client->getResponse()->getContent());
    }

    public function testTagNameIsEscaped(): void
    {
        $tag = (new Tag())->setName('<b>x</b>')->setSlug('b-x-b');
        $this->entityManager()->persist($tag);
        $this->createBook('Solaris', $tag);

        $this->client->request('GET', '/tags/b-x-b');

        self::assertResponseStatusCodeSame(200);
        self::assertStringNotContainsString('<b>x</b>', $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('h1', '<b>x</b>');
    }

    public function testUnknownSlugReturns404(): void
    {
        $this->client->request('GET', '/tags/nie-ma-takiego');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badSlugs(): iterable
    {
        yield 'uppercase' => ['Klasyka'];
        yield 'all uppercase' => ['KLASYKA'];
        yield 'accent' => ['klasyk%C4%85'];
        yield 'underscore' => ['kla_syka'];
        yield 'space' => ['kla%20syka'];
    }

    #[DataProvider('badSlugs')]
    public function testSlugWithUppercaseOrInvalidCharactersReturns404(string $slug): void
    {
        $this->createBook('Solaris', $this->tag);

        $this->client->request('GET', '/tags/'.$slug);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badPages(): iterable
    {
        yield 'text' => ['page=abc'];
        yield 'zero' => ['page=0'];
        yield 'negative' => ['page=-3'];
        yield 'array' => ['page[]=2'];
    }

    #[DataProvider('badPages')]
    public function testInvalidPageFallsBackToPageOne(string $query): void
    {
        $this->createBook('Solaris', $this->tag);

        $this->client->request('GET', '/tags/klasyka?'.$query);

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('tbody', 'Solaris');
    }

    public function testListIsPagedByTwentyAndPagerLinksStayOnTheTagRoute(): void
    {
        for ($i = 1; $i <= 21; ++$i) {
            $this->createBook(sprintf('Książka %02d', $i), $this->tag);
        }

        $crawler = $this->client->request('GET', '/tags/klasyka');
        self::assertCount(20, $crawler->filter('tbody tr'));
        self::assertStringNotContainsString('Książka 01', $crawler->filter('tbody')->text());
        $hrefs = $crawler->filter('ul.pagination a.page-link')->each(static fn ($link): string => $link->attr('href'));
        self::assertNotEmpty($hrefs);
        foreach ($hrefs as $href) {
            self::assertMatchesRegularExpression('#^/tags/klasyka\?page=\d+$#', $href);
        }

        $crawler = $this->client->request('GET', '/tags/klasyka?page=2');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'Książka 01');
        self::assertSame('2', trim($crawler->filter('ul.pagination a[aria-current="page"]')->text()));
    }

    public function testPageBeyondTheLastBehavesLikeTheBookList(): void
    {
        for ($i = 1; $i <= 21; ++$i) {
            $this->createBook(sprintf('Książka %02d', $i), $this->tag);
        }

        $this->client->request('GET', '/tags/klasyka?page=9');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorNotExists('tbody tr');
        self::assertSelectorTextContains('main', 'Żadna książka nie pasuje do wyszukiwania.');
        self::assertSelectorExists('ul.pagination');
    }

    public function testBookListPagerLinksAreUnchanged(): void
    {
        for ($i = 1; $i <= 21; ++$i) {
            $this->createBook(sprintf('Książka %02d', $i), null);
        }

        $crawler = $this->client->request('GET', '/books?title=Ksi&page=2');

        $hrefs = $crawler->filter('ul.pagination a.page-link')->each(static fn ($link): string => $link->attr('href'));
        self::assertSame(['/books?title=Ksi&page=1', '/books?title=Ksi&page=1', '/books?title=Ksi&page=2'], $hrefs);
    }

    public function testQueryCountDoesNotGrowWithBookCount(): void
    {
        $this->createReadBook($this->user, $this->createBook('Pierwsza', $this->tag), 3);
        $this->client->enableProfiler();
        $this->client->request('GET', '/tags/klasyka');
        $few = $this->queryCount();

        for ($i = 0; $i < 8; ++$i) {
            $this->createReadBook($this->user, $this->createBook('Kolejna '.$i, $this->tag), 2);
        }
        $this->client->enableProfiler();
        $this->client->request('GET', '/tags/klasyka');
        $many = $this->queryCount();

        self::assertGreaterThan(0, $few);
        self::assertSame($few, $many);
    }

    private function createBook(string $title, ?Tag $tag, Category $category = Category::Novel): Book
    {
        // One shared author, so this helper issues no SELECT that would skew the query count.
        $author = $this->author ??= (new Author())->setFirstName('Stanisław')->setLastName('Lem');
        $book = (new Book())
            ->setTitle($title)
            ->setIsbn(sprintf('97883080%05d', ++$this->isbnCounter))
            ->setPublicationYear(1961)
            ->setCategory($category)
            ->setAddedBy($this->user)
            ->addAuthor($author);
        if (null !== $tag) {
            $book->addTag($tag);
        }
        $this->entityManager()->persist($author);
        $this->entityManager()->persist($book);
        $this->entityManager()->flush();

        return $book;
    }

    private function createReadBook(User $user, Book $book, int $rating, ?string $comment = null): void
    {
        $this->entityManager()->persist(
            (new ReadBook())->setUser($user)->setBook($book)->setRating($rating)->setComment($comment)->setReadOn(new \DateTimeImmutable('2026-10-01')),
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
