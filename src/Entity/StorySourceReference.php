<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Taxonomy\Scripture;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A precise citation for a story: which book, volume/skand, chapter, section and verses,
 * which edition and translator. A story can cite several sources.
 */
#[ORM\Entity]
#[ORM\Table(name: 'story_source_reference')]
#[ORM\Index(name: 'idx_source_ref_story_position', columns: ['story_id', 'position'])]
class StorySourceReference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'sourceReferences')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Story $story = null;

    /** Linked scripture from the taxonomy (optional). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Scripture $scripture = null;

    /** Free-text title of the source (required when no scripture is linked). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $sourceTitle = null;

    /** Volume / skandha / kanda / parva. */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $volume = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $chapter = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $section = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $verseReferences = null;

    /** Edition / publisher, e.g. "गीता प्रेस, गोरखपुर — संस्करण 2018". */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $edition = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $translator = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    #[Assert\Url(requireTld: true)]
    private ?string $url = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $notes = null;

    /** English note for /en pages. Citation fields (volume, chapter, ...) are shown as entered. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $notesEn = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[Assert\Callback]
    public function validateTitle(ExecutionContextInterface $context): void
    {
        if (null === $this->scripture && (null === $this->sourceTitle || '' === trim($this->sourceTitle))) {
            $context->buildViolation('Select a scripture or enter a source title.')
                ->atPath('sourceTitle')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getScripture(): ?Scripture
    {
        return $this->scripture;
    }

    public function setScripture(?Scripture $scripture): static
    {
        $this->scripture = $scripture;

        return $this;
    }

    public function getSourceTitle(): ?string
    {
        return $this->sourceTitle;
    }

    public function setSourceTitle(?string $sourceTitle): static
    {
        $this->sourceTitle = $sourceTitle;

        return $this;
    }

    /** Display name: free-text title wins, otherwise the scripture name. */
    public function getDisplayTitle(): string
    {
        return $this->sourceTitle ?: ($this->scripture?->getName() ?? '');
    }

    /** English display name: the free-text title as cited, otherwise the scripture's English name. */
    public function getDisplayTitleEn(): ?string
    {
        return $this->sourceTitle ?: $this->scripture?->getNameEn();
    }

    public function getVolume(): ?string
    {
        return $this->volume;
    }

    public function setVolume(?string $volume): static
    {
        $this->volume = $volume;

        return $this;
    }

    public function getChapter(): ?string
    {
        return $this->chapter;
    }

    public function setChapter(?string $chapter): static
    {
        $this->chapter = $chapter;

        return $this;
    }

    public function getSection(): ?string
    {
        return $this->section;
    }

    public function setSection(?string $section): static
    {
        $this->section = $section;

        return $this;
    }

    public function getVerseReferences(): ?string
    {
        return $this->verseReferences;
    }

    public function setVerseReferences(?string $verseReferences): static
    {
        $this->verseReferences = $verseReferences;

        return $this;
    }

    public function getEdition(): ?string
    {
        return $this->edition;
    }

    public function setEdition(?string $edition): static
    {
        $this->edition = $edition;

        return $this;
    }

    public function getTranslator(): ?string
    {
        return $this->translator;
    }

    public function setTranslator(?string $translator): static
    {
        $this->translator = $translator;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

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

    public function getNotesEn(): ?string
    {
        return $this->notesEn;
    }

    public function setNotesEn(?string $notesEn): static
    {
        $notesEn = null !== $notesEn ? trim($notesEn) : null;
        $this->notesEn = '' === $notesEn ? null : $notesEn;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /** Snapshot used by revisions. @return array<string, mixed> */
    public function toSnapshot(): array
    {
        return [
            'scripture' => $this->scripture?->getName(),
            'sourceTitle' => $this->sourceTitle,
            'volume' => $this->volume,
            'chapter' => $this->chapter,
            'section' => $this->section,
            'verseReferences' => $this->verseReferences,
            'edition' => $this->edition,
            'translator' => $this->translator,
            'url' => $this->url,
            'notes' => $this->notes,
            'notesEn' => $this->notesEn,
        ];
    }
}
