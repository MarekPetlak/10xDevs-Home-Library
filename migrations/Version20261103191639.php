<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103191639 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create book, author, tag and the book_author / book_tag join tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE author (id INT AUTO_INCREMENT NOT NULL, first_name VARCHAR(100) NOT NULL COLLATE `utf8mb4_unicode_ci`, last_name VARCHAR(150) NOT NULL COLLATE `utf8mb4_unicode_ci`, UNIQUE INDEX UNIQ_AUTHOR_NAME (first_name, last_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE book (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, isbn VARCHAR(13) NOT NULL, publication_year SMALLINT NOT NULL, description LONGTEXT DEFAULT NULL, category VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, added_by_id INT NOT NULL, INDEX IDX_BOOK_ISBN (isbn), INDEX IDX_CBE5A33155B127A4 (added_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE book_author (book_id INT NOT NULL, author_id INT NOT NULL, INDEX IDX_9478D34516A2B381 (book_id), INDEX IDX_9478D345F675F31B (author_id), PRIMARY KEY (book_id, author_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE book_tag (book_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_F2F4CE1516A2B381 (book_id), INDEX IDX_F2F4CE15BAD26311 (tag_id), PRIMARY KEY (book_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tag (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(30) NOT NULL COLLATE `utf8mb4_unicode_ci`, UNIQUE INDEX UNIQ_TAG_NAME (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE book ADD CONSTRAINT FK_CBE5A33155B127A4 FOREIGN KEY (added_by_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE book_author ADD CONSTRAINT FK_9478D34516A2B381 FOREIGN KEY (book_id) REFERENCES book (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE book_author ADD CONSTRAINT FK_9478D345F675F31B FOREIGN KEY (author_id) REFERENCES author (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE book_tag ADD CONSTRAINT FK_F2F4CE1516A2B381 FOREIGN KEY (book_id) REFERENCES book (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE book_tag ADD CONSTRAINT FK_F2F4CE15BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE book_author');
        $this->addSql('DROP TABLE book_tag');
        $this->addSql('DROP TABLE book');
        $this->addSql('DROP TABLE author');
        $this->addSql('DROP TABLE tag');
    }
}
