<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
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
        // Needs MariaDB 10.10+ (production and CI run 10.11); MySQL and older MariaDB do not know this collation.
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MariaDBPlatform,
            'This migration needs MariaDB 10.10+ (collation utf8mb4_uca1400_as_ci).',
        );

        // The column had no collation of its own, so it followed the server default (utf8mb4_general_ci,
        // accent-insensitive). Changing a collation rebuilds the whole table (ALGORITHM=COPY) and blocks
        // writes while it runs: seconds for a home library, so run it outside busy hours and take a dump
        // first. Titles carry no unique index, so the change cannot collide.
        $this->addSql('ALTER TABLE book CHANGE title title VARCHAR(255) NOT NULL COLLATE `utf8mb4_uca1400_as_ci`');
    }

    public function down(Schema $schema): void
    {
        // Back to the table's default collation (rebuilds the table the same way).
        $this->addSql('ALTER TABLE book CHANGE title title VARCHAR(255) NOT NULL');
    }
}
