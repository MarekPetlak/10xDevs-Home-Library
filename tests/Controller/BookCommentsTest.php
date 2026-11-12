<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Author;
use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\User;
use App\Enum\Category;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BookCommentsTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $anna;
    private User $ola;
    private Book $book;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $users = static::getContainer()->get(UserManager::class);
        $this->anna = $users->create('anna@example.com', 'secret-pass');
        $this->ola = $users->create('ola@example.com', 'secret-pass');

        $author = (new Author())->setFirstName('Stanisław')->setLastName('Lem');
        $this->book = (new Book())
            ->setTitle('Solaris')
            ->setIsbn('9788308000011')
            ->setPublicationYear(1961)
            ->setCategory(Category::Novel)
            ->setAddedBy($this->anna)
            ->addAuthor($author);
        $this->em()->persist($author);
        $this->em()->persist($this->book);
        $this->em()->flush();
    }

    public function testCommentShowsForAReaderWhoHasNotReadTheBookWithMaskedEmailAndRating(): void
    {
        $this->read($this->anna, 4, 'Świetna książka.', '2026-09-15');
        $this->client->loginUser($this->ola);

        $this->client->request('GET', $this->showUrl());

        self::assertResponseStatusCodeSame(200);
        $block = $this->commentsBlock();
        self::assertStringContainsString('Świetna książka.', $block);
        self::assertStringContainsString('a***@example.com', $block);
        self::assertStringContainsString('4/5', $block);
        // Read date and raw e-mail never reach the page.
        self::assertStringNotContainsString('15.09.2026', $this->html());
        self::assertStringNotContainsString('anna@example.com', $this->html());
    }

    public function testRatingOfARecordWithoutACommentAppearsNowhere(): void
    {
        $this->read($this->anna, 5, null, '2026-09-15');
        $this->client->loginUser($this->ola);

        $this->client->request('GET', $this->showUrl());

        self::assertStringNotContainsString('5/5', $this->html());
        self::assertStringNotContainsString('a***@example.com', $this->html());
        self::assertStringContainsString('Brak komentarzy', $this->commentsBlock());
    }

    public function testCommentTextIsEscapedAndLineBreaksArePreserved(): void
    {
        $this->read($this->anna, 3, "<script>alert(1)</script>\ndruga linia", '2026-09-15');
        $this->client->loginUser($this->ola);

        $this->client->request('GET', $this->showUrl());

        self::assertStringNotContainsString('<script>alert(1)', $this->html());
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $this->html());
        self::assertStringContainsString('<br', $this->commentsBlockHtml());
    }

    public function testNewestCommentIsListedFirst(): void
    {
        $this->read($this->anna, 4, 'Pierwszy komentarz', '2026-09-15');
        $this->read($this->ola, 2, 'Drugi komentarz', '2026-09-16');
        $third = static::getContainer()->get(UserManager::class)->create('ewa@example.com', 'secret-pass');
        $this->client->loginUser($third);

        $this->client->request('GET', $this->showUrl());

        $block = $this->commentsBlock();
        self::assertLessThan(strpos($block, 'Pierwszy komentarz'), strpos($block, 'Drugi komentarz'));
    }

    public function testViewersOwnCommentIsNotRepeatedInTheBlock(): void
    {
        $this->read($this->anna, 4, 'Mój komentarz', '2026-09-15');
        $this->client->loginUser($this->anna);

        $this->client->request('GET', $this->showUrl());

        self::assertSame(1, substr_count($this->html(), 'Mój komentarz'));
        self::assertStringNotContainsString('Mój komentarz', $this->commentsBlock());
    }

    public function testRemovingTheReadRecordRemovesTheCommentFromOthers(): void
    {
        $this->read($this->anna, 4, 'Zniknie', '2026-09-15');
        $this->client->loginUser($this->anna);
        $crawler = $this->client->request('GET', $this->showUrl());
        $this->client->submit($crawler->filter('form[action$="/read/remove"]')->form());
        self::assertResponseRedirects($this->showUrl());

        $this->client->loginUser($this->ola);
        $this->client->request('GET', $this->showUrl());

        self::assertStringNotContainsString('Zniknie', $this->html());
        self::assertStringContainsString('Brak komentarzy', $this->commentsBlock());
    }

    public function testCommentsAlsoShowWhenTheMarkReadFormIsRejected(): void
    {
        $this->read($this->anna, 4, 'Widoczny mimo błędu', '2026-09-15');
        $this->client->loginUser($this->ola);
        $crawler = $this->client->request('GET', $this->showUrl());
        $form = $crawler->filter('form[name="mark_read"]')->form(['mark_read[comment]' => 'x']);

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Widoczny mimo błędu', $this->commentsBlock());
    }

    public function testAnonymousSeesTheBookButNoCommentsRatingsOrReadForm(): void
    {
        $this->read($this->anna, 4, 'Sekret', '2026-09-15');

        $this->client->request('GET', $this->showUrl());

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('h1', 'Solaris');
        $html = $this->html();
        self::assertStringNotContainsString('Sekret', $html);
        self::assertStringNotContainsString('4/5', $html);
        self::assertStringNotContainsString('a***@example.com', $html);
        self::assertStringNotContainsString('anna@example.com', $html);
        self::assertStringNotContainsString('Komentarze czytelników', $html);
        self::assertStringNotContainsString('Brak komentarzy', $html);
        self::assertSelectorNotExists('#comments');
        self::assertSelectorNotExists('form[name="mark_read"]');
        self::assertSelectorNotExists('form[action$="/read/remove"]');
    }

    private function read(User $user, int $rating, ?string $comment, string $readOn): void
    {
        $this->em()->persist(
            (new ReadBook())
                ->setUser($user)
                ->setBook($this->book)
                ->setRating($rating)
                ->setReadOn(new \DateTimeImmutable($readOn))
                ->setComment($comment),
        );
        $this->em()->flush();
    }

    private function showUrl(): string
    {
        return '/books/'.$this->book->getId();
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function commentsBlock(): string
    {
        return $this->client->getCrawler()->filter('section#comments')->text();
    }

    private function commentsBlockHtml(): string
    {
        return $this->client->getCrawler()->filter('section#comments')->html();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
