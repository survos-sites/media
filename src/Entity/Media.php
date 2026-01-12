<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Common\Filter\DateFilterInterface;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\User;
use App\Repository\MediaRepository;
use App\Repository\UserRepository;
use App\Workflow\MediaFlowDefinition;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Jenssegers\ImageHash\Implementations\PerceptualHash;
use Survos\BabelBundle\Attribute\BabelLocale;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\CoreBundle\Entity\RouteParametersTrait;
use Survos\MeiliBundle\Api\Filter\FacetsFieldSearchFilter;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Gedmo\Mapping\Annotation as Gedmo;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;
use Survos\ThumbHashBundle\Service\Thumbhash;
use Symfony\Component\Serializer\Attribute\Groups;
use Zenstruck\Alias;
use Zenstruck\Metadata;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use App\Workflow\MediaFlowDefinition as WF;


use Survos\BabelBundle\Entity\Traits\BabelHooksTrait;

use Doctrine\ORM\Mapping\Column;

use Survos\BabelBundle\Attribute\Translatable;
#[ORM\Entity(repositoryClass: MediaRepository::class)]
//#[ORM\UniqueConstraint('image_owner_code', fields: ['owner', 'code'])]
#[ORM\Index(name: 'media_status_code_index', fields: ['statusCode'])]
#[ORM\Index(name: 'media_size', fields: ['size'])]
#[ApiResource(
    // ugh, used for meili
    normalizationContext: [
        'groups' => ['media.read', 'rp','translation','marking','_translations'],
    ],
    operations: [
        new Get(
            normalizationContext: ['groups' => ['media.read', 'marking']],
        ),
        new GetCollection(
            normalizationContext: ['groups' => ['media.read', 'marking']],
        )
    ]
)]
#[Metadata('meili', true)]
// for meili
#[ApiFilter(filterClass: FacetsFieldSearchFilter::class, properties: [
    'root', 'marking','mimeType'])]
// for the doctrine API
#[ApiFilter(filterClass: SearchFilter::class, properties: [
    'root' => 'exact',
    'statusCode' => 'exact',
    'marking' => 'exact',
])]
#[ApiFilter(filterClass: DateFilter::class, properties: [
    'createdAt'  => DateFilterInterface::EXCLUDE_NULL,
    'updatedAt'  => DateFilterInterface::EXCLUDE_NULL
    ])]
#[Alias('media')]
#[MeiliIndex()] // maybe bring back sometime, esp with descriptions

/** @deprecated */
class Media implements /* MarkingInterface, */ \Stringable, RouteParametersInterface
{
    use BabelHooksTrait;

    use MarkingTrait;
    use RouteParametersTrait;
    public const UNIQUE_PARAMETERS = ['mediaId' => 'code'];
    const MEILI_ROUTE = 'meili-media';

    // @todo: move to .env so we can test locally
    # don't delete the local storage version before these thumbnails are created
    public const FILTERS=['small',
        'medium',
//        'large'
    ];

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['media.read'])]
    public ?string $mimeType = null;

    #[ORM\Column(length: 8, nullable: true)]
    #[Groups(['media.read'])]
    #[BabelLocale()] // the locale of the metadata, e.g. description or caption
    public ?string $locale = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['media.read'])]
    public ?array $colors = null;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    #[Groups(['media.read'])]
    # see https://chatgpt.com/share/68ce7f0a-7a34-8010-a4ea-9bcbead863a0 for how to use including facets
    public ?array $colorAnalysis = null;

    public array $hexColors {
        get => array_map(fn($c) => sprintf('#%06X', $c), $this->colors??[]);
    }

    // Compare:
        //$dist = $hasher->distance($hash, $hasher->hash($otherPath)); // Hamming distance
    #[ORM\Column(length: 16, nullable: true)]
    public string $perceptualHash;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $tempFilename = null; // so onDownloadComplete knows to upload and delete the temp file

    #[ORM\Column(nullable: true)]
    #[Groups(['media.read'])]
    #[ApiFilter(OrderFilter::class)]
    public ?int $size = null; // in bytes

    #[ORM\Column(nullable: true, options: ['jsonb' => true])]
    #[Groups(['media.read'])]
    public ?array $resized = null; // array of size=>url, e.g. small=>https...

    #[Map(source: 'resizedCount', if: false)]
    public int $resizedCount { get => count($this->resized??[]); }

    #[Groups(['media.read'])]
    #[Map(source: 'userCode', if: false)]
    public string $userCode {
        get => $this->user->getId();
    }

    #[ORM\Column]
    #[Gedmo\Timestampable(on:"create")]
    #[ApiFilter(filterClass: OrderFilter::class)]
    private(set) ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    #[Gedmo\Timestampable(on:"update")]
    #[ApiFilter(filterClass: OrderFilter::class)]
    public ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, Thumb>
     */
    #[ORM\OneToMany(targetEntity: Thumb::class, mappedBy: 'media', orphanRemoval: true)]
    private Collection $thumbs;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['media.read'])]
    public ?string $blur = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['media.read'])]
    public ?int $statusCode = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['media.read'])]
    public ?int $originalHeight = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['media.read'])]
    public ?int $originalWidth = null;

    #[ORM\Column(length: 4, nullable: true)]
    public ?string $ext = null;

    #[ORM\Column(nullable: true, options: ['jsonb' => true])]
    public ?array $exif = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['media.read'])]
    public ?array $context = null;

    #[ORM\ManyToOne(inversedBy: 'medias')]
