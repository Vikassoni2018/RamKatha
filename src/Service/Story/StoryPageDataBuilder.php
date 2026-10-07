<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\Entity\Story;
use App\Repository\StoryRepository;
use App\Service\Donation\DonationService;
use App\Service\Seo\SiteUrlGenerator;

/**
 * Data shared by the public story page and the admin preview, so the preview renders exactly
 * like the published page.
 */
final class StoryPageDataBuilder
{
    public function __construct(
        private readonly StoryRepository $stories,
        private readonly SiteUrlGenerator $urls,
        private readonly DonationService $donations,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(Story $story): array
    {
        $series = $story->getSeries();

        return [
            'story' => $story,
            'related' => $this->stories->findRelated($story, 6),
            'neighbours' => $this->stories->findSeriesNeighbours($story),
            'seriesOutline' => null !== $series ? $this->stories->findSeriesOutline($series) : [],
            'shareUrl' => $this->urls->storyCanonical($story),
            'donationPresets' => $this->donations->presetAmounts(),
        ];
    }
}
