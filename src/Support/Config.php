<?php

namespace Silverspoonmedia\VtualService\Support;

/**
 * Typed accessor over the `vtual-service` config array.
 *
 * Centralises the upstream constants (constants.php) and runtime configuration
 * (configuration.php) so the rest of the package never reads global `define()`
 * values or `config()` keys directly.
 */
class Config
{
    /** @var array<string, mixed> */
    protected array $config;

    protected ?string $resolvedAddKeySecret = null;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function serverName(): string
    {
        return (string) ($this->config['server_name'] ?? 'my instance');
    }

    public function googleAbuseExemption(): string
    {
        return (string) ($this->config['google_abuse_exemption'] ?? '');
    }

    public function multipleIdsEnabled(): bool
    {
        return (bool) ($this->config['multiple_ids_enabled'] ?? true);
    }

    public function multipleIdsMaximum(): int
    {
        return (int) ($this->config['multiple_ids_maximum'] ?? 50);
    }

    /**
     * Whether outbound YouTube/Google HTTP(S) requests should use a proxy.
     */
    public function proxyEnabled(): bool
    {
        $explicit = $this->config['proxy']['enabled'] ?? null;
        if ($explicit === false) {
            return false;
        }

        $endpoint = $this->resolvedProxyEndpoint();

        return $endpoint !== null;
    }

    public function proxyAddress(): string
    {
        return (string) ($this->resolvedProxyEndpoint()['address'] ?? '');
    }

    public function proxyPort(): int
    {
        return (int) ($this->resolvedProxyEndpoint()['port'] ?? 8080);
    }

    public function proxyUsername(): string
    {
        return (string) ($this->resolvedProxyEndpoint()['username'] ?? '');
    }

    public function proxyPassword(): string
    {
        return (string) ($this->resolvedProxyEndpoint()['password'] ?? '');
    }

    public function proxyType(): string
    {
        return (string) ($this->resolvedProxyEndpoint()['type'] ?? OutboundProxy::TYPE_HTTP);
    }

    /**
     * Whether the Tor preset (VTUAL_PROXY_TYPE=tor) is active.
     */
    public function torProxyActive(): bool
    {
        if (! $this->proxyEnabled()) {
            return false;
        }

        return strtolower(trim((string) (($this->config['proxy'] ?? [])['type'] ?? ''))) === OutboundProxy::TYPE_TOR;
    }

    /**
     * Whether outbound traffic should be verified as a Tor exit before scraping.
     *
     * True for the Tor preset, or socks5h aimed at the configured Tor SOCKS endpoint.
     */
    public function torEgressCheckRequired(): bool
    {
        if (! $this->proxyEnabled() || ! $this->torVerifyEgress()) {
            return false;
        }

        if ($this->torProxyActive()) {
            return true;
        }

        $endpoint = $this->resolvedProxyEndpoint();
        if ($endpoint === null || $endpoint['type'] !== OutboundProxy::TYPE_SOCKS5H) {
            return false;
        }

        $tor = (array) (($this->config['proxy'] ?? [])['tor'] ?? []);

        return $endpoint['address'] === (string) ($tor['host'] ?? '127.0.0.1')
            && $endpoint['port'] === (int) ($tor['port'] ?? 9050);
    }

    public function torVerifyEgress(): bool
    {
        return (bool) (($this->config['proxy']['tor'] ?? [])['verify_egress'] ?? true);
    }

    public function torVerifyUrl(): string
    {
        return (string) (($this->config['proxy']['tor'] ?? [])['verify_url'] ?? 'https://check.torproject.org/api/ip');
    }

    public function torVerifyCacheSeconds(): int
    {
        return max(0, (int) (($this->config['proxy']['tor'] ?? [])['verify_cache_seconds'] ?? 300));
    }

    /**
     * @return array{type: string, address: string, port: int, username: string, password: string}|null
     */
    public function proxyEndpoint(): ?array
    {
        return $this->proxyEnabled() ? $this->resolvedProxyEndpoint() : null;
    }

