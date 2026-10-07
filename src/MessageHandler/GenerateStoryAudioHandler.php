<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateStoryAudio;
use App\Repository\AudioAssetRepository;
use App\Service\Audio\AudioGenerationService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateStoryAudioHandler
{
    public function __construct(
        private readonly AudioAssetRepository $assets,
        private readonly AudioGenerationService $generator,
    ) {
    }

    public function __invoke(GenerateStoryAudio $message): void
    {
        $asset = $this->assets->find($message->audioAssetId);
        if (null === $asset) {
            return; // asset deleted meanwhile: nothing to do
        }
        $this->generator->generate($asset);
    }
}
