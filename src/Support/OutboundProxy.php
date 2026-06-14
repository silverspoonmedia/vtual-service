<?php

namespace Silverspoonmedia\VtualService\Support;

/**
 * Outbound proxy settings for YouTube/Google scraping.
 *
 * Supported types:
 * - http    — HTTP CONNECT proxy (PHP streams or cURL)
 * - socks5  — SOCKS5 proxy (requires ext-curl)
 * - socks5h — SOCKS5 with remote DNS resolution (requires ext-curl; use for Tor)
 * - tor     — Preset socks5h to local Tor daemon (127.0.0.1:9050 by default)
 */
final class OutboundProxy
{
    public const TYPE_HTTP = 'http';

    public const TYPE_SOCKS5 = 'socks5';

    public const TYPE_SOCKS5H = 'socks5h';

    public const TYPE_TOR = 'tor';

    public function __construct(
        public readonly bool $enabled,
        public readonly string $type,
        public readonly string $address,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $endpoint = $config->proxyEndpoint();

        if ($endpoint === null) {
            return new self(false, self::TYPE_HTTP, '', 8080, '', '');
        }

        return new self(
            true,
            $endpoint['type'],
            $endpoint['address'],
            $endpoint['port'],
            $endpoint['username'],
            $endpoint['password'],
        );
    }

    /**
     * SOCKS-based proxies cannot use PHP stream wrappers; they need cURL.
     */
    public function requiresCurl(): bool
    {
        return $this->enabled && $this->type !== self::TYPE_HTTP;
    }

    /**
     * libcurl proxy URL (socks5h://host:port or http://host:port).
     */
    public function curlProxyUrl(): string
    {
        $scheme = match ($this->type) {
            self::TYPE_SOCKS5 => 'socks5',
            self::TYPE_SOCKS5H, self::TYPE_TOR => 'socks5h',
            default => 'http',
        };

        return "{$scheme}://{$this->address}:{$this->port}";
    }

    public function streamTcpUri(): string
    {
        return 'tcp://' . $this->address . ':' . $this->port;
    }

    public function proxyAuthorizationHeader(): ?string
    {
        if ($this->username === '') {
            return null;
        }

        return 'Proxy-Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array<string, mixed>
     */
    public function mergeIntoStreamOpts(array $opts): array
    {
        if (! $this->enabled || $this->type !== self::TYPE_HTTP) {
            return $opts;
        }

        $opts['http'] ??= [];
        $opts['http']['proxy'] = $this->streamTcpUri();
        $opts['http']['request_fulluri'] = true;

        $auth = $this->proxyAuthorizationHeader();
        if ($auth !== null) {
            $headers = $opts['http']['header'] ?? [];
            $headers[] = $auth;
            $opts['http']['header'] = $headers;
        }

        return $opts;
    }
}
