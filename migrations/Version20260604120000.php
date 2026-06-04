<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Promote imgproxy /info classification and object identifier data from
 * Asset.context into first-class JSONB columns for indexing and faceting.
 */
final class Version20260604120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add first-class imgproxy classification/object identifier columns on asset';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset ADD COLUMN IF NOT EXISTS classification JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE asset ADD COLUMN IF NOT EXISTS object_identifiers JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE asset ADD COLUMN IF NOT EXISTS object_identifier_confidences JSONB DEFAULT NULL');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asset_classification_id ON asset (classification, id)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asset_object_identifiers_id ON asset (object_identifiers, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_asset_object_identifiers_id');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_asset_classification_id');
        $this->addSql('ALTER TABLE asset DROP COLUMN IF EXISTS object_identifier_confidences');
        $this->addSql('ALTER TABLE asset DROP COLUMN IF EXISTS object_identifiers');
        $this->addSql('ALTER TABLE asset DROP COLUMN IF EXISTS classification');
    }
}
