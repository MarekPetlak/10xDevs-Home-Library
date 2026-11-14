<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The ISBN-10 and ISBN-13 spellings of one book. Input is already validated and normalised
 * (no spaces or hyphens, upper case).
 */
final class IsbnForms
{
    /**
     * The input first, then its other form when one exists (979-prefixed ISBN-13 has no ISBN-10).
     *
     * @return list<string>
     */
    public static function of(string $isbn): array
    {
        $forms = [$isbn];

        if (10 === strlen($isbn)) {
            $forms[] = self::toIsbn13($isbn);
        } elseif (13 === strlen($isbn) && str_starts_with($isbn, '978')) {
            $forms[] = self::toIsbn10($isbn);
        }

        return $forms;
    }

    private static function toIsbn13(string $isbn10): string
    {
        $body = '978'.substr($isbn10, 0, 9);
        $sum = 0;
        foreach (str_split($body) as $i => $digit) {
            $sum += (int) $digit * (0 === $i % 2 ? 1 : 3);
        }

        return $body.((10 - $sum % 10) % 10);
    }

    private static function toIsbn10(string $isbn13): string
    {
        $body = substr($isbn13, 3, 9);
        $sum = 0;
        foreach (str_split($body) as $i => $digit) {
            $sum += (int) $digit * (10 - $i);
        }
        $check = (11 - $sum % 11) % 11;

        return $body.(10 === $check ? 'X' : (string) $check);
    }
}
