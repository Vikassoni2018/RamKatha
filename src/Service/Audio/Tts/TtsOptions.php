<?php

declare(strict_types=1);

namespace App\Service\Audio\Tts;

final class TtsOptions
{
    public function __construct(
        public readonly string $voice,
        public readonly string $language,
    ) {
    }
}
