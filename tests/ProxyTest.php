<?php

use Silverspoonmedia\VtualService\Http\YouTubeClient;
use Silverspoonmedia\VtualService\Support\Config;
use Silverspoonmedia\VtualService\Support\OutboundProxy;

describe('Proxy configuration', function () {
    it('parses an http proxy url with credentials', function () {
        $parsed = Config::parseProxyUrl('http://alice:secret@proxy.example.com:3128');

        expect($parsed)->toBe([
            'type' => 'http',
            'address' => 'proxy.example.com',
            'port' => 3128,
            'username' => 'alice',
            'password' => 'secret',
        ]);
    });

    it('parses a socks5h proxy url', function () {
        $parsed = Config::parseProxyUrl('socks5h://127.0.0.1:9050');

        expect($parsed['type'])->toBe('socks5h');
        expect($parsed['address'])->toBe('127.0.0.1');
        expect($parsed['port'])->toBe(9050);
    });

    it('enables proxy when address is configured', function () {
        $config = new Config([
            'proxy' => ['address' => '10.0.0.5', 'port' => 8080],
        ]);

        expect($config->proxyEnabled())->toBeTrue();
        expect($config->proxyEndpoint())->toBe([
            'type' => 'http',
            'address' => '10.0.0.5',
            'port' => 8080,
            'username' => '',
            'password' => '',
        ]);
    });

    it('enables tor preset from proxy type only', function () {
        $config = new Config([
            'proxy' => ['type' => 'tor'],
        ]);

        expect($config->proxyEnabled())->toBeTrue();
        expect($config->proxyType())->toBe('socks5h');
        expect($config->proxyAddress())->toBe('127.0.0.1');
        expect($config->proxyPort())->toBe(9050);
    });

    it('can be forced off with enabled=false', function () {
        $config = new Config([
            'proxy' => [
                'enabled' => false,
                'type' => 'tor',
            ],
        ]);

        expect($config->proxyEnabled())->toBeFalse();
    });
});

describe('OutboundProxy', function () {
    it('merges tcp proxy settings into stream opts for http type', function () {
        $config = new Config([
            'proxy' => ['address' => '10.0.0.5', 'port' => 3128],
        ]);
        $proxy = OutboundProxy::fromConfig($config);

        $merged = $proxy->mergeIntoStreamOpts(['http' => ['method' => 'GET']]);

        expect($merged['http']['proxy'])->toBe('tcp://10.0.0.5:3128');
        expect($merged['http']['request_fulluri'])->toBeTrue();
        expect($merged['http']['method'])->toBe('GET');
    });

    it('does not merge stream opts for socks proxies', function () {
        $config = new Config(['proxy' => ['type' => 'tor']]);
        $proxy = OutboundProxy::fromConfig($config);

        expect($proxy->requiresCurl())->toBeTrue();
        expect($proxy->curlProxyUrl())->toBe('socks5h://127.0.0.1:9050');
        expect($proxy->mergeIntoStreamOpts(['http' => ['method' => 'POST']]))->toBe(['http' => ['method' => 'POST']]);
    });

    it('adds proxy authorization when credentials are set', function () {
        $config = new Config([
            'proxy' => [
                'url' => 'http://user:pass@proxy.local:8888',
            ],
        ]);
        $proxy = OutboundProxy::fromConfig($config);
        $merged = $proxy->mergeIntoStreamOpts([]);

        expect($merged['http']['header'][0])->toStartWith('Proxy-Authorization: Basic ');
    });

    it('leaves opts unchanged when proxy is disabled', function () {
        $config = new Config(['proxy' => []]);
        $proxy = OutboundProxy::fromConfig($config);

        expect($proxy->enabled)->toBeFalse();
        expect($proxy->mergeIntoStreamOpts(['http' => ['method' => 'POST']]))->toBe(['http' => ['method' => 'POST']]);
    });
});

describe('YouTubeClient proxy integration', function () {
    it('reports proxy usage from configuration', function () {
        $withProxy = new YouTubeClient(new Config([
            'proxy' => ['address' => '10.0.0.5', 'port' => 8080],
            'user_agent' => 'TestAgent/1.0',
        ]));
        $withoutProxy = new YouTubeClient(new Config([
            'proxy' => [],
            'user_agent' => 'TestAgent/1.0',
        ]));

        expect($withProxy->usesProxy())->toBeTrue();
        expect($withoutProxy->usesProxy())->toBeFalse();
    });

    it('requires curl for tor proxy type', function () {
        $client = new YouTubeClient(new Config([
            'proxy' => ['type' => 'tor'],
            'user_agent' => 'TestAgent/1.0',
        ]));

        expect($client->proxyType())->toBe('socks5h');
        expect($client->proxyRequiresCurl())->toBe(extension_loaded('curl'));
    });
});
