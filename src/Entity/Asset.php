<?php
declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Survos\MediaBundle\Dto\MediaEnrichment;
use App\Entity\Variant;
use App\Workflow\AssetFlow as WF;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use Survos\MeiliBundle\Metadata\Facet;
use Survos\MeiliBundle\Metadata\Fields;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\MediaBundle\Util\MediaIdentity;
use App\Service\ImgProxyUrlHelper;
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
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection()
    ]
)]
#[MeiliIndex(
//    chats: ['meili_assistant'],
    sortable: ['createdAt', 'aiTokensTotal'],
    filterable: ['mime', 'clients', 'marking',
//        'aiDocumentType', 'aiDocumentSubtype',
        'subjects',
//                 'aiKeywords', 'aiPeople', 'aiPlaces', 'aiOrganisations', 'aiSafety'
    ],
    searchable: ['title', 'description', 'aiTitle', 'aiDescription', 'aiOcrText', 'aiKeywords',
                 'aiPeople', 'aiPlaces', 'aiSubjects'],
    persisted: new Fields(
        groups: ['asset.read'],
        fields: ['id',
            'originalUrl',
        'mime', 'width', 'title', 'description', 'height', 'createdAt', 'smallUrl', 'archiveUrl', 'marking',
                 'aiDocumentType'],
    ),
    prompts: [
        'system' => 'You are assisting with media assets. Always use tool-backed search results from this index and always include [id:{value}] where {value} is the Asset primary key field {{ primaryKey }}.',
    ],
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

    #[Groups(['asset.read'])]
    public ?string $title { get => $this->sourceMeta['dcterms:title'] ?? null; }
    #[Groups(['asset.read'])]
    public ?string $description { get => $this->sourceMeta['dcterms:description'] ?? null; }
    #[Groups(['asset.read'])]
    public ?array $subjects { get => $this->sourceMeta['dcterms:subject'] ?? $this->sourceMeta['iiif_subjects'] ?? null; }

    #[Groups(['asset.read'])]
    #[Facet()]
    public ?string $type { get => $this->sourceMeta['dcterms:type'] ?? null; }

    #[Groups(['asset.read'])]
    #[Facet()]
    public ?string $reuse { get => $this->sourceMeta['reuse_allowed'] ?? null; }

    #[Groups(['asset.read'])]
    public ?string $thumb {
        get => $this->smallUrl
            ?? $this->iiifManifestEntity?->thumbnailUrl
            ?? $this->sourceMeta['thumbnail_url']
            ?? null;
    }

    #[Groups(['asset.read'])]
    #[Facet()]
    public ?string $publisher { get => $this->sourceMeta['dcterms:publisher'] ?? null; }

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

    /**
     * Image-derived analysis data (OCR text, thumbhash, colors, phash, sha256).
     * Written by AssetWorkflow after download. Never overwritten by client metadata.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['asset.read'])]
    public ?array $context = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['asset.read'])]
    public ?string $localOcrText = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Groups(['asset.read'])]
    public ?float $localOcrConfidence = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    #[Groups(['asset.read'])]
    public ?string $localOcrPrimaryType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['asset.read'])]
    public ?string $localOcrSourceUrl = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    #[Groups(['asset.read'])]
    public ?string $localOcrProvider = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    #[Groups(['asset.read'])]
    public ?string $localOcrModel = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['asset.read'])]
    public ?\DateTimeImmutable $localOcrAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['asset.read'])]
    public ?int $localOcrStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['asset.read'])]
    public ?string $localOcrError = null;

    /**
     * Source metadata from the originating aggregator — dcterms:* keyed JSONB.
     * Written by BatchController from client context hints (DC fields, rights, ARK, IIIF URLs).
     * Never overwritten by image analysis.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['asset.read'])]
    public ?array $sourceMeta = null;

    #[ORM\ManyToOne(targetEntity: IiifManifest::class, inversedBy: 'assets')]
    #[ORM\JoinColumn(name: 'iiif_manifest_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public ?IiifManifest $iiifManifestEntity = null;

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
      * Normalized aggregate built from aiCompleted for display/indexing.
      */
     #[ORM\Column(type: Types::JSON, nullable: true)]
     #[Groups(['asset.read'])]
     public ?array $mediaEnrichment = null;

     /** Cached DTO view of mediaEnrichment (not persisted). */
     public ?MediaEnrichment $enriched = null;

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

    private ?string $smallUrlOverride = null;

    #[Groups(['asset.read'])]
    public ?string $smallUrl {
        get => $this->smallUrlOverride
            ?? ImgProxyUrlHelper::small($this->archiveUrl ?? $this->originalUrl);

        set(?string $value) {
            $this->smallUrlOverride = $value;
        }
    }

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
        $completed = $this->aiCompleted;
        if ($completed === []
            && isset($this->context['aiTaskResults'])
            && is_array($this->context['aiTaskResults'])
        ) {
            $completed = $this->context['aiTaskResults'];
        }

        $byTask = [];
        foreach ($completed as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $task = $entry['task'] ?? null;
            $result = $entry['result'] ?? null;
            if (!is_string($task) || !is_array($result)) {
                continue;
            }

            if (empty($result['failed']) && empty($result['skipped'])) {
                $byTask[$task] = $this->normalizeTaskResult($task, $result);
            }
        }

        return $byTask;
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function normalizeTaskResult(string $task, array $result): array
    {
        if ($task !== 'enrich_from_thumbnail') {
            return $result;
        }

        $speculations = $result['speculations'] ?? null;
        if (!is_array($speculations)) {
            return $result;
        }

        $normalized = [];
        foreach ($speculations as $speculation) {
            if (is_array($speculation)) {
                $normalized[] = $speculation;
                continue;
            }

            if (!is_string($speculation) || trim($speculation) === '') {
                continue;
            }

            $decoded = json_decode($speculation, true);
            if (is_array($decoded)) {
                $normalized[] = $decoded;
                continue;
            }

            $normalized[] = ['claim' => $speculation];
        }

        $result['speculations'] = $normalized;

        return $result;
    }

    public function getAiTitle(): ?string
    {
        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['title'])) {
            return is_string($this->mediaEnrichment['title']) ? $this->mediaEnrichment['title'] : null;
        }

        $r = $this->aiResults();
        return $r['generate_title']['title'] ?? null;
    }

    public function getAiDescription(): ?string
    {
        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['description'])) {
            return is_string($this->mediaEnrichment['description']) ? $this->mediaEnrichment['description'] : null;
        }

        $r = $this->aiResults();
        return $r['context_description']['description']
            ?? $r['basic_description']['description']
            ?? null;
    }

    public function getAiOcrText(): ?string
    {
        if (is_string($this->localOcrText) && trim($this->localOcrText) !== '') {
            return $this->localOcrText;
        }

        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['ocrText'])) {
            return is_string($this->mediaEnrichment['ocrText']) ? $this->mediaEnrichment['ocrText'] : null;
        }

        $r = $this->aiResults();
        return $r['ocr_mistral']['text'] ?? $r['ocr']['text'] ?? $r['transcribe_handwriting']['text'] ?? null;
    }

    /** @return string[] */
    public function getAiKeywords(): array
    {
        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['keywords']) && is_array($this->mediaEnrichment['keywords'])) {
            return array_values(array_filter($this->mediaEnrichment['keywords'], static fn ($v): bool => is_string($v) && $v !== ''));
        }

        return $this->aiResults()['keywords']['keywords'] ?? [];
    }

    /** @return string[] */
    public function getAiPeople(): array
    {
        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['people']) && is_array($this->mediaEnrichment['people'])) {
            return array_values(array_filter($this->mediaEnrichment['people'], static fn ($v): bool => is_string($v) && $v !== ''));
        }

        $r = $this->aiResults();
        return $r['people_and_places']['people'] ?? $r['extract_metadata']['people'] ?? [];
    }

    /** @return string[] */
    public function getAiPlaces(): array
    {
        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['places']) && is_array($this->mediaEnrichment['places'])) {
            return array_values(array_filter($this->mediaEnrichment['places'], static fn ($v): bool => is_string($v) && $v !== ''));
        }

        $r = $this->aiResults();
        return $r['people_and_places']['places'] ?? $r['extract_metadata']['places'] ?? [];
    }

    /** @return string[] */
    public function getAiOrganisations(): array
    {
        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['organisations']) && is_array($this->mediaEnrichment['organisations'])) {
            return array_values(array_filter($this->mediaEnrichment['organisations'], static fn ($v): bool => is_string($v) && $v !== ''));
        }

        $r = $this->aiResults();
        return array_values(array_unique(array_merge(
            $r['people_and_places']['organisations'] ?? [],
            $r['extract_metadata']['organisations'] ?? [],
        )));
    }

    public function getAiDateRange(): ?string
    {
        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['dateRange'])) {
            return is_string($this->mediaEnrichment['dateRange']) ? $this->mediaEnrichment['dateRange'] : null;
        }

        return $this->aiResults()['extract_metadata']['dateRange'] ?? null;
    }

    public function getAiSummary(): ?string
    {
        if (is_array($this->mediaEnrichment)) {
            if (isset($this->mediaEnrichment['summary']) && is_string($this->mediaEnrichment['summary'])) {
                return $this->mediaEnrichment['summary'];
            }

            if (isset($this->mediaEnrichment['denseSummary']) && is_string($this->mediaEnrichment['denseSummary'])) {
                return $this->mediaEnrichment['denseSummary'];
            }
        }

        return $this->aiResults()['summarize']['summary'] ?? null;
    }

    public function getAiDocumentSubtype(): ?string
    {
        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['documentSubtype'])) {
            return is_string($this->mediaEnrichment['documentSubtype']) ? $this->mediaEnrichment['documentSubtype'] : null;
        }

        return $this->aiResults()['classify']['subtype'] ?? null;
    }

    public function getMediaEnrichmentDto(): ?MediaEnrichment
    {
        if ($this->enriched instanceof MediaEnrichment) {
            return $this->enriched;
        }

        if (!is_array($this->mediaEnrichment)) {
            return null;
        }

        $this->enriched = MediaEnrichment::fromArray($this->mediaEnrichment);
        return $this->enriched;
    }

    #[ORM\PostLoad]
    public function hydrateEnriched(): void
    {
        $this->enriched = is_array($this->mediaEnrichment)
            ? MediaEnrichment::fromArray($this->mediaEnrichment)
            : null;
    }

    /**
     * Get the enrich_from_thumbnail result as a typed DTO.
     * Returns null if that task hasn't run yet.
     */
    public function getEnrichFromThumbnail(): ?\Survos\AiPipelineBundle\Result\EnrichFromThumbnailResult
    {
        $data = $this->aiResults()['enrich_from_thumbnail'] ?? null;
        if (!is_array($data)) return null;

        return new \Survos\AiPipelineBundle\Result\EnrichFromThumbnailResult(
            title:           $data['title']         ?? null,
            description:     $data['description']   ?? null,
            keywords:        $data['keywords']       ?? [],
            people:          $data['people']         ?? [],
            places:          $data['places']         ?? [],
            contentType:     $data['content_type']   ?? null,
            dateHint:        $data['date_hint']      ?? null,
            hasText:         (bool)($data['has_text'] ?? false),
            denseSummary:    $data['dense_summary']  ?? null,
            confidence:      (float)($data['confidence'] ?? 1.0),
            speculations:    $data['speculations']   ?? [],
        );
    }

    /**
     * Task classification for display grouping:
     *   ocr   → OCR tab (view image + text together)
     *   image → AI Metadata tab (visual analysis)
     */
    public static function taskGroup(string $taskName): string
    {
        return match($taskName) {
            'ocr', 'ocr_mistral', 'transcribe_handwriting', 'annotate_handwriting', 'layout'
                => 'ocr',
            default
                => 'image',
        };
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
