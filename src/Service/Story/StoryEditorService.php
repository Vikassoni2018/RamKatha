<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\Entity\Story;
use App\Entity\StorySlugHistory;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Repository\StoryRepository;
use App\Repository\StorySlugHistoryRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Cache\ContentCacheInvalidator;
use App\Service\Slug\SlugService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists stories edited in the admin: slug uniqueness + redirect history, derived content
 * fields, revisions, automatic verification reset, outdated-audio flag, audit and cache.
 */
final class StoryEditorService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StoryRepository $stories,
        private readonly StorySlugHistoryRepository $slugHistory,
        private readonly SlugService $slugs,
        private readonly StoryContentService $content,
        private readonly StoryRevisionService $revisions,
        private readonly StoryVerificationService $verification,
        private readonly AuditLogger $audit,
        private readonly ContentCacheInvalidator $cache,
    ) {
    }

    public function create(Story $story, User $author): void
    {
        $story->setSlug($this->slugs->uniqueStorySlug('' !== trim($story->getSlug()) ? $story->getSlug() : $story->getTitle()));
        $this->normalizeSeries($story);
        $this->normalizeReferences($story);
        $story->setCreatedBy($author);
        $story->setUpdatedBy($author);
        $this->content->refreshDerivedFields($story);

        $this->em->wrapInTransaction(function () use ($story, $author): void {
            $this->em->persist($story);
            $this->revisions->record($story, $author, 'Created');
            $this->audit->log(AuditAction::StoryCreated, $story, ['title' => $story->getTitle()], actor: $author);
            $this->em->flush();
        });
    }

    /**
     * @return list<string> changed integrity-relevant fields
     */
    public function update(Story $story, StorySnapshot $before, User $editor, ?string $changeSummary = null): array
    {
        $oldSlug = $before->slug;
        $requested = '' !== trim($story->getSlug()) ? $story->getSlug() : $story->getTitle();
        $story->setSlug($this->slugs->uniqueStorySlug($requested, $story));
        $this->normalizeSeries($story);
        $this->normalizeReferences($story);
        $this->content->refreshDerivedFields($story);
        $story->setUpdatedBy($editor);
        $story->touchUpdatedAt();

        $after = StorySnapshot::of($story);
        $changed = $before->diff($after);

        $this->em->wrapInTransaction(function () use ($story, $before, $after, $changed, $editor, $changeSummary, $oldSlug): void {
            if ($oldSlug !== $story->getSlug()) {
                $this->handleSlugChange($story, $oldSlug);
            }
            if ([] !== $changed) {
                $this->revisions->record($story, $editor, $changeSummary ?: 'Changed: '.implode(', ', $changed));
            }
            if ($before->affectsVerification($after)) {
                $this->verification->resetAfterChange($story, $changed);
            }
            if ($before->affectsNarration($after) && null !== $story->getCurrentAudio()) {
                $story->setAudioOutdated(true);
            }
            $this->audit->log(AuditAction::StoryUpdated, $story, ['fields' => $changed, 'summary' => $changeSummary], actor: $editor);
            $this->em->flush();
        });

        if ($story->isPublished()) {
            $this->cache->stories();
        }

        return $changed;
    }

    private function handleSlugChange(Story $story, string $oldSlug): void
    {
        // A slug that becomes current again must not keep redirecting.
        $reclaimed = $this->slugHistory->findOneBy(['slug' => $story->getSlug(), 'story' => $story]);
        if (null !== $reclaimed) {
            $this->em->remove($reclaimed);
        }

        // Keep old URLs alive with a 301 once the story has ever been public.
        if (null !== $story->getFirstPublishedAt() && '' !== $oldSlug && null === $this->slugHistory->findOneBy(['slug' => $oldSlug])) {
            $this->em->persist(new StorySlugHistory($story, $oldSlug));
        }
        $this->audit->log(AuditAction::StorySlugChanged, $story, ['from' => $oldSlug, 'to' => $story->getSlug()]);
    }

    private function normalizeSeries(Story $story): void
    {
        if (null === $story->getSeries()) {
            $story->setSeriesPosition(null);
        } elseif (null === $story->getSeriesPosition()) {
            $story->setSeriesPosition($this->stories->nextSeriesPosition($story->getSeries()));
        }
    }

    private function normalizeReferences(Story $story): void
    {
        $position = 0;
        foreach ($story->getSourceReferences() as $reference) {
            $reference->setStory($story);
            $reference->setPosition($position++);
        }
    }
}
