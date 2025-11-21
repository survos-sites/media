<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Symfony\Component\Serializer\Attribute\Groups;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Survos\SaisBundle\Service\SaisClientService;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
//#[ORM\Table(name: '`user`')]
#[ORM\Table(name: 'users')]
//#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['id'], message: 'There is already an account with this id')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, \Stringable
{

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column]
    private bool $isVerified = false;


    #[ORM\Column(length: 255, nullable: true)]
    private ?string $apiKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column]
    #[Groups(['user.read'])]
    private ?int $binCount = null;

    /**
     * @var Collection<int, Media>
     */
    #[ORM\OneToMany(targetEntity: Media::class, mappedBy: 'user', orphanRemoval: true)]
    #[Groups(['user.medias'])]
    public Collection $medias;


    /**
     * @param string|null $id
     * @param int|null $approxImageCount
     * @param string|null $mediaCallbackUrl
     * @param string|null $thumbCallbackUrl
     */
    public function __construct(
        #[ORM\Column(length: 255)]
        #[Assert\Unique()]
        #[ORM\Id]
        #[Groups(['user.read'])]
        private ?string $id = null,

        #[ORM\Column]
        #[Groups(['user.read'])]
        public ?int    $approxImageCount = null,

        #[ORM\Column(length: 255, nullable: true)]
        #[Groups(['user.read'])]
        public ?string $mediaCallbackUrl = null,

        #[ORM\Column(length: 255, nullable: true)]
        #[Groups(['user.read'])]
        public ?string $thumbCallbackUrl = null,
    )
    {
        $this->medias = new ArrayCollection();
        $this->password = $this->id;
        $this->binCount = SaisClientService::calculateBinCount($this->approxImageCount??0);
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string)$this->id;
    }

    /**
     * @return list<string>
     * @see UserInterface
     *
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function getMediaCallbackUrl(): ?string
    {
        return $this->mediaCallbackUrl;
    }

    public function setMediaCallbackUrl(?string $mediaCallbackUrl): static
    {
        $this->mediaCallbackUrl = $mediaCallbackUrl;

        return $this;
    }

    public function getThumbCallbackUrl(): ?string
    {
        return $this->thumbCallbackUrl;
    }

    public function setThumbCallbackUrl(?string $thumbCallbackUrl): static
    {
        $this->thumbCallbackUrl = $thumbCallbackUrl;

        return $this;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setApiKey(?string $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    public function getApproxImageCount(): ?int
    {
        return $this->approxImageCount;
    }

    public function setApproxImageCount(int $approxImageCount): static
    {
        $this->approxImageCount = $approxImageCount;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getBinCount(): ?int
    {
        return $this->binCount;
    }

    public function setBinCount(int $binCount): static
    {
        if ($this->binCount && ($this->binCount <> $binCount)) {
            throw new \Exception("bin count cannot be changed if images have been imported " . $this->approxImageCount);
        }
        $this->binCount = $binCount;

        return $this;
    }

    /**
     * @return Collection<int, Media>
     */
    public function getMedias(): Collection
    {
        return $this->medias;
    }

    public function addMedia(Media $media): static
    {
        if (!$this->medias->contains($media)) {
            $this->medias->add($media);
            $media->setUser($this);
        }

        return $this;
    }

    public function removeMedia(Media $media): static
    {
        if ($this->medias->removeElement($media)) {
            // set the owning side to null (unless already changed)
            if ($media->getUser() === $this) {
                $media->setUser(null);
            }
        }

        return $this;
    }

    public function __toString()
    {
        return $this->id;
    }
}
