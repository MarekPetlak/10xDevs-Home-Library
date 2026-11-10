<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

final class PasswordPrompt
{
    public function ask(SymfonyStyle $io, string $label, string $repeatLabel): ?string
    {
        $question = (new Question($label))->setHidden(true)->setHiddenFallback(false);
        $confirmation = (new Question($repeatLabel))->setHidden(true)->setHiddenFallback(false);

        $password = (string) $io->askQuestion($question);
        $repeated = (string) $io->askQuestion($confirmation);

        if ($password !== $repeated) {
            $io->error('The passwords do not match.');

            return null;
        }

        return $password;
    }
}
