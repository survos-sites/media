<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Composite (facetColumn, id) indexes for the Mezcalito search facets.
 *
 * The facet queries run `count(DISTINCT id) GROUP BY <col>`; without `id` in the
 * index Postgres sorts the whole table per facet (~1.5s each → ~11s page). A
 * covering (col, id) index makes each an index-only scan (~0.1s).
 *
 * WORKAROUND for https://github.com/Mezcalito/ux-search/issues/46 — drop these
 * once upstream stops emitting count(DISTINCT <pk>) for join-free facets.
 *
 * Hand-written (NOT diff-generated): the diff was contaminated with unrelated
 * asset_path/asset_variant drops and local-only timescaledb schema noise.
 *
 * Uses CREATE INDEX CONCURRENTLY (no write lock on the large asset table), which
 * requires running outside a transaction.
 */
final class Version20260603165726 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Composite (col, id) facet indexes on asset — workaround for Mezcalito/ux-search#46';
    }

    public function isTransactional(): bool
    {
        // CREATE INDEX CONCURRENTLY cannot run inside a transaction block.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asset_marking_id ON asset (marking, id)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asset_ai_doc_type_id ON asset (ai_document_type, id)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asset_mime_id ON asset (mime, id)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asset_ext_id ON asset (ext, id)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asset_size_id ON asset (size, id)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asset_width_id ON asset (width, id)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asset_height_id ON asset (height, id)');
        // The single-column mime index is subsumed by (mime, id) (leftmost prefix).
        $this->addSql('DROP INDEX IF EXISTS idx_asset_mime');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asset_mime ON asset (mime)');
        $this->addSql('DROP INDEX IF EXISTS idx_asset_marking_id');
        $this->addSql('DROP INDEX IF EXISTS idx_asset_ai_doc_type_id');
        $this->addSql('DROP INDEX IF EXISTS idx_asset_mime_id');
        $this->addSql('DROP INDEX IF EXISTS idx_asset_ext_id');
        $this->addSql('DROP INDEX IF EXISTS idx_asset_size_id');
        $this->addSql('DROP INDEX IF EXISTS idx_asset_width_id');
        $this->addSql('DROP INDEX IF EXISTS idx_asset_height_id');
    }
}
