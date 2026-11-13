<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Service\UserManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * The two demo accounts, present in both UI fixture groups (the "empty library" state still needs a login).
 */
final class UiUserFixtures extends Fixture implements FixtureGroupInterface
{
    public function __construct(private readonly UserManager $userManager)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $this->userManager->create(UiFixtureData::READER_EMAIL, UiFixtureData::PASSWORD);
        $this->userManager->create(UiFixtureData::OTHER_EMAIL, UiFixtureData::PASSWORD);
    }

    public static function getGroups(): array
    {
        return [UiFixtureData::GROUP_CATALOGUE, UiFixtureData::GROUP_EMPTY];
    }
}
