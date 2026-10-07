<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One publication attempt with the (redacted) platform response, for troubleshooting. */
#[ORM\Entity]
#[ORM\Table(name: 'social_post_attempt')]
#[ORM\Index(name: 'idx_social_attempt_post', columns: ['post_id', 'attempted_at'])]
class SocialPostAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'attempts')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private SocialPost $post,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $attemptedAt,
        #[ORM\Column]
        private bool $success,
        #[ORM\Column(nullable: true)]
        private ?int $httpStatus = null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $errorMessage = null,
        /** Response body excerpt. Never contains the access token (redacted before storing). */
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $responseExcerpt = null,
        #[ORM\Column(nullable: true)]
        private ?int $durationMs = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): SocialPost
    {
        return $this->post;
    }

    public function getAttemptedAt(): \DateTimeImmutable
    {
        return $this->attemptedAt;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getResponseExcerpt(): ?string
    {
        return $this->responseExcerpt;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }
}
