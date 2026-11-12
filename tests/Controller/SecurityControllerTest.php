<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class SecurityControllerTest extends WebTestCase
{
    private const EMAIL = 'anna@example.com';
    private const PASSWORD = 'secret-pass';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $container->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM `user`');
        $container->get(UserManager::class)->create(self::EMAIL, self::PASSWORD);

        $container->get('cache.rate_limiter')->clear();
        $container->get('cache.app')->clear();
    }

    public function testSuccessfulLoginRedirectsHomeAndShowsEmail(): void
    {
        $this->submitLogin(self::EMAIL, self::PASSWORD);

        self::assertResponseRedirects('/books');
        $this->client->followRedirect();
        self::assertSelectorTextContains('nav', self::EMAIL);
        self::assertSelectorExists('a[href*="/logout"]');
    }

    public function testWrongPasswordStaysOnLoginWithGenericError(): void
    {
        $this->submitLogin(self::EMAIL, 'wrong-password');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Nieprawidłowy adres e-mail lub hasło.');
        self::assertInputValueSame('_username', self::EMAIL);
    }

    public function testUnknownEmailShowsSameGenericError(): void
    {
        $this->submitLogin('ghost@example.com', 'whatever-pass');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Nieprawidłowy adres e-mail lub hasło.');
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $this->submitLogin(self::EMAIL, self::PASSWORD, 'tampered-token');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');
        $this->client->request('GET', '/books');
        self::assertSelectorTextNotContains('nav', self::EMAIL);
    }

    public function testMissingCsrfTokenIsRejected(): void
    {
        $this->client->request('GET', '/login');
        $this->client->request('POST', '/login', ['_username' => self::EMAIL, '_password' => self::PASSWORD]);

        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/books');
        self::assertSelectorTextNotContains('nav', self::EMAIL);
    }

    public function testLogoutReturnsToAnonymousHome(): void
    {
        $this->submitLogin(self::EMAIL, self::PASSWORD);
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('_csrf_token=', (string) $crawler->filter('a[href*="/logout"]')->attr('href'));

        $this->client->click($crawler->filter('a[href*="/logout"]')->link());
        self::assertResponseRedirects('/books');
        $this->client->followRedirect();

        self::assertSelectorTextNotContains('nav', self::EMAIL);
        self::assertSelectorExists('a[href="/login"]');
    }

    public function testLoginRedirectsWhenAlreadySignedIn(): void
    {
        $this->submitLogin(self::EMAIL, self::PASSWORD);

        $this->client->request('GET', '/login');

        self::assertResponseRedirects('/books');
    }

    public function testSixthFailedAttemptIsThrottled(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->submitLogin(self::EMAIL, 'wrong-password');
            $this->client->followRedirect();
            self::assertSelectorTextContains('.alert-danger', 'Nieprawidłowy adres e-mail lub hasło.');
        }

        // The window is exhausted: even the correct password is refused now.
        $this->submitLogin(self::EMAIL, self::PASSWORD);
        $this->client->followRedirect();

        self::assertSelectorExists('.alert-danger');
        self::assertSelectorTextNotContains('.alert-danger', 'Nieprawidłowy adres e-mail lub hasło.');
        $this->client->request('GET', '/books');
        self::assertSelectorTextNotContains('nav', self::EMAIL);
    }

    private function submitLogin(string $email, string $password, ?string $csrfToken = null): Crawler
    {
        $this->client->request('GET', '/login');
        $form = $this->client->getCrawler()->selectButton('Zaloguj')->form([
            '_username' => $email,
            '_password' => $password,
        ]);
        if (null !== $csrfToken) {
            $form['_csrf_token'] = $csrfToken;
        }

        return $this->client->submit($form);
    }
}
