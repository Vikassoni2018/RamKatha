<?php

declare(strict_types=1);

namespace App\Service\Social;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Facebook credentials come exclusively from the environment / Symfony secrets.
 * The access token is never stored in the database, rendered in HTML, or logged.
 */
final class FacebookConfig
{
    public function __construct(
        #[Autowire(env: 'bool:FACEBOOK_ENABLED')]
        private readonly bool $enabled,
        #[Autowire(env: 'FACEBOOK_PAGE_ID')]
        private readonly string $pageId,
        #[Autowire(env: 'FACEBOOK_ACCESS_TOKEN')]
        #[\SensitiveParameter]
        private readonly string $accessToken,
        #[Autowire(env: 'FACEBOOK_API_VERSION')]
        private readonly string $apiVersion,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isConfigured(): bool
    {
        return null === $this->getConfigurationError();
    }

    public function getConfigurationError(): ?string
    {
        if (!$this->enabled) {
            return 'Facebook integration is disabled (FACEBOOK_ENABLED=0).';
        }
        if ('' === trim($this->pageId)) {
            return 'Facebook is not configured: FACEBOOK_PAGE_ID is empty.';
        }
        if (!preg_match('/^\d{5,25}$/', trim($this->pageId))) {
            return 'FACEBOOK_PAGE_ID must be the numeric Page ID.';
        }
        if ('' === trim($this->accessToken)) {
            return 'Facebook is not configured: FACEBOOK_ACCESS_TOKEN is empty.';
        }

        return null;
    }

    public function getPageId(): string
    {
        return trim($this->pageId);
    }

    /** @internal only for the HTTP client request body */
    public function getAccessToken(): string
    {
        return trim($this->accessToken);
    }

    public function hasAccessToken(): bool
    {
        return '' !== trim($this->accessToken);
    }

    public function getApiVersion(): string
    {
        $version = trim($this->apiVersion);

        return preg_match('/^v\d+\.\d+$/', $version) ? $version : 'v23.0';
    }

    public function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->getApiVersion().'/'.ltrim($path, '/');
    }

    /** Removes the token from any text before it is stored or displayed. */
    public function redact(string $text): string
    {
        $token = $this->getAccessToken();

        return '' === $token ? $text : str_replace($token, '[redacted]', $text);
    }
}
