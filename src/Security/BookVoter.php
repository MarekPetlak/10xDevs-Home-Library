<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Book;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The single rule for who may change a book entry: only the user who added it.
 *
 * @extends Voter<string, Book>
 */
final class BookVoter extends Voter
{
    public const EDIT = 'BOOK_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof Book;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User
            && null !== $user->getId()
            && $user->getId() === $subject->getAddedBy()->getId();
    }
}
