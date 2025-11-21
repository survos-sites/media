<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\StorageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Survos\MeiliBundle\Metadata\MeiliIndex;

#[ORM\Entity(repositoryClass: StorageRepository::class)]
#[ApiResource]
#[MeiliIndex()]
class Storage implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $code = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adapter = null;

    /**
     * @var Collection<int, File>
     */
    #[ORM\OneToMany(targetEntity: File::class, mappedBy: 'storage', orphanRemoval: true)]
    private Collection $files;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $root = null;

    public function __construct()
    {
        $this->files = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getAdapter(): ?string
    {
        return $this->adapter;
    }

    public function setAdapter(string $adapter): static
    {
        $this->adapter = $adapter;

        return $this;
    }

    /**
     * @return Collection<int, File>
     */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function addFile(File $file): static
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
            $file->setStorage($this);
        }

        return $this;
    }

    public function removeFile(File $file): static
    {
        if ($this->files->removeElement($file)) {
            // set the owning side to null (unless already changed)
            if ($file->getStorage() === $this) {
                $file->setStorage(null);
            }
        }

        return $this;
    }

    public function __toString()
    {
        return $this->getCode();
    }

    public function getRoot(): ?string
    {
        return $this->root;
    }

    public function setRoot(?string $root): static
    {
        $this->root = $root;

        return $this;
    }
}
