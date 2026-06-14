<?php

namespace Silverspoonmedia\VtualService\Endpoints;

use Silverspoonmedia\VtualService\Exceptions\ApiException;
use Silverspoonmedia\VtualService\Support\Innertube;
use Silverspoonmedia\VtualService\Support\Parsers;
use Silverspoonmedia\VtualService\Support\Validators;

/**
 * Ported from upstream `playlistItems.php`.
 *
 * Returns `youtube#playlistItemListResponse` data with `snippet` for a
 * playlist, paginating through `pageToken`.
 */
class PlaylistItemsEndpoint extends Endpoint
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function list(array $params): array
    {
        $part = $this->paramPart($params, 'part');
        $playlistId = $this->param($params, 'playlistId');

        if ($part === null || $playlistId === null) {
            throw new ApiException('Required parameters not provided');
        }

        $parts = $this->normalizeList($part);
        if ($parts !== ['snippet']) {
            throw new ApiException('Invalid part');
        }

        if (! Validators::isPlaylistId($playlistId)) {
            throw new ApiException('Invalid playlistId');
        }

        $continuationToken = '';
        if (($pageToken = $this->param($params, 'pageToken')) !== null) {
            if (! Validators::isContinuationToken($pageToken)) {
                throw new ApiException('Invalid pageToken');
            }
            $continuationToken = $pageToken;
        }

        return $this->getApi($playlistId, $continuationToken);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getApi(string $playlistId, string $continuationToken): array
    {
        $continuationTokenProvided = $continuationToken !== '';

        if ($continuationTokenProvided) {
            $result = $this->client->getContinuationJson($continuationToken);
            $items = Innertube::continuationItems($result);
        } else {
            $url = "https://www.youtube.com/playlist?list=$playlistId";
            $result = $this->client->getJsonFromHtml($url, ['http' => ['header' => ['Accept-Language: en']]]);
            $items = Innertube::tabs($result)[0]['tabRenderer']['content']['sectionListRenderer']['contents'][0]['itemSectionRenderer']['contents'][0]['playlistVideoListRenderer']['contents'];
        }

        $answerItems = [];
        $itemsCount = count($items);
        for ($i = 0; $i < $itemsCount - 1; $i++) {
            $playlistVideoRenderer = $items[$i]['playlistVideoRenderer'];
            $answerItems[] = [
                'kind' => 'youtube#playlistItem',
                'etag' => 'NotImplemented',
                'snippet' => [
                    'publishedAt' => Parsers::publishedAt($playlistVideoRenderer['videoInfo']['runs'][2]['text']),
                    'title' => $playlistVideoRenderer['title']['runs'][0]['text'],
                    'thumbnails' => $playlistVideoRenderer['thumbnail']['thumbnails'],
                    'resourceId' => [
                        'kind' => 'youtube#video',
                        'videoId' => $playlistVideoRenderer['videoId'],
                    ],
                ],
            ];
        }

        $nextContinuationToken = urldecode(
            $items[100]['continuationItemRenderer']['continuationEndpoint']['continuationCommand']['token'] ?? ''
        );

        $answer = [
            'kind' => 'youtube#playlistItemListResponse',
            'etag' => 'NotImplemented',
        ];
        if ($nextContinuationToken !== '') {
            $answer['nextPageToken'] = $nextContinuationToken;
        }
        $answer['items'] = $answerItems;

        return $answer;
    }
}
