<?php
declare(strict_types=1);

namespace App\Entity;

use App\Entity\Variant;
use App\Workflow\AssetFlow as WF;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use Survos\MeiliBundle\Metadata\Fields;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\MediaBundle\Util\MediaIdentity;
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
    sortable: ['createdAt', 'aiTokensTotal'],
    filterable: ['mime', 'clients', 'marking', 'aiDocumentType', 'aiDocumentSubtype',
                 'aiKeywords', 'aiPeople', 'aiPlaces', 'aiOrganisations', 'aiSafety'],
    searchable: ['aiTitle', 'aiDescription', 'aiOcrText', 'aiKeywords',
                 'aiPeople', 'aiPlaces', 'aiSubjects', 'originalUrl'],
    persisted: new Fields(
        groups: ['asset.read'],
        fields: ['id', 'mime', 'width', 'height', 'createdAt', 'smallUrl', 'archiveUrl', 'marking',
                 'aiDocumentType'],
    ),
    ui: ['columns' => 4, 'cardClass' => 'asset-card'],
)]
class Asset implements MarkingInterface, \Stringable
{
    use MarkingTrait; // provides $marking + getters/setters compatible with the workflow engine

    /** Primary key: 16-char lowercase hex xxh3(originalUrl). */
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $id {
        set {
            if (\strlen($value) !== 16) {
                throw new \InvalidArgumentException('asset id must be exactly 16 hex chars (xxh3(originalUrl)). ' . $value);
            }
            $this->id = $value;
        }
    }

    /** Fast non-cryptographic content hash (xxh3 of bytes). */
    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    public ?string $contentHash = null;

    /** HTTP status from last fetch (used by guards); 200 = OK. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $statusCode = null;

    public ?int $sizeInMegabytes { get => $this->size ? (int)($this->size / (1024*1024)) : null;}

    /** Directory assignment under local.storage for Liip loader (3-hex dir). */
    #[ORM\ManyToOne(targetEntity: AssetPath::class)]
    #[ORM\JoinColumn(name: 'local_dir_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public ?AssetPath $localDir = null;


    /** Original MIME type (image/*, audio/*, video/*). */
    #[ORM\Column(type: Types::STRING, nullable: true)]
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

     /**
      * Immutable parent reference (xxh3 key of parent Asset).
      * Null for top-level assets (e.g. PDFs).
      */
     #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
     public ?string $parentKey = null;

     /**
      * Total number of derived child assets.
      * Includes pages, OCR, and any other derivatives.
      */
     #[ORM\Column(type: Types::INTEGER)]
     public int $childCount = 0;

     /**
      * 1-based page number for page assets.
      * Null for non-page assets.
      */
     #[ORM\Column(type: Types::INTEGER, nullable: true)]
     public ?int $pageNumber = null;

     /**
      * Denormalized indicator that OCR exists for THIS asset.
      * True means at least one OCR-derived Asset exists whose parentKey equals this asset's key.
      */
     #[ORM\Column(type: Types::BOOLEAN)]
     public bool $hasOcr = false;

     // ─────────────── AI task pipeline ───────────────

     /**
      * Ordered list of AI task names still to be executed.
      * Example: ["classify", "extract_metadata", "generate_title"]
      * A worker picks the first entry, runs it, then moves it to aiCompleted.
      */
     #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
     public array $aiQueue = [];

     /**
      * History of completed AI tasks.
      * Each entry: { task: string, at: ISO-8601 string, result: mixed }
      */
     #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
     public array $aiCompleted = [];

     /**
      * When true the AI worker skips this asset entirely.
      * Lets an operator pause processing (e.g. while reviewing results).
      */
     #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
     public bool $aiLocked = false;

     // ── AI classification — kept as a real column for SQL WHERE in browse ────
     // All other AI result fields (title, description, OCR text, keywords, etc.)
     // are computed at normalisation time by AssetNormalizer from aiCompleted.

