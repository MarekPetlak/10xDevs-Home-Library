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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Which personal data may appear for which role on which page (PRD guardrails, test-plan risk #3, roadmap S-03).
 *
 * Seed: Solaris (tag "klasyka") read by Anna (rating 4 + comment), Ola (rating 1, NO comment) and
 * Ewa, who only read an unrelated novel and is the "other signed-in user".
 */
final class PrivacyMatrixTest extends WebTestCase
{
    private const MARKER = 'MARKER-KOMENTARZ-ANNY-7731';
    private const ANNA_EMAIL = 'zofia.autorka@example.com';
    private const ANNA_MASKED = 'z***@example.com';
    private const OLA_EMAIL = 'olek.bez.komentarza@example.com';
    private const OLA_MASKED = 'o***@example.com';
    private const ANNA_RATING = '4/5';
    private const OLA_RATING = '1/5';
    private const EWA_OWN_RATING = '3/5';

    private KernelBrowser $client;
    private User $anna;
    private User $ola;
    private User $ewa;
    private Book $book;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-12 12:00:00', 'Europe/Warsaw'));
        $this->client = static::createClient();
        $users = static::getContainer()->get(UserManager::class);
        $this->anna = $users->create(self::ANNA_EMAIL, 'secret-pass');
        $this->ola = $users->create(self::OLA_EMAIL, 'secret-pass');
        $this->ewa = $users->create('ewa.widz@example.com', 'secret-pass');

        $tag = (new Tag())->setName('Klasyka')->setSlug('klasyka');
        $author = (new Author())->setFirstName('Stanisław')->setLastName('Lem');
        $this->book = (new Book())
            ->setTitle('Solaris')
            ->setIsbn('9788308000011')
            ->setPublicationYear(1961)
            ->setCategory(Category::Novel)
            ->setAddedBy($this->anna)
            ->addAuthor($author)
            ->addTag($tag);
        $other = (new Book())
            ->setTitle('Cyberiada')
            ->setIsbn('9788308000028')
            ->setPublicationYear(1965)
            ->setCategory(Category::Novel)
            ->setAddedBy($this->anna)
            ->addAuthor($author);
        foreach ([$tag, $author, $this->book, $other] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $this->read($this->anna, $this->book, 4, self::MARKER);
        $this->read($this->ola, $this->book, 1, null);
        $this->read($this->ewa, $other, 3, null);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, list<string>, list<string>, list<string>}>
     *                                                                                          role, page, strings that must appear, strings that must not appear, selectors that must not exist
     */
    public static function roleByPage(): iterable
    {
        $everything = [self::MARKER, self::ANNA_EMAIL, self::OLA_EMAIL, self::ANNA_MASKED, self::OLA_MASKED, self::ANNA_RATING, self::OLA_RATING];

        yield 'anonymous on /books' => ['anonymous', 'books', ['Solaris'], $everything, ['#recommendations', '[data-testid="recommendation-card"]', 'form[name="mark_read"]']];
        yield 'anonymous on /books/{id}' => ['anonymous', 'show', ['Solaris'], $everything, ['#comments', 'form[name="mark_read"]', 'form[action$="/read/remove"]']];
        yield 'anonymous on /tags/{slug}' => ['anonymous', 'tag', ['Solaris'], $everything, ['#recommendations', 'form[name="mark_read"]']];

        // Another reader sees catalogue data only on the list pages; the book page adds the commented record.
        $notOnListPages = [self::MARKER, self::ANNA_EMAIL, self::OLA_EMAIL, self::ANNA_MASKED, self::OLA_MASKED, self::ANNA_RATING, self::OLA_RATING];
        yield 'other user on /books' => ['other', 'books', ['Solaris', self::EWA_OWN_RATING], $notOnListPages, []];
        yield 'other user on /books/{id}' => [
            'other', 'show',
            [self::MARKER, self::ANNA_MASKED, self::ANNA_RATING],
            [self::ANNA_EMAIL, self::OLA_EMAIL, self::OLA_MASKED, self::OLA_RATING],
            [],
        ];
        yield 'other user on /tags/{slug}' => ['other', 'tag', ['Solaris'], $notOnListPages, []];

        // The author sees their own record and nothing of the others.
        // Their own e-mail legitimately shows in the navigation, so only the others' data is checked.
        $notTheirs = [self::OLA_EMAIL, self::OLA_MASKED, self::OLA_RATING, self::ANNA_MASKED];
        yield 'author on /books' => ['author', 'books', ['Solaris', self::ANNA_RATING], $notTheirs, []];
        yield 'author on /books/{id}' => ['author', 'show', [self::MARKER, self::ANNA_RATING], [self::OLA_EMAIL, self::OLA_MASKED, self::OLA_RATING, self::ANNA_MASKED], []];
        yield 'author on /tags/{slug}' => ['author', 'tag', ['Solaris', self::ANNA_RATING], $notTheirs, []];
    }

    /**
     * @param list<string> $present
     * @param list<string> $absent
     * @param list<string> $absentSelectors
     */
    #[DataProvider('roleByPage')]
    public function testPersonalDataAppearsOnlyWhereTheMatrixAllowsIt(string $role, string $page, array $present, array $absent, array $absentSelectors): void
    {
        $this->actAs($role);

        $this->client->request('GET', $this->url($page));

        $where = sprintf('[%s on %s]', $role, $page);
        self::assertResponseStatusCodeSame(200, $where);
        $html = $this->html();
        foreach ($present as $needle) {
            self::assertStringContainsString($needle, $html, sprintf('%s expected "%s" on the page', $where, $needle));
        }
        foreach ($absent as $needle) {
            self::assertStringNotContainsString($needle, $html, sprintf('%s must not leak "%s"', $where, $needle));
        }
        foreach ($absentSelectors as $selector) {
            self::assertSelectorNotExists($selector, sprintf('%s must not render "%s"', $where, $selector));
        }
    }

    public function testOtherUserSeesTheCommentOnlyInsideTheCommentsBlock(): void
    {
        $this->actAs('other');

        $this->client->request('GET', $this->url('show'));

        $block = $this->client->getCrawler()->filter('section#comments')->text();
        $where = '[other on show]';
        self::assertSame(1, substr_count($this->html(), self::MARKER), $where.' marker must occur exactly once');
        self::assertStringContainsString(self::MARKER, $block, $where.' marker belongs in the comments block');
        self::assertStringContainsString(self::ANNA_MASKED, $block, $where.' masked e-mail belongs in the comments block');
        self::assertStringContainsString(self::ANNA_RATING, $block, $where.' rating belongs in the comments block');
        self::assertCount(1, $this->client->getCrawler()->filter('section#comments article'), $where.' only the commented record is listed');
    }

    public function testAuthorSeesOwnRecordInTheReadBlockAndNotInTheCommentsBlock(): void
    {
        $this->actAs('author');

        $this->client->request('GET', $this->url('show'));

        $where = '[author on show]';
        self::assertSelectorExists('form[action$="/read/remove"]', $where.' own record belongs in the read-state block');
        self::assertSelectorNotExists('form[name="mark_read"]', $where.' no read form once the book is read');
        self::assertSame(1, substr_count($this->html(), self::MARKER), $where.' own comment must occur exactly once');
        $block = $this->client->getCrawler()->filter('section#comments')->text();
        self::assertStringNotContainsString(self::MARKER, $block, $where.' own comment must not repeat in the comments block');
        self::assertStringNotContainsString(self::ANNA_RATING, $block, $where.' own rating must not repeat in the comments block');
        self::assertCount(0, $this->client->getCrawler()->filter('section#comments article'), $where.' nobody else commented');
    }

    public function testRecommendationBlockShowsAggregateWithoutEmailsOrComments(): void
    {
        $this->actAs('other');

        $this->client->request('GET', $this->url('books'));

        $where = '[recommendations for other on books]';
        $cards = $this->client->getCrawler()->filter('#recommendations [data-testid="recommendation-card"]');
        self::assertCount(1, $cards, $where.' Solaris is the one unread novel rated by others');
        $card = $this->client->getCrawler()->filter('#recommendations')->text();
        self::assertStringContainsString('Solaris', $card, $where);
        self::assertStringContainsString('2,5', $card, $where.' average of 4 and 1');
        self::assertStringContainsString('2', $cards->eq(0)->text(), $where.' rating count');
        foreach ([self::ANNA_EMAIL, self::ANNA_MASKED, self::OLA_EMAIL, self::OLA_MASKED, self::MARKER, self::ANNA_RATING, self::OLA_RATING] as $needle) {
            self::assertStringNotContainsString($needle, $this->client->getCrawler()->filter('#recommendations')->html(), sprintf('%s must not leak "%s"', $where, $needle));
        }
    }

    private function actAs(string $role): void
    {
        match ($role) {
            'anonymous' => null,
            'other' => $this->client->loginUser($this->ewa),
            'author' => $this->client->loginUser($this->anna),
        };
    }

    private function url(string $page): string
    {
        return match ($page) {
            'books' => '/books',
            'show' => '/books/'.$this->book->getId(),
            'tag' => '/tags/klasyka',
        };
    }

    private function read(User $user, Book $book, int $rating, ?string $comment): void
    {
        $this->em()->persist(
            (new ReadBook())
                ->setUser($user)
                ->setBook($book)
                ->setRating($rating)
                ->setReadOn(new \DateTimeImmutable('2026-09-15'))
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
