<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260628221248 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ai_batch (tacman/ai-batch-bundle) for the OpenAI batch scheduler.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS ai_batch (
                id SERIAL PRIMARY KEY,
                provider_batch_id VARCHAR(255) DEFAULT NULL,
                provider VARCHAR(32) NOT NULL,
                task VARCHAR(64) NOT NULL,
                dataset_key VARCHAR(255) DEFAULT NULL,
                status VARCHAR(32) NOT NULL,
                input_file_path VARCHAR(255) DEFAULT NULL,
                input_file_id VARCHAR(255) DEFAULT NULL,
                output_file_id VARCHAR(255) DEFAULT NULL,
                error_file_id VARCHAR(255) DEFAULT NULL,
                request_count INT NOT NULL,
                completed_count INT NOT NULL,
                failed_count INT NOT NULL,
                applied_count INT NOT NULL,
                estimated_cost_usd NUMERIC(10, 6) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_polled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                saved_result_path VARCHAR(255) DEFAULT NULL,
                meta JSON NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_A4BC69917B00651C ON ai_batch (status)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_A4BC699192C4739C7B00651C ON ai_batch (provider, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_batch');
    }
}
