<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260512145723 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ai_task_run (id UUID NOT NULL, subject_type VARCHAR(64) NOT NULL, subject_id VARCHAR(26) NOT NULL, task_name VARCHAR(64) NOT NULL, status VARCHAR(255) NOT NULL, result JSON DEFAULT NULL, error TEXT DEFAULT NULL, queued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX ai_task_run_subject_idx ON ai_task_run (subject_type, subject_id)');
        $this->addSql('CREATE INDEX ai_task_run_status_idx ON ai_task_run (status)');
        $this->addSql('CREATE INDEX ai_task_run_lookup_idx ON ai_task_run (subject_type, subject_id, task_name)');
        $this->addSql('CREATE TABLE candidate (candidate_key VARCHAR(160) NOT NULL, provider_code VARCHAR(32) NOT NULL, source_id VARCHAR(160) DEFAULT NULL, kind VARCHAR(32) DEFAULT NULL, label VARCHAR(255) DEFAULT NULL, description TEXT DEFAULT NULL, source_url VARCHAR(500) DEFAULT NULL, locale VARCHAR(16) DEFAULT NULL, country VARCHAR(8) DEFAULT NULL, dataset_key VARCHAR(160) DEFAULT NULL, status VARCHAR(32) NOT NULL, meta JSONB NOT NULL, discovered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, hydrated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, promoted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, provider_entity_code VARCHAR(32) DEFAULT NULL, PRIMARY KEY (candidate_key))');
        $this->addSql('CREATE INDEX IDX_C8B28E44FA460441 ON candidate (provider_entity_code)');
        $this->addSql('CREATE INDEX IDX_C8B28E44929D53E4 ON candidate (provider_code)');
        $this->addSql('CREATE INDEX IDX_C8B28E447B00651C ON candidate (status)');
        $this->addSql('ALTER TABLE candidate ADD CONSTRAINT FK_C8B28E44FA460441 FOREIGN KEY (provider_entity_code) REFERENCES provider (code) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE asset ADD pending_steps JSON DEFAULT \'{}\' NOT NULL');
        $this->addSql('ALTER TABLE asset_variant ADD pending_steps JSON DEFAULT \'{}\' NOT NULL');
        $this->addSql('ALTER TABLE file ADD pending_steps JSON DEFAULT \'{}\' NOT NULL');
        $this->addSql('ALTER TABLE media_record ADD pending_steps JSON DEFAULT \'{}\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE candidate DROP CONSTRAINT FK_C8B28E44FA460441');
        $this->addSql('DROP TABLE ai_task_run');
        $this->addSql('DROP TABLE candidate');
        $this->addSql('ALTER TABLE asset DROP pending_steps');
        $this->addSql('ALTER TABLE asset_variant DROP pending_steps');
        $this->addSql('ALTER TABLE file DROP pending_steps');
        $this->addSql('ALTER TABLE media_record DROP pending_steps');
    }
}
