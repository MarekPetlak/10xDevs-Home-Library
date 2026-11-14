<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tag')]
#[ORM\UniqueConstraint(name: 'UNIQ_TAG_NAME', columns: ['name'])]
#[ORM\UniqueConstraint(name: 'UNIQ_TAG_SLUG', columns: ['slug'])]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, options: ['collation' => 'utf8mb4_uca1400_as_ci'])]
    private string $name = '';

    /**
     * URL identifier, lowercase ASCII. Same collation as the name on purpose: unique ignoring case,
     * and accent variants of a name get distinct slugs through a numeric suffix (`zolw`, `zolw-2`).
     */
    #[ORM\Column(length: 40, options: ['collation' => 'utf8mb4_uca1400_as_ci'])]
    private string $slug = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }
}
