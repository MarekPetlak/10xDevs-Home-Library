<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\TagRepository;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Turns a tag name into a free, URL-safe slug: ASCII, lowercase, `tag` when nothing is left, `-2`, `-3`... on collisions.
 */
final class TagSlugger
{
    private const FALLBACK = 'tag';
    // The slug column holds 40 characters; transliteration can lengthen a name ("ß" -> "ss", CJK -> pinyin),
    // so the base is cut to leave room for a "-NNN" collision suffix.
    private const MAX_BASE_LENGTH = 36;

    public function __construct(private readonly TagRepository $tagRepository)
    {
    }

    public function slugFor(string $name): string
    {
        $base = rtrim(substr(strtolower((new AsciiSlugger('pl'))->slug($name)->toString()), 0, self::MAX_BASE_LENGTH), '-');
        if ('' === $base) {
            $base = self::FALLBACK;
        }

        $slug = $base;
        for ($suffix = 2; $this->tagRepository->slugExists($slug); ++$suffix) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
