<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * What the comments block shows for one reader: the e-mail (masked in the template), the rating and the text.
 * It carries no User entity, so no password hash is loaded to render a comment.
 */
final readonly class CommentView
{
    public function __construct(
        public string $email,
        public int $rating,
        public string $comment,
    ) {
    }
}
