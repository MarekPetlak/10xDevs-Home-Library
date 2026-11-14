<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tag;
use App\Repository\TagRepository;
use App\Service\TagSlugger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TagSluggerTest extends KernelTestCase
{
    private TagSlugger $slugger;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->slugger = $container->get(TagSlugger::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testSpacesAndCaseBecomeALowercaseHyphenatedSlug(): void
    {
        self::assertSame('science-fiction', $this->slugger->slugFor('Science Fiction'));
    }

    public function testPolishLettersAreTransliterated(): void
    {
        self::assertSame('zolw', $this->slugger->slugFor('Żółw'));
    }

    public function testCaseAndAccentVariantsGetDistinctSlugs(): void
    {
        $first = $this->createTag('Zolw');
        $second = $this->createTag('żółw');

        self::assertSame('zolw', $first->getSlug());
        self::assertSame('zolw-2', $second->getSlug());
        self::assertSame('zolw-3', $this->slugger->slugFor('ŻÓŁW'));
    }

    public function testNameOfOnlySymbolsFallsBackToTagThenNumbers(): void
    {
        self::assertSame('tag', $this->createTag('!!!')->getSlug());
        self::assertSame('tag-2', $this->createTag('???')->getSlug());
    }

    public function testSlugsDoNotCollideCaseInsensitively(): void
    {
        $this->createTag('Klasyka');

        self::assertSame('klasyka-2', $this->slugger->slugFor('KLASYKA'));
    }

    public function testRepositoryFindsATagBySlug(): void
    {
        $tag = $this->createTag('Science Fiction');
        $repository = self::getContainer()->get(TagRepository::class);

        self::assertSame($tag->getId(), $repository->findOneBySlug('science-fiction')?->getId());
        self::assertNull($repository->findOneBySlug('missing'));
    }

    private function createTag(string $name): Tag
    {
        $tag = (new Tag())->setName($name)->setSlug($this->slugger->slugFor($name));
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }
}
