<?php
declare(strict_types=1);

namespace App\Entity;

use App\Entity\Variant;
use App\Workflow\AssetFlow as WF;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\MeiliBundle\Metadata\Fields;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\SaisBundle\Service\SaisClientService;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: \App\Repository\AssetRepository::class)]
#[ORM\Table]
#[ORM\Index(name: 'idx_asset_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_asset_mime', columns: ['mime'])]
#[ORM\Index(name: 'idx_asset_backend', columns: ['storage_backend'])]
#[MeiliIndex(
    persisted: new Fields(
        groups: ['asset.read'],
        fields: ['id','mime','storageBackend','width','height','createdAt'],
    )
)]
class Asset implements MarkingInterface, \Stringable
{
    use MarkingTrait; // provides $marking + getters/setters compatible with the workflow engine

    /** Primary key: 16-byte xxh3_128 (PostgreSQL bytea). */
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $id {
        set {
            if (\strlen($value) !== 16) {
                throw new \InvalidArgumentException('contentHash must be exactly 16 bytes (xxh3_128).');
            }
            $this->id = $value;
        }
    }

    /** HTTP status from last fetch (used by guards); 200 = OK. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $statusCode = null;

    /** Directory assignment under local.storage for Liip loader (3-hex dir). */
    #[ORM\ManyToOne(targetEntity: AssetPath::class)]
    #[ORM\JoinColumn(name: 'local_dir_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public ?AssetPath $localDir = null;


    /** Original MIME type (image/*, audio/*, video/*). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['asset.read'])]
    public ?string $mime = null;

    /** Bytes of original file. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    #[Groups(['asset.read'])]
    public ?int $size = null {
        set {
            if ($value !== null && $value < 0) {
                throw new \InvalidArgumentException('size must be >= 0 or null');
            }
            $this->size = $value;
        }
    }

    /** Dimensions (for images/videos when known). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['asset.read'])]
    public ?int $width = null {
        set {
            if ($value !== null && $value < 0) {
                throw new \InvalidArgumentException('width must be >= 0 or null');
            }
            $this->width = $value;
        }
    }

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['asset.read'])]
    public ?int $height = null {
        set {
            if ($value !== null && $value < 0) {
                throw new \InvalidArgumentException('height must be >= 0 or null');
            }
            $this->height = $value;
        }
    }

    /** Arbitrary filterable context (JSONB): aggregator/museum/dataset/etc. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['asset.read'])]
    public ?array $context = null;

    /** Storage path/key of ORIGINAL (e.g., o/ab/cd/<hash>.<ext>). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $storageBackend = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $storageKey = null;

    /** Temp filename during fetch; not a durable path. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $tempFilename = null;

    /** Optional original extension hint (jpg, mp4, …). */
    #[ORM\Column(type: Types::STRING, length: 12, nullable: true)]
    public ?string $ext = null;

    /** Variant map (preset => url/info). */
    /** Variants generated for this asset (e.g., liip presets, formats). */
    #[ORM\OneToMany(mappedBy: 'asset', targetEntity: Variant::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['preset' => 'ASC', 'format' => 'ASC'])]
//    #[Groups(['asset.read'])]
    public Collection $variants;

    /** Ingest timestamp. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    /** Soft delete. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    public ?\DateTime $deletedAt = null;

    public int $resizedCount { get => count($this->resized??[]); }
    public string $path { get =>
        $this->localDir->id . '/' . $this->id . '.' . $this->ext;
    }


    public function __construct(
        /** Source/original URL (for provenance / retries). */
        #[ORM\Column(type: Types::TEXT, nullable: false)]
        public ?string $originalUrl = null
    )
    {
        $this->id = SaisClientService::calculateCode($originalUrl);
        $this->createdAt   = new \DateTimeImmutable();
        $this->marking     = WF::PLACE_NEW; // seed initial marking via workflow constant
        $this->variants    = new ArrayCollection();
    }


//    /** Convenience: 32-char lowercase hex of PK. */
//    public function contentHashHex(): string
//    {
//        return \bin2hex($this->contentHash);
//    }

    public function __toString()
    {
        return $this->id;
    }

    public function getThumbnailUrl(): ?string
    {
        /** @var Variant $variant */
        foreach ($this->variants as $variant) {
            if ($variant->preset === 'small') {
                return $variant->url;
            }
        }
        return null;

    }


    public function addVariant(Variant $v): void
    {
        if (!$this->variants->contains($v)) {
            $this->variants->add($v);
            $v->asset = $this;
        }
    }

    public function removeVariant(Variant $v): void
    {
            $this->variants->removeElement($v);
        }

}
