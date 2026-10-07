<?php

declare(strict_types=1);

namespace App\Service\Audio\Tts;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Optional adapter for any HTTP TTS endpoint you operate or subscribe to (not tied to a vendor).
 * Contract: POST JSON {"text", "voice", "language", "format"} → response body = audio bytes
 * (Content-Type audio/*). Optional bearer token via TTS_HTTP_API_KEY.
 */
final class HttpTtsProvider implements TextToSpeechProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'TTS_HTTP_ENDPOINT')]
        private readonly string $endpoint,
        #[Autowire(env: 'TTS_HTTP_API_KEY')]
        private readonly string $apiKey,
        #[Autowire(env: 'TTS_VOICE')]
        private readonly string $defaultVoice,
        #[Autowire(env: 'TTS_OUTPUT_FORMAT')]
        private readonly string $outputFormat,
        #[Autowire(env: 'int:TTS_TIMEOUT')]
        private readonly int $timeout,
    ) {
    }

    public function getName(): string
    {
        return 'http';
    }

    public function isConfigured(): bool
    {
        return null === $this->getConfigurationError();
    }

    public function getConfigurationError(): ?string
    {
        if ('' === trim($this->endpoint)) {
            return 'HTTP TTS is not configured: set TTS_HTTP_ENDPOINT.';
        }
        if (!preg_match('#^https?://#i', $this->endpoint)) {
            return 'TTS_HTTP_ENDPOINT must be an http(s) URL.';
        }

        return null;
    }

    public function getOutputExtension(): string
    {
        return 'mp3' === strtolower(trim($this->outputFormat)) ? 'mp3' : 'wav';
    }

    public function getDefaultVoice(): string
    {
        return $this->defaultVoice;
    }

    public function synthesize(string $text, string $outputPath, TtsOptions $options): void
    {
        if (null !== $error = $this->getConfigurationError()) {
            throw new TtsException($error);
        }

        $headers = ['Accept' => 'audio/*'];
        if ('' !== $this->apiKey) {
            $headers['Authorization'] = 'Bearer '.$this->apiKey;
        }

        try {
            $response = $this->httpClient->request('POST', $this->endpoint, [
                'headers' => $headers,
                'json' => [
                    'text' => $text,
                    'voice' => $options->voice,
                    'language' => $options->language,
                    'format' => $this->getOutputExtension(),
                ],
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new TtsException(\sprintf('HTTP TTS endpoint returned status %d: %s', $status, mb_substr($response->getContent(false), 0, 300)));
            }
            $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
            if (!str_starts_with(strtolower($contentType), 'audio/') && 'application/octet-stream' !== strtolower($contentType)) {
                throw new TtsException(\sprintf('HTTP TTS endpoint returned unexpected content type "%s".', $contentType));
            }
            $handle = fopen($outputPath, 'wb');
            if (false === $handle) {
                throw new TtsException('Cannot write synthesized audio to disk.');
            }
            foreach ($this->httpClient->stream($response) as $chunk) {
                fwrite($handle, $chunk->getContent());
            }
            fclose($handle);
        } catch (ExceptionInterface $e) {
            // The API key is a header and never part of the exception message.
            throw new TtsException('HTTP TTS request failed: '.$e->getMessage(), previous: $e);
        }

        if (!is_file($outputPath) || filesize($outputPath) < 100) {
            throw new TtsException('HTTP TTS endpoint returned an empty audio file.');
        }
    }
}
