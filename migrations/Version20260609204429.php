<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260609204429 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_asset_dataset_id');
        $this->addSql('DROP INDEX idx_asset_object_identifiers_id');
        $this->addSql('DROP INDEX idx_asset_classification_id');
        $this->addSql('DROP INDEX idx_asset_height_id');
        $this->addSql('DROP INDEX idx_asset_width_id');
        $this->addSql('DROP INDEX idx_asset_size_id');
        $this->addSql('DROP INDEX idx_asset_ext_id');
        $this->addSql('DROP INDEX idx_asset_mime_id');
        $this->addSql('DROP INDEX idx_asset_ai_doc_type_id');
        $this->addSql('DROP INDEX idx_asset_marking_id');
        $this->addSql('ALTER TABLE asset ADD provider VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE asset DROP provider');
        $this->addSql('CREATE INDEX idx_asset_dataset_id ON asset (dataset, id)');
        $this->addSql('CREATE INDEX idx_asset_object_identifiers_id ON asset (object_identifiers, id)');
        $this->addSql('CREATE INDEX idx_asset_classification_id ON asset (classification, id)');
        $this->addSql('CREATE INDEX idx_asset_height_id ON asset (height, id)');
        $this->addSql('CREATE INDEX idx_asset_width_id ON asset (width, id)');
        $this->addSql('CREATE INDEX idx_asset_size_id ON asset (size, id)');
        $this->addSql('CREATE INDEX idx_asset_ext_id ON asset (ext, id)');
        $this->addSql('CREATE INDEX idx_asset_mime_id ON asset (mime, id)');
        $this->addSql('CREATE INDEX idx_asset_ai_doc_type_id ON asset (ai_document_type, id)');
        $this->addSql('CREATE INDEX idx_asset_marking_id ON asset (marking, id)');
    }
}
