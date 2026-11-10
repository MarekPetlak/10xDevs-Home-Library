<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110161533 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Author and tag names: accent-sensitive, case-insensitive collation (uca1400_as_ci)';
    }

    public function up(Schema $schema): void
    {
        // utf8mb4_unicode_ci also ignored accents, so "José" and "Jose" collided on the unique indexes.
        // Needs MariaDB 10.10+ (production and CI run 10.11). Existing rows cannot collide: the old
        // collation was the coarser one, so no two stored names are equal under the new one either.
        $this->addSql('ALTER TABLE author CHANGE first_name first_name VARCHAR(100) NOT NULL COLLATE `utf8mb4_uca1400_as_ci`, CHANGE last_name last_name VARCHAR(150) NOT NULL COLLATE `utf8mb4_uca1400_as_ci`');
        $this->addSql('ALTER TABLE tag CHANGE name name VARCHAR(30) NOT NULL COLLATE `utf8mb4_uca1400_as_ci`');
    }

    public function down(Schema $schema): void
    {
        // Fails with a duplicate-key error if names differing only in accents were added after up().
        $this->addSql('ALTER TABLE author CHANGE first_name first_name VARCHAR(100) NOT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE last_name last_name VARCHAR(150) NOT NULL COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE tag CHANGE name name VARCHAR(30) NOT NULL COLLATE `utf8mb4_unicode_ci`');
    }
}
