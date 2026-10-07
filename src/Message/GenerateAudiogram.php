<?php

declare(strict_types=1);

namespace App\Message;

/** Renders an audiogram video (cover + title + audio preview) with FFmpeg. */
final class GenerateAudiogram
{
    public function __construct(
        public readonly int $socialAssetId,
    ) {
    }
}
