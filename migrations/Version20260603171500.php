<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop the retired asset_path and asset_variant tables (+ the asset.local_dir_id
 * FK into asset_path). Those entities were removed from the codebase but the
 * tables still exist in the live schema.
 *
 * Hand-written for correct DROP ordering: the auto-generated diff dropped
 * asset_path BEFORE removing the FK on asset that references it, which fails
 * with "cannot drop table asset_path because other objects depend on it".
 * We remove dependents first (asset's FK + local_dir_id column), then the tables.
 */
final class Version20260603171500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop retired asset_path / asset_variant tables and asset.local_dir_id FK';
    }

    public function up(Schema $schema): void
    {
        // 1. Remove the dependency: asset -> asset_path FK and its column/index.
        $this->addSql('ALTER TABLE asset DROP CONSTRAINT IF EXISTS fk_2af5a5ce9c1af63');
        // Dropping the column also drops idx_2af5a5ce9c1af63.
        $this->addSql('ALTER TABLE asset DROP COLUMN IF EXISTS local_dir_id');

        // 2. Now the tables can go. DROP TABLE removes asset_variant's own FK.
        $this->addSql('DROP TABLE IF EXISTS asset_variant');
        $this->addSql('DROP TABLE IF EXISTS asset_path');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE asset_path (id VARCHAR(3) NOT NULL, files INT NOT NULL, bytes INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, asset_meta JSONB DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_storage_files ON asset_path (files)');

        $this->addSql("CREATE TABLE asset_variant (id VARCHAR(255) NOT NULL, preset VARCHAR(64) NOT NULL, format VARCHAR(12) NOT NULL, size BIGINT DEFAULT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, quality SMALLINT DEFAULT NULL, storage_backend TEXT DEFAULT NULL, storage_key TEXT DEFAULT NULL, url TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, marking VARCHAR(32) DEFAULT NULL, asset_id VARCHAR(16) NOT NULL, pending_steps JSON DEFAULT '{}' NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE INDEX idx_variant_format ON asset_variant (format)');
        $this->addSql('CREATE INDEX idx_92adfefb5da1941 ON asset_variant (asset_id)');
        $this->addSql('CREATE INDEX idx_variant_created_at ON asset_variant (created_at)');
        $this->addSql('CREATE INDEX idx_variant_preset ON asset_variant (preset)');
        $this->addSql('CREATE UNIQUE INDEX uniq_asset_preset_format ON asset_variant (asset_id, preset, format)');
        $this->addSql('ALTER TABLE asset_variant ADD CONSTRAINT fk_92adfefb5da1941 FOREIGN KEY (asset_id) REFERENCES asset (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE asset ADD local_dir_id VARCHAR(3) DEFAULT NULL');
        $this->addSql('ALTER TABLE asset ADD CONSTRAINT fk_2af5a5ce9c1af63 FOREIGN KEY (local_dir_id) REFERENCES asset_path (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_2af5a5ce9c1af63 ON asset (local_dir_id)');
    }
}
