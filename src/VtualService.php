<?php

namespace Silverspoonmedia\VtualService;

use Silverspoonmedia\VtualService\Endpoints\ChannelsEndpoint;
use Silverspoonmedia\VtualService\Endpoints\CommentThreadsEndpoint;
use Silverspoonmedia\VtualService\Endpoints\CommunityEndpoint;
use Silverspoonmedia\VtualService\Endpoints\LiveChatsEndpoint;
use Silverspoonmedia\VtualService\Endpoints\LivesEndpoint;
use Silverspoonmedia\VtualService\Endpoints\PlaylistItemsEndpoint;
use Silverspoonmedia\VtualService\Endpoints\PlaylistsEndpoint;
use Silverspoonmedia\VtualService\Endpoints\SearchEndpoint;
use Silverspoonmedia\VtualService\Endpoints\VideosEndpoint;
use Silverspoonmedia\VtualService\Services\ApiKeyService;

/**
 * Facade entry point for the ported YouTube operational API.
 *
 * Each public method maps to an upstream PHP endpoint file and returns a
 * decoded response array instead of echoing JSON.
 */
class VtualService
{
    public function __construct(
        protected ChannelsEndpoint $channels,
        protected CommentThreadsEndpoint $commentThreads,
        protected CommunityEndpoint $community,
        protected LiveChatsEndpoint $liveChats,
        protected LivesEndpoint $lives,
        protected PlaylistItemsEndpoint $playlistItems,
        protected PlaylistsEndpoint $playlists,
        protected SearchEndpoint $search,
        protected VideosEndpoint $videos,
        protected ApiKeyService $apiKeys,
    ) {}

    /** @param array<string, mixed> $params */
    public function channels(array $params): array
    {
        return $this->channels->list($params);
    }

    /** @param array<string, mixed> $params */
    public function commentThreads(array $params): array
    {
        return $this->commentThreads->list($params);
    }

    /** @param array<string, mixed> $params */
    public function community(array $params): array
    {
        return $this->community->list($params);
    }

    /** @param array<string, mixed> $params */
    public function liveChats(array $params): array
    {
        return $this->liveChats->list($params);
    }

    /** @param array<string, mixed> $params */
    public function lives(array $params): array
    {
        return $this->lives->list($params);
    }

    /** @param array<string, mixed> $params */
    public function playlistItems(array $params): array
    {
        return $this->playlistItems->list($params);
    }

    /** @param array<string, mixed> $params */
    public function playlists(array $params): array
    {
        return $this->playlists->list($params);
    }

    /** @param array<string, mixed> $params */
    public function search(array $params): array
    {
        return $this->search->list($params);
    }

    /** @param array<string, mixed> $params */
    public function videos(array $params): array
    {
        return $this->videos->list($params);
    }

    public function addKey(string $key, ?string $forceSecret = null): string
    {
        return $this->apiKeys->addKey($key, $forceSecret);
    }

    /** @return array{body: string, keys_count: int} */
    public function noKey(string $apiPath, bool $monitoring = false): array
    {
        return $this->apiKeys->proxyNoKeyRequest($apiPath, $monitoring);
    }

    public function keysCount(): int
    {
        return $this->apiKeys->keysCount();
    }
}
