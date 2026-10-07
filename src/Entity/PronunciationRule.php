<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\PronunciationRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A text substitution applied before narration (e.g. "श्रीकृष्ण" → "श्री कृष्ण").
 * Rules are applied in deterministic order: priority DESC, then id ASC.
 */
#[ORM\Entity(repositoryClass: PronunciationRuleRepository::class)]
#[ORM\Table(name: 'pronunciation_rule')]
#[ORM\Index(name: 'idx_pron_lang_enabled_priority', columns: ['language', 'enabled', 'priority'])]
#[ORM\HasLifecycleCallbacks]
class PronunciationRule
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $sourceText = '';

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $replacementText = '';

    #[ORM\Column(length: 10, options: ['default' => 'hi'])]
    #[Assert\NotBlank]
    private string $language = 'hi';

    /** Only meaningful for scripts with letter case (Latin abbreviations etc.). */
    #[ORM\Column(options: ['default' => false])]
    private bool $caseSensitive = false;

    /** Match whole words only (recommended), so "राम" does not alter "रामायण". */
    #[ORM\Column(options: ['default' => true])]
    private bool $wholeWord = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\Range(min: -1000, max: 1000)]
    private int $priority = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceText(): string
    {
        return $this->sourceText;
    }

    public function setSourceText(string $sourceText): static
    {
        $this->sourceText = $sourceText;

        return $this;
    }

    public function getReplacementText(): string
    {
        return $this->replacementText;
    }

    public function setReplacementText(?string $replacementText): static
    {
        $this->replacementText = (string) $replacementText;

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

    public function isCaseSensitive(): bool
    {
        return $this->caseSensitive;
    }

    public function setCaseSensitive(bool $caseSensitive): static
    {
        $this->caseSensitive = $caseSensitive;

        return $this;
    }

    public function isWholeWord(): bool
    {
        return $this->wholeWord;
    }

    public function setWholeWord(bool $wholeWord): static
    {
        $this->wholeWord = $wholeWord;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

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
}
