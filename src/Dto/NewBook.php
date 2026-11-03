<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Category;
use App\Service\NameList;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form input for adding a book; the single owner of its validation rules.
 */
final class NewBook
{
    public const MAX_TAGS = 5;
    public const MAX_TAG_LENGTH = 30;
    public const MAX_FIRST_NAME_LENGTH = 100;
    public const MAX_LAST_NAME_LENGTH = 150;
    public const MIN_YEAR = 1000;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $title = null;

    /** Comma-separated "first last" entries. */
    public ?string $authors = null;

    #[Assert\NotBlank]
    #[Assert\Isbn]
    public ?string $isbn = null;

    #[Assert\NotNull]
    public ?int $publicationYear = null;

    #[Assert\NotNull]
    public ?Category $category = null;

    /** Comma-separated tag names. */
    public ?string $tags = null;

    #[Assert\Length(max: 2000)]
    public ?string $description = null;

    #[Assert\Callback]
    public function validateAuthors(ExecutionContextInterface $context): void
    {
        $authors = NameList::parse($this->authors ?? '');

        if ([] === $authors) {
            $context->buildViolation('book.authors.required')->atPath('authors')->addViolation();

            return;
        }

        foreach ($authors as $author) {
            $name = NameList::splitAuthor($author);

            if ('' === $name['firstName']) {
                $context->buildViolation('book.authors.single_word')
                    ->setParameter('{{ author }}', $author)
                    ->atPath('authors')
                    ->addViolation();

                return;
            }

            if (mb_strlen($name['firstName']) > self::MAX_FIRST_NAME_LENGTH || mb_strlen($name['lastName']) > self::MAX_LAST_NAME_LENGTH) {
                $context->buildViolation('book.authors.too_long')
                    ->setParameter('{{ author }}', $author)
                    ->atPath('authors')
                    ->addViolation();

                return;
            }
        }
    }

    #[Assert\Callback]
    public function validateTags(ExecutionContextInterface $context): void
    {
        $tags = NameList::parse($this->tags ?? '');

        if (count($tags) > self::MAX_TAGS) {
            $context->buildViolation('book.tags.too_many')
                ->setParameter('{{ limit }}', (string) self::MAX_TAGS)
                ->atPath('tags')
                ->addViolation();

            return;
        }

        foreach ($tags as $tag) {
            if (mb_strlen($tag) > self::MAX_TAG_LENGTH) {
                $context->buildViolation('book.tags.too_long')
                    ->setParameter('{{ tag }}', $tag)
                    ->setParameter('{{ limit }}', (string) self::MAX_TAG_LENGTH)
                    ->atPath('tags')
                    ->addViolation();

                return;
            }
        }
    }

    #[Assert\Callback]
    public function validateYear(ExecutionContextInterface $context): void
    {
        if (null === $this->publicationYear) {
            return;
        }

        $max = (int) date('Y') + 1;
        if ($this->publicationYear < self::MIN_YEAR || $this->publicationYear > $max) {
            $context->buildViolation('book.year.invalid')
                ->setParameter('{{ min }}', (string) self::MIN_YEAR)
                ->setParameter('{{ max }}', (string) $max)
                ->atPath('publicationYear')
                ->addViolation();
        }
    }
}
