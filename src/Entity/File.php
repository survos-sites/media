<?php

namespace App\Entity;

use ApiPlatform\Elasticsearch\Filter\OrderFilter;
use App\Repository\FileRepository;
use App\Workflow\IFileWorkflow;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\CoreBundle\Entity\RouteParametersTrait;
use Survos\MeiliBundle\Metadata\Fields;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\Tree\Traits\TreeTrait;
use Survos\Tree\TreeInterface;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;
use Symfony\Component\Serializer\Annotation\Groups;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Serializer\Filter\PropertyFilter;

#[ApiResource(
    normalizationContext: ['groups' => ['Default', 'jstree', 'minimum', 'marking', 'transitions', 'rp']],
    denormalizationContext: ['groups' => ["Default", "minimum", "browse"]],
)]
#[ApiFilter(OrderFilter::class, properties: ['marking', 'org', 'shortName', 'fullName'], arguments: ['orderParameterName' => 'order'])]
#[ApiFilter(SearchFilter::class, properties: ['id' => 'exact', 'name' => 'partial', 'isDir' => 'exact'])]
#[Gedmo\Tree(type: "nested")]
#[ORM\Entity(repositoryClass: FileRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_STORAGE_PATH', fields: ['storage', 'path'])]
#[MeiliIndex(
    persisted: new Fields(
        groups: ['file.read']
    ),
    sortable: ['listingCount'],
    filterable: ['fileSize','listingCount','type']
)]
class File implements \Stringable, TreeInterface, MarkingInterface,RouteParametersInterface
{
    use TreeTrait;
    use MarkingTrait;
    use RouteParametersTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['minimum', 'search', 'jstree'])]
    private int $id;
    #[ORM\Column(type: 'string', length: 255)]
    #[Groups(['minimum', 'search', 'jstree'])]
    private(set) string $name;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastModified = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['file.read'])]
    private(set) ?int $fileSize = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['file.read'])]
    public ?int $listingCount = null;

    #[Groups(['file.read'])]
    public string $type { get => $this->isDir ? 'dir' : 'file'; }


//    private $children;
    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'files')]
        #[ORM\JoinColumn(nullable: false)]
        private(set) ?Storage $storage = null,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        #[Groups(['file.read'])]
        private(set) ?string  $path = null,
        #[ORM\Column(type: 'boolean')]
        #[Groups(['file.read'])]
        private(set) bool $isDir = false,
        #[ORM\Column(type: 'boolean')]
        #[Groups(['file.read'])]
        private(set) bool $isPublic = true,
    )
    {
        $this->children = new ArrayCollection();
        if ($storage) {
            $this->storage->addFile($this);
        }
        $this->marking = $this->isDir ? IFileWorkflow::PLACE_NEW_DIR : IFileWorkflow::PLACE_NEW_FILE;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): self
    {
        $this->isPublic = $isPublic;
        return $this;
    }

//    public function getId(): ?int
//    {
//        return $this->id;
//    }

    #[Groups(['minimum', 'search', 'jstree'])]
    public function getId(): ?string
    {
        return $this->id;
    }


    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(?string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getIsDir(): ?bool
    {
        return $this->isDir;
    }

    public function setIsDir(bool $isDir): self
    {
        $this->isDir = $isDir;

        return $this;
    }

    public function __toString(): string
    {
        return (string)$this->getName();
    }

    public function getExtension(): ?string
    {
        return pathinfo($this->getName(), PATHINFO_EXTENSION);
    }

    public function getStorage(): ?Storage
    {
        return $this->storage;
    }

    public function setStorage(?Storage $storage): static
    {
        $this->storage = $storage;

        return $this;
    }

    public function getLastModified(): ?\DateTimeInterface
    {
        return $this->lastModified;
    }

    public function setLastModified(\DateTimeInterface $lastModified): static
    {
        $this->lastModified = $lastModified;

        return $this;
    }


    public function getListingCount(): ?int
    {
        return $this->listingCount;
    }

    public function setListingCount(?int $listingCount): static
    {
        $this->listingCount = $listingCount;

        return $this;
    }

    public function getZoneId(): string
    {
        return $this->getStorage()->getCode();
    }
}
