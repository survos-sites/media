<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260116143654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE index_info');
        $this->addSql('ALTER TABLE asset ADD parent_key VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE asset ADD child_count INT NOT NULL');
        $this->addSql('ALTER TABLE asset ADD page_number INT DEFAULT NULL');
        $this->addSql('ALTER TABLE asset ADD has_ocr BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE asset ADD small_url TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE media ADD small_url TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE media ADD storage_key TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE media RENAME COLUMN thumbnail_url TO s3_url');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE index_info (index_name VARCHAR(255) NOT NULL, locale VARCHAR(255) DEFAULT NULL, last_indexed TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, document_count INT NOT NULL, settings JSONB NOT NULL, task_id VARCHAR(255) DEFAULT NULL, primary_key VARCHAR(255) NOT NULL, batch_id VARCHAR(255) DEFAULT NULL, status VARCHAR(20) DEFAULT NULL, PRIMARY KEY (index_name))');
        $this->addSql('ALTER TABLE asset DROP parent_key');
        $this->addSql('ALTER TABLE asset DROP child_count');
        $this->addSql('ALTER TABLE asset DROP page_number');
        $this->addSql('ALTER TABLE asset DROP has_ocr');
        $this->addSql('ALTER TABLE asset DROP small_url');
        $this->addSql('ALTER TABLE media ADD thumbnail_url TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE media DROP s3_url');
        $this->addSql('ALTER TABLE media DROP small_url');
        $this->addSql('ALTER TABLE media DROP storage_key');
    }
}
