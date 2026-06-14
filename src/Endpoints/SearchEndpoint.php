<?php

namespace Silverspoonmedia\VtualService\Endpoints;

use Silverspoonmedia\VtualService\Exceptions\ApiException;
use Silverspoonmedia\VtualService\Support\Innertube;
use Silverspoonmedia\VtualService\Support\Parsers;
use Silverspoonmedia\VtualService\Support\Protobuf;
use Silverspoonmedia\VtualService\Support\Validators;

/** Ported from upstream `search.php`. */
class SearchEndpoint extends Endpoint
{
    private const REAL_OPTIONS = ['id', 'snippet'];

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function list(array $params): array
    {
        $part = $this->paramPart($params, 'part');
        $channelId = $this->param($params, 'channelId');
        $hashtag = $this->param($params, 'hashtag');
        $q = $this->param($params, 'q');
        $eventType = $this->param($params, 'eventType');

        $hasFilter = $channelId !== null || $hashtag !== null || $q !== null;
        $hasOrderOrFilter = $this->param($params, 'order') !== null || $hashtag !== null || $q !== null || $eventType !== null;

        if ($part === null || ! $hasFilter || ! $hasOrderOrFilter) {
            throw new ApiException('Required parameters not provided');
        }

        $options = $this->resolveParts($part, self::REAL_OPTIONS);
        if ($options['snippet']) {
            $options['id'] = true;
        }

        if ($channelId !== null && ! Validators::isChannelId($channelId)) {
            throw new ApiException('Invalid channelId');
        }
        if ($eventType !== null && ! Validators::isEventType($eventType)) {
            throw new ApiException('Invalid eventType');
        }
        if ($hashtag !== null && ! Validators::isHashtag($hashtag)) {
            throw new ApiException('Invalid hashtag');
        }
        if ($q !== null && ! Validators::isQuery($q)) {
            throw new ApiException('Invalid q');
        }

        $order = $this->param($params, 'order') ?? 'relevance';
        if ($this->param($params, 'order') !== null && $eventType === null && ! in_array($order, ['viewCount', 'relevance'], true)) {
            throw new ApiException('Invalid order');
        }

        $continuationToken = '';
        if (($pageToken = $this->param($params, 'pageToken')) !== null) {
            if (! Validators::isContinuationToken($pageToken)) {
                throw new ApiException('Invalid pageToken');
            }
            $continuationToken = $pageToken;
        }

        return $this->getApi($params, $options, $order, $continuationToken);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, bool> $options
     * @return array<string, mixed>
     */
    protected function getApi(array $params, array $options, string $order, string $continuationToken): array
    {
        $continuationTokenProvided = $continuationToken !== '';
        $json = null;
        $items = [];

        if ($this->param($params, 'hashtag') !== null) {
            $id = (string) $params['hashtag'];
            $json = $continuationTokenProvided
                ? $this->client->getContinuationJson($continuationToken)
                : $this->client->getJsonFromHtml('https://www.youtube.com/hashtag/' . urlencode($id));
            $items = $continuationTokenProvided
                ? Innertube::continuationItems($json ?? [])
                : Innertube::tabs($json ?? [])[0]['tabRenderer']['content']['richGridRenderer']['contents'];
        } elseif ($this->param($params, 'eventType') !== null) {
            $json = $this->client->getJsonFromHtml("https://www.youtube.com/channel/{$params['channelId']}/videos?view=2&live_view=502");
            $items = Innertube::tabs($json ?? [])[1]['tabRenderer']['content']['sectionListRenderer']['contents'][0]['itemSectionRenderer']['contents'][0]['gridRenderer']['items'];
        } elseif ($this->param($params, 'q') !== null) {
            $rawData = ['context' => ['client' => ['clientName' => 'WEB', 'clientVersion' => $this->config->musicVersion()]]];
            if (($params['type'] ?? null) === 'short') {
                $rawData['continuation'] = Protobuf::shortsSearchContinuation((string) $params['q']);
            } else {
                $rawData['query'] = str_replace('"', '\"', (string) $params['q']);
                if ($order === 'viewCount') {
                    $rawData['params'] = 'EgIQAQ==';
                }
            }
            if ($continuationTokenProvided) {
                $rawData['continuation'] = $continuationToken;
            }
            $json = $this->client->postInnertube('https://www.youtube.com/youtubei/v1/search?key=' . $this->config->uiKey(), $rawData);
            if (($params['type'] ?? null) === 'short') {
                $items = $json['onResponseReceivedCommands'][0]['reloadContinuationItemsCommand']['continuationItems'][0]['twoColumnSearchResultsRenderer']['primaryContents']['sectionListRenderer']['contents'][0]['itemSectionRenderer']['contents'];
            } else {
                $section = ($continuationTokenProvided
                    ? $json['onResponseReceivedCommands'][0]['appendContinuationItemsAction']['continuationItems']
                    : $json['contents']['twoColumnSearchResultsRenderer']['primaryContents']['sectionListRenderer']['contents'])[0]['itemSectionRenderer']['contents'];
                $items = $section;
            }
        } else {
            $rawData = [
                'context' => ['client' => ['clientName' => 'WEB', 'clientVersion' => $this->config->clientVersion()]],
                'browseId' => $params['channelId'],
                'params' => 'EgZ2aWRlb3MYASAAMAE=',
            ];
            if ($continuationTokenProvided) {
                $rawData['continuation'] = $continuationToken;
            }
            $json = $this->client->postInnertube('https://www.youtube.com/youtubei/v1/browse?key=' . $this->config->uiKey(), $rawData);
            $items = $continuationTokenProvided
                ? Innertube::continuationItems($json ?? [])
                : Innertube::tabs($json ?? [])[1]['tabRenderer']['content']['sectionListRenderer']['contents'][0]['itemSectionRenderer']['contents'][0]['gridRenderer']['items'];
        }

        $answerItems = [];
        $itemsCount = count($items);
        $upperBound = $itemsCount - ($continuationTokenProvided || $this->param($params, 'hashtag') !== null ? 1 : 0);

        for ($i = 0; $i < $upperBound; $i++) {
            $item = $items[$i];
            if ($this->param($params, 'hashtag') !== null) {
                $path = 'richItemRenderer/content/videoRenderer';
            } elseif ($this->param($params, 'q') !== null) {
                $path = 'videoRenderer';
                if (! array_key_exists($path, $item)) {
                    continue;
                }
            } else {
                $path = 'gridVideoRenderer';
            }

            $gridVideoRenderer = \Silverspoonmedia\VtualService\Support\Json::value($item, $path);
            $answerItem = ['kind' => 'youtube#searchResult', 'etag' => 'NotImplemented'];

            if ($options['id']) {
                $answerItem['id'] = ['kind' => 'youtube#video', 'videoId' => $gridVideoRenderer['videoId']];
            }

            if ($options['snippet']) {
                $run = $gridVideoRenderer['ownerText']['runs'][0];
                $browseEndpoint = $run['navigationEndpoint']['browseEndpoint'];
                $channelHandle = substr($browseEndpoint['canonicalBaseUrl'], 1);
                $badges = ! empty($gridVideoRenderer['badges'])
                    ? array_map(fn ($badge) => $badge['metadataBadgeRenderer']['label'], $gridVideoRenderer['badges'])
                    : [];
                $chaptersRaw = $gridVideoRenderer['expandableMetadata']['expandableMetadataRenderer']['expandedContent']['horizontalCardListRenderer']['cards'] ?? [];
                $chapters = ! empty($chaptersRaw) ? array_map(function ($chapter) {
                    $renderer = $chapter['macroMarkersListItemRenderer'];

                    return [
                        'title' => $renderer['title']['simpleText'],
                        'time' => Parsers::intFromDuration($renderer['timeDescription']['simpleText']),
                        'thumbnails' => $renderer['thumbnail']['thumbnails'],
                    ];
                }, $chaptersRaw) : [];

                $answerItem['snippet'] = [
                    'channelId' => $browseEndpoint['browseId'],
                    'title' => $gridVideoRenderer['title']['runs'][0]['text'],
                    'thumbnails' => $gridVideoRenderer['thumbnail']['thumbnails'],
                    'channelTitle' => $run['text'],
                    'channelHandle' => str_starts_with($channelHandle, '@') ? $channelHandle : null,
                    'timestamp' => $gridVideoRenderer['publishedTimeText']['simpleText'],
                    'duration' => Parsers::intFromDuration($gridVideoRenderer['lengthText']['simpleText']),
                    'views' => Parsers::intFromViewCount($gridVideoRenderer['viewCountText']['simpleText']),
                    'badges' => $badges,
                    'channelApproval' => $gridVideoRenderer['ownerBadges'][0]['metadataBadgeRenderer']['tooltip'],
                    'channelThumbnails' => $gridVideoRenderer['channelThumbnailSupportedRenderers']['channelThumbnailWithLinkRenderer']['thumbnail']['thumbnails'],
                    'detailedMetadataSnippet' => $gridVideoRenderer['detailedMetadataSnippets'][0]['snippetText']['runs'],
                    'chapters' => $chapters,
                ];
            }

            $answerItems[] = $answerItem;
        }

        $nextContinuationToken = '';
        if ($this->param($params, 'hashtag') !== null) {
            $nextContinuationToken = $itemsCount > 60 ? ($items[60] ?? '') : '';
        } else {
            $nextContinuationToken = $itemsCount > 30 ? ($items[30] ?? '') : '';
        }
        if (is_array($nextContinuationToken) && $nextContinuationToken !== []) {
            $nextContinuationToken = $nextContinuationToken['continuationItemRenderer']['continuationEndpoint']['continuationCommand']['token'] ?? '';
        } elseif (! is_string($nextContinuationToken)) {
            $nextContinuationToken = '';
        }

        if ($this->param($params, 'q') !== null) {
            $sections = $continuationTokenProvided
                ? $json['onResponseReceivedCommands'][0]['appendContinuationItemsAction']['continuationItems']
                : $json['contents']['twoColumnSearchResultsRenderer']['primaryContents']['sectionListRenderer']['contents'];
            $nextContinuationToken = $sections[1]['continuationItemRenderer']['continuationEndpoint']['continuationCommand']['token'] ?? '';
        }

        $answer = ['kind' => 'youtube#searchListResponse', 'etag' => 'NotImplemented'];
        $nextContinuationToken = urldecode((string) $nextContinuationToken);
        if ($nextContinuationToken !== '') {
            $answer['nextPageToken'] = $nextContinuationToken;
        }
        $answer['items'] = $answerItems;

        return $answer;
    }
}
