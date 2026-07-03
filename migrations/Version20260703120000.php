<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add asset.claim_{caption,prose,subjects,type} — the AI title/description/keywords
 * denormalized from the separate claims store (see ClaimSearchSync) — and rebuild the
 * full-text GIN index to include them. Without this, the search vector only covered
 * classification/object_identifiers/local_ocr_text, which excluded exactly the fields
 * (AI-generated caption + keywords) that the search hit card displays and that users
 * actually type into the search box, so most real queries returned zero results.
 */
final class Version20260703120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add asset claim_* search columns and rebuild idx_asset_fts to include them';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Full-text GIN index is PostgreSQL-only (use sqlite-fts5 on SQLite).',
        );

        $this->addSql('ALTER TABLE asset ADD COLUMN claim_caption TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE asset ADD COLUMN claim_prose TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE asset ADD COLUMN claim_subjects JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE asset ADD COLUMN claim_type VARCHAR(64) DEFAULT NULL');

        $this->addSql('DROP INDEX IF EXISTS idx_asset_fts');
        $this->addSql(
            "CREATE INDEX IF NOT EXISTS idx_asset_fts ON asset USING gin (("
            ."setweight(to_tsvector('english', coalesce(classification::text, '') || ' ' || coalesce(claim_caption, '') || ' ' || coalesce(claim_type, '')), 'A') || "
            ."setweight(to_tsvector('english', coalesce(object_identifiers::text, '') || ' ' || coalesce(claim_subjects::text, '')), 'B') || "
            ."setweight(to_tsvector('english', coalesce(ai_document_type, '')), 'C') || "
            ."setweight(to_tsvector('english', coalesce(local_ocr_text, '') || ' ' || coalesce(claim_prose, '')), 'D')))",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_asset_fts');
        $this->addSql(
            "CREATE INDEX IF NOT EXISTS idx_asset_fts ON asset USING gin (("
            ."setweight(to_tsvector('english', coalesce(classification::text, '')), 'A') || "
            ."setweight(to_tsvector('english', coalesce(object_identifiers::text, '')), 'B') || "
            ."setweight(to_tsvector('english', coalesce(ai_document_type, '')), 'C') || "
            ."setweight(to_tsvector('english', coalesce(local_ocr_text, '')), 'D')))",
        );

        $this->addSql('ALTER TABLE asset DROP COLUMN claim_caption');
        $this->addSql('ALTER TABLE asset DROP COLUMN claim_prose');
        $this->addSql('ALTER TABLE asset DROP COLUMN claim_subjects');
        $this->addSql('ALTER TABLE asset DROP COLUMN claim_type');
    }
}
