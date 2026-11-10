<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\EmailMasker;
use Twig\Attribute\AsTwigFilter;

/**
 * The only way an e-mail address reaches a template next to shared content.
 */
final class EmailMaskExtension
{
    public function __construct(private readonly EmailMasker $emailMasker)
    {
    }

    #[AsTwigFilter('mask_email')]
    public function maskEmail(string $email): string
    {
        return $this->emailMasker->mask($email);
    }
}
