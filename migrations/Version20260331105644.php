<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260331105644 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create dataset/provider/iiif tables and add local OCR + IIIF fields to asset';
    }

    public function up(Schema $schema): void
    {
//        $this->addSql("ALTER SYSTEM SET default_toast_compression = 'lz4';");

//        $this->addSql("SELECT pg_reload_conf();");
        $this->addSql("CREATE TABLE dataset_info (dataset_key VARCHAR(128) NOT NULL, label VARCHAR(255) DEFAULT NULL, description TEXT DEFAULT NULL, aggregator VARCHAR(255) DEFAULT NULL, locale VARCHAR(255) DEFAULT NULL, country VARCHAR(255) DEFAULT NULL, contact_url VARCHAR(255) DEFAULT NULL, rights_uri VARCHAR(255) DEFAULT NULL, obj_count INT NOT NULL, meta_path VARCHAR(255) DEFAULT NULL, raw_path VARCHAR(255) DEFAULT NULL, normalized_path VARCHAR(255) DEFAULT NULL, profile_path VARCHAR(255) DEFAULT NULL, pixie_db_path VARCHAR(255) DEFAULT NULL, status VARCHAR(32) NOT NULL, normalized_count INT DEFAULT NULL, pixie_row_count INT DEFAULT NULL, meili_doc_count INT DEFAULT NULL, last_scanned TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_normalized TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_indexed TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cores JSON NOT NULL, fields JSONB NOT NULL, profile_summary JSONB DEFAULT NULL, meili_settings JSONB NOT NULL, meta JSONB NOT NULL, PRIMARY KEY (dataset_key))");
        $this->addSql("CREATE INDEX IDX_4E0D6452BB5381D3 ON dataset_info (aggregator)");
        $this->addSql("CREATE INDEX IDX_4E0D64524180C698 ON dataset_info (locale)");
        $this->addSql("CREATE INDEX IDX_4E0D64527B00651C ON dataset_info (status)");

        $this->addSql("CREATE TABLE iiif_manifest (id VARCHAR(16) NOT NULL, manifest_url TEXT NOT NULL, image_base TEXT DEFAULT NULL, thumbnail_url TEXT DEFAULT NULL, label TEXT DEFAULT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, manifest_json JSON DEFAULT NULL, source VARCHAR(24) DEFAULT 'reference' NOT NULL, fetched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))");
        $this->addSql("CREATE INDEX idx_iiif_manifest_fetched_at ON iiif_manifest (fetched_at)");
        $this->addSql("CREATE UNIQUE INDEX uniq_iiif_manifest_url ON iiif_manifest (manifest_url)");

        $this->addSql("CREATE TABLE provider (code VARCHAR(32) NOT NULL, label VARCHAR(255) DEFAULT NULL, description TEXT DEFAULT NULL, homepage VARCHAR(500) DEFAULT NULL, logo VARCHAR(500) DEFAULT NULL, approx_inst_count INT DEFAULT NULL, approx_obj_count INT DEFAULT NULL, default_locale VARCHAR(10) DEFAULT NULL, data_reuse VARCHAR(255) DEFAULT NULL, terms_url VARCHAR(255) DEFAULT NULL, dataset_count INT DEFAULT NULL, synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (code))");

        $this->addSql("ALTER TABLE asset ADD COLUMN local_ocr_text TEXT DEFAULT NULL");
        $this->addSql("ALTER TABLE asset ADD COLUMN local_ocr_confidence DOUBLE PRECISION DEFAULT NULL");
        $this->addSql("ALTER TABLE asset ADD COLUMN local_ocr_primary_type VARCHAR(64) DEFAULT NULL");
        $this->addSql("ALTER TABLE asset ADD COLUMN local_ocr_source_url TEXT DEFAULT NULL");
        $this->addSql("ALTER TABLE asset ADD COLUMN local_ocr_provider VARCHAR(64) DEFAULT NULL");
        $this->addSql("ALTER TABLE asset ADD COLUMN local_ocr_model VARCHAR(64) DEFAULT NULL");
        $this->addSql("ALTER TABLE asset ADD COLUMN local_ocr_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL");
        $this->addSql("ALTER TABLE asset ADD COLUMN local_ocr_status INT DEFAULT NULL");
        $this->addSql("ALTER TABLE asset ADD COLUMN local_ocr_error TEXT DEFAULT NULL");
        $this->addSql("ALTER TABLE asset ADD COLUMN iiif_manifest_id VARCHAR(16) DEFAULT NULL");

//        $this->addSql("ALTER TABLE asset ALTER COLUMN local_ocr_text SET COMPRESSION lz4");
//        $this->addSql("ALTER TABLE asset ALTER COLUMN local_ocr_text SET STORAGE EXTENDED");

        $this->addSql("ALTER TABLE asset ADD CONSTRAINT FK_2AF5A5C73AF2900 FOREIGN KEY (iiif_manifest_id) REFERENCES iiif_manifest (id) ON DELETE SET NULL NOT DEFERRABLE");
        $this->addSql("CREATE INDEX IDX_2AF5A5C73AF2900 ON asset (iiif_manifest_id)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE asset DROP CONSTRAINT FK_2AF5A5C73AF2900");
        $this->addSql("DROP INDEX IDX_2AF5A5C73AF2900");

//        $this->addSql("ALTER TABLE asset ALTER COLUMN local_ocr_text RESET COMPRESSION");

        $this->addSql("ALTER TABLE asset DROP COLUMN local_ocr_text");
        $this->addSql("ALTER TABLE asset DROP COLUMN local_ocr_confidence");
        $this->addSql("ALTER TABLE asset DROP COLUMN local_ocr_primary_type");
        $this->addSql("ALTER TABLE asset DROP COLUMN local_ocr_source_url");
        $this->addSql("ALTER TABLE asset DROP COLUMN local_ocr_provider");
        $this->addSql("ALTER TABLE asset DROP COLUMN local_ocr_model");
        $this->addSql("ALTER TABLE asset DROP COLUMN local_ocr_at");
        $this->addSql("ALTER TABLE asset DROP COLUMN local_ocr_status");
        $this->addSql("ALTER TABLE asset DROP COLUMN local_ocr_error");
        $this->addSql("ALTER TABLE asset DROP COLUMN iiif_manifest_id");

        $this->addSql("DROP TABLE dataset_info");
        $this->addSql("DROP TABLE iiif_manifest");
        $this->addSql("DROP TABLE provider");
    }
}
