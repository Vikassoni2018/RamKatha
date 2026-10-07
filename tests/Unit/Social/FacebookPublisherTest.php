<?php

declare(strict_types=1);

namespace App\Tests\Unit\Social;

use App\Entity\SocialAccount;
use App\Entity\SocialPost;
use App\Entity\Story;
use App\Enum\SocialPlatform;
use App\Service\Social\FacebookConfig;
use App\Service\Social\FacebookPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FacebookPublisherTest extends TestCase
{
    private const TOKEN = 'EAAB-secret-page-token-123';

    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    /** @var list<string> */
    private array $logLines = [];

    private function publisher(MockResponse ...$responses): FacebookPublisher
    {
        $queue = $responses;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$queue): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? '')];

            return array_shift($queue) ?? throw new \LogicException('Unexpected HTTP request.');
        });
        $logger = new class($this->logLines) extends AbstractLogger {
            public function __construct(private array &$lines)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = $message.' '.json_encode($context);
            }
        };

        return new FacebookPublisher($client, new FacebookConfig(true, '1234567890', self::TOKEN, 'v23.0'), $logger);
    }

    private function post(): SocialPost
    {
        $story = (new Story())->setTitle('डेमो कथा')->setSlug('demo-katha');

        return (new SocialPost($story, new SocialAccount(SocialPlatform::Facebook, 'Demo Page')))
            ->setMessage("डेमो कथा\n\nपढ़ें और सुनें")
            ->setLink('https://example.test/katha/demo-katha');
    }

    public function testSuccessfulPublishReturnsPostIdAndUrl(): void
    {
        $result = $this->publisher(new MockResponse('{"id":"1234567890_987654321"}', ['http_code' => 200]))->publish($this->post());

        self::assertTrue($result->success);
        self::assertSame('1234567890_987654321', $result->externalId);
        self::assertSame('https://www.facebook.com/1234567890/posts/987654321', $result->externalUrl);

        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://graph.facebook.com/v23.0/1234567890/feed', $this->requests[0]['url']);
        self::assertStringNotContainsString(self::TOKEN, $this->requests[0]['url'], 'The token must never be sent in the URL.');
        parse_str($this->requests[0]['body'], $body);
        self::assertSame(self::TOKEN, $body['access_token']);
        self::assertSame('https://example.test/katha/demo-katha', $body['link']);
    }

    public function testExpiredTokenIsNotRetryableAndTokenIsRedacted(): void
    {
        $error = json_encode(['error' => ['message' => 'Error validating access token '.self::TOKEN.': Session has expired', 'type' => 'OAuthException', 'code' => 190]]);
        $result = $this->publisher(new MockResponse((string) $error, ['http_code' => 400]))->publish($this->post());

        self::assertFalse($result->success);
        self::assertFalse($result->retryable);
        self::assertSame(400, $result->httpStatus);
        self::assertStringContainsString('Facebook error 190', (string) $result->error);
        self::assertStringContainsString('expired', (string) $result->error);
        self::assertStringNotContainsString(self::TOKEN, (string) $result->error);
        self::assertStringNotContainsString(self::TOKEN, (string) $result->responseExcerpt);
        self::assertStringContainsString('[redacted]', (string) $result->responseExcerpt);
        foreach ($this->logLines as $line) {
            self::assertStringNotContainsString(self::TOKEN, $line, 'Tokens must never reach the logs.');
        }
    }

    public function testServerErrorsAndRateLimitsAreRetryable(): void
    {
        self::assertTrue($this->publisher(new MockResponse('Service Unavailable', ['http_code' => 503]))->publish($this->post())->retryable);
        self::assertTrue($this->publisher(new MockResponse('{"error":{"message":"Application request limit reached","code":4}}', ['http_code' => 400]))->publish($this->post())->retryable);
        self::assertFalse($this->publisher(new MockResponse('{"error":{"message":"Permissions error","code":200}}', ['http_code' => 403]))->publish($this->post())->retryable);
    }

    public function testNetworkErrorIsRetryableAndRedacted(): void
    {
        $result = $this->publisher(new MockResponse('', ['error' => 'Could not resolve host for '.self::TOKEN]))->publish($this->post());

        self::assertFalse($result->success);
        self::assertTrue($result->retryable);
        self::assertStringContainsString('Network error', (string) $result->error);
        self::assertStringNotContainsString(self::TOKEN, (string) $result->error);
    }

    public function testMissingPostIdIsAFailure(): void
    {
        $result = $this->publisher(new MockResponse('{"success":true}', ['http_code' => 200]))->publish($this->post());

        self::assertFalse($result->success);
        self::assertNull($result->externalId);
    }

    public function testDisabledIntegrationNeverCallsTheApi(): void
    {
        $client = new MockHttpClient(static fn () => throw new \LogicException('No request expected.'));
        $publisher = new FacebookPublisher($client, new FacebookConfig(false, '', '', 'v23.0'), new \Psr\Log\NullLogger());

        $result = $publisher->publish($this->post());

        self::assertFalse($result->success);
        self::assertFalse($result->retryable);
        self::assertStringStartsWith('Feature is not configured', (string) $result->error);
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testConfigValidatesPageIdAndApiVersion(): void
    {
        self::assertStringContainsString('numeric', (string) (new FacebookConfig(true, 'my-page', 'x', 'v23.0'))->getConfigurationError());
        self::assertStringContainsString('ACCESS_TOKEN', (string) (new FacebookConfig(true, '1234567890', ' ', 'v23.0'))->getConfigurationError());
        self::assertSame('v23.0', (new FacebookConfig(true, '1234567890', 'x', '../evil'))->getApiVersion());
    }
}
