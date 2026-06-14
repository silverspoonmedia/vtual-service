<?php

use Silverspoonmedia\VtualService\Support\Json;
use Silverspoonmedia\VtualService\Support\Parsers;
use Silverspoonmedia\VtualService\Support\Protobuf;
use Silverspoonmedia\VtualService\Support\Validators;

describe('Validators', function () {
    it('validates channel ids', function () {
        expect(Validators::isChannelId('UCv_LqFI-0vMVYgNR3TeB3zQ'))->toBeTrue();
        expect(Validators::isChannelId('not-a-channel'))->toBeFalse();
    });

    it('validates video ids', function () {
        expect(Validators::isVideoId('dQw4w9WgXcQ'))->toBeTrue();
        expect(Validators::isVideoId('short'))->toBeFalse();
    });

    it('validates youtube data api keys', function () {
        expect(Validators::isYouTubeDataApiV3Key('AIzaSyA' . str_repeat('x', 32)))->toBeTrue();
        expect(Validators::isYouTubeDataApiV3Key('not-a-key'))->toBeFalse();
    });
});

describe('Parsers', function () {
    it('parses abbreviated counts', function () {
        expect(Parsers::intValue('1.2K views', 'view'))->toBe(1200);
        expect(Parsers::intValue('No'))->toBe(0);
    });

    it('parses view counts', function () {
        expect(Parsers::intFromViewCount('1,234 views'))->toBe(1234);
        expect(Parsers::intFromViewCount('No views'))->toBe(0);
    });

    it('parses durations', function () {
        expect(Parsers::intFromDuration('1:02:03'))->toBe(3723);
        expect(Parsers::intFromDuration('-1:00'))->toBe(-60);
    });

    it('parses relative published at strings', function () {
        $now = 1_700_000_000;
        $published = Parsers::publishedAt('3 days ago', $now);
        expect($published)->toBe($now - 3 * 86_400);
    });

    it('encodes base64url', function () {
        expect(Parsers::base64UrlEncode('hello'))->toBe('aGVsbG8');
    });
});

describe('Json path helpers', function () {
    it('reads nested paths', function () {
        $json = ['a' => ['b' => ['c' => 42]]];
        expect(Json::pathExists($json, 'a/b/c'))->toBeTrue();
        expect(Json::value($json, 'a/b/c'))->toBe(42);
        expect(Json::value($json, 'missing', null, 'default'))->toBe('default');
    });
});

describe('Protobuf encoders', function () {
    it('builds community params', function () {
        $params = Protobuf::communityParams('UgkxTESTPOSTID12345678901234567890');
        expect($params)->not->toBeEmpty();
        expect(base64_decode($params, true))->not->toBeFalse();
    });

    it('builds shorts search continuation', function () {
        $token = Protobuf::shortsSearchContinuation('test query');
        expect($token)->not->toBeEmpty();
    });
});
