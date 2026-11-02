<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testHomeIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
    }

    public function testLayoutIsPolishAndLoadsBootstrap(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertSelectorExists('html[lang="pl"]');
        self::assertSelectorExists('link[rel="stylesheet"][href*="bootstrap"]');
    }
}
