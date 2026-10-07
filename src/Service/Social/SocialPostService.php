<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Entity\SocialPost;
use App\Entity\SocialPostAttempt;
use App\Entity\Story;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\NotificationType;
use App\Enum\SocialPlatform;
use App\Enum\SocialPostStatus;
use App\Exception\DomainRuleException;
use App\Exception\FeatureNotConfiguredException;
use App\Message\PublishSocialPost;
use App\Repository\SocialAccountRepository;
use App\Repository\SocialPostRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Notification\NotificationService;
use App\Service\Seo\SiteUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lifecycle of social posts: draft → queued → publishing → published | failed.
 * Publication is idempotent (atomic claim) and every attempt is recorded for troubleshooting.
 */
final class SocialPostService
{
    /** @var array<string, SocialPublisherInterface> */
    private array $publishers = [];

    /**
     * @param iterable<SocialPublisherInterface> $publishers
     */
    public function __construct(
        #[AutowireIterator('app.social_publisher')]
        iterable $publishers,
        private readonly EntityManagerInterface $em,
        private readonly SocialPostRepository $posts,
        private readonly SocialAccountRepository $accounts,
        private readonly SocialCaptionRenderer $captions,
        private readonly SiteUrlGenerator $urls,
        private readonly MessageBusInterface $bus,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'monolog.logger.social')]
        private readonly LoggerInterface $logger,
    ) {
        foreach ($publishers as $publisher) {
            $this->publishers[$publisher->getPlatform()->value] = $publisher;
        }
    }

    public function getAccount(SocialPlatform $platform = SocialPlatform::Facebook): SocialAccount
    {
        return $this->accounts->getOrCreate($platform);
    }

    public function publisherFor(SocialPlatform $platform): SocialPublisherInterface
    {
        return $this->publishers[$platform->value] ?? throw new FeatureNotConfiguredException($platform->value, \sprintf('No publisher is available for %s.', $platform->label()));
    }

    /** Creates a draft post with the rendered default caption (admin can edit before publishing). */
    public function createDraft(Story $story, ?User $by, SocialPlatform $platform = SocialPlatform::Facebook): SocialPost
    {
        if (!$story->isPublished($this->clock->now())) {
            throw new DomainRuleException('Only published stories can be shared on social media.');
        }
        $account = $this->getAccount($platform);
        $post = new SocialPost($story, $account);
        $post->setMessage($this->captions->renderDefault($account, $story));
        $post->setLink($this->urls->storyCanonical($story));
        $post->setCreatedBy($by);
        $this->em->persist($post);
        $this->audit->log(AuditAction::SocialPostCreated, $story, ['platform' => $platform->value], actor: $by);
        $this->em->flush();

        return $post;
    }

    /**
     * Queues a post for publication now or at a scheduled time.
     *
     * @throws FeatureNotConfiguredException
     */
    public function queue(SocialPost $post, ?\DateTimeImmutable $scheduledAt = null): void
    {
        $this->assertPublishable($post);
        if (!$post->getStatus()->isEditable()) {
            throw new DomainRuleException('This post can no longer be queued.');
        }
        $now = $this->clock->now();
        $post->setScheduledAt(null !== $scheduledAt && $scheduledAt > $now ? $scheduledAt : null);
        $post->setStatus(SocialPostStatus::Queued);
        $this->em->flush();

        if (null === $post->getScheduledAt()) {
            $this->bus->dispatch(new PublishSocialPost((int) $post->getId()));
        }
    }

    /** Automatic queueing when a story gets published (if enabled for the story and configured). */
    public function autoQueueForStory(Story $story): ?SocialPost
    {
        if (!$story->isAutoPublishFacebook()) {
            return null;
        }
        $account = $this->getAccount(SocialPlatform::Facebook);
        $publisher = $this->publishers[SocialPlatform::Facebook->value] ?? null;
        if (!$account->isEnabled() || null === $publisher || null !== $publisher->getConfigurationError()) {
            $this->logger->info('Auto-publish skipped for story {id}: Facebook not enabled/configured.', ['id' => $story->getId()]);

            return null;
        }
        if ($this->posts->hasActiveOrPublishedForStory($story)) {
            return null;
        }

        $post = new SocialPost($story, $account);
        $post->setMessage($this->captions->renderDefault($account, $story));
        $post->setLink($this->urls->storyCanonical($story));
        $post->setStatus(SocialPostStatus::Queued);
        $this->em->persist($post);
        $this->audit->log(AuditAction::SocialPostCreated, $story, ['platform' => 'facebook', 'auto' => true]);
        $this->em->flush();
        $this->bus->dispatch(new PublishSocialPost((int) $post->getId()));

        return $post;
    }

    /**
     * Performs the actual publication. Safe to call repeatedly (message redelivery, cron, retries).
     */
    public function process(SocialPost $post): void
    {
        if (SocialPostStatus::Published === $post->getStatus()) {
            return;
        }
        $now = $this->clock->now();
        if (!$this->posts->claimForPublishing($post, $now->modify('-15 minutes'))) {
            $this->logger->info('Social post {id} already being processed or not claimable.', ['id' => $post->getId()]);

            return;
        }
        $this->em->refresh($post);

        $publisher = $this->publishers[$post->getAccount()->getPlatform()->value] ?? null;
        $result = null === $publisher
            ? PublishResult::failure('No publisher available for this platform.', false)
            : $publisher->publish($post);

        $post->recordAttempt(new SocialPostAttempt(
            $post,
            $this->clock->now(),
            $result->success,
            $result->httpStatus,
            $result->error,
            $result->responseExcerpt,
            $result->durationMs,
        ));

        if ($result->success) {
            $post->markPublished((string) $result->externalId, $result->externalUrl, $this->clock->now());
            $this->audit->log(AuditAction::SocialPublished, $post->getStory(), ['postId' => $result->externalId, 'socialPost' => $post->getId()]);
            $this->em->flush();
            $this->notifications->notify(NotificationType::SocialPublished, 'Facebook post published: '.$post->getStory()->getTitle(), null, '/admin/social/'.$post->getId());

            return;
        }

        $post->markFailed((string) $result->error, $result->retryable);
        $this->audit->log(AuditAction::SocialFailed, $post->getStory(), ['error' => $result->error, 'retryable' => $result->retryable, 'socialPost' => $post->getId()]);
        $this->em->flush();
        $this->notifications->notify(
            NotificationType::SocialFailed,
            'Facebook publication failed: '.$post->getStory()->getTitle(),
            $result->error.($result->retryable && $post->canAutoRetry() ? ' (will be retried automatically)' : ''),
            '/admin/social/'.$post->getId(),
        );
    }

    /** Manual retry from the admin. */
    public function retry(SocialPost $post): void
    {
        if (SocialPostStatus::Failed !== $post->getStatus()) {
            throw new DomainRuleException('Only failed posts can be retried.');
        }
        $this->queue($post);
    }

    /** Cron / scheduler: due queued posts + automatic retries of transient failures. */
    public function processDue(): int
    {
        $now = $this->clock->now();
        $ids = array_unique(array_merge(
            $this->posts->findDueIds($now),
            $this->posts->findAutoRetryIds($now->modify('-10 minutes')),
        ));
        $processed = 0;
        foreach ($ids as $id) {
            $post = $this->posts->find($id);
            if (null === $post) {
                continue;
            }
            if (SocialPostStatus::Failed === $post->getStatus() && !$post->canAutoRetry()) {
                continue;
            }
            $this->process($post);
            ++$processed;
        }

        return $processed;
    }

    public function testConnection(SocialPlatform $platform = SocialPlatform::Facebook): PublishResult
    {
        $publisher = $this->publisherFor($platform);
        $result = $publisher->testConnection();
        $account = $this->getAccount($platform);
        $account->recordConnectionCheck($result->success, $result->success ? ($result->remoteName ?? 'connected') : (string) $result->error, $result->remoteName, $this->clock->now());
        $this->em->flush();

        return $result;
    }

    private function assertPublishable(SocialPost $post): void
    {
        $publisher = $this->publisherFor($post->getAccount()->getPlatform());
        if (null !== $error = $publisher->getConfigurationError()) {
            throw new FeatureNotConfiguredException('facebook', 'Feature is not configured: '.$error);
        }
        if (!$post->getAccount()->isEnabled()) {
            throw new FeatureNotConfiguredException('facebook', 'Facebook publishing is switched off in Admin → Facebook.');
        }
    }
}
