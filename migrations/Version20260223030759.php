<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260223030759 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE storage_node (id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, last_modified TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, file_size INT DEFAULT NULL, dir_count INT DEFAULT NULL, file_count INT DEFAULT NULL, status VARCHAR(255) NOT NULL, meta JSON DEFAULT NULL, path VARCHAR(255) DEFAULT NULL, is_dir BOOLEAN NOT NULL, is_public BOOLEAN NOT NULL, parent_id VARCHAR DEFAULT NULL, storage_id VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8C522350727ACA70 ON storage_node (parent_id)');
        $this->addSql('CREATE INDEX IDX_8C5223505CC5DB90 ON storage_node (storage_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_STORAGE_STORAGE_PATH ON storage_node (storage_id, path)');
        $this->addSql('CREATE TABLE storage_storage (adapter VARCHAR(255) DEFAULT NULL, root VARCHAR(255) DEFAULT NULL, id VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE storage_node ADD CONSTRAINT FK_8C522350727ACA70 FOREIGN KEY (parent_id) REFERENCES storage_node (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE storage_node ADD CONSTRAINT FK_8C5223505CC5DB90 FOREIGN KEY (storage_id) REFERENCES storage_storage (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE asset ADD content_hash VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE storage_node DROP CONSTRAINT FK_8C522350727ACA70');
        $this->addSql('ALTER TABLE storage_node DROP CONSTRAINT FK_8C5223505CC5DB90');
        $this->addSql('DROP TABLE storage_node');
        $this->addSql('DROP TABLE storage_storage');
        $this->addSql('ALTER TABLE asset DROP content_hash');
    }
}
