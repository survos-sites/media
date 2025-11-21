<?php
declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Repository\AssetPathRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AssetPathRepository::class)]
#[ORM\Table(name: 'asset_path')]
//#[ORM\UniqueConstraint(name: 'uniq_storage_dir', columns: ['storage', 'dir3'])]
#[ORM\Index(name: 'idx_storage_files', columns: ['files'])]
class AssetPath
{
//    /** e.g. "local" | "archive" (or any label that maps to a FlysystemOperator) */
//    #[ORM\Column(type: Types::STRING, length: 32)]
//    public string $storage;

    /** 3-hex directory code: 000..fff */
    #[ORM\Column(type: Types::STRING, length: 3)]
    #[ORM\Id]
    public string $id;

    /** How many files currently assigned to this directory (originals or variants — your choice). */
    #[ORM\Column(type: Types::INTEGER)]
    public int $files = 0;

    /** Total bytes across files tracked here (optional, best-effort). */
    #[ORM\Column(type: Types::INTEGER)] # https://github.com/EasyCorp/EasyAdminBundle/issues/7090 BIGINT when fixed
    public int $bytes = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    #[ApiProperty(description: "cache of individual files in this directory")]
    public array $assetMeta;

    public function __construct( string $dir3)
    {
        $this->id      = strtolower($dir3);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
