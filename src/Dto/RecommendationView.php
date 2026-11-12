<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Category;

/**
 * What a recommendation card shows for one book: title, category and the average rating with its count.
 * It carries no Book entity, so rendering the cards triggers no extra queries.
 */
final readonly class RecommendationView
{
    public Category $category;
    public float $averageRating;
    public int $ratingCount;

    public function __construct(
        public int $bookId,
        public string $title,
        Category|string $category,
        float|int|string $averageRating,
        int|string $ratingCount,
    ) {
        $this->category = $category instanceof Category ? $category : Category::from($category);
        $this->averageRating = (float) $averageRating;
        $this->ratingCount = (int) $ratingCount;
    }
}
