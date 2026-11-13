<?php

declare(strict_types=1);

namespace App\DataFixtures;

/**
 * Constants shared by the UI screenshot fixtures and the Playwright spec (e2e/).
 * The data is deterministic on purpose: screenshots are compared against committed baselines.
 */
final class UiFixtureData
{
    public const GROUP_CATALOGUE = 'books-ui-catalogue';
    public const GROUP_EMPTY = 'books-ui-empty';

    public const READER_EMAIL = 'anna@example.com';
    public const OTHER_EMAIL = 'ola@example.com';
    public const PASSWORD = 'secret-pass';

    private function __construct()
    {
    }
}
