<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Clock\Clock;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form input for marking a book as read; the single owner of its validation rules.
 */
final class MarkRead
{
    public const TIME_ZONE = 'Europe/Warsaw';
    public const MAX_COMMENT_LENGTH = 2000;

    #[Assert\NotNull(message: 'reading.rating.required')]
    #[Assert\Range(notInRangeMessage: 'reading.rating.invalid', min: 1, max: 5)]
    public ?int $rating = null;

    #[Assert\NotNull(message: 'reading.date.required')]
    public ?\DateTimeImmutable $readOn = null;

    #[Assert\Length(max: self::MAX_COMMENT_LENGTH, maxMessage: 'reading.comment.too_long')]
    public ?string $comment = null;

    /** The current calendar day in the time zone of the library, as midnight of that day. */
    public static function today(): \DateTimeImmutable
    {
        return Clock::get()->now()->setTimezone(new \DateTimeZone(self::TIME_ZONE))->setTime(0, 0);
    }

    #[Assert\Callback]
    public function validateReadOn(ExecutionContextInterface $context): void
    {
        if (null !== $this->readOn && $this->readOn->format('Y-m-d') > self::today()->format('Y-m-d')) {
            $context->buildViolation('reading.date.future')->atPath('readOn')->addViolation();
        }
    }
}
