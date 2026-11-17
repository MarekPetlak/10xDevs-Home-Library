<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117201022 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Book updated_at: when the entry was last edited (null = never edited, no backfill)';
    }

    // MariaDB commits implicitly on DDL, so a wrapping transaction would be gone before Doctrine commits it.
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MariaDBPlatform,
            'This migration is written and verified for MariaDB (production and CI run 10.11).',
        );

        // A nullable ADD COLUMN without a default expression is an instant, metadata-only operation on
        // MariaDB 10.3+ (ALGORITHM=INSTANT, appended as the last column): no table rebuild and no long lock
        // on the populated `book` table. Existing rows keep NULL, meaning "never edited".
        $this->addSql('ALTER TABLE book ADD updated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Dropping a column rebuilds the `book` table (ALGORITHM=COPY on MariaDB 10.11) and blocks writes
        // while it runs: moments for a home library.
        $this->addSql('ALTER TABLE book DROP updated_at');
    }
}
