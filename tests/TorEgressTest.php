<?php

use Silverspoonmedia\VtualService\Exceptions\TorEgressException;
use Silverspoonmedia\VtualService\Http\YouTubeClient;
use Silverspoonmedia\VtualService\Support\Config;
use Silverspoonmedia\VtualService\Support\OutboundProxy;
use Silverspoonmedia\VtualService\Support\TorEgressVerifier;

describe('Tor egress configuration', function () {
    it('detects tor preset as active', function () {
        $config = new Config(['proxy' => ['type' => 'tor']]);

        expect($config->torProxyActive())->toBeTrue();
        expect($config->torVerifyEgress())->toBeTrue();
        expect($config->torVerifyUrl())->toBe('https://check.torproject.org/api/ip');
    });

    it('does not treat arbitrary socks5h url as tor preset', function () {
        $config = new Config([
            'proxy' => ['url' => 'socks5h://10.0.0.99:1080'],
        ]);

        expect($config->torProxyActive())->toBeFalse();
        expect($config->torEgressCheckRequired())->toBeFalse();
    });

    it('requires egress check for socks5h aimed at tor endpoint', function () {
        $config = new Config([
            'proxy' => ['url' => 'socks5h://127.0.0.1:9050'],
        ]);

        expect($config->torProxyActive())->toBeFalse();
        expect($config->torEgressCheckRequired())->toBeTrue();
    });

    it('allows disabling egress verification', function () {
        $config = new Config([
            'proxy' => [
                'type' => 'tor',
                'tor' => ['verify_egress' => false],
            ],
        ]);

        expect($config->torVerifyEgress())->toBeFalse();
    });
});

describe('TorEgressVerifier', function () {
    it('passes when IsTor is true', function () {
        $config = new Config(['proxy' => ['type' => 'tor']]);
        $proxy = OutboundProxy::fromConfig($config);
        $verifier = new TorEgressVerifier(
            $config,
            $proxy,
            fn (): array => [json_encode(['IsTor' => true, 'IP' => '185.220.101.1']), []],
        );

        $verifier->assertValid();
        expect(true)->toBeTrue();
    });

    it('throws when IsTor is false', function () {
        $config = new Config(['proxy' => ['type' => 'tor']]);
        $proxy = OutboundProxy::fromConfig($config);
        $verifier = new TorEgressVerifier(
            $config,
            $proxy,
            fn (): array => [json_encode(['IsTor' => false, 'IP' => '203.0.113.1']), []],
        );

        $verifier->assertValid();
    })->throws(TorEgressException::class, 'not a valid Tor exit');

    it('throws when verification endpoint is unreachable', function () {
        $config = new Config(['proxy' => ['type' => 'tor']]);
        $proxy = OutboundProxy::fromConfig($config);
        $verifier = new TorEgressVerifier(
            $config,
            $proxy,
            fn (): array => [false, []],
        );

        $verifier->assertValid();
    })->throws(TorEgressException::class, 'could not reach the verification endpoint');

    it('skips verification for non-tor proxy types', function () {
        $config = new Config(['proxy' => ['address' => '10.0.0.1', 'port' => 8080]]);
        $proxy = OutboundProxy::fromConfig($config);
        $called = false;
        $verifier = new TorEgressVerifier(
            $config,
            $proxy,
            function () use (&$called): array {
                $called = true;

                return [false, []];
            },
        );

        $verifier->assertValid();
        expect($called)->toBeFalse();
    });

    it('caches a successful verification', function () {
        $config = new Config([
            'proxy' => [
                'type' => 'tor',
                'tor' => ['verify_cache_seconds' => 300],
            ],
        ]);
        $proxy = OutboundProxy::fromConfig($config);
        $calls = 0;
        $verifier = new TorEgressVerifier(
            $config,
            $proxy,
            function () use (&$calls): array {
                $calls++;

                return [json_encode(['IsTor' => true, 'IP' => '185.220.101.1']), []];
            },
        );

        $verifier->assertValid();
        $verifier->assertValid();

        expect($calls)->toBe(1);
    });
});

describe('YouTubeClient tor guard', function () {
    it('blocks scraping when tor egress check fails', function () {
        $config = new Config(['proxy' => ['type' => 'tor'], 'user_agent' => 'Test/1.0']);
        $proxy = OutboundProxy::fromConfig($config);
        $verifier = new TorEgressVerifier(
            $config,
            $proxy,
            fn (): array => [json_encode(['IsTor' => false, 'IP' => '203.0.113.50']), []],
        );
        $client = new YouTubeClient($config, $verifier);

        $client->getRemote('https://www.youtube.com/');
    })->throws(TorEgressException::class);
});
