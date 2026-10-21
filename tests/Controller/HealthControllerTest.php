<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthzReturnsOkWithoutAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/healthz');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('ok', $client->getResponse()->getContent());
    }
}
