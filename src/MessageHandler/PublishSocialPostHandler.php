<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PublishSocialPost;
use App\Repository\SocialPostRepository;
use App\Service\Social\SocialPostService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PublishSocialPostHandler
{
    public function __construct(
        private readonly SocialPostRepository $posts,
        private readonly SocialPostService $service,
    ) {
    }

    public function __invoke(PublishSocialPost $message): void
    {
        $post = $this->posts->find($message->socialPostId);
        if (null === $post) {
            return;
        }
        $this->service->process($post);
    }
}
