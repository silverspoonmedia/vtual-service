<?php

namespace Silverspoonmedia\VtualService\Http;

use Silverspoonmedia\VtualService\Exceptions\TorEgressException;
use Silverspoonmedia\VtualService\Exceptions\UnusualTrafficException;
use Silverspoonmedia\VtualService\Support\Config;
use Silverspoonmedia\VtualService\Support\Json;
use Silverspoonmedia\VtualService\Support\OutboundProxy;
use Silverspoonmedia\VtualService\Support\TorEgressVerifier;

/**
 * Outbound HTTP layer for scraping YouTube.
 *
 * All requests to YouTube/Google flow through {@see mergeRequestOpts()} with an
 * optional proxy (HTTP, SOCKS5, SOCKS5h, or Tor).
 */
class YouTubeClient
{
    protected OutboundProxy $proxy;

    protected ?TorEgressVerifier $torEgressVerifier = null;

    public function __construct(
        protected Config $config,
        ?TorEgressVerifier $torEgressVerifier = null,
    ) {
        $this->proxy = OutboundProxy::fromConfig($config);
        $this->torEgressVerifier = $torEgressVerifier;
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array<string, mixed>
     */
    protected function mergeRequestOpts(array $opts): array
    {
        $opts = $this->proxy->mergeIntoStreamOpts($opts);

        $userAgent = $this->config->userAgent();
        if ($userAgent === '') {
            return $opts;
        }

        $opts['http'] ??= [];
        $headers = $opts['http']['header'] ?? [];
        foreach ($headers as $header) {
            if (is_string($header) && str_starts_with(strtolower($header), 'user-agent:')) {
                return $opts;
            }
        }

        $headers[] = 'User-Agent: '.$userAgent;
        $opts['http']['header'] = $headers;

        return $opts;
    }

    /**
     * When Tor preset is active, verify egress IP before any outbound request.
     *
     * @throws TorEgressException
     */
    protected function ensureTorEgress(): void
    {
        $this->torVerifier()->assertValid();
    }

    protected function torVerifier(): TorEgressVerifier
    {
        return $this->torEgressVerifier ??= new TorEgressVerifier($this->config, $this->proxy);
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return resource
     */
    protected function contextFromOpts(array $opts)
    {
        $exemption = $this->config->googleAbuseExemption();
        if ($exemption !== '') {
            $cookieToAdd = 'GOOGLE_ABUSE_EXEMPTION='.$exemption;
            if (array_key_exists('http', $opts) && array_key_exists('header', $opts['http'])) {
                $headers = $opts['http']['header'];
                $found = false;
                foreach ($headers as $i => $header) {
                    if (str_starts_with($header, 'Cookie: ')) {
                        $opts['http']['header'][$i] = "$header; $cookieToAdd";
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    $opts['http']['header'][] = "Cookie: $cookieToAdd";
                }
            } else {
                $opts = ['http' => ['header' => ["Cookie: $cookieToAdd"]]];
            }
        }

        return stream_context_create($opts);
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array{0: string|false, 1: array<int, string>}
     */
    protected function fetchWithHeaders(string $url, array $opts): array
    {
        $this->ensureTorEgress();
        $opts = $this->mergeRequestOpts($opts);

        if ($this->proxy->requiresCurl()) {
            return CurlTransport::fetch($url, $opts, $this->proxy);
        }

        $context = $this->contextFromOpts($opts);
        $http_response_header = [];
        $result = @file_get_contents($url, false, $context);
        $headers = $http_response_header;

        return [$result, $headers];
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array<int, string>
     */
    protected function fetchHeadersOnly(string $url, array $opts): array
    {
        $this->ensureTorEgress();
        $opts = $this->mergeRequestOpts($opts);

        if ($this->proxy->requiresCurl()) {
            [, $headers] = CurlTransport::fetch($url, $opts, $this->proxy, headersOnly: true);

            return $headers;
        }

        $headers = get_headers($url, true, $this->contextFromOpts($opts));

        if ($headers === false) {
            return [];
        }

        if (array_is_list($headers)) {
            return $headers;
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $lines[] = is_int($name) ? $item : "$name: $item";
                }
            } else {
                $lines[] = is_int($name) ? $value : "$name: $value";
            }
        }

        return $lines;
    }

    public function isRedirection(string $url): bool
    {
        $opts = ['http' => ['ignore_errors' => true, 'follow_location' => false]];
        $headers = $this->fetchHeadersOnly($url, $opts);
        $statusLine = $headers[0] ?? '';
        $code = (int) (explode(' ', $statusLine)[1] ?? 0);

        if (in_array($code, $this->config->unusualTrafficHttpCodes(), true)) {
            throw new UnusualTrafficException;
        }

        return $code === 303;
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    public function getRemote(string $url, array $opts = [], bool $verifyTrafficIfForbidden = true): string
    {
        [$result, $headers] = $this->fetchWithHeaders($url, $opts);
        $statusLine = $headers[0] ?? '';

        foreach ($this->config->unusualTrafficHttpCodes() as $code) {
            if (str_contains($statusLine, (string) $code) && ($code !== 403 || $verifyTrafficIfForbidden)) {
                throw new UnusualTrafficException;
            }
        }

        return $result === false ? '' : $result;
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array<mixed>|null
     */
    public function getJson(string $url, array $opts = [], bool $verifyTrafficIfForbidden = true): ?array
    {
        return json_decode($this->getRemote($url, $opts, $verifyTrafficIfForbidden), true);
    }

    protected function jsonStringFromHtmlScriptPrefix(string $html, string $scriptPrefix): string
    {
        return explode(';</script>', explode("\">$scriptPrefix", $html, 3)[1] ?? '', 2)[0];
    }

    protected function jsonStringFromHtml(string $html, string $scriptVariable = '', string $prefix = 'var '): string
    {
        if ($scriptVariable === '') {
            $scriptVariable = 'ytInitialData';
        }

        return $this->jsonStringFromHtmlScriptPrefix($html, "$prefix$scriptVariable = ");
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array<mixed>|null
     */
    public function getJsonFromHtml(
        string $url,
        array $opts = [],
        string $scriptVariable = '',
        string $prefix = 'var ',
        bool $forceLanguage = false,
        bool $verifiesChannelRedirection = false
    ): ?array {
        if ($forceLanguage) {
            $header = 'Accept-Language: en';
            if (! Json::pathExists($opts, 'http/header')) {
                $opts['http']['header'] = [$header];
            } else {
                $opts['http']['header'][] = $header;
            }
        }

        $html = $this->getRemote($url, $opts);
        $jsonStr = $this->jsonStringFromHtml($html, $scriptVariable, $prefix);
        $json = json_decode($jsonStr, true);

        if ($verifiesChannelRedirection) {
            $redirectedPath = 'onResponseReceivedActions/0/navigateAction/endpoint/browseEndpoint/browseId';
            if (Json::pathExists($json, $redirectedPath)) {
                $redirectedId = Json::value($json, $redirectedPath);
                $url = preg_replace('/[\w\-_]{24}/', $redirectedId, $url);

                return $this->getJsonFromHtml($url, $opts, $scriptVariable, $prefix, $forceLanguage, $verifiesChannelRedirection);
            }
        }

        return $json;
    }

    public function getContinuationJson(string $continuationToken): ?array
    {
        $containsVisitorData = str_contains($continuationToken, ',');
        $visitorData = null;
        if ($containsVisitorData) {
            [$continuationToken, $visitorData] = explode(',', $continuationToken);
        }

        $rawData = [
            'context' => [
                'client' => [
                    'clientName' => 'WEB',
                    'clientVersion' => $this->config->musicVersion(),
                ],
            ],
            'continuation' => $continuationToken,
        ];
        if ($containsVisitorData) {
            $rawData['context']['client']['visitorData'] = $visitorData;
        }

        $opts = [
            'http' => [
                'header' => ['Content-Type: application/json'],
                'method' => 'POST',
                'content' => json_encode($rawData),
            ],
        ];

        return $this->getJson('https://www.youtube.com/youtubei/v1/browse?key='.$this->config->uiKey(), $opts);
    }

    /**
     * @param  array<string, mixed>  $rawData
     * @param  array<int, string>  $extraHeaders
     * @return array<mixed>|null
     */
    public function postInnertube(string $url, array $rawData, array $extraHeaders = []): ?array
    {
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => array_merge(['Content-Type: application/json'], $extraHeaders),
                'content' => json_encode($rawData),
            ],
        ];

        return $this->getJson($url, $opts);
    }

    public function usesProxy(): bool
    {
        return $this->proxy->enabled;
    }

    public function proxyType(): string
    {
        return $this->proxy->type;
    }

    public function proxyRequiresCurl(): bool
    {
        return $this->proxy->requiresCurl();
    }

    public function uiKey(): string
    {
        return $this->config->uiKey();
    }

    public function musicVersion(): string
    {
        return $this->config->musicVersion();
    }

    public function clientVersion(): string
    {
        return $this->config->clientVersion();
    }
}
