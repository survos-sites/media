<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260514154955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE claim (id VARCHAR(26) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, scope VARCHAR(64) DEFAULT NULL, subject_type VARCHAR(32) NOT NULL, subject_id VARCHAR(64) NOT NULL, predicate VARCHAR(64) NOT NULL, source VARCHAR(128) NOT NULL, value JSONB NOT NULL, confidence SMALLINT NOT NULL, basis TEXT DEFAULT NULL, run_id VARCHAR(26) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_claim_scope_subject_pred ON claim (scope, subject_type, subject_id, predicate)');
        $this->addSql('CREATE INDEX idx_claim_scope_source ON claim (scope, source)');
        $this->addSql('CREATE INDEX idx_claim_run ON claim (run_id)');
        $this->addSql('CREATE TABLE claim_run (id VARCHAR(26) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, scope VARCHAR(64) DEFAULT NULL, subject_type VARCHAR(32) NOT NULL, subject_id VARCHAR(64) NOT NULL, source VARCHAR(128) NOT NULL, model VARCHAR(128) DEFAULT NULL, prompt TEXT DEFAULT NULL, response JSONB DEFAULT NULL, input_tokens INT DEFAULT NULL, output_tokens INT DEFAULT NULL, image_tokens INT DEFAULT NULL, duration_ms INT DEFAULT NULL, claim_count INT DEFAULT 0 NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_claim_run_scope_subject_source ON claim_run (scope, subject_type, subject_id, source)');
        $this->addSql('CREATE INDEX idx_claim_run_created ON claim_run (created_at)');
        $this->addSql('CREATE TABLE subject (id VARCHAR(26) NOT NULL, scope VARCHAR(255) DEFAULT NULL, subject_type VARCHAR(255) NOT NULL, subject_id VARCHAR(255) NOT NULL, data JSON DEFAULT \'{}\' NOT NULL, workflow_locked BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, marking VARCHAR(32) DEFAULT NULL, pending_steps JSON DEFAULT \'{}\' NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_subject ON subject (scope, subject_type, subject_id)');
        $this->addSql('DROP TABLE ai_task_run');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ai_task_run (id UUID NOT NULL, subject_type VARCHAR(64) NOT NULL, subject_id VARCHAR(26) NOT NULL, task_name VARCHAR(64) NOT NULL, status VARCHAR(255) NOT NULL, result JSON DEFAULT NULL, error TEXT DEFAULT NULL, queued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX ai_task_run_lookup_idx ON ai_task_run (subject_type, subject_id, task_name)');
        $this->addSql('CREATE INDEX ai_task_run_status_idx ON ai_task_run (status)');
        $this->addSql('CREATE INDEX ai_task_run_subject_idx ON ai_task_run (subject_type, subject_id)');
        $this->addSql('DROP TABLE claim');
        $this->addSql('DROP TABLE claim_run');
        $this->addSql('DROP TABLE subject');
    }
}