     /** Classified document/object type — stored for SQL filtering only. */
     #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
     public ?string $aiDocumentType = null;

     /** Client codes referencing this asset (additive). */
     #[ORM\Column(type: Types::JSON)]
     public array $clients = [];

    /** Storage path/key of ORIGINAL (e.g., o/ab/cd/<hash>.<ext>). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $storageBackend = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $storageKey = null;

    /** URL of archived original (object storage) */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['asset.read'])] // for now, maybe removed after debugging
    public ?string $archiveUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['asset.read'])] // directly to imgProxy after archive.  could happen elsewhere, but for now it's here.
    public ?string $smallUrl = null;

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
        $this->localDir?->id . '/' . $this->id . '.' . $this->ext;
    }


    public function __construct(
        /** Source/original URL (for provenance / retries). */
        #[ORM\Column(type: Types::TEXT, nullable: false)]
        public string $originalUrl
    )
    {
        $this->id          = MediaIdentity::idFromOriginalUrl($this->originalUrl);
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

//    public function getThumbnailUrl(): ?string
//    {
//        /** @var Variant $variant */
//        foreach ($this->variants as $variant) {
//            if ($variant->preset === 'small') {
//                return $variant->url;
//            }
//        }
//        return null;
//
//    }


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

    // ── Computed AI accessors (read from aiCompleted — no DB columns) ─────────
    // Used by Twig templates and anywhere that needs AI results without going
    // through the serializer. The normalizer uses its own expandAiCompleted()
    // for Meilisearch/API output; these are the entity-side equivalents.

    /** @return array<string, mixed>  last successful result per task, keyed by task name */
    public function aiResults(): array
    {
        $byTask = [];
        foreach ($this->aiCompleted as $entry) {
            $task = $entry['task'] ?? null;
            if ($task && empty($entry['result']['failed']) && empty($entry['result']['skipped'])) {
                $byTask[$task] = $entry['result'];
            }
        }
        return $byTask;
    }

    public function getAiTitle(): ?string
    {
        $r = $this->aiResults();
        return $r['generate_title']['title'] ?? null;
    }

    public function getAiDescription(): ?string
    {
        $r = $this->aiResults();
        return $r['context_description']['description']
            ?? $r['basic_description']['description']
            ?? null;
    }

    public function getAiOcrText(): ?string
    {
        $r = $this->aiResults();
        return $r['ocr_mistral']['text'] ?? $r['ocr']['text'] ?? $r['transcribe_handwriting']['text'] ?? null;
    }

    /** @return string[] */
    public function getAiKeywords(): array
    {
        return $this->aiResults()['keywords']['keywords'] ?? [];
    }

    /** @return string[] */
    public function getAiPeople(): array
    {
        $r = $this->aiResults();
        return $r['people_and_places']['people'] ?? $r['extract_metadata']['people'] ?? [];
    }

    /** @return string[] */
    public function getAiPlaces(): array
    {
        $r = $this->aiResults();
        return $r['people_and_places']['places'] ?? $r['extract_metadata']['places'] ?? [];
    }

    /** @return string[] */
    public function getAiOrganisations(): array
    {
        $r = $this->aiResults();
        return array_values(array_unique(array_merge(
            $r['people_and_places']['organisations'] ?? [],
            $r['extract_metadata']['organisations'] ?? [],
        )));
    }

    public function getAiDateRange(): ?string
    {
        return $this->aiResults()['extract_metadata']['dateRange'] ?? null;
    }

    public function getAiSummary(): ?string
    {
        return $this->aiResults()['summarize']['summary'] ?? null;
    }

    public function getAiDocumentSubtype(): ?string
    {
        return $this->aiResults()['classify']['subtype'] ?? null;
    }

    /** Total tokens spent across all completed tasks. */
    public function getAiTokensTotal(): int
    {
        $total = 0;
        foreach ($this->aiCompleted as $entry) {
            $total += $entry['result']['_tokens']['total'] ?? 0;
        }
        return $total;
    }
}
