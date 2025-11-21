<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\ThumbRepository;
use App\Workflow\ThumbFlowDefinition;
use Doctrine\ORM\Mapping as ORM;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;
use Zenstruck\Alias;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\ObjectMapper\Attribute\Map;
use App\Workflow\ThumbFlowDefinition as WF;
#[ORM\Entity(repositoryClass: ThumbRepository::class)]
#[ApiResource(
    operations: [
        new Get(
            normalizationContext: ['groups' => ['media.read']],
        ),
        new GetCollection(
            normalizationContext: ['groups' => ['media.read']],
        )
    ],
    normalizationContext: ['groups' => ['media.read']],
)]
#[ApiFilter(filterClass: SearchFilter::class, properties: [
    'liipCode' => 'exact',
    'marking' => 'exact',
    'media' => 'exact',
])]
#[Alias('thumb')]
//#[MeiliIndex()]

class Thumb implements MarkingInterface, \Stringable
{
    use MarkingTrait;


    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Groups(['thumb.read'])]
    #[ORM\Column(nullable: true)]
    public ?int $size = null;

    #[ORM\Column(nullable: true)]
    public ?int $w = null;

    #[ORM\Column(nullable: true)]
    public ?int $h = null;

    #[Groups(['thumb.read'])]
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $url = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'thumbs')]
        #[ORM\JoinColumn(referencedColumnName: 'code', nullable: false)]
        #[Map(source: 'media', if:false)]
        private ?Media $media = null,

        #[Groups(['thumb.read'])]
        #[ORM\Column(length: 16)]
        #[Map(source: 'liipCode', if:false)]
        private(set) ?string $liipCode = null,

    )
    {
        if ($this->media) {
            $media->addThumb($this);
        }
        $this->marking = 'new';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    // this is so we can import.
    public function setId(?int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getMedia(): ?Media
    {
        return $this->media;
    }

    public function setMedia(?Media $media): static
    {
        $this->media = $media;

        return $this;
    }

    public function getW(): ?int
    {
        return $this->w;
    }

    public function setW(?int $w): static
    {
        $this->w = $w;

        return $this;
    }

    public function getH(): ?int
    {
        return $this->h;
    }

    public function setH(?int $h): static
    {
        $this->h = $h;

        return $this;
    }

    public function __toString(): string
    {
        return $this->getMedia() . '-' . $this->liipCode;
        // TODO: Implement __toString() method.
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }
}
