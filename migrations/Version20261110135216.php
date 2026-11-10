<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110135216 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create read_book (user, book, rating, read date, optional comment)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE read_book (id INT AUTO_INCREMENT NOT NULL, rating SMALLINT NOT NULL, read_on DATE NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, book_id INT NOT NULL, INDEX IDX_READ_BOOK_BOOK (book_id), UNIQUE INDEX UNIQ_READ_BOOK_USER_BOOK (user_id, book_id), INDEX IDX_4E117F08A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE read_book ADD CONSTRAINT FK_4E117F08A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE read_book ADD CONSTRAINT FK_4E117F0816A2B381 FOREIGN KEY (book_id) REFERENCES book (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE read_book DROP FOREIGN KEY FK_4E117F08A76ED395');
        $this->addSql('ALTER TABLE read_book DROP FOREIGN KEY FK_4E117F0816A2B381');
        $this->addSql('DROP TABLE read_book');
    }
}
