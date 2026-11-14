<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Author;
use App\Entity\Book;
use App\Entity\Tag;
use App\Repository\AuthorRepository;
use App\Repository\TagRepository;
use App\Service\BookManager;
use App\Service\UserManager;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BookControllerTest extends WebTestCase
{
    private const EMAIL = 'anna@example.com';
    private const SUBMIT = 'Zapisz książkę';
    private const CONFIRM = 'Dodaj mimo to';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $user = static::getContainer()->get(UserManager::class)->create(self::EMAIL, 'secret-pass');
        $this->client->loginUser($user);
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->restart();
        $this->client->request('GET', '/books/new');

        self::assertResponseRedirects('/login');
    }

    public function testSignedInSeesFormAndNavbarLink(): void
    {
        $this->client->request('GET', '/books/new');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorExists('form[name="book"]');
        foreach (['title', 'authors', 'isbn', 'publicationYear', 'category', 'tags', 'description'] as $field) {
            self::assertSelectorExists(sprintf('[name="book[%s]"]', $field));
        }
        self::assertSelectorTextContains('nav a[href="/books/new"]', 'Dodaj książkę');
        self::assertSelectorTextContains('select[name="book[category]"]', 'Literatura faktu');
    }

    public function testValidSubmitPersistsBookAndRedirectsWithFlash(): void
    {
        $this->submit(['book[isbn]' => '978-83-7327-189-0', 'book[tags]' => 'klasyka, polska', 'book[description]' => '  Opis  ']);

        self::assertResponseRedirects('/books/new');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Książka została dodana do kolekcji.');

        $books = $this->entityManager()->getRepository(Book::class)->findAll();
        self::assertCount(1, $books);
        self::assertSame('Dziady', $books[0]->getTitle());
        self::assertSame('9788373271890', $books[0]->getIsbn());
        self::assertSame('Opis', $books[0]->getDescription());
        self::assertSame(self::EMAIL, $books[0]->getAddedBy()->getEmail());
        self::assertCount(1, $books[0]->getAuthors());
        self::assertCount(2, $books[0]->getTags());
    }

    public function testValidIsbn10IsAcceptedAndUpperCased(): void
    {
        $this->submit(['book[isbn]' => '0-8044-2957-x']);

        self::assertResponseRedirects('/books/new');
        $book = $this->entityManager()->getRepository(Book::class)->findOneBy([]);
        self::assertSame('080442957X', $book?->getIsbn());
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('invalidSubmissions')]
    public function testInvalidSubmitReturns400WithErrorAndPersistsNothing(array $values): void
    {
        $this->submit($values);

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorExists('.invalid-feedback');
        $em = $this->entityManager();
        self::assertCount(0, $em->getRepository(Book::class)->findAll());
        self::assertCount(0, $em->getRepository(Author::class)->findAll());
        self::assertCount(0, $em->getRepository(Tag::class)->findAll());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidSubmissions(): iterable
    {
        yield 'empty title' => [['book[title]' => '']];
        yield 'no authors' => [['book[authors]' => ' , ']];
        yield 'single word author' => [['book[authors]' => 'Homer']];
        yield 'one of authors single word' => [['book[authors]' => 'Jan Kowalski, Homer']];
        yield 'invalid isbn' => [['book[isbn]' => '123']];
        yield 'empty isbn' => [['book[isbn]' => '']];
        yield 'missing year' => [['book[publicationYear]' => '']];
        yield 'year too low' => [['book[publicationYear]' => '999']];
        yield 'year too high' => [['book[publicationYear]' => (string) ((int) date('Y') + 2)]];
        yield 'six tags' => [['book[tags]' => 'a, b, c, d, e, f']];
        yield 'tag over 30 characters' => [['book[tags]' => str_repeat('x', 31)]];
        yield 'description over 2000 characters' => [['book[description]' => str_repeat('x', 2001)]];
        yield 'first name over 100 characters' => [['book[authors]' => str_repeat('x', 101).' Kowalski']];
        yield 'title over 255 characters' => [['book[title]' => str_repeat('x', 256)]];
        yield 'eleven authors' => [['book[authors]' => implode(', ', array_map(static fn (int $i): string => 'Imie'.chr(97 + $i).' Nazwisko', range(0, 10)))]];
        yield 'authors text over 1000 characters' => [['book[authors]' => implode(', ', array_fill(0, 60, 'Adam Mickiewiczowski-Kowalski'))]];
        yield 'tags text over 1000 characters' => [['book[tags]' => str_repeat('ab, ', 300)]];
    }

    public function testTenAuthorsAreAccepted(): void
    {
        $authors = implode(', ', array_map(static fn (int $i): string => 'Imie'.chr(97 + $i).' Nazwisko', range(0, 9)));

        $this->submit(['book[authors]' => $authors]);

        self::assertResponseRedirects('/books/new');
    }

    public function testLostCreationRaceShowsATryAgainErrorInsteadOfA500(): void
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
        ));

        $this->submit([]);

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('.invalid-feedback, .alert-danger, .form-error-message', 'Spróbuj ponownie');
        self::assertInputValueSame('book[title]', 'Dziady');
    }

    public function testBoundaryValuesAreAccepted(): void
    {
        $this->submit([
            'book[publicationYear]' => '1000',
            'book[tags]' => 'a, b, c, d, '.str_repeat('x', 30),
            'book[description]' => str_repeat('x', 2000),
        ]);

        self::assertResponseRedirects('/books/new');
    }

    public function testInvalidSubmitKeepsEnteredValues(): void
    {
        $this->submit(['book[authors]' => 'Homer']);

        self::assertInputValueSame('book[title]', 'Dziady');
        self::assertSelectorTextContains('.invalid-feedback', 'musi mieć imię i nazwisko');
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $this->submit(['book[_token]' => 'tampered-token']);

        self::assertResponseStatusCodeSame(400);
        self::assertCount(0, $this->entityManager()->getRepository(Book::class)->findAll());
    }

    public function testMissingCsrfTokenIsRejected(): void
    {
        $this->client->request('POST', '/books/new', ['book' => [
            'title' => 'Dziady',
            'authors' => 'Adam Mickiewicz',
            'isbn' => '9788373271890',
            'publicationYear' => '1822',
            'category' => 'poetry',
        ]]);

        self::assertResponseStatusCodeSame(400);
        self::assertCount(0, $this->entityManager()->getRepository(Book::class)->findAll());
    }

    public function testDuplicateIsbnWarnsAndSavesNothing(): void
    {
        $this->submit([]);
        $this->submit(['book[title]' => 'Pan Tadeusz', 'book[authors]' => 'Jan Kowalski']);

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('.alert-warning', 'ten sam ISBN');
        self::assertSelectorTextContains('.alert-warning a[href^="/books/"][target="_blank"]', 'Dziady');
        self::assertSelectorExists('button[name="book[confirm_duplicate]"]');
        self::assertInputValueSame('book[title]', 'Pan Tadeusz');
        self::assertInputValueSame('book[authors]', 'Jan Kowalski');
        self::assertCount(1, $this->entityManager()->getRepository(Book::class)->findAll());
    }

    public function testSameTitleAndSharedAuthorWarns(): void
    {
        $this->submit([]);
        $this->submit(['book[title]' => '  dziady ', 'book[authors]' => 'Adam Mickiewicz, Jan Kowalski', 'book[isbn]' => '9788324031283']);

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('.alert-warning', 'ten sam tytuł i autor');
        self::assertSelectorTextNotContains('.alert-warning', 'ten sam ISBN');
        self::assertCount(1, $this->entityManager()->getRepository(Book::class)->findAll());
    }

    public function testIsbn10AgainstStoredIsbn13Warns(): void
    {
        $this->submit([]);
        // ISBN-10 form of 978-83-7327-189-0.
        $this->submit(['book[title]' => 'Inny tytuł', 'book[authors]' => 'Jan Kowalski', 'book[isbn]' => '8373271899']);

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('.alert-warning', 'ten sam ISBN');
    }

    public function testAddAnywaySavesTheDuplicateAndRedirectsWithFlash(): void
    {
        $this->submit([]);
        $this->submit([]);
        self::assertResponseStatusCodeSame(400);

        $this->client->submit($this->client->getCrawler()->selectButton(self::CONFIRM)->form());

        self::assertResponseRedirects('/books/new');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Książka została dodana do kolekcji.');
        self::assertSelectorNotExists('.alert-warning');
        self::assertCount(2, $this->entityManager()->getRepository(Book::class)->findAll());
    }

    public function testSubmittingTheSameDuplicateWithTheNormalButtonWarnsAgain(): void
    {
        $this->submit([]);
        $this->submit([]);
        $this->client->submit($this->client->getCrawler()->selectButton(self::SUBMIT)->form());

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorExists('.alert-warning');
        self::assertCount(1, $this->entityManager()->getRepository(Book::class)->findAll());
    }

    public function testNonDuplicateSavesWithoutWarningOrConfirmButton(): void
    {
        $this->client->request('GET', '/books/new');
        self::assertSelectorNotExists('button[name="book[confirm_duplicate]"]');

        $this->submit([]);
        self::assertResponseRedirects('/books/new');

        $this->client->request('GET', '/books/new');
        self::assertSelectorNotExists('.alert-warning');
        self::assertSelectorNotExists('button[name="book[confirm_duplicate]"]');

        $this->submit(['book[title]' => 'Pan Tadeusz', 'book[isbn]' => '9788324031283']);
        self::assertResponseRedirects('/books/new');
        self::assertCount(2, $this->entityManager()->getRepository(Book::class)->findAll());
    }

    public function testInvalidFormShowsErrorsButNoDuplicateWarning(): void
    {
        $this->submit([]);
        $this->submit(['book[authors]' => 'Homer']);

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorExists('.invalid-feedback');
        self::assertSelectorNotExists('.alert-warning');
        self::assertSelectorNotExists('button[name="book[confirm_duplicate]"]');
    }

    /**
     * @param array<string, string> $overrides
     */
    private function submit(array $overrides): void
    {
        $this->client->request('GET', '/books/new');
        $form = $this->client->getCrawler()->selectButton(self::SUBMIT)->form(array_merge([
            'book[title]' => 'Dziady',
            'book[authors]' => 'Adam Mickiewicz',
            'book[isbn]' => '9788373271890',
            'book[publicationYear]' => '1822',
            'book[category]' => 'poetry',
        ], $overrides));

        $this->client->submit($form);
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em;
    }
}
