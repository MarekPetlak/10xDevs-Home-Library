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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * End to end for the soft delete (S-08, test-plan risks #4 and #5): a deleted book disappears from every page
 * and every recommendation, while the other readers' ratings and comments stay in the database.
 *
 * Seed: Anna added "Solaris" (active) and "Cyberiada" (gets deleted), both Novels tagged "klasyka". Ola rated both
 * (Solaris 3, Cyberiada 5 with a comment), Anna rated Cyberiada 4. Ewa, the viewer, only read the novel "Lalka",
 * so Novel is her recent category and Cyberiada would be the best recommendation if it were still visible.
 * The deletion is a raw SQL update on purpose: this test does not depend on BookManager.
 */
final class DeletedBookVisibilityTest extends WebTestCase
{
    private const COMMENT = 'Komentarz-Oli-o-Cyberiadzie-5521';

    private KernelBrowser $client;
    private User $anna;
    private User $ola;
    private User $ewa;
    private Book $active;
    private Book $deleted;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-12 12:00:00', 'Europe/Warsaw'));
        $this->client = static::createClient();
        $users = static::getContainer()->get(UserManager::class);
        $this->anna = $users->create('anna@example.com', 'secret-pass');
        $this->ola = $users->create('ola@example.com', 'secret-pass');
        $this->ewa = $users->create('ewa@example.com', 'secret-pass');

        $tag = (new Tag())->setName('Klasyka')->setSlug('klasyka');
        $author = (new Author())->setFirstName('Stanisław')->setLastName('Lem');
        $this->active = $this->book('Solaris', '9788308000011', $author, $tag);
        $this->deleted = $this->book('Cyberiada', '9788308000028', $author, $tag);
        $lalka = $this->book('Lalka', '9788308000035', $author, null);
        foreach ([$tag, $author, $this->active, $this->deleted, $lalka] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $this->read($this->ola, $this->active, 3, null, '2026-09-15');
        $this->read($this->ola, $this->deleted, 5, self::COMMENT, '2026-09-16');
        $this->read($this->anna, $this->deleted, 4, null, '2026-09-17');
        $this->read($this->ewa, $lalka, 4, null, '2026-10-01');
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testBeforeTheDeletionTheBookIsVisibleAndRecommended(): void
    {
        $this->client->loginUser($this->ewa);

        $this->client->request('GET', '/books');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Cyberiada', $this->html());
        self::assertStringContainsString('Cyberiada', $this->client->getCrawler()->filter('#recommendations')->text());
    }

    public function testADeletedBookIsAbsentFromTheListTheTagPageAndTheRecommendations(): void
    {
        $this->softDelete($this->deleted);
        $this->client->loginUser($this->ewa);

        $this->client->request('GET', '/books');
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Solaris', $this->html());
        self::assertStringNotContainsString('Cyberiada', $this->html());
        $cards = $this->client->getCrawler()->filter('#recommendations [data-testid="recommendation-card"]');
        self::assertCount(1, $cards);
        self::assertStringContainsString('Solaris', $cards->eq(0)->text());

        $this->client->request('GET', '/books?title=Cyber');
        self::assertResponseStatusCodeSame(200);
        self::assertStringNotContainsString('Cyberiada', $this->html());

        $this->client->request('GET', '/tags/klasyka');
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('table', 'Solaris');
        self::assertSelectorTextNotContains('table', 'Cyberiada');
    }

    public function testADeletedBookIsAbsentFromTheListForAnonymousVisitors(): void
    {
        $this->softDelete($this->deleted);

        $this->client->request('GET', '/books');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Solaris', $this->html());
        self::assertStringNotContainsString('Cyberiada', $this->html());
    }

    public function testTheDetailUrlOfADeletedBookIs404ForEveryone(): void
    {
        $this->softDelete($this->deleted);
        $url = '/books/'.$this->deleted->getId();

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        foreach ([$this->ewa, $this->ola, $this->anna] as $user) {
            $this->client->loginUser($user);
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $user->getEmail());
        }
        $this->client->request('GET', '/books/'.$this->active->getId());
        self::assertResponseStatusCodeSame(200);
    }

    public function testOtherUsersReadRecordsSurviveTheDeletionUnchanged(): void
    {
        $this->softDelete($this->deleted);
        $this->em()->clear();

        $rows = $this->em()->getRepository(ReadBook::class)->findBy(['book' => $this->deleted->getId()], ['rating' => 'DESC']);

        self::assertCount(2, $rows);
        self::assertSame('ola@example.com', $rows[0]->getUser()->getEmail());
        self::assertSame(5, $rows[0]->getRating());
        self::assertSame(self::COMMENT, $rows[0]->getComment());
        self::assertSame('2026-09-16', $rows[0]->getReadOn()->format('Y-m-d'));
        self::assertSame('anna@example.com', $rows[1]->getUser()->getEmail());
        self::assertSame(4, $rows[1]->getRating());
        self::assertNull($rows[1]->getComment());
        self::assertCount(4, $this->em()->getRepository(ReadBook::class)->findAll());
    }

    private function softDelete(Book $book): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE book SET deleted_at = ? WHERE id = ?', ['2026-11-12 11:00:00', $book->getId()]);
    }

    private function book(string $title, string $isbn, Author $author, ?Tag $tag): Book
    {
        $book = (new Book())
            ->setTitle($title)
            ->setIsbn($isbn)
            ->setPublicationYear(1965)
            ->setCategory(Category::Novel)
            ->setAddedBy($this->anna)
            ->addAuthor($author);
        if (null !== $tag) {
            $book->addTag($tag);
        }

        return $book;
    }

    private function read(User $user, Book $book, int $rating, ?string $comment, string $day): void
    {
        $this->em()->persist(
            (new ReadBook())
                ->setUser($user)
                ->setBook($book)
                ->setRating($rating)
                ->setReadOn(new \DateTimeImmutable($day))
                ->setComment($comment),
        );
        $this->em()->flush();
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
