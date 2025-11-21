<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250923205526 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE processed_messages ADD run_id INT NOT NULL');
        $this->addSql('ALTER TABLE processed_messages ADD attempt SMALLINT NOT NULL');
        $this->addSql('ALTER TABLE processed_messages ADD message_type VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE processed_messages ADD description VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE processed_messages ADD dispatched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE processed_messages ADD received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE processed_messages ADD finished_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE processed_messages ADD wait_time BIGINT NOT NULL');
        $this->addSql('ALTER TABLE processed_messages ADD handle_time BIGINT NOT NULL');
        $this->addSql('ALTER TABLE processed_messages ADD memory_usage INT NOT NULL');
        $this->addSql('ALTER TABLE processed_messages ADD transport VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE processed_messages ADD tags VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE processed_messages ADD failure_type VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE processed_messages ADD failure_message TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE processed_messages ADD results JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE processed_messages DROP run_id');
        $this->addSql('ALTER TABLE processed_messages DROP attempt');
        $this->addSql('ALTER TABLE processed_messages DROP message_type');
        $this->addSql('ALTER TABLE processed_messages DROP description');
        $this->addSql('ALTER TABLE processed_messages DROP dispatched_at');
        $this->addSql('ALTER TABLE processed_messages DROP received_at');
        $this->addSql('ALTER TABLE processed_messages DROP finished_at');
        $this->addSql('ALTER TABLE processed_messages DROP wait_time');
        $this->addSql('ALTER TABLE processed_messages DROP handle_time');
        $this->addSql('ALTER TABLE processed_messages DROP memory_usage');
        $this->addSql('ALTER TABLE processed_messages DROP transport');
        $this->addSql('ALTER TABLE processed_messages DROP tags');
        $this->addSql('ALTER TABLE processed_messages DROP failure_type');
        $this->addSql('ALTER TABLE processed_messages DROP failure_message');
        $this->addSql('ALTER TABLE processed_messages DROP results');
    }
}
