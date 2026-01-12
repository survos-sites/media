<?php
declare(strict_types=1);

namespace App\Entity;

use App\Workflow\VariantFlowDefinition as WF;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: \App\Repository\VariantRepository::class)]
#[ORM\Table(name: 'asset_variant')]
#[ORM\UniqueConstraint(name: 'uniq_asset_preset_format', columns: ['asset_id', 'preset', 'format'])]
#[ORM\Index(name: 'idx_variant_preset', columns: ['preset'])]
#[ORM\Index(name: 'idx_variant_format', columns: ['format'])]
#[ORM\Index(name: 'idx_variant_created_at', columns: ['created_at'])]
//#[MeiliIndex()]
class Variant implements MarkingInterface, \Stringable
{
    use MarkingTrait; // provides $marking + accessors for workflow

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING)]
    #[Groups(['asset.read'])]
    public string $id;

    /** Many variants belong to one Asset. */
    #[ORM\ManyToOne(targetEntity: Asset::class, inversedBy: 'variants')]
    #[ORM\JoinColumn(name: 'asset_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public Asset $asset;

    /** e.g. small, medium, large, 800w, card, hero… */
    #[ORM\Column(type: Types::STRING, length: 64)]
    #[Groups(['asset.read'])]
    public string $preset;

    /** e.g. webp, avif, jpg */
    #[ORM\Column(type: Types::STRING, length: 12)]
    #[Groups(['asset.read'])]
    public string $format;

    /** Encoded bytes (if known). */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    #[Groups(['asset.read'])]
    public ?int $size = null;

    /** Dimensions (if known). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['asset.read'])]
    public ?int $width = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['asset.read'])]
    public ?int $height = null;

    /** Optional encode quality/CRF */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Groups(['asset.read'])]
    public ?int $quality = null;

    /** Where it lives; useful if originals and variants differ (e.g., s3 vs. local). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $storageBackend = null;

    /** Object key / relative path, e.g. v/medium/ab/cd/<hex>.webp */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $storageKey = null;

    /** Public or signed URL, if you store it. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['asset.read'])]
    public ?string $url = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    public function __construct(Asset $asset, string $preset, string $format)
    {
        $this->id = $asset->id . '-' . $preset;
        $this->asset     = $asset;
        $this->preset    = $preset;
        $this->format    = ltrim($format, '.');
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();

        // seed initial mark so the workflow can auto-advance if configured
        $this->marking   = WF::PLACE_NEW;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->preset . '.' . $this->format;
    }
}
