<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add media_record entity, workflow scaffold fields, and asset relation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE media_record (id VARCHAR(16) NOT NULL, record_key VARCHAR(191) NOT NULL, label VARCHAR(255) DEFAULT NULL, source_url TEXT DEFAULT NULL, source_mime VARCHAR(128) DEFAULT NULL, ocr_text TEXT DEFAULT NULL, source_meta JSON DEFAULT NULL, context JSON DEFAULT NULL, child_count INT NOT NULL, ai_queue JSON DEFAULT \'[]\' NOT NULL, ai_completed JSON DEFAULT \'[]\' NOT NULL, ai_locked BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, marking VARCHAR(32) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C76484F1A66100BB ON media_record (record_key)');
        $this->addSql('CREATE INDEX idx_media_record_created_at ON media_record (created_at)');
        $this->addSql('CREATE INDEX idx_media_record_record_key ON media_record (record_key)');

        $this->addSql('ALTER TABLE asset ADD media_record_id VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE asset RENAME COLUMN temp_filename TO local_canonical_filename');
        $this->addSql('ALTER TABLE asset ADD local_small_filename TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE asset ADD CONSTRAINT FK_5E9E89CB4C2F2C3F FOREIGN KEY (media_record_id) REFERENCES media_record (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_asset_media_record ON asset (media_record_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset DROP CONSTRAINT FK_5E9E89CB4C2F2C3F');
        $this->addSql('DROP INDEX idx_asset_media_record');
        $this->addSql('ALTER TABLE asset DROP local_small_filename');
        $this->addSql('ALTER TABLE asset RENAME COLUMN local_canonical_filename TO temp_filename');
        $this->addSql('ALTER TABLE asset DROP media_record_id');

        $this->addSql('DROP TABLE media_record');
    }
}
