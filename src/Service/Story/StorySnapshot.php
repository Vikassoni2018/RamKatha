<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\Entity\Story;

/**
 * Immutable capture of the integrity-relevant parts of a story, taken before an edit so we can
 * tell what changed (revisions, verification reset, outdated audio, slug redirects).
 */
final class StorySnapshot
{
    /**
     * @param list<array<string, mixed>> $references
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly string $excerpt,
        public readonly string $body,
        public readonly string $sourceType,
        public readonly ?string $sourceNotes,
        public readonly array $references,
        public readonly ?int $scriptureId,
        public readonly ?string $titleEn,
        public readonly ?string $subtitleEn,
        public readonly ?string $excerptEn,
        public readonly ?string $bodyEn,
        public readonly ?string $sourceNotesEn,
    ) {
    }

    public static function of(Story $story): self
    {
        $references = [];
        foreach ($story->getSourceReferences() as $reference) {
            $references[] = $reference->toSnapshot();
        }

        return new self(
            $story->getSlug(),
            $story->getTitle(),
            $story->getSubtitle(),
            $story->getExcerpt(),
            $story->getBody(),
            $story->getSourceType()->value,
            $story->getSourceNotes(),
            $references,
            $story->getScripture()?->getId(),
            $story->getTitleEn(),
            $story->getSubtitleEn(),
            $story->getExcerptEn(),
            $story->getBodyEn(),
            $story->getSourceNotesEn(),
        );
    }

    /** @return list<string> names of changed integrity-relevant fields */
    public function diff(self $other): array
    {
        $changed = [];
        foreach (['title', 'subtitle', 'excerpt', 'body', 'sourceType', 'sourceNotes', 'references', 'scriptureId', 'titleEn', 'subtitleEn', 'excerptEn', 'bodyEn', 'sourceNotesEn'] as $field) {
            if ($this->{$field} !== $other->{$field}) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /** Changes that invalidate a source verification (the English text is reviewed like the Hindi one). */
    public function affectsVerification(self $other): bool
    {
        return [] !== array_intersect($this->diff($other), ['title', 'body', 'sourceType', 'sourceNotes', 'references', 'scriptureId', 'titleEn', 'bodyEn', 'sourceNotesEn']);
    }

    /** Changes that make the (Hindi) narration outdated. */
    public function affectsNarration(self $other): bool
    {
        return [] !== array_intersect($this->diff($other), ['title', 'subtitle', 'body']);
    }

    /** @return array<string, mixed> */
    public function sourceSnapshot(): array
    {
        return [
            'sourceType' => $this->sourceType,
            'sourceNotes' => $this->sourceNotes,
            'references' => $this->references,
        ];
    }

    /** @return array<string, ?string>|null English version for the revision history (null when there is none) */
    public function englishSnapshot(): ?array
    {
        $english = [
            'title' => $this->titleEn,
            'subtitle' => $this->subtitleEn,
            'excerpt' => $this->excerptEn,
            'body' => $this->bodyEn,
            'sourceNotes' => $this->sourceNotesEn,
        ];

        return [] === array_filter($english, static fn (?string $v) => null !== $v) ? null : $english;
    }
}
