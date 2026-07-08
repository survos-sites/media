<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260708153622 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Asset::faceCount (imgproxy face-box count, for the portrait/couple/group facet)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset ADD face_count INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset DROP face_count');
    }
}
