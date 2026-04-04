<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MediaRecordRepository;
use App\Workflow\MediaRecordFlow;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;

#[ORM\Entity(repositoryClass: MediaRecordRepository::class)]
#[ORM\Table]
#[ORM\Index(name: 'idx_media_record_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_media_record_record_key', columns: ['record_key'])]
final class MediaRecord implements MarkingInterface, \Stringable
{
    use MarkingTrait;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $id;

    #[ORM\Column(name: 'record_key', type: Types::STRING, length: 191, unique: true)]
    public string $recordKey;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public ?string $label = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $sourceUrl = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    public ?string $sourceMime = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $ocrText = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $sourceMeta = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $context = null;

    #[ORM\Column(type: Types::INTEGER)]
    public int $childCount = 0;

    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $aiQueue = [];

    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $aiCompleted = [];

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    public bool $aiLocked = false;

    #[ORM\OneToMany(mappedBy: 'mediaRecord', targetEntity: Asset::class)]
    public Collection $assets;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    public function __construct(string $recordKey)
    {
        $normalized = trim($recordKey);
        if ($normalized === '') {
            throw new \InvalidArgumentException('media record key cannot be empty');
        }

        $this->recordKey = $normalized;
        $this->id = substr(hash('xxh3', strtolower($normalized)), 0, 16);
        $this->createdAt = new \DateTimeImmutable();
        $this->marking = MediaRecordFlow::PLACE_NEW;
        $this->assets = new ArrayCollection();
    }

    public function addAsset(Asset $asset): void
    {
        if (!$this->assets->contains($asset)) {
            $this->assets->add($asset);
        }
        if ($asset->mediaRecord !== $this) {
            $asset->mediaRecord = $this;
        }
        $this->childCount = $this->assets->count();
    }

    public function __toString(): string
    {
        return $this->recordKey;
    }
}
