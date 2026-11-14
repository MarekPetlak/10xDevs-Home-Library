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

    public function testTransliterationThatLengthensANameStillFitsTheColumn(): void
    {
        $first = $this->createTag(str_repeat('ß', 30)); // transliterates to 60 characters
        $second = $this->createTag(str_repeat('ß', 29).'S');

        self::assertSame(str_repeat('ss', 18), $first->getSlug());
        self::assertSame(str_repeat('ss', 18).'-2', $second->getSlug());
        self::assertLessThanOrEqual(40, \strlen($second->getSlug()));
    }

    public function testCutSlugNeverEndsWithAHyphen(): void
    {
        $slug = $this->slugger->slugFor(str_repeat('中', 10)); // "zhong-zhong-..." is longer than the limit

        self::assertLessThanOrEqual(36, \strlen($slug));
        self::assertStringEndsNotWith('-', $slug);
        self::assertStringStartsWith('zhong-zhong', $slug);
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
