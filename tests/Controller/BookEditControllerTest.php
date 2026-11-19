<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\NewBook;
use App\Entity\Author;
use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\Category;
use App\Repository\AuthorRepository;
use App\Repository\TagRepository;
use App\Service\BookManager;
use App\Service\TagSlugger;
use App\Service\UserManager;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class BookEditControllerTest extends WebTestCase
{
    private const SUBMIT = 'Zapisz książkę';
    private const CONFIRM = 'Zapisz mimo to';
    private const EDITED_AT = '2026-11-17 10:00:00';

    private KernelBrowser $client;
    private User $owner;
    private Book $book;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-10 09:00:00', 'Europe/Warsaw'));
        $this->client = static::createClient();
        $this->owner = static::getContainer()->get(UserManager::class)->create('anna@example.com', 'secret-pass');
        $this->client->loginUser($this->owner);

        $input = new NewBook();
        $input->title = 'Dziady';
        $input->authors = 'Adam Mickiewicz, Anna Nowak';
        $input->isbn = '9788373271890';
        $input->publicationYear = 1822;
        $input->category = Category::Poetry;
        $input->tags = 'klasyka';
        $input->description = 'Dramat romantyczny.';
        // No clear() here: the user created above must stay managed.
        // Built by hand: fetching the BookManager service would initialize it and stop a test from replacing it.
        $container = static::getContainer();
        $this->book = (new BookManager(
            $container->get(EntityManagerInterface::class),
            $container->get(AuthorRepository::class),
            $container->get(TagRepository::class),
            $container->get(TagSlugger::class),
        ))->add($input, $this->owner);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testOwnerSeesFormPrefilledWithCurrentValues(): void
    {
        $this->client->request('GET', $this->editUrl());

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorExists('form[name="book"]');
        self::assertInputValueSame('book[title]', 'Dziady');
        self::assertInputValueSame('book[authors]', 'Adam Mickiewicz, Anna Nowak');
        self::assertInputValueSame('book[isbn]', '9788373271890');
        self::assertInputValueSame('book[publicationYear]', '1822');
        self::assertSelectorExists('select[name="book[category]"] option[value="poetry"][selected]');
        self::assertInputValueSame('book[tags]', 'klasyka');
        self::assertSelectorTextContains('textarea[name="book[description]"]', 'Dramat romantyczny.');
        self::assertSelectorNotExists('button[name="book[confirm_duplicate]"]');
        self::assertSelectorNotExists('.alert-warning');
    }

    public function testValidSaveChangesAllFieldsAndRedirectsWithFlash(): void
    {
        $this->submit([
            'book[title]' => 'Pan Tadeusz',
            'book[authors]' => 'Jan Kowalski',
            'book[isbn]' => '978-0-306-40615-7',
            'book[publicationYear]' => '1834',
            'book[category]' => 'novel',
            'book[tags]' => 'epopeja, polska',
            'book[description]' => '  Epopeja narodowa.  ',
        ]);

        self::assertResponseRedirects($this->showUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Zmiany w książce zostały zapisane.');
        self::assertSelectorTextContains('h1', 'Pan Tadeusz');

        $stored = $this->storedBook();
        self::assertSame('Pan Tadeusz', $stored->getTitle());
        self::assertSame('9780306406157', $stored->getIsbn());
        self::assertSame(1834, $stored->getPublicationYear());
        self::assertSame(Category::Novel, $stored->getCategory());
        self::assertSame('Epopeja narodowa.', $stored->getDescription());
        self::assertSame(['Jan Kowalski'], $stored->getAuthors()->map(static fn (Author $a): string => $a->getFullName())->toArray());
        self::assertEqualsCanonicalizing(['epopeja', 'polska'], $stored->getTags()->map(static fn (Tag $t): string => $t->getName())->toArray());
        self::assertSame('anna@example.com', $stored->getAddedBy()->getEmail());
        self::assertCount(1, $this->entityManager()->getRepository(Book::class)->findAll());
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('invalidSubmissions')]
    public function testInvalidSubmitReturns400AndChangesNothing(array $values): void
    {
        $this->submit($values);

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorExists('.invalid-feedback');
        $this->assertBookUntouched();
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidSubmissions(): iterable
    {
        yield 'empty title' => [['book[title]' => '']];
        yield 'single word author' => [['book[authors]' => 'Homer']];
        yield 'invalid isbn' => [['book[isbn]' => '123']];
        yield 'year too low' => [['book[publicationYear]' => '999']];
        yield 'six tags' => [['book[tags]' => 'a, b, c, d, e, f']];
    }

    public function testChangingTheIsbnToAnotherBooksIsbnWarnsAndSavesNothing(): void
    {
        $this->storeOtherBook();

        $this->submit(['book[title]' => 'Zmieniony', 'book[isbn]' => '9788324031283']);

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('.alert-warning', 'ten sam ISBN');
        self::assertSelectorTextContains('.alert-warning a[href^="/books/"][target="_blank"]', 'Solaris');
        self::assertSelectorExists('button[name="book[confirm_duplicate]"]');
        self::assertInputValueSame('book[title]', 'Zmieniony');
        $this->assertBookUntouched();
    }

    public function testConfirmDuplicateButtonSavesDespiteTheWarning(): void
    {
        $this->storeOtherBook();
        $this->submit(['book[isbn]' => '9788324031283']);
        self::assertResponseStatusCodeSame(400);

        $this->client->submit($this->client->getCrawler()->selectButton(self::CONFIRM)->form());

        self::assertResponseRedirects($this->showUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Zmiany w książce zostały zapisane.');
        self::assertSame('9788324031283', $this->storedBook()->getIsbn());
    }

    public function testNormalButtonAfterTheWarningWarnsAgain(): void
    {
        $this->storeOtherBook();
        $this->submit(['book[isbn]' => '9788324031283']);
        $this->client->submit($this->client->getCrawler()->selectButton(self::SUBMIT)->form());

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorExists('.alert-warning');
        self::assertSame('9788373271890', $this->storedBook()->getIsbn());
    }

    public function testSavingWithoutChangingTheIsbnDoesNotWarnAboutTheBookItself(): void
    {
        // Same ISBN, title and author as the stored row: only the excluded edited book matches.
        $this->submit(['book[description]' => 'Nowy opis.']);

        self::assertResponseRedirects($this->showUrl());
        self::assertSame('Nowy opis.', $this->storedBook()->getDescription());
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $this->submit(['book[title]' => 'Zmieniony', 'book[_token]' => 'tampered-token']);

        self::assertResponseStatusCodeSame(400);
        $this->assertBookUntouched();
    }

    public function testMissingCsrfTokenIsRejected(): void
    {
        $this->client->request('POST', $this->editUrl(), ['book' => [
            'title' => 'Zmieniony',
            'authors' => 'Adam Mickiewicz',
            'isbn' => '9788373271890',
            'publicationYear' => '1822',
            'category' => 'poetry',
        ]]);

        self::assertResponseStatusCodeSame(400);
        $this->assertBookUntouched();
    }

    public function testAnotherUsersReadRecordIsUnchangedByTheEdit(): void
    {
        $ola = static::getContainer()->get(UserManager::class)->create('ola@example.com', 'secret-pass');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(
            (new ReadBook())
                ->setUser($ola)
                ->setBook($this->book)
                ->setRating(4)
                ->setReadOn(new \DateTimeImmutable('2026-09-15'))
                ->setComment('Komentarz Oli'),
        );
        $em->flush();

        $this->submit(['book[title]' => 'Pan Tadeusz', 'book[authors]' => 'Jan Kowalski', 'book[tags]' => '']);

        self::assertResponseRedirects($this->showUrl());
        $rows = $this->entityManager()->getRepository(ReadBook::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame(4, $rows[0]->getRating());
        self::assertSame('Komentarz Oli', $rows[0]->getComment());
        self::assertSame('2026-09-15', $rows[0]->getReadOn()->format('Y-m-d'));
        self::assertSame('ola@example.com', $rows[0]->getUser()->getEmail());
        self::assertSame($this->book->getId(), $rows[0]->getBook()->getId());
    }

    public function testUpdatedAtIsNullBeforeTheEditAndTheClockTimeAfterIt(): void
    {
        self::assertNull($this->storedBook()->getUpdatedAt());
        $this->client->request('GET', $this->showUrl());
        self::assertStringNotContainsString('Zaktualizowano', $this->pageText());

        Clock::set(new MockClock(self::EDITED_AT, 'Europe/Warsaw'));
        $this->submit(['book[title]' => 'Pan Tadeusz']);
        self::assertResponseRedirects($this->showUrl());

        $expected = (new \DateTimeImmutable(self::EDITED_AT.' Europe/Warsaw'))->getTimestamp();
        self::assertSame($expected, $this->storedBook()->getUpdatedAt()?->getTimestamp());

        $this->client->request('GET', $this->showUrl());
        self::assertSelectorTextContains('dl', 'Zaktualizowano');
        self::assertSelectorTextContains('dl', '17.11.2026');
    }

    public function testLostUpdateRaceShowsATryAgainErrorInsteadOfA500(): void
    {
        // Without this the client reboots the kernel between requests and drops the replaced service.
        $this->client->disableReboot();
        $failing = $this->createStub(EntityManagerInterface::class);
        $failing->method('wrapInTransaction')->willThrowException(
            new UniqueConstraintViolationException($this->createStub(DriverException::class), null),
        );
        static::getContainer()->set(BookManager::class, new BookManager(
            $failing,
            static::getContainer()->get(AuthorRepository::class),
            static::getContainer()->get(TagRepository::class),
            static::getContainer()->get(TagSlugger::class),
        ));

        $this->submit(['book[title]' => 'Pan Tadeusz']);

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('.invalid-feedback, .alert-danger, .form-error-message', 'Spróbuj ponownie');
        self::assertInputValueSame('book[title]', 'Pan Tadeusz');
        $this->assertBookUntouched();
    }

    public function testUnknownBookReturns404(): void
    {
        $this->client->request('GET', '/books/999999/edit');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/books/999999/edit');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletedBookReturns404EvenForItsOwner(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('UPDATE book SET deleted_at = ? WHERE id = ?', ['2026-11-09 10:00:00', $this->book->getId()]);

        $this->client->request('GET', $this->editUrl());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', $this->editUrl(), ['book' => [
            'title' => 'Przejęta',
            'authors' => 'Adam Mickiewicz',
            'isbn' => '9788373271890',
            'publicationYear' => '1822',
            'category' => 'poetry',
        ]]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Dziady', $this->storedBook()->getTitle());
    }

    /**
     * Opens the real edit form and submits it with the given field overrides.
     *
     * @param array<string, string> $overrides
     */
    private function submit(array $overrides): void
    {
        $this->client->request('GET', $this->editUrl());
        $form = $this->client->getCrawler()->selectButton(self::SUBMIT)->form($overrides);

        $this->client->submit($form);
    }

    private function storeOtherBook(): void
    {
        $author = (new Author())->setFirstName('Stanisław')->setLastName('Lem');
        $other = (new Book())
            ->setTitle('Solaris')
            ->setIsbn('9788324031283')
            ->setPublicationYear(1961)
            ->setCategory(Category::Novel)
            ->setAddedBy($this->owner)
            ->addAuthor($author);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($author);
        $em->persist($other);
        $em->flush();
    }

    private function assertBookUntouched(): void
    {
        $stored = $this->storedBook();
        self::assertSame('Dziady', $stored->getTitle());
        self::assertSame('9788373271890', $stored->getIsbn());
        self::assertSame(1822, $stored->getPublicationYear());
        self::assertSame(Category::Poetry, $stored->getCategory());
        self::assertSame('Dramat romantyczny.', $stored->getDescription());
        self::assertEqualsCanonicalizing(['Adam Mickiewicz', 'Anna Nowak'], $stored->getAuthors()->map(static fn (Author $a): string => $a->getFullName())->toArray());
        self::assertSame(['klasyka'], array_values($stored->getTags()->map(static fn (Tag $t): string => $t->getName())->toArray()));
        self::assertNull($stored->getUpdatedAt());
    }

    private function storedBook(): Book
    {
        $stored = $this->entityManager()->getRepository(Book::class)->find($this->book->getId());
        self::assertNotNull($stored);

        return $stored;
    }

    private function pageText(): string
    {
        return $this->client->getCrawler()->filter('main')->text();
    }

    private function showUrl(): string
    {
        return '/books/'.$this->book->getId();
    }

    private function editUrl(): string
    {
        return $this->showUrl().'/edit';
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em;
    }
}