//    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    #[ORM\JoinColumn(nullable: false)]
    public ?User $user = null;

    /**
     * @param string|null $path
     */
    public function __construct(
        string $root,

        #[ORM\Id]
        #[ORM\Column(length: 255)]
        #[Groups(['media.read'])]
        #[ApiProperty(identifier: true)]
        #[MeiliId] // @phpstan-ignore-line

        private(set) ?string         $code=null, // includes root!
        #[ORM\Column(length: 255, nullable: true)]
        #[Groups(['media.read'])]
        public ?string         $path=null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        #[Groups(['media.read'])]
        public ?string         $originalUrl=null
    )
    {
        // Store root value temporarily for code generation
        $rootValue = $root;
        // cannot change the root, since it creates the file in storage there.  Code is also based on it.
        if ($this->originalUrl && !$this->code) {
            $this->code = SaisClientService::calculateCode(url: $this->originalUrl, root: $rootValue);

        }
        $this->thumbs = new ArrayCollection();
        $this->marking = MediaFlowDefinition::PLACE_NEW;
    }

    /**
     * Get the root (user code) from the relationship
     */
    public function getRoot(): ?string
    {
        return $this->user?->getId();
    }

    /**
     * Get the root (user code) for serialization without circular reference
     */
    #[Groups(['media.read'])]
    public function getRootCode(): string
    {
        return $this->user?->getId() ?? '';
    }


    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getOriginalUrl(): ?string
    {
        return $this->originalUrl;
    }

    public function setOriginalUrl(?string $originalUrl): static
    {
        $this->originalUrl = $originalUrl;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function addThumbData($filter, ?int $size = null, ?string $url=null): static
    {
        $filters = $this->resized??[];
        $filters[$filter] = $url;
        $this->resized = $filters;
        return $this;

    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable|string $createdAt): static
    {

        $this->createdAt = is_string($createdAt) ? new \DateTimeImmutable($createdAt) : $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable|string|null $updatedAt): static
    {
        $this->updatedAt = is_string($updatedAt) ? new \DateTimeImmutable($updatedAt) : $updatedAt;

        return $this;
    }


    public function __toString(): string
    {
        return $this->getCode();
    }

    public function getBlur(): ?string
    {
        return $this->blur;
    }

    public function setBlur(?string $blur): static
    {
        $this->blur = $blur;

        return $this;
    }

    public function getBlurData(): ?array
    {
        return $this->getBlur() ? Thumbhash::convertStringToHash($this->getBlur()) : null;
    }

    #[ApiProperty(identifier: false)]
    public function getId(): string
    {
        return $this->getCode();
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function setStatusCode(?int $statusCode): static
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    public function getOriginalHeight(): ?int
    {
        return $this->originalHeight;
    }

    public function setOriginalHeight(?int $originalHeight): static
    {
        $this->originalHeight = $originalHeight;

        return $this;
    }

    public function getOriginalWidth(): ?int
    {
        return $this->originalWidth;
    }

    public function setOriginalWidth(?int $originalWidth): static
    {
        $this->originalWidth = $originalWidth;

        return $this;
    }

    public function getExt(): ?string
    {
        return $this->ext;
    }

    public function setExt(?string $ext): static
    {
        $this->ext = $ext;

        return $this;
    }

    public function getExif(): ?array
    {
        return $this->exif;
    }

    public function setExif(?array $exif): static
    {
        $this->exif = $exif;

        return $this;
    }

    /**
     * Returns an array key by size, e.g. small: http://media/cache...
     *
     * Slow!  Optimize later (@todo)
     *
     * @return array<string,string>
     */
//    #[Groups(['media.read'])]
    public function refreshResized(): array
    {
        $resized = [];
        foreach ($this->getThumbs() as $thumb) {
            if ($this->size) {
                $resized[$thumb->liipCode] = $thumb->url;
            }
        }
        $this->resized = $resized;
        return $resized;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }

        // <BABEL:TRANSLATABLE:START description>
        #[Column(type: Types::TEXT, nullable: true)]
        private ?string $descriptionBacking = null;

        #[Translatable(context: NULL)]
        public ?string $description {
            get => $this->resolveTranslatable('description', $this->descriptionBacking, NULL);
            set => $this->descriptionBacking = $value;
        }
        // <BABEL:TRANSLATABLE:END description>

        // <BABEL:TRANSLATABLE:START caption>
        #[Column(type: Types::TEXT, nullable: true)]
        private ?string $captionBacking = null;

        #[Translatable(context: NULL)]
        public ?string $caption {
            get => $this->resolveTranslatable('caption', $this->captionBacking, NULL);
            set => $this->captionBacking = $value;
        }
        // <BABEL:TRANSLATABLE:END caption>
}
