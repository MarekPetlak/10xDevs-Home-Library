<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Book deleted_at: when the entry was soft-deleted (null = active, no backfill) and its index';
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

        // The statements use IF NOT EXISTS / IF EXISTS (MariaDB 10.1.4+) because this migration is not transactional:
        // if CREATE INDEX fails after the column was added, a re-run must not stop at "duplicate column".
        // A nullable ADD COLUMN without a default expression is an instant, metadata-only operation on
        // MariaDB 10.3+ (ALGORITHM=INSTANT, appended as the last column): no table rebuild and no long lock
        // on the populated `book` table. Existing rows keep NULL, meaning "active".
        $this->addSql('ALTER TABLE book ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL');

        // On MariaDB 10.11 this is an in-place secondary-index build (no table copy): writes stay allowed,
        // the server is only briefly busier while the index is built.
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_BOOK_DELETED_AT ON book (deleted_at)');
    }

    public function down(Schema $schema): void
    {
        // Drop the index first, then the column. Dropping the column rebuilds the `book` table
        // (ALGORITHM=COPY on MariaDB 10.11) and blocks writes while it runs: moments for a home library.
        $this->addSql('DROP INDEX IF EXISTS IDX_BOOK_DELETED_AT ON book');
        $this->addSql('ALTER TABLE book DROP COLUMN IF EXISTS deleted_at');
    }
}
