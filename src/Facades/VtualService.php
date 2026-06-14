<?php

namespace Silverspoonmedia\VtualService\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array<string, mixed> channels(array<string, mixed> $params)
 * @method static array<string, mixed> commentThreads(array<string, mixed> $params)
 * @method static array<string, mixed> community(array<string, mixed> $params)
 * @method static array<string, mixed> liveChats(array<string, mixed> $params)
 * @method static array<string, mixed> lives(array<string, mixed> $params)
 * @method static array<string, mixed> playlistItems(array<string, mixed> $params)
 * @method static array<string, mixed> playlists(array<string, mixed> $params)
 * @method static array<string, mixed> search(array<string, mixed> $params)
 * @method static array<string, mixed> videos(array<string, mixed> $params)
 * @method static string addKey(string $key, ?string $forceSecret = null)
 * @method static array{body: string, keys_count: int} noKey(string $apiPath, bool $monitoring = false)
 * @method static int keysCount()
 *
 * @see \Silverspoonmedia\VtualService\VtualService
 */
class VtualService extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Silverspoonmedia\VtualService\VtualService::class;
    }
}
