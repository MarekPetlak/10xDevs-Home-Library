<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class Version20261114100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tag slug: unique, URL-safe identifier (utf8mb4_uca1400_as_ci), backfilled for existing tags';
    }

    // MariaDB commits implicitly on DDL, so a wrapping transaction would be gone before Doctrine commits it
    // ("There is no active transaction"). Nested transactions are unsafe here too: after the implicit commit a
    // savepoint no longer exists ("SAVEPOINT DOCTRINE_n does not exist"), so the backfill uses none and is
    // instead deterministic and safe to re-run.
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Needs MariaDB 10.10+ (production and CI run 10.11); MySQL and older MariaDB do not know this collation.
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MariaDBPlatform,
            'This migration needs MariaDB 10.10+ (collation utf8mb4_uca1400_as_ci).',
        );

        // Adding the column, then making it NOT NULL and indexing it in postUp(), each rebuild the small `tag`
        // table (ALGORITHM=COPY) and block writes while they run: moments for a home library, so take a dump first.
        // MariaDB DDL cannot be rolled back, so every step is safe to re-run: a failed postUp() leaves the column
        // in place and the next `migrate` carries on instead of failing with "duplicate column".
        if (!$this->slugColumnExists()) {
            $this->addSql('ALTER TABLE tag ADD slug VARCHAR(40) DEFAULT NULL COLLATE `utf8mb4_uca1400_as_ci`');
        }
    }

    private function slugColumnExists(): bool
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tag' AND COLUMN_NAME = 'slug'",
        ) > 0;
    }

    private function slugIndexExists(): bool
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tag' AND INDEX_NAME = 'UNIQ_TAG_SLUG'",
        ) > 0;
    }

    public function postUp(Schema $schema): void
    {
        // Frozen copy of the slug rule (AsciiSlugger 'pl', lowercase, at most 36 characters, `tag` when empty, `-2`, `-3`... on collision),
        // so later changes to App\Service\TagSlugger cannot alter what this old migration does.
        $slugger = new AsciiSlugger('pl');
        $taken = [];
        $tags = $this->connection->fetchAllAssociative('SELECT id, name FROM tag ORDER BY id');
        // No transaction (see isTransactional()): tags are processed in id order, so a re-run after an interruption
        // assigns exactly the same slugs.
        foreach ($tags as $row) {
            // Cut to 36 characters so base plus a "-NNN" suffix fits VARCHAR(40) (transliteration can lengthen names).
            $base = rtrim(substr(strtolower($slugger->slug((string) $row['name'])->toString()), 0, 36), '-');
            if ('' === $base) {
                $base = 'tag';
            }
            $slug = $base;
            // Slugs are lowercase ASCII, so an in-memory lookup matches the case-insensitive unique index.
            for ($suffix = 2; isset($taken[$slug]); ++$suffix) {
                $slug = $base.'-'.$suffix;
            }
            $taken[$slug] = true;
            $this->connection->executeStatement('UPDATE tag SET slug = ? WHERE id = ?', [$slug, $row['id']]);
        }

        // Both statements are no-ops when a previous, interrupted run already applied them.
        $this->connection->executeStatement('ALTER TABLE tag CHANGE slug slug VARCHAR(40) NOT NULL COLLATE `utf8mb4_uca1400_as_ci`');
        if (!$this->slugIndexExists()) {
            $this->connection->executeStatement('CREATE UNIQUE INDEX UNIQ_TAG_SLUG ON tag (slug)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_TAG_SLUG ON tag');
        $this->addSql('ALTER TABLE tag DROP slug');
    }
}
