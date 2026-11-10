<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns an e-mail address into the obfuscated form shown next to a reader's comment.
 * It only reduces exposure (first letter and domain stay visible); it does not anonymise the author.
 */
final class EmailMasker
{
    public function mask(string $email): string
    {
        $at = strrpos($email, '@');
        if (false === $at) {
            return '***';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);

        if (mb_strlen($local) <= 1) {
            return '*'.$domain;
        }

        return mb_substr($local, 0, 1).'***'.$domain;
    }
}
