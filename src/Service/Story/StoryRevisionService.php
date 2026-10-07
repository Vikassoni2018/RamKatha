<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\Entity\Story;
use App\Entity\StoryRevision;
use App\Entity\User;
use App\Repository\StoryRevisionRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Records content snapshots on creation and on every significant change. */
final class StoryRevisionService
{
    public function __construct(
        private readonly StoryRevisionRepository $revisions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function record(Story $story, ?User $by, ?string $summary = null): StoryRevision
    {
        $snapshot = StorySnapshot::of($story);
        $version = null === $story->getId() ? 1 : $this->revisions->findLatestVersionNumber($story) + 1;

        $revision = new StoryRevision(
            $story,
            $version,
            $story->getTitle(),
            $story->getSubtitle(),
            $story->getExcerpt(),
            $story->getBody(),
            $snapshot->sourceSnapshot(),
            $by,
            null !== $summary ? mb_substr($summary, 0, 255) : null,
            $snapshot->englishSnapshot(),
        );
        $this->em->persist($revision);

        return $revision;
    }

    /** Copies a revision's content back onto the story (caller saves through StoryEditorService). */
    public function applyToStory(StoryRevision $revision, Story $story): void
    {
        $story->setTitle($revision->getTitle());
        $story->setSubtitle($revision->getSubtitle());
        $story->setExcerpt($revision->getExcerpt());
        $story->setBody($revision->getBody());

        $english = $revision->getEnglishSnapshot() ?? [];
        $story->setTitleEn($english['title'] ?? null);
        $story->setSubtitleEn($english['subtitle'] ?? null);
        $story->setExcerptEn($english['excerpt'] ?? null);
        $story->setBodyEn($english['body'] ?? null);
    }
}
