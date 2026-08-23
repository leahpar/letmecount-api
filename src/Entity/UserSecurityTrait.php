<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\Groups;

trait UserSecurityTrait
{
    #[ORM\Column(length: 180)]
    #[Groups(['user:read', 'user:write'])]
    private ?string $username = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    #[Groups(['user:read'])]
    private array $roles = [];

    /**
     * Jeton de liaison à usage unique, généré par l'admin.
     * Sert uniquement à rattacher une identité externe (Google ou Apple) à ce
     * compte lors de la première connexion : ce n'est plus un moyen de connexion.
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['user:token'])]
    private ?string $token = null;

    /**
     * Identifiant Google (claim `sub`) de l'utilisateur, une fois son compte lié.
     * Stable et opaque : on ne stocke ni email ni nom (cf. doc/authentification-oauth.md).
     */
    #[ORM\Column(length: 255, unique: true, nullable: true)]
    #[Ignore]
    private ?string $googleSub = null;

    /**
     * Identifiant Apple (claim `sub`), même rôle que {@see $googleSub}.
     * Un compte n'est lié qu'à un seul provider (cf. décision D7).
     */
    #[ORM\Column(length: 255, unique: true, nullable: true)]
    #[Ignore]
    private ?string $appleSub = null;

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    #[Ignore]
    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    /**
     * @see UserInterface
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

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(?string $token): static
    {
        $this->token = $token;

        return $this;
    }

    public function getGoogleSub(): ?string
    {
        return $this->googleSub;
    }

    public function setGoogleSub(?string $googleSub): static
    {
        $this->googleSub = $googleSub;

        return $this;
    }

    public function getAppleSub(): ?string
    {
        return $this->appleSub;
    }

    public function setAppleSub(?string $appleSub): static
    {
        $this->appleSub = $appleSub;

        return $this;
    }

    /**
     * Un compte est « lié » dès qu'une identité externe y est rattachée, quel que
     * soit le provider : c'est ce qui interdit à un jeton d'invitation fuité de
     * détourner un compte déjà actif.
     */
    #[Ignore]
    public function isLinked(): bool
    {
        return null !== $this->googleSub || null !== $this->appleSub;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // @deprecated, to be removed when upgrading to Symfony 8
    }
}
