<?php

namespace Silverspoonmedia\VtualService\Support;

use Silverspoonmedia\VtualService\Exceptions\TorEgressException;
use Silverspoonmedia\VtualService\Http\CurlTransport;

/**
 * Verifies that outbound traffic through the Tor preset exits via a Tor relay.
 *
 * Uses the Tor Project check API ({@see Config::torVerifyUrl()}) over the same
 * SOCKS proxy. Fails closed: any inconclusive result blocks scraping.
 */
final class TorEgressVerifier
{
    private ?bool $verified = null;

    private ?int $verifiedAt = null;

    /**
     * @param  (callable(string): array{0: string|false, 1: array<int, string>})|null  $fetcher
     */
    public function __construct(
        protected Config $config,
        protected OutboundProxy $proxy,
        protected mixed $fetcher = null,
    ) {}

    /**
     * @throws TorEgressException
     */
    public function assertValid(): void
    {
        if (! $this->config->torEgressCheckRequired()) {
            return;
        }

        if (! $this->proxy->enabled) {
            throw new TorEgressException(
                'Tor proxy is configured but outbound proxy is disabled. Scraping aborted to prevent IP leak.'
            );
        }

        $ttl = $this->config->torVerifyCacheSeconds();
        if ($this->verified === true && $this->verifiedAt !== null && (time() - $this->verifiedAt) < $ttl) {
            return;
        }

        if (! extension_loaded('curl')) {
            throw new TorEgressException(
                'Tor egress verification requires the PHP curl extension. Scraping aborted to prevent IP leak.'
            );
        }

        $url = $this->config->torVerifyUrl();
        $fetcher = $this->fetcher ?? fn (string $checkUrl): array => CurlTransport::fetch($checkUrl, [], $this->proxy);
        [$body] = $fetcher($url);

        if ($body === false || $body === '') {
            throw new TorEgressException(
                'Tor egress check failed: could not reach the verification endpoint through the Tor proxy. Scraping aborted to prevent IP leak.'
            );
        }

        $data = json_decode($body, true);
        if (! is_array($data) || ($data['IsTor'] ?? false) !== true) {
            $ip = is_array($data) ? (string) ($data['IP'] ?? 'unknown') : 'unknown';

            throw new TorEgressException(
                "Tor egress check failed: outbound IP ({$ip}) is not a valid Tor exit. Scraping aborted to prevent IP leak."
            );
        }

        $this->verified = true;
        $this->verifiedAt = time();
    }

    public function resetCache(): void
    {
        $this->verified = null;
        $this->verifiedAt = null;
    }
}
