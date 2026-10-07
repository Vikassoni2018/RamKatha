<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateAudiogram;
use App\Repository\SocialAssetRepository;
use App\Service\Social\AudiogramService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateAudiogramHandler
{
    public function __construct(
        private readonly SocialAssetRepository $assets,
        private readonly AudiogramService $service,
    ) {
    }

    public function __invoke(GenerateAudiogram $message): void
    {
        $asset = $this->assets->find($message->socialAssetId);
        if (null !== $asset) {
            $this->service->generate($asset);
        }
    }
}
