<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\NewBook;
use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\User;
use App\Enum\Category;
use App\Repository\AuthorRepository;
use App\Repository\TagRepository;
use App\Service\BookManager;
use App\Service\TagSlugger;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Soft delete through the real route (S-08, test-plan risks #1 and #4): ownership, CSRF and the survival of
 * other readers' data. Anna owns "Dziady"; Ola owns "Solaris" and has read "Dziady" with a comment.
 */
final class BookDeleteControllerTest extends WebTestCase
{
    private const COMMENT = 'Komentarz Oli o Dziadach';
    private const DELETED_AT = '2026-11-10 09:00:00';

    private KernelBrowser $client;
    private User $anna;
    private User $ola;
    private User $ewa;
    private Book $book;
    private Book $olaBook;

    protected function setUp(): void
    {
        Clock::set(new MockClock(self::DELETED_AT, 'Europe/Warsaw'));
        $this->client = static::createClient();
        $users = static::getContainer()->get(UserManager::class);
        $this->anna = $users->create('anna@example.com', 'secret-pass');
        $this->ola = $users->create('ola@example.com', 'secret-pass');
        $this->ewa = $users->create('ewa@example.com', 'secret-pass');

        $this->book = $this->addBook('Dziady', '9788373271890', 'Adam Mickiewicz', $this->anna);
        $this->olaBook = $this->addBook('Solaris', '9788324031283', 'Stanisław Lem', $this->ola);

        $this->read($this->ola, $this->book, 4, self::COMMENT, '2026-09-15');
        $this->read($this->ewa, $this->book, 2, null, '2026-09-16');
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testOwnerDeletesWithTheTokenFromTheDetailPageAndIsRedirectedToTheListWithAFlash(): void
    {
        $this->client->loginUser($this->anna);
        $token = $this->tokenFromDetailPage($this->book);

        $this->client->request('POST', $this->deleteUrl($this->book), ['_token' => $token]);

        self::assertResponseRedirects('/books');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Książka została usunięta.');
        self::assertStringNotContainsString('Dziady', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Solaris', (string) $this->client->getResponse()->getContent());

        $stored = $this->stored($this->book);
        $expected = (new \DateTimeImmutable(self::DELETED_AT.' Europe/Warsaw'))->getTimestamp();
        self::assertSame($expected, $stored->getDeletedAt()?->getTimestamp());

        $this->client->request('GET', '/books/'.$this->book->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testOtherUsersReadRecordsAndCommentsAreUnchangedAfterTheDelete(): void
    {
        $this->client->loginUser($this->anna);
        $this->client->request('POST', $this->deleteUrl($this->book), ['_token' => $this->tokenFromDetailPage($this->book)]);
        self::assertResponseRedirects('/books');

        $this->em()->clear();
        $rows = $this->em()->getRepository(ReadBook::class)->findBy(['book' => $this->book->getId()], ['rating' => 'DESC']);

        self::assertCount(2, $rows);
        self::assertSame('ola@example.com', $rows[0]->getUser()->getEmail());
        self::assertSame(4, $rows[0]->getRating());
        self::assertSame(self::COMMENT, $rows[0]->getComment());
        self::assertSame('2026-09-15', $rows[0]->getReadOn()->format('Y-m-d'));
        self::assertSame('ewa@example.com', $rows[1]->getUser()->getEmail());
        self::assertSame(2, $rows[1]->getRating());
        self::assertNull($rows[1]->getComment());
    }

    public function testSignedInNonOwnerWithAValidTokenGets403AndTheBookIsUntouched(): void
    {
        // Ola's token comes from the delete button of her own book: it is valid for her session, so only the voter can refuse.
        $this->client->loginUser($this->ola);
        $token = $this->tokenFromDetailPage($this->olaBook);

        $this->client->request('POST', $this->deleteUrl($this->book), ['_token' => $token]);

        self::assertResponseStatusCodeSame(403);
        $this->assertNotDeleted($this->book);
        $this->assertNotDeleted($this->olaBook);
    }

    public function testOwnerPostingTheIdOfAForeignBookIsRefusedAndTheForeignBookIsUntouched(): void
    {
        $this->client->loginUser($this->anna);
        $token = $this->tokenFromDetailPage($this->book);

        $this->client->request('POST', $this->deleteUrl($this->olaBook), ['_token' => $token]);

        self::assertResponseStatusCodeSame(403);
        $this->assertNotDeleted($this->olaBook);
        $this->assertNotDeleted($this->book);
    }

    public function testAnonymousIsSentToLoginAndTheBookIsUntouched(): void
    {
        $this->client->request('POST', $this->deleteUrl($this->book), ['_token' => 'any-token']);

        self::assertResponseRedirects('/login');
        $this->assertNotDeleted($this->book);
    }

    public function testMissingCsrfTokenGives400AndTheBookIsUntouched(): void
    {
        $this->client->loginUser($this->anna);

        $this->client->request('POST', $this->deleteUrl($this->book));

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Odśwież stronę', (string) $this->client->getResponse()->getContent());
        $this->assertNotDeleted($this->book);
    }

    public function testInvalidCsrfTokenGives400AndTheBookIsUntouched(): void
    {
        $this->client->loginUser($this->anna);

        $this->client->request('POST', $this->deleteUrl($this->book), ['_token' => 'tampered-token']);

        self::assertResponseStatusCodeSame(400);
        $this->assertNotDeleted($this->book);
    }

    public function testUnknownBookReturns404(): void
    {
        $this->client->loginUser($this->anna);

        $this->client->request('POST', '/books/999999/delete', ['_token' => $this->tokenFromDetailPage($this->book)]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnAlreadyDeletedBookReturns404AndKeepsItsOriginalDeletionTime(): void
    {
        $this->client->loginUser($this->anna);
        $token = $this->tokenFromDetailPage($this->book);
        $this->client->request('POST', $this->deleteUrl($this->book), ['_token' => $token]);
        self::assertResponseRedirects('/books');

        Clock::set(new MockClock('2026-11-11 09:00:00', 'Europe/Warsaw'));
        $this->client->request('POST', $this->deleteUrl($this->book), ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        $expected = (new \DateTimeImmutable(self::DELETED_AT.' Europe/Warsaw'))->getTimestamp();
        self::assertSame($expected, $this->stored($this->book)->getDeletedAt()?->getTimestamp());
    }

    public function testDeleteRouteRejectsGet(): void
    {
        $this->client->loginUser($this->anna);

        $this->client->request('GET', $this->deleteUrl($this->book));

        self::assertResponseStatusCodeSame(405);
        $this->assertNotDeleted($this->book);
    }

    private function tokenFromDetailPage(Book $book): string
    {
        $this->client->request('GET', '/books/'.$book->getId());
        self::assertResponseStatusCodeSame(200);
        $token = $this->client->getCrawler()->filter('form[action="'.$this->deleteUrl($book).'"] input[name="_token"]')->attr('value');
        self::assertNotEmpty($token);

        return (string) $token;
    }

    private function addBook(string $title, string $isbn, string $author, User $owner): Book
    {
        $input = new NewBook();
        $input->title = $title;
        $input->authors = $author;
        $input->isbn = $isbn;
        $input->publicationYear = 1900;
        $input->category = Category::Novel;
        $input->tags = '';
        // Built by hand, as in BookEditControllerTest, so the shared kernel services stay untouched.
        $container = static::getContainer();

        return (new BookManager(
            $container->get(EntityManagerInterface::class),
            $container->get(AuthorRepository::class),
            $container->get(TagRepository::class),
            $container->get(TagSlugger::class),
        ))->add($input, $owner);
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

    private function assertNotDeleted(Book $book): void
    {
        $stored = $this->stored($book);
        self::assertNull($stored->getDeletedAt());
        self::assertFalse($stored->isDeleted());
    }

    private function stored(Book $book): Book
    {
        $this->em()->clear();
        $stored = $this->em()->getRepository(Book::class)->find($book->getId());
        self::assertNotNull($stored);

        return $stored;
    }

    private function deleteUrl(Book $book): string
    {
        return '/books/'.$book->getId().'/delete';
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
