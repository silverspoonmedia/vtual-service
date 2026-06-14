<?php

use Illuminate\Support\Facades\Route;
use Silverspoonmedia\VtualService\Exceptions\ApiException;

it('registers youtube operational routes when enabled', function () {
    $routes = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();

    expect($routes)->toContain('youtube/channels');
    expect($routes)->toContain('youtube/videos');
    expect($routes)->toContain('youtube/noKey/{path?}');
});

it('resolves VtualService from the container', function () {
    expect(app(\Silverspoonmedia\VtualService\VtualService::class))
        ->toBeInstanceOf(\Silverspoonmedia\VtualService\VtualService::class);
});

it('rejects requests when instance key is required', function () {
    config()->set('vtual-service.restrict_usage_to_key', 'secret-key');
    app()->forgetInstance(\Silverspoonmedia\VtualService\Support\Config::class);

    $response = $this->get('/youtube/videos?part=snippet&id=dQw4w9WgXcQ');

    $response->assertStatus(400);
    $response->assertJsonPath('error.message', 'This instance requires that you provide the appropriate <code>instanceKey</code> parameter!');
});

it('formats api exceptions like upstream dieWithJsonMessage', function () {
    $exception = new ApiException('Invalid part foo', 400);

    expect($exception->toArray())->toBe([
        'error' => [
            'code' => 400,
            'message' => 'Invalid part foo',
        ],
    ]);
});

it('formats tor egress exceptions as json api errors', function () {
    $exception = new \Silverspoonmedia\VtualService\Exceptions\TorEgressException('Tor egress check failed');

    expect($exception->apiCode())->toBe(503);
    expect($exception->toArray()['error']['code'])->toBe(503);
});
