<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260403170523 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE asset DROP small_url');
        $this->addSql('ALTER TABLE dataset_info ADD provider_code VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE dataset_info ADD CONSTRAINT FK_4E0D6452929D53E4 FOREIGN KEY (provider_code) REFERENCES provider (code) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_4E0D6452929D53E4 ON dataset_info (provider_code)');
        $this->addSql('ALTER TABLE users DROP bin_count');
        $this->addSql('ALTER TABLE users DROP approx_image_count');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE asset ADD small_url TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE dataset_info DROP CONSTRAINT FK_4E0D6452929D53E4');
        $this->addSql('DROP INDEX IDX_4E0D6452929D53E4');
        $this->addSql('ALTER TABLE dataset_info DROP provider_code');
        $this->addSql('ALTER TABLE users ADD bin_count INT NOT NULL');
        $this->addSql('ALTER TABLE users ADD approx_image_count INT NOT NULL');
    }
}
