<?php

use Illuminate\Support\Facades\Route;
use Silverspoonmedia\VtualService\Http\Controllers\YouTubeOperationalController;

$prefix = config('vtual-service.routes.prefix', 'youtube');
$middleware = config('vtual-service.routes.middleware', ['api']);

Route::middleware($middleware)
    ->prefix($prefix)
    ->group(function () {
        Route::get('channels', [YouTubeOperationalController::class, 'channels']);
        Route::get('videos', [YouTubeOperationalController::class, 'videos']);
        Route::get('search', [YouTubeOperationalController::class, 'search']);
        Route::get('playlists', [YouTubeOperationalController::class, 'playlists']);
        Route::get('playlistItems', [YouTubeOperationalController::class, 'playlistItems']);
        Route::get('commentThreads', [YouTubeOperationalController::class, 'commentThreads']);
        Route::get('community', [YouTubeOperationalController::class, 'community']);
        Route::get('lives', [YouTubeOperationalController::class, 'lives']);
        Route::get('liveChats', [YouTubeOperationalController::class, 'liveChats']);
        Route::get('addKey', [YouTubeOperationalController::class, 'addKey']);
        Route::get('noKey/{path?}', [YouTubeOperationalController::class, 'noKey'])->where('path', '.*');
    });
