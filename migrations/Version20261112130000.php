<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Book list ordering: index on (created_at, id) for ORDER BY created_at DESC, id DESC';
    }

    public function up(Schema $schema): void
    {
        // InnoDB builds a secondary index online (no table copy, writes continue), on any MariaDB or MySQL version.
        $this->addSql('CREATE INDEX IDX_BOOK_CREATED_AT_ID ON book (created_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_BOOK_CREATED_AT_ID ON book');
    }
}