    /**
     * @return array{type: string, address: string, port: int, username: string, password: string}|null
     */
    protected function resolvedProxyEndpoint(): ?array
    {
        $proxy = (array) ($this->config['proxy'] ?? []);
        $configuredType = strtolower(trim((string) ($proxy['type'] ?? '')));

        if ($configuredType === OutboundProxy::TYPE_TOR) {
            $tor = (array) ($proxy['tor'] ?? []);

            return [
                'type' => OutboundProxy::TYPE_SOCKS5H,
                'address' => (string) ($tor['host'] ?? '127.0.0.1'),
                'port' => (int) ($tor['port'] ?? 9050),
                'username' => '',
                'password' => '',
            ];
        }

        $url = trim((string) ($proxy['url'] ?? ''));
        if ($url !== '') {
            return self::parseProxyUrl($url, $configuredType !== '' ? $configuredType : null);
        }

        $address = trim((string) ($proxy['address'] ?? ''));
        if ($address === '') {
            return null;
        }

        return [
            'type' => self::normalizeProxyType($configuredType !== '' ? $configuredType : OutboundProxy::TYPE_HTTP),
            'address' => $address,
            'port' => (int) ($proxy['port'] ?? 8080),
            'username' => (string) ($proxy['username'] ?? ''),
            'password' => (string) ($proxy['password'] ?? ''),
        ];
    }

    /**
     * @return array{type: string, address: string, port: int, username: string, password: string}
     */
    public static function parseProxyUrl(string $url, ?string $fallbackType = null): array
    {
        if (! str_contains($url, '://')) {
            $url = ($fallbackType && $fallbackType !== OutboundProxy::TYPE_HTTP ? $fallbackType : 'http') . '://' . $url;
        }

        $parts = parse_url($url);
        $address = (string) ($parts['host'] ?? '');
        if ($address === '') {
            throw new \InvalidArgumentException("Invalid proxy URL: $url");
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $type = self::normalizeProxyType(match ($scheme) {
            'socks5' => OutboundProxy::TYPE_SOCKS5,
            'socks5h' => OutboundProxy::TYPE_SOCKS5H,
            default => $fallbackType ?? OutboundProxy::TYPE_HTTP,
        });

        return [
            'type' => $type,
            'address' => $address,
            'port' => (int) ($parts['port'] ?? ($type === OutboundProxy::TYPE_HTTP ? 8080 : 9050)),
            'username' => rawurldecode((string) ($parts['user'] ?? '')),
            'password' => rawurldecode((string) ($parts['pass'] ?? '')),
        ];
    }

    public static function normalizeProxyType(string $type): string
    {
        return match (strtolower($type)) {
            OutboundProxy::TYPE_SOCKS5, 'socks' => OutboundProxy::TYPE_SOCKS5,
            OutboundProxy::TYPE_SOCKS5H => OutboundProxy::TYPE_SOCKS5H,
            OutboundProxy::TYPE_TOR => OutboundProxy::TYPE_SOCKS5H,
            default => OutboundProxy::TYPE_HTTP,
        };
    }

    public function keysFile(): string
    {
        return (string) ($this->config['keys_file'] ?? '');
    }

    public function restrictUsageToKey(): string
    {
        return (string) ($this->config['restrict_usage_to_key'] ?? '');
    }

    /**
     * Upstream NEW_ADD_KEY_FORCE_SECRET: use the configured value, otherwise a
     * stable per-process random secret to prevent denial-of-service.
     */
    public function addKeyForceSecret(): string
    {
        $configured = (string) ($this->config['add_key_force_secret'] ?? '');
        if ($configured !== '') {
            return $configured;
        }

        return $this->resolvedAddKeySecret ??= bin2hex(random_bytes(16));
    }

    /** @return array<int, string> */
    public function addKeyToInstances(): array
    {
        return array_values((array) ($this->config['add_key_to_instances'] ?? []));
    }

    public function musicVersion(): string
    {
        return (string) ($this->config['music_version'] ?? '2.9999099');
    }

    public function clientVersion(): string
    {
        return (string) ($this->config['client_version'] ?? '1.9999099');
    }

    public function uiKey(): string
    {
        return (string) ($this->config['ui_key'] ?? '');
    }

    public function userAgent(): string
    {
        return (string) ($this->config['user_agent'] ?? 'Firefox/100');
    }

    /** @return array<int, int> */
    public function unusualTrafficHttpCodes(): array
    {
        return array_map('intval', (array) ($this->config['unusual_traffic_http_codes'] ?? [302, 403, 429]));
    }

    public function raw(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
