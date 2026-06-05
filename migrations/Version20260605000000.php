<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Full-text search index for asset, used by the postgres-bm25 search adapter.
 *
 * Expression GIN index over to_tsvector(local_ocr_text). No table column is added:
 * the index expression must stay identical to AssetSearch's matchExpression /
 * scoreExpression for the planner to use it. Doctrine cannot reverse-engineer
 * expression indexes, so its schema diff leaves this alone.
 */
final class Version20260605000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add full-text (tsvector) GIN index on asset.local_ocr_text for postgres-bm25 search';
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

        // Weighted vector: A=classification labels, B=detected objects,
        // C=document type, D=OCR text. Must match AssetSearch's matchExpression.
        $this->addSql(
            "CREATE INDEX IF NOT EXISTS idx_asset_fts ON asset USING gin (("
            ."setweight(to_tsvector('english', coalesce(classification::text, '')), 'A') || "
            ."setweight(to_tsvector('english', coalesce(object_identifiers::text, '')), 'B') || "
            ."setweight(to_tsvector('english', coalesce(ai_document_type, '')), 'C') || "
            ."setweight(to_tsvector('english', coalesce(local_ocr_text, '')), 'D')))",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_asset_fts');
    }
}
