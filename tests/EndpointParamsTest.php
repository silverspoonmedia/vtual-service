<?php

use Silverspoonmedia\VtualService\Endpoints\Endpoint;
use Silverspoonmedia\VtualService\Exceptions\ApiException;
use Silverspoonmedia\VtualService\Http\YouTubeClient;
use Silverspoonmedia\VtualService\Support\Config;

/**
 * Exposes protected Endpoint list helpers for unit tests.
 */
final class EndpointParamsProbe extends Endpoint
{
    public function __construct()
    {
        parent::__construct(
            new YouTubeClient(new Config(['user_agent' => 'Test/1.0'])),
            new Config([]),
        );
    }

    /** @return array<int, string> */
    public function normalize(mixed $value): array
    {
        return $this->normalizeList($value);
    }

    /** @param  array<int, string>  $realOptions */
    public function parts(string|array|null $part, array $realOptions): array
    {
        return $this->resolveParts($part, $realOptions);
    }

    /** @return array<int, string> */
    public function ids(string|array $value, string $field = 'id'): array
    {
        return $this->multipleIds($value, $field);
    }
}

describe('Endpoint list parameters', function () {
    it('accepts part as an array of strings', function () {
        $probe = new EndpointParamsProbe;

        $options = $probe->parts(['snippet', 'statistics'], ['snippet', 'statistics', 'contentDetails']);

        expect($options)->toBe([
            'snippet' => true,
            'statistics' => true,
            'contentDetails' => false,
        ]);
    });

    it('still accepts comma-separated strings for http compatibility', function () {
        $probe = new EndpointParamsProbe;

        expect($probe->parts('snippet,statistics', ['snippet', 'statistics']))->toBe([
            'snippet' => true,
            'statistics' => true,
        ]);

        expect($probe->ids('abc,def'))->toBe(['abc', 'def']);
    });

    it('accepts id as an array of strings', function () {
        $probe = new EndpointParamsProbe;

        expect($probe->ids([
            'dQw4w9WgXcQ',
            '9bZkp7q19f0',
            'jNQXAC9IVRw',
        ]))->toBe([
            'dQw4w9WgXcQ',
            '9bZkp7q19f0',
            'jNQXAC9IVRw',
        ]);
    });

    it('flattens comma-separated values inside array elements', function () {
        $probe = new EndpointParamsProbe;

        expect($probe->ids(['dQw4w9WgXcQ,9bZkp7q19f0', 'jNQXAC9IVRw']))->toBe([
            'dQw4w9WgXcQ',
            '9bZkp7q19f0',
            'jNQXAC9IVRw',
        ]);
    });

    it('rejects scalar parameters passed as arrays', function () {
        $endpoint = new class(new YouTubeClient(new Config(['user_agent' => 'Test/1.0'])), new Config([])) extends Endpoint
        {
            public function read(array $params): ?string
            {
                return $this->param($params, 'q');
            }
        };

        $endpoint->read(['q' => ['search term']]);
    })->throws(ApiException::class, 'must be a string');
});
