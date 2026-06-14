<?php

namespace Silverspoonmedia\VtualService\Services;

use Silverspoonmedia\VtualService\Exceptions\ApiException;
use Silverspoonmedia\VtualService\Http\YouTubeClient;
use Silverspoonmedia\VtualService\Support\Config;
use Silverspoonmedia\VtualService\Support\Validators;

/**
 * YouTube Data API v3 key pool management.
 *
 * Ported from upstream `addKey.php` and `noKey/index.php`.
 */
class ApiKeyService
{
    public function __construct(
        protected YouTubeClient $client,
        protected Config $config,
    ) {}

    /**
     * Add a shared API key to the local pool.
     *
     * Upstream: addKey.php
     */
    public function addKey(string $key, ?string $forceSecret = null): string
    {
        if (! Validators::isYouTubeDataApiV3Key($key)) {
            return "The key provided isn't a YouTube Data API v3 key.";
        }

        $keysFile = $this->config->keysFile();
        $keysContent = is_file($keysFile) ? (string) file_get_contents($keysFile) : '';
        $keys = $keysContent === '' ? [] : explode("\n", $keysContent);

        if (in_array($key, $keys, true)) {
            return 'This YouTube Data API v3 key is already in the list.';
        }

        $content = $this->client->getJson(
            "https://www.googleapis.com/youtube/v3/videos?part=snippet&id=mWdFMNQBcjs&key=$key",
            ['http' => ['ignore_errors' => true]],
            false
        );

        $forceOk = $forceSecret !== null && $forceSecret === $this->config->addKeyForceSecret();
        $titleOk = ($content['items'][0]['snippet']['title'] ?? null) === 'A public video';

        if ($titleOk || $forceOk) {
            $this->ensureKeysDirectory($keysFile);
            file_put_contents($keysFile, ($keysContent === '' ? '' : "\n").$key, FILE_APPEND);

            if ($forceSecret === null) {
                foreach ($this->config->addKeyToInstances() as $instance) {
                    $this->client->getRemote($instance.'addKey.php?key='.$key.'&forceSecret='.$this->config->addKeyForceSecret());
                }
            }

            return 'YouTube Data API v3 key added.';
        }

        if (($content['error']['errors'][0]['reason'] ?? null) === 'quotaExceeded') {
            return 'Not adding YouTube Data API v3 key having quota exceeded.';
        }

        return 'Incorrect YouTube Data API v3 key.';
    }

    /**
     * Proxy a no-key YouTube Data API v3 request through the key pool.
     *
     * Upstream: noKey/index.php
     *
     * @return array{body: string, keys_count: int}
     */
    public function proxyNoKeyRequest(string $apiPath, bool $monitoring = false): array
    {
        if (str_contains($apiPath, 'key=')) {
            throw new ApiException('No YouTube Data API v3 key is required to use the no-key service!');
        }

        $keysFile = $this->config->keysFile();
        if (! is_file($keysFile)) {
            throw new ApiException($keysFile.' does not exist!');
        }

        $keys = explode("\n", (string) file_get_contents($keysFile));
        $keysCount = count($keys);
        $url = 'https://www.googleapis.com/youtube/v3/'.ltrim($apiPath, '/').(str_contains($apiPath, '?') ? '&' : '?').'key=';
        $options = ['http' => ['ignore_errors' => true]];

        for ($keysIndex = 0; $keysIndex < $keysCount; $keysIndex++) {
            $key = $keys[$keysIndex];
            $response = $this->client->getRemote($url.$key, $options, false);
            $response = str_replace($key, '!Please contact Benjamin Loison to tell him how you did that!', $response);
            $json = json_decode($response, true);

            if (array_key_exists('error', $json ?? [])) {
                $error = $json['error'];
                $message = $error['message'] ?? '';

                if (($error['errors'][0]['domain'] ?? null) !== 'youtube.quota') {
                    if ($this->shouldRemoveKey($message)) {
                        $newKeys = array_merge(array_slice($keys, $keysIndex + 1), array_slice($keys, 0, $keysIndex));
                        file_put_contents($keysFile, implode("\n", $newKeys));
                        $keysIndex--;
                        $keysCount--;
                        $keys = $newKeys;

                        continue;
                    }

                    return ['body' => $this->maybeAddMonitoring($response, $monitoring, $keysCount), 'keys_count' => $keysCount];
                }
            } else {
                if ($keysIndex !== 0) {
                    $newKeys = array_merge(array_slice($keys, $keysIndex), array_slice($keys, 0, $keysIndex));
                    file_put_contents($keysFile, implode("\n", $newKeys));
                }

                return ['body' => $this->maybeAddMonitoring($response, $monitoring, $keysCount), 'keys_count' => $keysCount];
            }
        }

        $message = 'The request cannot be completed because the YouTube operational API run out of quota. Please try again later.';
        $json = [
            'error' => [
                'code' => 403,
                'message' => $message,
                'errors' => [[
                    'message' => $message,
                    'domain' => 'youtube.quota',
                    'reason' => 'quotaExceeded',
                ]],
            ],
        ];

        return ['body' => $this->maybeAddMonitoring(json_encode($json, JSON_PRETTY_PRINT), $monitoring, $keysCount), 'keys_count' => $keysCount];
    }

    public function keysCount(): int
    {
        $keysFile = $this->config->keysFile();

        return is_file($keysFile) ? substr_count((string) file_get_contents($keysFile), "\n") + 1 : 0;
    }

    protected function shouldRemoveKey(string $message): bool
    {
        return $message === 'API key expired. Please renew the API key.'
            || str_ends_with($message, 'has been suspended.')
            || $message === 'API key not valid. Please pass a valid API key.'
            || $message === 'API Key not found. Please pass a valid API key.'
            || str_starts_with($message, 'YouTube Data API v3 has not been used in project ')
            || str_ends_with($message, 'are blocked.')
            || Validators::matches('The provided API key has an IP address restriction\. The originating IP address of the call \(([\da-f:]{4,39}|\d{1,3}.\d{1,3}.\d{1,3}.\d{1,3})\) violates this restriction\.', $message);
    }

    protected function maybeAddMonitoring(string $content, bool $monitoring, int $keysCount): string
    {
        if (! $monitoring) {
            return $content;
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            return $content;
        }
        $data['monitoring'] = $keysCount;

        return json_encode($data, JSON_PRETTY_PRINT);
    }

    protected function ensureKeysDirectory(string $keysFile): void
    {
        $dir = dirname($keysFile);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
