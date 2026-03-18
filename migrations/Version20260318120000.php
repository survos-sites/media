<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add media_enrichment JSON column to asset';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset ADD media_enrichment JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset DROP media_enrichment');
    }
}
