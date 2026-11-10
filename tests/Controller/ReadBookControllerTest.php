<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Author;
use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\User;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

final class ReadBookControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private Book $book;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = static::getContainer()->get(UserManager::class)->create('anna@example.com', 'secret-pass');
        $this->client->loginUser($this->user);
        $this->book = $this->createBook();
    }

    protected function tearDown(): void
    {
        Clock::set(new \Symfony\Component\Clock\NativeClock());
        parent::tearDown();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->restart();

        $this->client->request('POST', $this->readUrl());
        self::assertResponseRedirects('/login');

        $this->client->request('POST', $this->readUrl().'/remove');
        self::assertResponseRedirects('/login');
    }

    public function testUnreadBookShowsFormDefaultingToToday(): void
    {
        Clock::set(new MockClock('2026-11-10 23:30:00 UTC'));

        $this->client->request('GET', $this->showUrl());

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorExists('form[name="mark_read"]');
        self::assertCount(5, $this->client->getCrawler()->filter('input[type="radio"][name="mark_read[rating]"]'));
        self::assertInputValueSame('mark_read[readOn]', '2026-11-11');
    }

    public function testValidSubmitStoresRatingDateCommentAndUser(): void
    {
        $this->submit(['mark_read[rating]' => '4', 'mark_read[readOn]' => '2026-10-01', 'mark_read[comment]' => '  Świetna  ']);

        self::assertResponseRedirects($this->showUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'oznaczona jako przeczytana');
        $text = $this->client->getCrawler()->filter('main')->text();
        foreach (['Przeczytana', '4/5', '01.10.2026', 'Świetna'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
        self::assertSelectorNotExists('form[name="mark_read"]');

        $rows = $this->entityManager()->getRepository(ReadBook::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame(4, $rows[0]->getRating());
        self::assertSame('2026-10-01', $rows[0]->getReadOn()->format('Y-m-d'));
        self::assertSame('Świetna', $rows[0]->getComment());
        self::assertSame('anna@example.com', $rows[0]->getUser()->getEmail());
        self::assertSame($this->book->getId(), $rows[0]->getBook()->getId());
    }

    public function testBlankCommentIsStoredAsNull(): void
    {
        $this->submit(['mark_read[rating]' => '3', 'mark_read[comment]' => "  \n "]);

        self::assertResponseRedirects($this->showUrl());
        $row = $this->entityManager()->getRepository(ReadBook::class)->findOneBy([]);
        self::assertNull($row?->getComment());
    }

    public function testCommentOf2000CharactersIsAccepted(): void
    {
        $this->submit(['mark_read[rating]' => '3', 'mark_read[comment]' => str_repeat('x', 2000)]);

        self::assertResponseRedirects($this->showUrl());
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('invalidSubmissions')]
    public function testInvalidSubmitReturns400AndSavesNothing(array $fields): void
    {
        $this->post($fields);

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorExists('.invalid-feedback');
        self::assertCount(0, $this->entityManager()->getRepository(ReadBook::class)->findAll());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidSubmissions(): iterable
    {
        yield 'rating 0' => [['rating' => '0']];
        yield 'rating 6' => [['rating' => '6']];
        yield 'empty rating' => [['rating' => '']];
        yield 'future date' => [['readOn' => '2999-01-01']];
        yield 'missing date' => [['readOn' => '']];
        yield 'comment of 2001 characters' => [['comment' => str_repeat('x', 2001)]];
    }

    public function testTodayIsAcceptedJustAfterLocalMidnightWhileUtcIsStillYesterday(): void
    {
        Clock::set(new MockClock('2026-11-10 23:30:00 UTC'));

        $this->post(['readOn' => '2026-11-11']);
        self::assertResponseRedirects($this->showUrl());
        self::assertCount(1, $this->entityManager()->getRepository(ReadBook::class)->findAll());
    }

    public function testTomorrowInWarsawIsRejectedEvenWhenUtcDayMatches(): void
    {
        Clock::set(new MockClock('2026-11-10 23:30:00 UTC'));

        $this->post(['readOn' => '2026-11-12']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testSecondSubmitShowsWarningAndKeepsOneRow(): void
    {
        $crawler = $this->client->request('GET', $this->showUrl());
        $token = (string) $crawler->filter('input[name="mark_read[_token]"]')->attr('value');
        $this->post(['rating' => '4'], $token);
        self::assertResponseRedirects($this->showUrl());
        $this->post(['rating' => '2'], $token);

        self::assertResponseRedirects($this->showUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-warning', 'już oznaczona');
        $rows = $this->entityManager()->getRepository(ReadBook::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame(4, $rows[0]->getRating());
    }

    public function testRemoveDeletesRecordIncludingComment(): void
    {
        $this->submit(['mark_read[rating]' => '5', 'mark_read[comment]' => 'Do usunięcia']);

        $this->removeWithToken();

        self::assertResponseRedirects($this->showUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'usunięte');
        self::assertSelectorExists('form[name="mark_read"]');
        self::assertCount(0, $this->entityManager()->getRepository(ReadBook::class)->findAll());
    }

    public function testRemoveWithoutRecordIsQuiet(): void
    {
        $this->removeWithToken();

        self::assertResponseRedirects($this->showUrl());
    }

    public function testRemoveConfirmationMentionsRatingDateAndComment(): void
    {
        $this->submit(['mark_read[rating]' => '5']);
        $this->client->request('GET', $this->showUrl());

        self::assertSelectorExists('form[action$="/read/remove"][onsubmit*="confirm("]');
        self::assertStringContainsString('ocenę, datę przeczytania i komentarz', $this->client->getResponse()->getContent());
    }

    public function testMissingOrInvalidCsrfTokenOnRemoveIsRejected(): void
    {
        $this->submit(['mark_read[rating]' => '5']);

        $this->client->request('POST', $this->readUrl().'/remove');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', $this->readUrl().'/remove', ['_token' => 'tampered']);
        self::assertResponseStatusCodeSame(400);

        self::assertCount(1, $this->entityManager()->getRepository(ReadBook::class)->findAll());
    }

    public function testMissingCsrfTokenOnReadIsRejected(): void
    {
        $this->client->request('POST', $this->readUrl(), ['mark_read' => ['rating' => '4', 'readOn' => '2026-10-01']]);

        self::assertResponseStatusCodeSame(400);
        self::assertCount(0, $this->entityManager()->getRepository(ReadBook::class)->findAll());
    }

    public function testUnknownBookReturns404(): void
    {
        $this->client->request('POST', '/books/999999/read');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/books/999999/read/remove');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOthersRatingNeverAppearsOnMyListOrDetailPage(): void
    {
        $this->submit(['mark_read[rating]' => '5', 'mark_read[comment]' => 'Tajny komentarz Anny']);

        $other = static::getContainer()->get(UserManager::class)->create('ola@example.com', 'secret-pass');
        $this->client->loginUser($other);

        $this->client->request('GET', '/books');
        self::assertStringNotContainsString('5/5', $this->client->getResponse()->getContent());
        self::assertSelectorNotExists('.badge.text-bg-success');

        $this->client->request('GET', $this->showUrl());
        self::assertStringNotContainsString('5/5', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Tajny komentarz Anny', $this->client->getResponse()->getContent());
        self::assertSelectorExists('form[name="mark_read"]');
    }

    public function testRemoveOnlyAffectsTheCurrentUsersOwnRecord(): void
    {
        $this->submit(['mark_read[rating]' => '5']);

        $other = static::getContainer()->get(UserManager::class)->create('ola@example.com', 'secret-pass');
        $this->client->loginUser($other);
        $this->submit(['mark_read[rating]' => '2']);
        $this->removeWithToken();

        $rows = $this->entityManager()->getRepository(ReadBook::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame('anna@example.com', $rows[0]->getUser()->getEmail());
        self::assertSame(5, $rows[0]->getRating());
    }

    /**
     * Submits the real form from the detail page.
     *
     * @param array<string, string> $overrides
     */
    private function submit(array $overrides): void
    {
        $this->client->request('GET', $this->showUrl());
        $form = $this->client->getCrawler()->selectButton('Zapisz jako przeczytaną')->form(array_merge([
            'mark_read[readOn]' => '2026-10-01',
        ], $overrides));

        $this->client->submit($form);
    }

    /**
     * Posts raw field values (allowing values the form would not offer) with a valid CSRF token.
     *
     * @param array<string, string> $fields
     */
    private function post(array $fields, ?string $token = null): void
    {
        if (null === $token) {
            $crawler = $this->client->request('GET', $this->showUrl());
            $token = $crawler->filter('input[name="mark_read[_token]"]')->attr('value');
        }

        $this->client->request('POST', $this->readUrl(), ['mark_read' => array_merge(
            ['rating' => '4', 'readOn' => '2026-10-01', 'comment' => '', '_token' => $token],
            $fields,
        )]);
    }

    private function removeWithToken(): void
    {
        $crawler = $this->client->request('GET', $this->showUrl());
        $button = $crawler->filter('form[action$="/read/remove"]');
        $token = $button->count() > 0 ? $button->filter('input[name="_token"]')->attr('value') : $this->tokenFromSession();

        $this->client->request('POST', $this->readUrl().'/remove', ['_token' => $token]);
    }

    private function tokenFromSession(): string
    {
        // With no read record the remove form is absent; read the token from a read book page instead.
        $this->submit(['mark_read[rating]' => '1']);
        $crawler = $this->client->request('GET', $this->showUrl());
        $token = $crawler->filter('form[action$="/read/remove"] input[name="_token"]')->attr('value');
        $this->entityManager()->createQuery('DELETE FROM '.ReadBook::class)->execute();

        return $token;
    }

    private function showUrl(): string
    {
        return '/books/'.$this->book->getId();
    }

    private function readUrl(): string
    {
        return $this->showUrl().'/read';
    }

    private function createBook(): Book
    {
        $author = (new Author())->setFirstName('Stanisław')->setLastName('Lem');
        $book = (new Book())
            ->setTitle('Solaris')
            ->setIsbn('9788308000011')
            ->setPublicationYear(1961)
            ->setCategory(\App\Enum\Category::Novel)
            ->setAddedBy($this->user)
            ->addAuthor($author);
        // No clear() here: the user created in setUp must stay managed.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($author);
        $em->persist($book);
        $em->flush();

        return $book;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em;
    }
}
