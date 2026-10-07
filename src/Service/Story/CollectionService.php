<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\Entity\CollectionItem;
use App\Entity\Story;
use App\Entity\User;
use App\Entity\UserCollection;
use App\Enum\CollectionVisibility;
use App\Enum\MetricEvent;
use App\Exception\DomainRuleException;
use App\Repository\UserCollectionRepository;
use App\Service\Analytics\AnalyticsRecorder;
use App\Service\Config\FeatureFlags;
use App\Service\Slug\SlugService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * User collections: creation, duplicate-safe additions, ordering (transactional) and removal.
 * DomainRuleException messages are shown to the visitor, so they are translated (page language).
 */
final class CollectionService
{
    public const MAX_COLLECTIONS_PER_USER = 50;
    public const MAX_ITEMS_PER_COLLECTION = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserCollectionRepository $collections,
        private readonly SlugService $slugs,
        private readonly AnalyticsRecorder $analytics,
        private readonly FeatureFlags $features,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function create(User $user, string $name, ?string $description = null, CollectionVisibility $visibility = CollectionVisibility::Private): UserCollection
    {
        if ($this->collections->countForUser($user) >= self::MAX_COLLECTIONS_PER_USER) {
            throw new DomainRuleException($this->translator->trans('collection.error.limit_reached', ['%max%' => self::MAX_COLLECTIONS_PER_USER]));
        }
        $collection = new UserCollection($user, trim($name));
        $collection->setDescription($description);
        $collection->setVisibility($this->allowedVisibility($visibility));
        $this->save($collection);

        return $collection;
    }

    /** Persists (new or edited) collection with a per-user unique slug. */
    public function save(UserCollection $collection): void
    {
        $collection->setVisibility($this->allowedVisibility($collection->getVisibility()));
        $collection->setSlug($this->slugs->uniqueSlug(UserCollection::class, $collection->getName(), $collection->getId(), ['user' => $collection->getUser()]));
        $collection->touchUpdatedAt();
        $this->em->persist($collection);
        $this->em->flush();
    }

    /** @return bool false when the story was already in the collection (duplicates prevented) */
    public function addStory(UserCollection $collection, Story $story): bool
    {
        if (null !== $this->collections->findItem($collection, $story)) {
            return false;
        }
        if ($collection->getItems()->count() >= self::MAX_ITEMS_PER_COLLECTION) {
            throw new DomainRuleException($this->translator->trans('collection.error.full'));
        }

        $item = new CollectionItem($collection, $story, $this->collections->maxPosition($collection) + 1);
        $collection->addItem($item);
        $collection->touchUpdatedAt();
        $this->em->persist($item);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Concurrent double submit: the story is already there.
            return false;
        }
        $this->analytics->record((int) $story->getId(), MetricEvent::CollectionAdd);

        return true;
    }

    public function removeStory(UserCollection $collection, Story $story): bool
    {
        $item = $this->collections->findItem($collection, $story);
        if (null === $item) {
            return false;
        }
        $collection->removeItem($item);
        $collection->touchUpdatedAt();
        $this->em->remove($item);
        $this->em->flush();

        return true;
    }

    /**
     * Applies a new order (list of story UUIDs) in one transaction. Unknown ids are ignored and
     * stories missing from the list keep their relative order at the end.
     *
     * @param list<string> $storyUuids
     */
    public function reorder(UserCollection $collection, array $storyUuids): void
    {
        $this->em->wrapInTransaction(function () use ($collection, $storyUuids): void {
            $byUuid = [];
            foreach ($collection->getItems() as $item) {
                $byUuid[$item->getStory()->getUuid()->toRfc4122()] = $item;
            }
            $position = 1;
            foreach ($storyUuids as $uuid) {
                if (isset($byUuid[$uuid])) {
                    $byUuid[$uuid]->setPosition($position++);
                    unset($byUuid[$uuid]);
                }
            }
            $rest = array_values($byUuid);
            usort($rest, static fn (CollectionItem $a, CollectionItem $b) => $a->getPosition() <=> $b->getPosition());
            foreach ($rest as $item) {
                $item->setPosition($position++);
            }
            $collection->touchUpdatedAt();
            $this->em->flush();
        });
    }

    public function delete(UserCollection $collection): void
    {
        $this->em->remove($collection);
        $this->em->flush();
    }

    private function allowedVisibility(CollectionVisibility $visibility): CollectionVisibility
    {
        if (CollectionVisibility::Public === $visibility && !$this->features->publicCollectionsEnabled()) {
            return CollectionVisibility::Unlisted;
        }

        return $visibility;
    }
}
