<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\NewBook;
use App\Entity\Book;
use App\Entity\User;
use App\Enum\Category;
use App\Exception\BookConflictException;
use App\Repository\AuthorRepository;
use App\Repository\TagRepository;
use App\Service\BookManager;
use App\Service\DomainFailureLogger;
use App\Service\TagSlugger;
use App\Service\UserManager;
use App\Tests\Support\RecordingLogger;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Findings 1 and 3 of the observability audit: a lost add/edit race and an invalid CSRF token reach the error
 * stream as one `error` record with ids only, while the response the user sees stays the same.
 */
final class DomainFailureLoggingTest extends WebTestCase
{
    private const EMAIL = 'anna@example.com';
    private const PASSWORD = 'secret-pass';

    private KernelBrowser $client;
    private User $user;
    private Book $book;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Without this the client reboots the kernel between requests and drops the replaced services.
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->user = $container->get(UserManager::class)->create(self::EMAIL, self::PASSWORD);
        $this->client->loginUser($this->user);

        $input = new NewBook();
        $input->title = 'Dziady';
        $input->authors = 'Adam Mickiewicz';
        $input->isbn = '9788373271890';
        $input->publicationYear = 1822;
        $input->category = Category::Poetry;
        $input->tags = '';
        // Built by hand: fetching the BookManager service would initialize it and stop a test from replacing it.
        $this->book = (new BookManager(
            $container->get(EntityManagerInterface::class),
            $container->get(AuthorRepository::class),
            $container->get(TagRepository::class),
            $container->get(TagSlugger::class),
        ))->add($input, $this->user);

        $this->logger = new RecordingLogger();
        $container->set(DomainFailureLogger::class, new DomainFailureLogger($this->logger));
    }

    public function testLostRaceOnAddLogsOneErrorAndKeepsTheResponse(): void
    {
        $this->replaceBookManagerWithALosingOne();

        $this->client->request('GET', '/books/new');
        $form = $this->client->getCrawler()->selectButton('Zapisz książkę')->form([
            'book[title]' => 'Pan Tadeusz',
            'book[authors]' => 'Adam Mickiewicz',
            'book[isbn]' => '9788324031283',
            'book[publicationYear]' => '1834',
            'book[category]' => 'poetry',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('.invalid-feedback, .alert-danger, .form-error-message', 'Spróbuj ponownie');
        $context = $this->assertOneErrorRecord(['action', 'book_id', 'exception', 'user_id']);
        self::assertSame('add', $context['action']);
        self::assertNull($context['book_id']);
        self::assertInstanceOf(BookConflictException::class, $context['exception']);
        self::assertInstanceOf(UniqueConstraintViolationException::class, $context['exception']->getPrevious());
    }

    public function testLostRaceOnEditLogsOneErrorAndKeepsTheResponse(): void
    {
        $this->replaceBookManagerWithALosingOne();

        $this->client->request('GET', '/books/'.$this->book->getId().'/edit');
        $form = $this->client->getCrawler()->selectButton('Zapisz książkę')->form(['book[title]' => 'Pan Tadeusz']);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('.invalid-feedback, .alert-danger, .form-error-message', 'Spróbuj ponownie');
        $context = $this->assertOneErrorRecord(['action', 'book_id', 'exception', 'user_id']);
        self::assertSame('edit', $context['action']);
        self::assertSame($this->book->getId(), $context['book_id']);
        self::assertInstanceOf(UniqueConstraintViolationException::class, $context['exception']->getPrevious());
    }

    public function testInvalidCsrfTokenOnDeleteLogsOneErrorAndKeepsTheResponse(): void
    {
        $this->client->request('POST', '/books/'.$this->book->getId().'/delete', ['_token' => 'tampered-token']);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Odśwież stronę', (string) $this->client->getResponse()->getContent());
        $context = $this->assertOneErrorRecord(['book_id', 'route', 'user_id']);
        self::assertSame('book_delete', $context['route']);
        self::assertSame($this->book->getId(), $context['book_id']);
    }

    public function testInvalidCsrfTokenOnUnReadLogsOneErrorAndKeepsTheResponse(): void
    {
        $this->client->request('POST', '/books/'.$this->book->getId().'/read/remove', ['_token' => 'tampered-token']);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Odśwież stronę', (string) $this->client->getResponse()->getContent());
        $context = $this->assertOneErrorRecord(['book_id', 'route', 'user_id']);
        self::assertSame('book_read_remove', $context['route']);
        self::assertSame($this->book->getId(), $context['book_id']);
    }

    private function replaceBookManagerWithALosingOne(): void
    {
        $container = static::getContainer();
        $failing = $this->createStub(EntityManagerInterface::class);
        $failing->method('wrapInTransaction')->willThrowException(
            new UniqueConstraintViolationException($this->createStub(DriverException::class), null),
        );
        $container->set(BookManager::class, new BookManager(
            $failing,
            $container->get(AuthorRepository::class),
            $container->get(TagRepository::class),
            $container->get(TagSlugger::class),
        ));
    }

    /**
     * @param list<string> $expectedKeys sorted
     *
     * @return array<string, mixed>
     */
    private function assertOneErrorRecord(array $expectedKeys): array
    {
        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame('error', $record['level']);

        $keys = array_keys($record['context']);
        sort($keys);
        self::assertSame($expectedKeys, $keys);
        self::assertSame($this->user->getId(), $record['context']['user_id']);

        // Only ids and names: no e-mail, password or token anywhere in the record.
        $dump = $record['message'].json_encode(
            array_diff_key($record['context'], ['exception' => true]),
            JSON_THROW_ON_ERROR,
        );
        self::assertStringNotContainsString(self::EMAIL, $dump);
        self::assertStringNotContainsString('@', $dump);
        self::assertStringNotContainsString(self::PASSWORD, $dump);
        self::assertStringNotContainsString('tampered-token', $dump);

        return $record['context'];
    }
}
