<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuthorRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AuthorRepository::class)]
#[ORM\Table(name: 'author')]
#[ORM\UniqueConstraint(name: 'UNIQ_AUTHOR_NAME', columns: ['first_name', 'last_name'])]
class Author
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, options: ['collation' => 'utf8mb4_unicode_ci'])]
    #[Assert\NotBlank]
    private string $firstName = '';

    #[ORM\Column(length: 150, options: ['collation' => 'utf8mb4_unicode_ci'])]
    #[Assert\NotBlank]
    private string $lastName = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getFullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }
}
