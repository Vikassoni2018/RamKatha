<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialPost;
use App\Enum\SocialPlatform;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Publishes link posts to a Facebook Page through the Meta Graph API (POST /{page-id}/feed).
 * Facebook renders the story's OpenGraph image/title as the link preview card.
 *
 * The access token is sent in the POST body (never in the URL), and every response/error text is
 * passed through FacebookConfig::redact() before it is stored or logged.
 */
final class FacebookPublisher implements SocialPublisherInterface
{
    /** Graph API error codes that indicate a temporary condition worth retrying. */
    private const RETRYABLE_CODES = [1, 2, 4, 17, 32, 341, 368, 613];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly FacebookConfig $config,
        #[Autowire(service: 'monolog.logger.social')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getPlatform(): SocialPlatform
    {
        return SocialPlatform::Facebook;
    }

    public function getConfigurationError(): ?string
    {
        return $this->config->getConfigurationError();
    }

    public function publish(SocialPost $post): PublishResult
    {
        if (null !== $error = $this->config->getConfigurationError()) {
            return PublishResult::failure('Feature is not configured: '.$error, false);
        }

        $body = ['message' => $post->getMessage(), 'access_token' => $this->config->getAccessToken()];
        if ('' !== $post->getLink()) {
            $body['link'] = $post->getLink();
        }

        return $this->call('POST', $this->config->getPageId().'/feed', $body, function (array $data, int $status, string $excerpt, int $ms): PublishResult {
            $id = isset($data['id']) ? (string) $data['id'] : null;
            if (null === $id || '' === $id) {
                return PublishResult::failure('Facebook did not return a post id.', false, $status, $excerpt, $ms);
            }

            return PublishResult::success($id, $this->postUrl($id), $status, $excerpt, $ms);
        });
    }

    public function testConnection(): PublishResult
    {
        if (null !== $error = $this->config->getConfigurationError()) {
            return PublishResult::failure('Feature is not configured: '.$error, false);
        }

        return $this->call('GET', $this->config->getPageId(), ['fields' => 'id,name', 'access_token' => $this->config->getAccessToken()], function (array $data, int $status, string $excerpt, int $ms): PublishResult {
            return PublishResult::success($data['id'] ?? null, null, $status, $excerpt, $ms, $data['name'] ?? null);
        });
    }

    /**
     * @param array<string, string> $params
     * @param callable(array<string, mixed>, int, string, int): PublishResult $onSuccess
     */
    private function call(string $method, string $path, array $params, callable $onSuccess): PublishResult
    {
        $started = microtime(true);
        $options = 'GET' === $method ? ['query' => $params] : ['body' => $params];
        $options['timeout'] = 30;

        try {
            $response = $this->httpClient->request($method, $this->config->graphUrl($path), $options);
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            $message = $this->config->redact($e->getMessage());
            $this->logger->warning('Facebook request failed (network): {error}', ['error' => $message]);

            return PublishResult::failure('Network error while contacting Facebook: '.$message, true, null, null, $this->elapsed($started));
        }

        $ms = $this->elapsed($started);
        $excerpt = mb_substr($this->config->redact($raw), 0, 2000);
        try {
            $data = json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = [];
        }
        $data = \is_array($data) ? $data : [];

        if ($status >= 200 && $status < 300 && !isset($data['error'])) {
            $this->logger->info('Facebook {method} {path} succeeded', ['method' => $method, 'path' => $path, 'status' => $status]);

            return $onSuccess($data, $status, $excerpt, $ms);
        }

        $error = $data['error'] ?? [];
        $code = (int) ($error['code'] ?? 0);
        $message = $this->config->redact((string) ($error['message'] ?? 'HTTP '.$status));
        $retryable = $status >= 500 || 429 === $status || \in_array($code, self::RETRYABLE_CODES, true) || !empty($error['is_transient']);
        $hint = match (true) {
            190 === $code => ' (the access token is invalid or expired — generate a new long-lived Page token)',
            \in_array($code, [10, 200, 283], true) => ' (missing permission: the token needs pages_manage_posts and pages_read_engagement)',
            default => '',
        };
        $this->logger->error('Facebook {method} {path} failed: {code} {message}', ['method' => $method, 'path' => $path, 'code' => $code, 'message' => $message, 'status' => $status]);

        return PublishResult::failure(\sprintf('Facebook error %d: %s%s', $code, $message, $hint), $retryable, $status, $excerpt, $ms);
    }

    /** "PAGEID_POSTID" → https://www.facebook.com/PAGEID/posts/POSTID */
    private function postUrl(string $id): ?string
    {
        if (!preg_match('/^(\d+)_(\d+)$/', $id, $m)) {
            return null;
        }

        return \sprintf('https://www.facebook.com/%s/posts/%s', $m[1], $m[2]);
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
