<?php

declare(strict_types=1);

namespace App\Message;

/** Generates (or regenerates) narration for an AudioAsset. Handled asynchronously. */
final class GenerateStoryAudio
{
    public function __construct(
        public readonly int $audioAssetId,
    ) {
    }
}
