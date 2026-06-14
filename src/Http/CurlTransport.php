<?php

namespace Silverspoonmedia\VtualService\Http;

use RuntimeException;
use Silverspoonmedia\VtualService\Support\OutboundProxy;

/**
 * Executes outbound HTTP(S) requests through cURL.
 *
 * Required for SOCKS5 / SOCKS5h / Tor proxies because PHP stream wrappers
 * only support HTTP CONNECT proxies.
 */
final class CurlTransport
{
    /**
     * @param  array<string, mixed>  $opts  Stream-style options (`['http' => ...]`)
     * @return array{0: string|false, 1: array<int, string>}
     */
    public static function fetch(string $url, array $opts, OutboundProxy $proxy, bool $headersOnly = false): array
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException(
                'The PHP curl extension is required for SOCKS5, SOCKS5h, and Tor proxies. '
                . 'Install ext-curl or switch VTUAL_PROXY_TYPE to http.'
            );
        }

        $http = $opts['http'] ?? [];
        $method = strtoupper((string) ($http['method'] ?? ($headersOnly ? 'HEAD' : 'GET')));
        $headerLines = array_values(array_filter($http['header'] ?? [], 'is_string'));

        $ch = curl_init($url);
        if ($ch === false) {
            return [false, []];
        }

        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => ($http['follow_location'] ?? true) !== false,
            CURLOPT_HTTPHEADER => $headerLines,
        ];

        if ($headersOnly) {
            $curlOpts[CURLOPT_NOBODY] = true;
        }

        if ($method === 'POST' && isset($http['content'])) {
            $curlOpts[CURLOPT_POSTFIELDS] = $http['content'];
        }

        if (isset($http['user_agent']) && is_string($http['user_agent'])) {
            $curlOpts[CURLOPT_USER_AGENT] = $http['user_agent'];
        }

        if ($proxy->enabled) {
            if ($proxy->type === OutboundProxy::TYPE_HTTP) {
                $curlOpts[CURLOPT_PROXY] = $proxy->address . ':' . $proxy->port;
                $curlOpts[CURLOPT_HTTPPROXYTUNNEL] = true;
            } else {
                $curlOpts[CURLOPT_PROXY] = $proxy->curlProxyUrl();
            }

            if ($proxy->username !== '') {
                $curlOpts[CURLOPT_PROXYUSERPWD] = $proxy->username . ':' . $proxy->password;
            }
        }

        curl_setopt_array($ch, $curlOpts);
        $response = curl_exec($ch);

        if ($response === false) {
            curl_close($ch);

            return [false, []];
        }

        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($response, 0, $headerSize);
        $body = $headersOnly ? '' : substr($response, $headerSize);
        $headers = array_values(array_filter(explode("\r\n", $rawHeaders), fn ($line) => $line !== ''));

        return [$body, $headers];
    }
}
