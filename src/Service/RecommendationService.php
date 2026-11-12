<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\MarkRead;
use App\Dto\RecommendationView;
use App\Entity\User;
use App\Enum\Category;
use App\Repository\BookRepository;
use App\Repository\ReadBookRepository;

/**
 * Recommends up to three books: the best-rated unread ones from the category the user read most
 * in the last 12 months, topped up from their second most-read category.
 */
final class RecommendationService
{
    private const LIMIT = 3;
    private const WINDOW = '-12 months';

    public function __construct(
        private readonly BookRepository $bookRepository,
        private readonly ReadBookRepository $readBookRepository,
    ) {
    }

    /**
     * @return list<RecommendationView>
     */
    public function forUser(User $user): array
    {
        $since = MarkRead::today()->modify(self::WINDOW);
        $rows = $this->readBookRepository->countByCategorySince($user, $since);

        usort($rows, self::compareCategoryRows(...));

        $recommendations = [];
        foreach (array_slice($rows, 0, 2) as $row) {
            $missing = self::LIMIT - count($recommendations);
            if ($missing <= 0) {
                break;
            }
            array_push($recommendations, ...$this->bookRepository->findRecommendationCandidates($user, $row['category'], $missing));
        }

        return $recommendations;
    }

    /**
     * Most reads first, then the most recently read day, then the order of the Category enum.
     *
     * @param array{category: Category, count: int, lastReadOn: \DateTimeImmutable} $a
     * @param array{category: Category, count: int, lastReadOn: \DateTimeImmutable} $b
     */
    private static function compareCategoryRows(array $a, array $b): int
    {
        return [$b['count'], $b['lastReadOn']->format('Y-m-d'), self::enumRank($a['category'])]
            <=> [$a['count'], $a['lastReadOn']->format('Y-m-d'), self::enumRank($b['category'])];
    }

    private static function enumRank(Category $category): int
    {
        return (int) array_search($category, Category::cases(), true);
    }
}
