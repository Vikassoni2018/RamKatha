<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\Entity\Story;
use App\Entity\User;
use App\Repository\StoryProgressRepository;
use App\Repository\StoryRepository;
use App\Repository\Taxonomy\FestivalRepository;
use App\Service\Cache\ContentCacheInvalidator;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Builds the homepage from the database. Story-ID lists per block are cached (10 min, tag
 * "stories"); all cards are then hydrated in ONE query. Personalised blocks (continue listening)
 * are never cached globally. Empty blocks are simply omitted by the template.
 */
final class HomepageService
{
    public function __construct(
        private readonly StoryRepository $stories,
        private readonly StoryProgressRepository $progress,
        private readonly FestivalRepository $festivals,
        private readonly SettingsService $settings,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'cache.content')]
        private readonly TagAwareCacheInterface $cache,
    ) {
    }

    /**
     * @return array{
     *   todayFeatured: ?Story, featured: list<Story>, popular: list<Story>, recent: list<Story>,
     *   under10: list<Story>, under20: list<Story>, latestAudio: list<Story>,
     *   festival: ?\App\Entity\Taxonomy\Festival, festivalStories: list<Story>
     * }
     */
    public function build(): array
    {
        $blocks = $this->cache->get('home_blocks_v2_'.$this->clock->now()->format('Ymd'), function (ItemInterface $item): array {
            $item->tag([ContentCacheInvalidator::TAG_STORIES]);
            $item->expiresAfter(600);

            return $this->computeBlocks();
        });

        $allIds = [];
        foreach ($blocks as $key => $ids) {
            if (\is_array($ids)) {
                array_push($allIds, ...$ids);
            }
        }
        $cards = [];
        foreach ($this->stories->findCardsByIds(array_values(array_unique($allIds))) as $story) {
            $cards[$story->getId()] = $story;
        }
        $pick = static fn (array $ids): array => array_values(array_filter(array_map(static fn (int $id) => $cards[$id] ?? null, $ids)));

        $festival = null !== $blocks['festivalId'] ? $this->festivals->find($blocks['festivalId']) : null;

        return [
            'todayFeatured' => null !== $blocks['todayFeatured'] ? ($cards[$blocks['todayFeatured']] ?? null) : null,
            'featured' => $pick($blocks['featured']),
            'popular' => $pick($blocks['popular']),
            'recent' => $pick($blocks['recent']),
            'under10' => $pick($blocks['under10']),
            'under20' => $pick($blocks['under20']),
            'latestAudio' => $pick($blocks['latestAudio']),
            'festival' => $festival,
            'festivalStories' => $pick($blocks['festivalStories']),
        ];
    }

    /** @return array{listening: list<\App\Entity\StoryProgress>, reading: list<\App\Entity\StoryProgress>} */
    public function continueFor(User $user): array
    {
        return [
            'listening' => $this->progress->findContinueListening($user, 6),
            'reading' => $this->progress->findContinueReading($user, 4),
        ];
    }

    /** @return array<string, mixed> */
    private function computeBlocks(): array
    {
        $limit = $this->settings->getInt(SettingsRegistry::HOME_SECTION_LIMIT);
        $featuredLimit = $this->settings->getInt(SettingsRegistry::HOME_FEATURED_LIMIT);

        // "Today's featured story" rotates daily through the featured stories (deterministic).
        $featured = $this->stories->findFeaturedIds($featuredLimit + 1);
        $recent = $this->stories->findLatestIds($limit + 1);
        $todayPool = [] !== $featured ? $featured : \array_slice($recent, 0, 1);
        $today = [] !== $todayPool ? $todayPool[(int) $this->clock->now()->format('z') % \count($todayPool)] : null;

        $exclude = static fn (array $ids) => array_values(array_filter($ids, static fn (int $id) => $id !== $today));

        $festival = $this->festivals->findNextUpcoming($this->clock->now(), 45);
        $festivalStories = null !== $festival ? $this->stories->findIdsByFestival($festival, $limit) : [];

        return [
            'todayFeatured' => $today,
            'featured' => \array_slice($exclude($featured), 0, $featuredLimit),
            'popular' => $this->stories->findPopularIds($limit),
            'recent' => \array_slice($exclude($recent), 0, $limit),
            'under10' => $this->stories->findAudioIdsByDuration(null, 600, $limit),
            'under20' => $this->stories->findAudioIdsByDuration(601, 1200, $limit),
            'latestAudio' => $this->stories->findLatestAudioIds($limit),
            'festivalId' => [] !== $festivalStories ? $festival?->getId() : null,
            'festivalStories' => $festivalStories,
        ];
    }
}
