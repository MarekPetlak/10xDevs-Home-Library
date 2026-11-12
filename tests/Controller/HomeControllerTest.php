<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\User;
use App\Enum\Category;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class HomeControllerTest extends WebTestCase
{
    /** @var array<string, int> */
    private array $bookIds = [];

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testHomeRedirectsToTheBookListForVisitorsAndUsers(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseRedirects('/books', 302);

        $user = static::getContainer()->get(UserManager::class)->create('anna@example.com', 'secret-pass');
        $client->loginUser($user);
        $client->request('GET', '/');
        self::assertResponseRedirects('/books', 302);
    }

    public function testMainPageIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/books');

        self::assertResponseStatusCodeSame(200);
    }

    public function testAnonymousHomeShowsLoginLinkAndNoPersonalData(): void
    {
        $client = static::createClient();
        $client->request('GET', '/books');

        self::assertSelectorExists('a[href="/login"]');
        self::assertSelectorNotExists('a[href*="/logout"]');
        self::assertSelectorTextNotContains('body', '@');
    }

    public function testLayoutIsPolishAndLoadsBootstrap(): void
    {
        $client = static::createClient();
        $client->request('GET', '/books');

        self::assertSelectorExists('html[lang="pl"]');
        self::assertSelectorExists('link[rel="stylesheet"][href*="bootstrap"]');
    }

    public function testAnonymousHomeShowsNoRecommendationBlockNorEmptyMessage(): void
    {
        $client = static::createClient();
        $this->seedRecommendableData();
        $client->request('GET', '/books');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorNotExists('#recommendations');
        self::assertSelectorNotExists('.recommendation-card');
        self::assertSelectorTextNotContains('body', 'Rekomendacje dla Ciebie');
        self::assertSelectorTextNotContains('body', 'Przeczytaj i oceń kilka książek');
    }

    public function testLoggedInUserWithoutReadsSeesTheEmptyStateMessage(): void
    {
        $client = static::createClient();
        $user = static::getContainer()->get(UserManager::class)->create('anna@example.com', 'secret-pass');
        $client->loginUser($user);

        $client->request('GET', '/books');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('#recommendations h2', 'Rekomendacje dla Ciebie');
        self::assertSelectorTextContains('#recommendations', 'Przeczytaj i oceń kilka książek, aby dostać rekomendacje.');
        self::assertSelectorNotExists('.recommendation-card');
    }

    public function testLoggedInUserSeesTopThreeUnreadBooksWithLinkCategoryAndRating(): void
    {
        $client = static::createClient();
        [$anna] = $this->seedRecommendableData();
        $client->loginUser($anna);

        $crawler = $client->request('GET', '/books');

        self::assertResponseStatusCodeSame(200);
        $cards = $crawler->filter('#recommendations .recommendation-card');
        self::assertCount(3, $cards);

        $hit = $cards->eq(0);
        self::assertSame('Hit', trim($hit->filter('a')->text()));
        self::assertSame('/books/'.$this->bookIds['Hit'], $hit->filter('a')->attr('href'));
        self::assertStringContainsString('Powieść', $hit->text());
        self::assertStringContainsString('Średnia ocena: 4,5 (liczba ocen: 2)', $hit->text());

        $good = $cards->eq(1);
        self::assertSame('Dobra', trim($good->filter('a')->text()));
        self::assertSame('/books/'.$this->bookIds['Dobra'], $good->filter('a')->attr('href'));
        self::assertStringContainsString('Średnia ocena: 4,0 (liczba ocen: 1)', $good->text());

        self::assertSame('Średnia', trim($cards->eq(2)->filter('a')->text()));
        self::assertStringContainsString('Średnia ocena: 3,0 (liczba ocen: 1)', $cards->eq(2)->text());

        $section = $crawler->filter('#recommendations')->text();
        self::assertStringNotContainsString('Czwarta', $section);
        self::assertStringNotContainsString('Przeczytana', $section);
        self::assertStringNotContainsString('Przeczytaj i oceń kilka książek', $section);
    }

    public function testRecommendationsSitAboveTheBookListAndStayOnFilteredPages(): void
    {
        $client = static::createClient();
        [$anna] = $this->seedRecommendableData();
        $client->loginUser($anna);

        $client->request('GET', '/books');
        $html = (string) $client->getResponse()->getContent();
        self::assertLessThan(strpos($html, '<table'), strpos($html, 'id="recommendations"'));
        self::assertLessThan(strpos($html, '<form method="get"'), strpos($html, 'id="recommendations"'));

        $client->request('GET', '/books?title=Hit&page=1');
        self::assertSelectorExists('#recommendations .recommendation-card');
        self::assertSelectorTextContains('table', 'Hit');
    }

    public function testRecommendationsDoNotLeakOtherUsersEmails(): void
    {
        $client = static::createClient();
        [$anna] = $this->seedRecommendableData();
        $client->loginUser($anna);

        $client->request('GET', '/books');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('ola@example.com', $html);
        self::assertStringNotContainsString('ewa@example.com', $html);
    }

    /**
     * Anna read two novels (rated also by Ola); four more unread novels are rated by Ola and Ewa.
     *
     * @return array{User, User, User}
     */
    private function seedRecommendableData(): array
    {
        Clock::set(new MockClock('2026-11-12 12:00:00', 'Europe/Warsaw'));
        $users = static::getContainer()->get(UserManager::class);
        $anna = $users->create('anna@example.com', 'secret-pass');
        $ola = $users->create('ola@example.com', 'secret-pass');
        $ewa = $users->create('ewa@example.com', 'secret-pass');

        $this->addBook('Przeczytana 1', [[$anna, 5], [$ola, 5]], $ola);
        $this->addBook('Przeczytana 2', [[$anna, 5], [$ola, 5]], $ola);
        $this->addBook('Hit', [[$ola, 4], [$ewa, 5]], $ola);
        $this->addBook('Dobra', [[$ola, 4]], $ola);
        $this->addBook('Średnia', [[$ewa, 3]], $ola);
        $this->addBook('Czwarta', [[$ewa, 2]], $ola);

        return [$anna, $ola, $ewa];
    }

    /**
     * @param list<array{User, int}> $ratings
     */
    private function addBook(string $title, array $ratings, User $addedBy): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $book = (new Book())
            ->setTitle($title)
            ->setIsbn(sprintf('97883%08d', count($this->bookIds) + 1))
            ->setPublicationYear(2000)
            ->setCategory(Category::Novel)
            ->setAddedBy($addedBy);
        $em->persist($book);
        $em->flush();
        $this->bookIds[$title] = (int) $book->getId();

        foreach ($ratings as [$user, $rating]) {
            $em->persist(
                (new ReadBook())
                    ->setUser($user)
                    ->setBook($book)
                    ->setRating($rating)
                    ->setReadOn(new \DateTimeImmutable('2026-09-15')),
            );
        }
        $em->flush();
    }
}
