<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ErrorPageTest extends WebTestCase
{
    public function testNotFoundIsRenderedInTheAppLayoutForAnonymousVisitors(): void
    {
        // debug: false makes Symfony render templates/bundles/TwigBundle/Exception/error.html.twig, as in production.
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/no-such-page');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Nie znaleziono strony');
        self::assertSelectorExists('nav.navbar');
        self::assertSelectorExists('main a.btn-primary[href="/books"]');
    }

    public function testServerErrorTemplateRendersInTheAppLayoutWithoutAUser(): void
    {
        $client = static::createClient(['debug' => false]);
        $client->request('GET', '/login');
        $container = static::getContainer();
        // A real 500 is hard to provoke; render the same template TwigBundle uses, inside a request without a signed-in user.
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);

        $html = $container->get('twig')->render('@Twig/Exception/error.html.twig', ['status_code' => 500, 'status_text' => 'Internal Server Error']);

        self::assertStringContainsString('Coś poszło nie tak', $html);
        self::assertStringContainsString('navbar', $html);
        self::assertStringContainsString('href="/books"', $html);
    }
}
