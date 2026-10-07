<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AppController;
use App\Enum\MetricEvent;
use App\Enum\StoryStatus;
use App\Service\Analytics\AnalyticsRecorder;
use App\Service\Seo\SiteUrlGenerator;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Batched, anonymous first-party analytics (sendBeacon). Only whitelisted client events are
 * accepted, for published stories, with a per-IP token-bucket limit. Nothing personal is stored.
 */
final class AnalyticsApiController extends AppController
{
    private const MAX_EVENTS = 40;

    #[Route('/api/analytics/events', name: 'api_analytics_events', methods: ['POST'])]
    public function collect(
        Request $request,
        Connection $connection,
        AnalyticsRecorder $recorder,
        SiteUrlGenerator $urls,
        #[Autowire(service: 'limiter.analytics')]
        RateLimiterFactory $limiter,
    ): Response {
        // Reject cross-site submissions when the browser tells us the origin.
        $origin = $request->headers->get('Origin');
        if (null !== $origin && !$this->sameOrigin($origin, $request, $urls)) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }
        if (\strlen($request->getContent()) > 16384) {
            return new Response('', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        if (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new Response('', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $events = $this->jsonPayload($request)['events'] ?? [];
        if (!\is_array($events) || [] === $events) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $valid = [];
        foreach (\array_slice($events, 0, self::MAX_EVENTS) as $event) {
            if (!\is_array($event) || !\is_string($event['s'] ?? null) || !\is_string($event['e'] ?? null) || !Uuid::isValid($event['s'])) {
                continue;
            }
            $type = MetricEvent::tryFrom($event['e']);
            if (null === $type || !$type->isClientReportable()) {
                continue;
            }
            $valid[] = [Uuid::fromString($event['s'])->toBinary(), $type];
        }
        if ([] === $valid) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        // Resolve story ids in one query; ignore unknown/unpublished stories.
        $binaries = array_values(array_unique(array_column($valid, 0)));
        $rows = $connection->fetchAllAssociative(
            'SELECT id, uuid FROM story WHERE uuid IN (:uuids) AND status = :published',
            ['uuids' => $binaries, 'published' => StoryStatus::Published->value],
            ['uuids' => ArrayParameterType::BINARY],
        );
        $ids = [];
        foreach ($rows as $row) {
            $ids[$row['uuid']] = (int) $row['id'];
        }
        foreach ($valid as [$binary, $type]) {
            if (isset($ids[$binary])) {
                $recorder->record($ids[$binary], $type);
            }
        }
        $recorder->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function sameOrigin(string $origin, Request $request, SiteUrlGenerator $urls): bool
    {
        $host = parse_url($origin, \PHP_URL_HOST);

        return $host === $request->getHost() || $host === parse_url($urls->base(), \PHP_URL_HOST);
    }
}
