<?php

namespace Silverspoonmedia\VtualService;

use Illuminate\Support\Facades\Config as LaravelConfig;
use Silverspoonmedia\VtualService\Endpoints\ChannelsEndpoint;
use Silverspoonmedia\VtualService\Endpoints\CommentThreadsEndpoint;
use Silverspoonmedia\VtualService\Endpoints\CommunityEndpoint;
use Silverspoonmedia\VtualService\Endpoints\LiveChatsEndpoint;
use Silverspoonmedia\VtualService\Endpoints\LivesEndpoint;
use Silverspoonmedia\VtualService\Endpoints\PlaylistItemsEndpoint;
use Silverspoonmedia\VtualService\Endpoints\PlaylistsEndpoint;
use Silverspoonmedia\VtualService\Endpoints\SearchEndpoint;
use Silverspoonmedia\VtualService\Endpoints\VideosEndpoint;
use Silverspoonmedia\VtualService\Http\YouTubeClient;
use Silverspoonmedia\VtualService\Services\ApiKeyService;
use Silverspoonmedia\VtualService\Support\Config;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class VtualServiceServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vtual-service')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Config::class, function () {
            return new Config(LaravelConfig::get('vtual-service', []));
        });

        $this->app->singleton(YouTubeClient::class, function ($app) {
            return new YouTubeClient($app->make(Config::class));
        });

        foreach ([
            ChannelsEndpoint::class,
            CommentThreadsEndpoint::class,
            CommunityEndpoint::class,
            LiveChatsEndpoint::class,
            LivesEndpoint::class,
            PlaylistItemsEndpoint::class,
            PlaylistsEndpoint::class,
            SearchEndpoint::class,
            VideosEndpoint::class,
            ApiKeyService::class,
        ] as $class) {
            $this->app->singleton($class, fn ($app) => new $class(
                $app->make(YouTubeClient::class),
                $app->make(Config::class),
            ));
        }

        $this->app->singleton(VtualService::class, function ($app) {
            return new VtualService(
                $app->make(ChannelsEndpoint::class),
                $app->make(CommentThreadsEndpoint::class),
                $app->make(CommunityEndpoint::class),
                $app->make(LiveChatsEndpoint::class),
                $app->make(LivesEndpoint::class),
                $app->make(PlaylistItemsEndpoint::class),
                $app->make(PlaylistsEndpoint::class),
                $app->make(SearchEndpoint::class),
                $app->make(VideosEndpoint::class),
                $app->make(ApiKeyService::class),
            );
        });
    }

    public function packageBooted(): void
    {
        if (! config('vtual-service.routes.enabled', true)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/youtube.php');
    }
}
