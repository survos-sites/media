<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260303021920 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE asset ADD ai_queue JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE asset ADD ai_completed JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE asset ADD ai_locked BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE asset ADD ai_document_type VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE asset DROP ai_queue');
        $this->addSql('ALTER TABLE asset DROP ai_completed');
        $this->addSql('ALTER TABLE asset DROP ai_locked');
        $this->addSql('ALTER TABLE asset DROP ai_document_type');
    }
}
