<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Taxonomy\Category;
use App\Entity\Taxonomy\Deity;
use App\Entity\Taxonomy\Festival;
use App\Entity\Taxonomy\Scripture;
use App\Entity\Taxonomy\Series;
use App\Entity\Taxonomy\Tag;
use App\Entity\Traits\TimestampableTrait;
use App\Enum\AudioStatus;
use App\Enum\SourceType;
use App\Enum\StoryStatus;
use App\Enum\VerificationStatus;
use App\Repository\StoryRepository;
use App\Service\I18n\Locales;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A story (katha). Status changes go exclusively through StoryWorkflowService.
 * Lifetime counters (viewCount, listenCount, ...) are denormalised from DailyStoryMetric by the
 * analytics aggregation job, so page views never write to this row.
 */
#[ORM\Entity(repositoryClass: StoryRepository::class)]
#[ORM\Table(name: 'story')]
#[ORM\UniqueConstraint(name: 'uniq_story_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_story_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_story_status_published', columns: ['status', 'published_at'])]
#[ORM\Index(name: 'idx_story_featured_status', columns: ['featured', 'status', 'published_at'])]
#[ORM\Index(name: 'idx_story_status_scheduled', columns: ['status', 'scheduled_at'])]
#[ORM\Index(name: 'idx_story_status_popularity', columns: ['status', 'popularity_score'])]
#[ORM\Index(name: 'idx_story_status_duration', columns: ['status', 'audio_duration_seconds'])]
#[ORM\Index(name: 'idx_story_audio_status', columns: ['audio_status'])]
#[ORM\Index(name: 'idx_story_series_position', columns: ['series_id', 'series_position'])]
#[ORM\Index(name: 'idx_story_updated', columns: ['updated_at'])]
#[ORM\Index(name: 'ft_story_title', columns: ['title', 'subtitle', 'original_title'], flags: ['fulltext'])]
#[ORM\Index(name: 'ft_story_search', columns: ['title', 'subtitle', 'excerpt', 'search_text'], flags: ['fulltext'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'This slug is already used by another story.')]
class Story
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $uuid;

    // --- Content -----------------------------------------------------------------------------

    /** Display title in the story language (Hindi). */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title = '';

    /** Original title, e.g. in Sanskrit or as named in the source. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $originalTitle = null;

    #[ORM\Column(length: 190)]
    #[Assert\Length(max: 190)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'Slug may contain lowercase letters, digits and hyphens only.')]
    private string $slug = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $subtitle = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 600)]
    private string $excerpt = '';

    /** Sanitised HTML (see HtmlContentSanitizer). */
    #[ORM\Column(type: Types::TEXT, length: 16777215)]
    #[Assert\NotBlank]
    private string $body = '';

    /** Plain-text search document (body + taxonomy names), maintained by StoryContentService. */
    #[ORM\Column(type: Types::TEXT, length: 16777215)]
    private string $searchText = '';

    /** BCP-47 language code of the content. */
    #[ORM\Column(length: 10, options: ['default' => 'hi'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 10)]
    private string $language = 'hi';

    #[ORM\Column(options: ['default' => 0])]
    private int $wordCount = 0;

    #[ORM\Column(options: ['default' => 1])]
    private int $readingTimeMinutes = 1;

    // --- English version -----------------------------------------------------------------------
    // Optional, written or reviewed by an editor (never raw machine output). The story is offered in
    // English under /en once title, excerpt and text exist (hasEnglishVersion()); until then English
    // pages show the Hindi original with a notice. Edits reset source verification like Hindi edits.

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $titleEn = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $subtitleEn = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 600)]
    private ?string $excerptEn = null;

    /** Sanitised HTML (see HtmlContentSanitizer). */
    #[ORM\Column(type: Types::TEXT, length: 16777215, nullable: true)]
    private ?string $bodyEn = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 5000)]
    private ?string $sourceNotesEn = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $seoTitleEn = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $seoDescriptionEn = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $donationMessageEn = null;

    /** Derived from bodyEn by StoryContentService (0 = no English text). */
    #[ORM\Column(options: ['default' => 0])]
    private int $wordCountEn = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $readingTimeMinutesEn = 0;

    // --- Workflow ------------------------------------------------------------------------------

    #[ORM\Column(length: 32, enumType: StoryStatus::class)]
    private StoryStatus $status = StoryStatus::Draft;

    #[ORM\Column(options: ['default' => false])]
    private bool $featured = false;

    /** Reserved for future use; all content is currently free. */
    #[ORM\Column(options: ['default' => false])]
    private bool $premium = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstPublishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reviewNotes = null;

    // --- Source & authenticity ------------------------------------------------------------------

    #[ORM\Column(length: 40, enumType: SourceType::class)]
    private SourceType $sourceType = SourceType::TraditionalNarrative;

    #[ORM\Column(length: 32, enumType: VerificationStatus::class)]
    private VerificationStatus $verificationStatus = VerificationStatus::Unverified;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $sourceVerifiedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sourceVerifiedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $verificationNotes = null;

    /** General editorial notes about the sources (shown publicly in the source box). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 5000)]
    private ?string $sourceNotes = null;

    /** @var Collection<int, StorySourceReference> */
    #[ORM\OneToMany(targetEntity: StorySourceReference::class, mappedBy: 'story', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Valid]
    private Collection $sourceReferences;

    // --- Taxonomy ------------------------------------------------------------------------------

    /** @var Collection<int, Category> */
    #[ORM\ManyToMany(targetEntity: Category::class)]
    #[ORM\JoinTable(name: 'story_category')]
    #[ORM\JoinColumn(name: 'story_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'category_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $categories;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'story_tag')]
    #[ORM\JoinColumn(name: 'story_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $tags;

    /** @var Collection<int, Deity> */
    #[ORM\ManyToMany(targetEntity: Deity::class)]
    #[ORM\JoinTable(name: 'story_deity')]
    #[ORM\JoinColumn(name: 'story_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'deity_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $deities;

    /** @var Collection<int, Festival> */
    #[ORM\ManyToMany(targetEntity: Festival::class)]
    #[ORM\JoinTable(name: 'story_festival')]
    #[ORM\JoinColumn(name: 'story_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'festival_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $festivals;

    /** Primary scripture (for /granth browsing). Detailed citations live in sourceReferences. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Scripture $scripture = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Series $series = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $seriesPosition = null;

    // --- Media ---------------------------------------------------------------------------------

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?MediaFile $coverImage = null;

    /** Optional dedicated social sharing image (falls back to cover, then site default). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?MediaFile $ogImage = null;

    // --- Audio ---------------------------------------------------------------------------------

    #[ORM\Column(options: ['default' => true])]
    private bool $audioEnabled = true;

    /** Optional per-story voice override for the TTS provider. */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $ttsVoice = null;

    #[ORM\Column(length: 20, nullable: true, enumType: AudioStatus::class)]
    private ?AudioStatus $audioStatus = null;

    #[ORM\Column(nullable: true)]
    private ?int $audioDurationSeconds = null;

    #[ORM\ManyToOne(targetEntity: AudioAsset::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?AudioAsset $currentAudio = null;

    /** True when content changed after the current audio was generated. */
    #[ORM\Column(options: ['default' => false])]
    private bool $audioOutdated = false;

    // --- SEO & social --------------------------------------------------------------------------

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $seoTitle = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $seoDescription = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    #[Assert\Url(requireTld: true)]
    private ?string $canonicalUrl = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $robotsIndex = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $robotsFollow = true;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $facebookTitle = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $facebookDescription = null;

    /** Queue a Facebook post automatically when this story gets published. */
    #[ORM\Column(options: ['default' => false])]
    private bool $autoPublishFacebook = false;

    // --- Support CTA ---------------------------------------------------------------------------

    #[ORM\Column(options: ['default' => true])]
    private bool $showDonationCta = true;

    /** Optional story-specific voluntary support message (overrides the site default). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $donationMessage = null;

    // --- Denormalised counters (maintained by AnalyticsAggregator) -----------------------------

    #[ORM\Column(options: ['default' => 0])]
    private int $viewCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $listenCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $completionCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $bookmarkCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $shareCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $donationCount = 0;

    /** Verified contributions attributed to this story, in paise. */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int|string $donationAmountPaise = 0;

    /** Recency-weighted engagement score (see PopularityCalculator). */
    #[ORM\Column(options: ['default' => 0])]
    private int $popularityScore = 0;

    public function __construct()
    {
        $this->uuid = Uuid::v7();
        $this->sourceReferences = new ArrayCollection();
        $this->categories = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->deities = new ArrayCollection();
        $this->festivals = new ArrayCollection();
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = trim($title);

        return $this;
    }

    public function getOriginalTitle(): ?string
    {
        return $this->originalTitle;
    }

    public function setOriginalTitle(?string $originalTitle): static
    {
        $this->originalTitle = $originalTitle;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getSubtitle(): ?string
    {
        return $this->subtitle;
    }

    public function setSubtitle(?string $subtitle): static
    {
        $this->subtitle = $subtitle;

        return $this;
    }

    public function getExcerpt(): string
    {
        return $this->excerpt;
    }

    public function setExcerpt(string $excerpt): static
    {
        $this->excerpt = trim($excerpt);

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getSearchText(): string
    {
        return $this->searchText;
    }

    public function setSearchText(string $searchText): static
    {
        $this->searchText = $searchText;

        return $this;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setLanguage(string $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getWordCount(): int
    {
        return $this->wordCount;
    }

    public function setWordCount(int $wordCount): static
    {
        $this->wordCount = $wordCount;

        return $this;
    }

    public function getReadingTimeMinutes(): int
    {
        return $this->readingTimeMinutes;
    }

    public function setReadingTimeMinutes(int $readingTimeMinutes): static
    {
        $this->readingTimeMinutes = max(1, $readingTimeMinutes);

        return $this;
    }

    /** Reading time of the text shown in that locale (English text when it exists). */
    public function getReadingTimeMinutesFor(string $locale): int
    {
        return Locales::ENGLISH === $locale && $this->readingTimeMinutesEn > 0 ? $this->readingTimeMinutesEn : $this->readingTimeMinutes;
    }

    // --- English version -----------------------------------------------------------------------

    /** True when the story can be read in English (title, excerpt and text are present). */
    public function hasEnglishVersion(): bool
    {
        foreach ([$this->titleEn, $this->excerptEn, $this->bodyEn] as $value) {
            if (null === $value || '' === trim(strip_tags($value))) {
                return false;
            }
        }

        return true;
    }

    /** An English version is all or nothing: a half-filled one would mix languages on the page. */
    #[Assert\Callback]
    public function validateEnglishVersion(ExecutionContextInterface $context): void
    {
        $fields = ['titleEn' => $this->titleEn, 'excerptEn' => $this->excerptEn, 'bodyEn' => $this->bodyEn];
        $filled = array_filter($fields, static fn (?string $v) => null !== $v && '' !== trim(strip_tags($v)));
        $optional = array_filter([$this->subtitleEn, $this->sourceNotesEn, $this->seoTitleEn, $this->seoDescriptionEn, $this->donationMessageEn], static fn (?string $v) => null !== $v && '' !== trim($v));
        if ([] === $filled && [] === $optional) {
            return;
        }
        foreach (array_diff_key($fields, $filled) as $field => $value) {
            $context->buildViolation('The English version needs a title, an excerpt and the story text (or leave all English fields empty).')
                ->atPath($field)
                ->addViolation();
        }
    }

    public function getTitleEn(): ?string
    {
        return $this->titleEn;
    }

    public function setTitleEn(?string $titleEn): static
    {
        $this->titleEn = self::nullIfBlank($titleEn);

        return $this;
    }

    public function getSubtitleEn(): ?string
    {
        return $this->subtitleEn;
    }

    public function setSubtitleEn(?string $subtitleEn): static
    {
        $this->subtitleEn = self::nullIfBlank($subtitleEn);

        return $this;
    }

    public function getExcerptEn(): ?string
    {
        return $this->excerptEn;
    }

    public function setExcerptEn(?string $excerptEn): static
    {
        $this->excerptEn = self::nullIfBlank($excerptEn);

        return $this;
    }

    public function getBodyEn(): ?string
    {
        return $this->bodyEn;
    }

    public function setBodyEn(?string $bodyEn): static
    {
        $this->bodyEn = null !== $bodyEn && '' !== trim(strip_tags($bodyEn)) ? $bodyEn : null;

        return $this;
    }

    public function getSourceNotesEn(): ?string
    {
        return $this->sourceNotesEn;
    }

    public function setSourceNotesEn(?string $sourceNotesEn): static
    {
        $this->sourceNotesEn = self::nullIfBlank($sourceNotesEn);

        return $this;
    }

    public function getSeoTitleEn(): ?string
    {
        return $this->seoTitleEn;
    }

    public function setSeoTitleEn(?string $seoTitleEn): static
    {
        $this->seoTitleEn = self::nullIfBlank($seoTitleEn);

        return $this;
    }

    public function getSeoDescriptionEn(): ?string
    {
        return $this->seoDescriptionEn;
    }

    public function setSeoDescriptionEn(?string $seoDescriptionEn): static
    {
        $this->seoDescriptionEn = self::nullIfBlank($seoDescriptionEn);

        return $this;
    }

    public function getDonationMessageEn(): ?string
    {
        return $this->donationMessageEn;
    }

    public function setDonationMessageEn(?string $donationMessageEn): static
    {
        $this->donationMessageEn = self::nullIfBlank($donationMessageEn);

        return $this;
    }

    public function getWordCountEn(): int
    {
        return $this->wordCountEn;
    }

    public function setWordCountEn(int $wordCountEn): static
    {
        $this->wordCountEn = max(0, $wordCountEn);

        return $this;
    }

    public function getReadingTimeMinutesEn(): int
    {
        return $this->readingTimeMinutesEn;
    }

    public function setReadingTimeMinutesEn(int $readingTimeMinutesEn): static
    {
        $this->readingTimeMinutesEn = max(0, $readingTimeMinutesEn);

        return $this;
    }

    private static function nullIfBlank(?string $value): ?string
    {
        $value = null !== $value ? trim($value) : null;

        return '' === $value ? null : $value;
    }

    public function getStatus(): StoryStatus
    {
        return $this->status;
    }

    /** @internal use StoryWorkflowService */
    public function setStatus(StoryStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isPublished(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return StoryStatus::Published === $this->status && null !== $this->publishedAt && $this->publishedAt <= $now;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setFeatured(bool $featured): static
    {
        $this->featured = $featured;

        return $this;
    }

    public function isPremium(): bool
    {
        return $this->premium;
    }

    public function setPremium(bool $premium): static
    {
        $this->premium = $premium;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;
        if (null !== $publishedAt && null === $this->firstPublishedAt) {
            $this->firstPublishedAt = $publishedAt;
        }

        return $this;
    }

    public function getFirstPublishedAt(): ?\DateTimeImmutable
    {
        return $this->firstPublishedAt;
    }

    public function getScheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;

        return $this;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function setArchivedAt(?\DateTimeImmutable $archivedAt): static
    {
        $this->archivedAt = $archivedAt;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getReviewNotes(): ?string
    {
        return $this->reviewNotes;
    }

    public function recordReview(User $reviewer, \DateTimeImmutable $at, ?string $notes): static
    {
        $this->reviewedBy = $reviewer;
        $this->reviewedAt = $at;
        $this->reviewNotes = $notes;

        return $this;
    }

    public function getSourceType(): SourceType
    {
        return $this->sourceType;
    }

    public function setSourceType(SourceType $sourceType): static
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    public function getVerificationStatus(): VerificationStatus
    {
        return $this->verificationStatus;
    }

    public function isSourceVerified(): bool
    {
        return VerificationStatus::Verified === $this->verificationStatus && null !== $this->sourceVerifiedBy && null !== $this->sourceVerifiedAt;
    }

    /** @internal use StoryVerificationService */
    public function markSourceVerified(User $verifier, \DateTimeImmutable $at, ?string $notes): static
    {
        $this->verificationStatus = VerificationStatus::Verified;
        $this->sourceVerifiedBy = $verifier;
        $this->sourceVerifiedAt = $at;
        $this->verificationNotes = $notes;

        return $this;
    }

    /** @internal use StoryVerificationService */
    public function resetSourceVerification(VerificationStatus $status = VerificationStatus::Unverified, ?string $notes = null): static
    {
        $this->verificationStatus = $status;
        $this->sourceVerifiedBy = null;
        $this->sourceVerifiedAt = null;
        if (null !== $notes) {
            $this->verificationNotes = $notes;
        }

        return $this;
    }

    public function getSourceVerifiedBy(): ?User
    {
        return $this->sourceVerifiedBy;
    }

    public function getSourceVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->sourceVerifiedAt;
    }

    public function getVerificationNotes(): ?string
    {
        return $this->verificationNotes;
    }

    public function getSourceNotes(): ?string
    {
        return $this->sourceNotes;
    }

    public function setSourceNotes(?string $sourceNotes): static
    {
        $this->sourceNotes = $sourceNotes;

        return $this;
    }

    /** @return Collection<int, StorySourceReference> */
    public function getSourceReferences(): Collection
    {
        return $this->sourceReferences;
    }

    public function addSourceReference(StorySourceReference $reference): static
    {
        if (!$this->sourceReferences->contains($reference)) {
            $this->sourceReferences->add($reference);
            $reference->setStory($this);
        }

        return $this;
    }

    public function removeSourceReference(StorySourceReference $reference): static
    {
        $this->sourceReferences->removeElement($reference);

        return $this;
    }

    /** @return Collection<int, Category> */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Category $category): static
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
        }

        return $this;
    }

    public function removeCategory(Category $category): static
    {
        $this->categories->removeElement($category);

        return $this;
    }

    public function getPrimaryCategory(): ?Category
    {
        $first = $this->categories->first();

        return false === $first ? null : $first;
    }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    /** @return Collection<int, Deity> */
    public function getDeities(): Collection
    {
        return $this->deities;
    }

    public function addDeity(Deity $deity): static
    {
        if (!$this->deities->contains($deity)) {
            $this->deities->add($deity);
        }

        return $this;
    }

    public function removeDeity(Deity $deity): static
    {
        $this->deities->removeElement($deity);

        return $this;
    }

    /** @return Collection<int, Festival> */
    public function getFestivals(): Collection
    {
        return $this->festivals;
    }

    public function addFestival(Festival $festival): static
    {
        if (!$this->festivals->contains($festival)) {
            $this->festivals->add($festival);
        }

        return $this;
    }

    public function removeFestival(Festival $festival): static
    {
        $this->festivals->removeElement($festival);

        return $this;
    }

    public function getScripture(): ?Scripture
    {
        return $this->scripture;
    }

    public function setScripture(?Scripture $scripture): static
    {
        $this->scripture = $scripture;

        return $this;
    }

    public function getSeries(): ?Series
    {
        return $this->series;
    }

    public function setSeries(?Series $series): static
    {
        $this->series = $series;

        return $this;
    }

    public function getSeriesPosition(): ?int
    {
        return $this->seriesPosition;
    }

    public function setSeriesPosition(?int $seriesPosition): static
    {
        $this->seriesPosition = $seriesPosition;

        return $this;
    }

    public function getCoverImage(): ?MediaFile
    {
        return $this->coverImage;
    }

    public function setCoverImage(?MediaFile $coverImage): static
    {
        $this->coverImage = $coverImage;

        return $this;
    }

    public function getOgImage(): ?MediaFile
    {
        return $this->ogImage;
    }

    public function setOgImage(?MediaFile $ogImage): static
    {
        $this->ogImage = $ogImage;

        return $this;
    }

    public function isAudioEnabled(): bool
    {
        return $this->audioEnabled;
    }

    public function setAudioEnabled(bool $audioEnabled): static
    {
        $this->audioEnabled = $audioEnabled;

        return $this;
    }

    public function getTtsVoice(): ?string
    {
        return $this->ttsVoice;
    }

    public function setTtsVoice(?string $ttsVoice): static
    {
        $this->ttsVoice = $ttsVoice;

        return $this;
    }

    public function getAudioStatus(): ?AudioStatus
    {
        return $this->audioStatus;
    }

    public function setAudioStatus(?AudioStatus $audioStatus): static
    {
        $this->audioStatus = $audioStatus;

        return $this;
    }

    public function getAudioDurationSeconds(): ?int
    {
        return $this->audioDurationSeconds;
    }

    public function setAudioDurationSeconds(?int $audioDurationSeconds): static
    {
        $this->audioDurationSeconds = $audioDurationSeconds;

        return $this;
    }

    public function getCurrentAudio(): ?AudioAsset
    {
        return $this->currentAudio;
    }

    public function setCurrentAudio(?AudioAsset $currentAudio): static
    {
        $this->currentAudio = $currentAudio;

        return $this;
    }

    /** Whether playable audio exists for the public player. */
    public function hasPlayableAudio(): bool
    {
        return $this->audioEnabled && null !== $this->currentAudio && AudioStatus::Ready === $this->currentAudio->getStatus();
    }

    public function isAudioOutdated(): bool
    {
        return $this->audioOutdated;
    }

    public function setAudioOutdated(bool $audioOutdated): static
    {
        $this->audioOutdated = $audioOutdated;

        return $this;
    }

    public function getSeoTitle(): ?string
    {
        return $this->seoTitle;
    }

    public function setSeoTitle(?string $seoTitle): static
    {
        $this->seoTitle = $seoTitle;

        return $this;
    }

    public function getSeoDescription(): ?string
    {
        return $this->seoDescription;
    }

    public function setSeoDescription(?string $seoDescription): static
    {
        $this->seoDescription = $seoDescription;

        return $this;
    }

    public function getCanonicalUrl(): ?string
    {
        return $this->canonicalUrl;
    }

    public function setCanonicalUrl(?string $canonicalUrl): static
    {
        $this->canonicalUrl = $canonicalUrl;

        return $this;
    }

    public function isRobotsIndex(): bool
    {
        return $this->robotsIndex;
    }

    public function setRobotsIndex(bool $robotsIndex): static
    {
        $this->robotsIndex = $robotsIndex;

        return $this;
    }

    public function isRobotsFollow(): bool
    {
        return $this->robotsFollow;
    }

    public function setRobotsFollow(bool $robotsFollow): static
    {
        $this->robotsFollow = $robotsFollow;

        return $this;
    }

    public function getFacebookTitle(): ?string
    {
        return $this->facebookTitle;
    }

    public function setFacebookTitle(?string $facebookTitle): static
    {
        $this->facebookTitle = $facebookTitle;

        return $this;
    }

    public function getFacebookDescription(): ?string
    {
        return $this->facebookDescription;
    }

    public function setFacebookDescription(?string $facebookDescription): static
    {
        $this->facebookDescription = $facebookDescription;

        return $this;
    }

    public function isAutoPublishFacebook(): bool
    {
        return $this->autoPublishFacebook;
    }

    public function setAutoPublishFacebook(bool $autoPublishFacebook): static
    {
        $this->autoPublishFacebook = $autoPublishFacebook;

        return $this;
    }

    public function isShowDonationCta(): bool
    {
        return $this->showDonationCta;
    }

    public function setShowDonationCta(bool $showDonationCta): static
    {
        $this->showDonationCta = $showDonationCta;

        return $this;
    }

    public function getDonationMessage(): ?string
    {
        return $this->donationMessage;
    }

    public function setDonationMessage(?string $donationMessage): static
    {
        $this->donationMessage = $donationMessage;

        return $this;
    }

    public function getViewCount(): int
    {
        return $this->viewCount;
    }

    public function getListenCount(): int
    {
        return $this->listenCount;
    }

    public function getCompletionCount(): int
    {
        return $this->completionCount;
    }

    public function getBookmarkCount(): int
    {
        return $this->bookmarkCount;
    }

    public function getShareCount(): int
    {
        return $this->shareCount;
    }

    public function getDonationCount(): int
    {
        return $this->donationCount;
    }

    public function getDonationAmountPaise(): int
    {
        return (int) $this->donationAmountPaise;
    }

    public function getPopularityScore(): int
    {
        return $this->popularityScore;
    }

    /** Effective SEO title with fallback. */
    public function getEffectiveSeoTitle(): string
    {
        return $this->seoTitle ?: $this->title;
    }

    public function getEffectiveSeoDescription(): string
    {
        return $this->seoDescription ?: $this->excerpt;
    }

    /** English pages use the English SEO fields once an English version exists. */
    public function getEffectiveSeoTitleFor(string $locale): string
    {
        if (Locales::ENGLISH === $locale && $this->hasEnglishVersion()) {
            return $this->seoTitleEn ?: (string) $this->titleEn;
        }

        return $this->getEffectiveSeoTitle();
    }

    public function getEffectiveSeoDescriptionFor(string $locale): string
    {
        if (Locales::ENGLISH === $locale && $this->hasEnglishVersion()) {
            return $this->seoDescriptionEn ?: (string) $this->excerptEn;
        }

        return $this->getEffectiveSeoDescription();
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
