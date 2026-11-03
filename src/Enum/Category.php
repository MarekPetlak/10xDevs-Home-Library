<?php

declare(strict_types=1);

namespace App\Enum;

enum Category: string
{
    case Novel = 'novel';
    case Crime = 'crime';
    case Fantasy = 'fantasy';
    case NonFiction = 'non-fiction';
    case Biography = 'biography';
    case History = 'history';
    case Science = 'science';
    case SelfHelp = 'self-help';
    case Children = 'children';
    case Poetry = 'poetry';
    case Other = 'other';

    public function getTranslationKey(): string
    {
        return 'book.category.'.$this->value;
    }
}
