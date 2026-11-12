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

        usort($rows, static fn (array $a, array $b): int => [$b['count'], $b['lastReadOn']->format('Y-m-d'), array_search($a['category'], Category::cases(), true)]
            <=> [$a['count'], $a['lastReadOn']->format('Y-m-d'), array_search($b['category'], Category::cases(), true)]);

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
}
