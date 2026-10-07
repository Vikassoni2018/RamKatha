<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\DonationStatus;
use App\Enum\PaymentMethod;
use App\Repository\DonationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A voluntary contribution. Amounts are stored as integer paise (never floats).
 * NEVER stores UPI PINs, OTPs, card or bank credentials — only an optional transaction reference.
 * Status changes go exclusively through DonationService (audited, transactional).
 */
#[ORM\Entity(repositoryClass: DonationRepository::class)]
#[ORM\Table(name: 'donation')]
#[ORM\UniqueConstraint(name: 'uniq_donation_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_donation_reference_code', columns: ['reference_code'])]
#[ORM\Index(name: 'idx_donation_status_created', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_donation_story_status', columns: ['story_id', 'status'])]
#[ORM\Index(name: 'idx_donation_reference', columns: ['upi_transaction_reference'])]
#[ORM\Index(name: 'idx_donation_created', columns: ['created_at'])]
#[ORM\HasLifecycleCallbacks]
class Donation
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Story $story = null;

    /** Payer / donor name (optional). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $donorName = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column]
    private int $amountPaise;

    #[ORM\Column(length: 3, options: ['default' => 'INR'])]
    private string $currency = 'INR';

    #[ORM\Column(length: 20, enumType: PaymentMethod::class)]
    private PaymentMethod $paymentMethod = PaymentMethod::UpiManual;

    #[ORM\Column(length: 20, enumType: DonationStatus::class)]
    private DonationStatus $status = DonationStatus::Initiated;

    /** Transaction note (tn) used in the UPI intent — short, sanitised, contains a reference code. */
    #[ORM\Column(length: 80)]
    private string $paymentNote = '';

    /** Short human-friendly reference shown to the donor (e.g. "KS7F3K2Q"). */
    #[ORM\Column(length: 16)]
    private string $referenceCode = '';

    /** UTR / UPI transaction reference declared by the donor (optional). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $upiTransactionReference = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paymentDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $declaredAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $anonymous = false;

    /** Optional message from the donor. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNotes = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $verifiedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $rejectedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rejectedAt = null;

    /** Future gateway integration (Mode B). */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $gatewayName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $gatewayPaymentId = null;

    public function __construct(int $amountPaise, string $referenceCode, string $paymentNote, string $currency = 'INR')
    {
        if ($amountPaise <= 0) {
            throw new \InvalidArgumentException('Donation amount must be positive.');
        }
        $this->uuid = Uuid::v7();
        $this->amountPaise = $amountPaise;
        $this->referenceCode = $referenceCode;
        $this->paymentNote = $paymentNote;
        $this->currency = $currency;
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getStory(): ?Story
    {
        return $this->story;
    }

    public function setStory(?Story $story): static
    {
        $this->story = $story;

        return $this;
    }

    public function getDonorName(): ?string
    {
        return $this->donorName;
    }

    public function setDonorName(?string $donorName): static
    {
        $this->donorName = $donorName;

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

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getAmountPaise(): int
    {
        return $this->amountPaise;
    }

    /** Only allowed before admin review (donor may correct the declared amount). */
    public function setAmountPaise(int $amountPaise): static
    {
        if ($amountPaise <= 0) {
            throw new \InvalidArgumentException('Donation amount must be positive.');
        }
        $this->amountPaise = $amountPaise;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getPaymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(PaymentMethod $paymentMethod): static
    {
        $this->paymentMethod = $paymentMethod;

        return $this;
    }

    public function getStatus(): DonationStatus
    {
        return $this->status;
    }

    /** @internal use DonationService */
    public function setStatus(DonationStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPaymentNote(): string
    {
        return $this->paymentNote;
    }

    public function getReferenceCode(): string
    {
        return $this->referenceCode;
    }

    public function getUpiTransactionReference(): ?string
    {
        return $this->upiTransactionReference;
    }

    public function setUpiTransactionReference(?string $upiTransactionReference): static
    {
        $this->upiTransactionReference = $upiTransactionReference;

        return $this;
    }

    public function getPaymentDate(): ?\DateTimeImmutable
    {
        return $this->paymentDate;
    }

    public function setPaymentDate(?\DateTimeImmutable $paymentDate): static
    {
        $this->paymentDate = $paymentDate;

        return $this;
    }

    public function getDeclaredAt(): ?\DateTimeImmutable
    {
        return $this->declaredAt;
    }

    public function setDeclaredAt(?\DateTimeImmutable $declaredAt): static
    {
        $this->declaredAt = $declaredAt;

        return $this;
    }

    public function isAnonymous(): bool
    {
        return $this->anonymous;
    }

    public function setAnonymous(bool $anonymous): static
    {
        $this->anonymous = $anonymous;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getAdminNotes(): ?string
    {
        return $this->adminNotes;
    }

    public function setAdminNotes(?string $adminNotes): static
    {
        $this->adminNotes = $adminNotes;

        return $this;
    }

    public function getVerifiedBy(): ?User
    {
        return $this->verifiedBy;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function markVerified(User $admin, \DateTimeImmutable $at): void
    {
        $this->verifiedBy = $admin;
        $this->verifiedAt = $at;
        $this->rejectedBy = null;
        $this->rejectedAt = null;
    }

    public function getRejectedBy(): ?User
    {
        return $this->rejectedBy;
    }

    public function getRejectedAt(): ?\DateTimeImmutable
    {
        return $this->rejectedAt;
    }

    public function markRejected(User $admin, \DateTimeImmutable $at): void
    {
        $this->rejectedBy = $admin;
        $this->rejectedAt = $at;
    }

    public function getGatewayName(): ?string
    {
        return $this->gatewayName;
    }

    public function getGatewayPaymentId(): ?string
    {
        return $this->gatewayPaymentId;
    }

    public function setGatewayReference(?string $gatewayName, ?string $gatewayPaymentId): static
    {
        $this->gatewayName = $gatewayName;
        $this->gatewayPaymentId = $gatewayPaymentId;

        return $this;
    }

    /** Name safe to show in admin lists (respects the anonymity wish in exports). */
    public function getDisplayName(): string
    {
        if ($this->anonymous) {
            return 'Anonymous';
        }

        return $this->donorName ?: ($this->user?->getDisplayName() ?? '—');
    }
}
