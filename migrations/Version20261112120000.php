<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Book title: accent-sensitive, case-insensitive collation (uca1400_as_ci), like author and tag names';
    }

    public function up(Schema $schema): void
    {
        // The column had no collation of its own, so it followed the server default (utf8mb4_general_ci,
        // accent-insensitive). Needs MariaDB 10.10+ (production and CI run 10.11). Titles carry no
        // unique index, so the change cannot collide.
        $this->addSql('ALTER TABLE book CHANGE title title VARCHAR(255) NOT NULL COLLATE `utf8mb4_uca1400_as_ci`');
    }

    public function down(Schema $schema): void
    {
        // Back to the table's default collation.
        $this->addSql('ALTER TABLE book CHANGE title title VARCHAR(255) NOT NULL');
    }
}
