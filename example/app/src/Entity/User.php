<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Base\Database\Attribute\DiscriminatorEntry;
use Doctrine\ORM\Mapping as ORM;

/**
 * The demo's member. base-bundle's User identifies people by email and has
 * no username; the forum and the mailbox address members by username (an
 * @mention, a recipient typed by hand), so the application's User carries
 * one - as every base-bundle application's User does.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'userProfile')]
#[DiscriminatorEntry(value: 'user')]
class User extends \Base\Entity\User
{
    #[ORM\Column(type: 'string', length: 255, unique: true)]
    protected $username;

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;

        return $this;
    }

    public function __toString(): string
    {
        return (string) ($this->username ?? parent::__toString());
    }
}
