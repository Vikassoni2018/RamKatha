<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContactMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ContactMessageRepository::class)]
#[ORM\Table(name: 'contact_message')]
#[ORM\Index(name: 'idx_contact_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_contact_read', columns: ['read_at'])]
class ContactMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Validation messages are translation keys of the "validators" domain.
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'contact.name.not_blank')]
    #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'contact.email.not_blank')]
    #[Assert\Email(message: 'contact.email.invalid')]
    #[Assert\Length(max: 180)]
    private string $email = '';

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'contact.subject.not_blank')]
    #[Assert\Length(max: 200)]
    private string $subject = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'contact.message.not_blank')]
    #[Assert\Length(min: 10, max: 5000)]
    private string $message = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $archived = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = trim((string) $email);

        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): static
    {
        $this->subject = trim((string) $subject);

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = trim((string) $message);

        return $this;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function markRead(\DateTimeImmutable $at): void
    {
        $this->readAt ??= $at;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): static
    {
        $this->archived = $archived;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
