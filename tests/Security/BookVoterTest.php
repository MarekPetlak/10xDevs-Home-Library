<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Book;
use App\Entity\User;
use App\Security\BookVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class BookVoterTest extends TestCase
{
    public function testOwnerMayEdit(): void
    {
        $owner = $this->user(1);
        $book = (new Book())->setAddedBy($owner);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->token($owner), $book));
    }

    public function testAnotherUserWithTheSameIdValueIsTheSamePerson(): void
    {
        // A freshly loaded User instance for the same row is still the owner: compared by id, not identity.
        $book = (new Book())->setAddedBy($this->user(1));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->token($this->user(1)), $book));
    }

    public function testOtherUserMayNotEdit(): void
    {
        $book = (new Book())->setAddedBy($this->user(1));

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->token($this->user(2)), $book));
    }

    public function testAnonymousMayNotEdit(): void
    {
        $book = (new Book())->setAddedBy($this->user(1));

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new NullToken(), $book));
    }

    public function testUnsavedUserIsNeverTheOwner(): void
    {
        $unsaved = $this->user(null);
        $book = (new Book())->setAddedBy($unsaved);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->token($unsaved), $book));
    }

    public function testAbstainsOnOtherAttributesAndSubjects(): void
    {
        $voter = new BookVoter();
        $token = $this->token($this->user(1));

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new Book(), ['SOMETHING_ELSE']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new \stdClass(), [BookVoter::EDIT]));
    }

    private function vote(TokenInterface $token, Book $book): int
    {
        return (new BookVoter())->vote($token, $book, [BookVoter::EDIT]);
    }

    private function token(User $user): TokenInterface
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private function user(?int $id): User
    {
        $user = (new User())->setEmail('user'.($id ?? 'x').'@example.com');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
