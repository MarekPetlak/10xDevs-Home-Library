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

    public function up(Schema $schema): void
    {
        // Needs MariaDB 10.10+ (production and CI run 10.11); MySQL and older MariaDB do not know this collation.
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MariaDBPlatform,
            'This migration needs MariaDB 10.10+ (collation utf8mb4_uca1400_as_ci).',
        );

        // Adding the column, then making it NOT NULL and indexing it in postUp(), each rebuild the small `tag`
        // table (ALGORITHM=COPY) and block writes while they run: moments for a home library, so take a dump first.
        $this->addSql('ALTER TABLE tag ADD slug VARCHAR(40) DEFAULT NULL COLLATE `utf8mb4_uca1400_as_ci`');
    }

    public function postUp(Schema $schema): void
    {
        // Frozen copy of the slug rule (AsciiSlugger 'pl', lowercase, `tag` when empty, `-2`, `-3`... on collision),
        // so later changes to App\Service\TagSlugger cannot alter what this old migration does.
        $slugger = new AsciiSlugger('pl');
        $taken = [];
        $tags = $this->connection->fetchAllAssociative('SELECT id, name FROM tag ORDER BY id');
        foreach ($tags as $row) {
            $base = strtolower($slugger->slug((string) $row['name'])->toString());
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

        $this->connection->executeStatement('ALTER TABLE tag CHANGE slug slug VARCHAR(40) NOT NULL COLLATE `utf8mb4_uca1400_as_ci`');
        $this->connection->executeStatement('CREATE UNIQUE INDEX UNIQ_TAG_SLUG ON tag (slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_TAG_SLUG ON tag');
        $this->addSql('ALTER TABLE tag DROP slug');
    }
}
