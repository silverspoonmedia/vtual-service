<?php

namespace Silverspoonmedia\VtualService\Endpoints;

use Silverspoonmedia\VtualService\Exceptions\ApiException;
use Silverspoonmedia\VtualService\Support\Innertube;
use Silverspoonmedia\VtualService\Support\Json;
use Silverspoonmedia\VtualService\Support\Parsers;
use Silverspoonmedia\VtualService\Support\Validators;

/** Ported from upstream `channels.php`. */
class ChannelsEndpoint extends Endpoint
{
    private const REAL_OPTIONS = [
        'status',
        'upcomingEvents',
        'shorts',
        'community',
        'channels',
        'about',
        'approval',
        'playlists',
        'snippet',
        'membership',
        'popular',
        'recent',
        'letsPlay',
    ];

    private const COMMUNITY_TAB_NAME = 'Posts';

    /** @var array<string, bool> */
    private array $options = [];

    private bool $returnEmptyItems = false;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function list(array $params): array
    {
        $part = $this->paramPart($params, 'part');
        $this->options = $part !== null
            ? $this->resolveParts($part, self::REAL_OPTIONS)
            : array_fill_keys(self::REAL_OPTIONS, false);

        $ids = [];

        if (($cIdList = $this->paramList($params, 'cId')) !== null) {
            foreach ($this->multipleIds($cIdList, 'cId') as $realCId) {
                if (! Validators::isCId($realCId)) {
                    throw new ApiException('Invalid cId');
                }
                $result = $this->client->getJsonFromHtml("https://www.youtube.com/c/$realCId/about");
                $ids[] = $result['metadata']['channelMetadataRenderer']['externalId'];
            }
        } elseif (($idList = $this->paramList($params, 'id')) !== null) {
            foreach ($this->multipleIds($idList) as $realId) {
                if (! Validators::isChannelId($realId)) {
                    throw new ApiException('Invalid id');
                }
            }
            $ids = $idList;
        } elseif (($handleList = $this->paramList($params, 'handle')) !== null) {
            foreach ($this->multipleIds($handleList, 'handle') as $realHandle) {
                if (! Validators::isHandle($realHandle)) {
                    throw new ApiException('Invalid handle');
                }
                $result = $this->client->getJsonFromHtml("https://www.youtube.com/$realHandle");
                $paramsList = $result['responseContext']['serviceTrackingParams'][0]['params'];
                $resolvedId = null;
                foreach ($paramsList as $param) {
                    if ($param['key'] === 'browse_id') {
                        $resolvedId = $param['value'];
                        break;
                    }
                }
                $ids[] = $resolvedId;
            }
        } elseif (($forUsernameList = $this->paramList($params, 'forUsername')) !== null) {
            foreach ($this->multipleIds($forUsernameList, 'forUsername') as $realUsername) {
                if (! Validators::isUsername($realUsername)) {
                    throw new ApiException('Invalid forUsername');
                }
                $result = $this->client->getJsonFromHtml("https://www.youtube.com/user/$realUsername");
                $ids[] = $result['header']['c4TabbedHeaderRenderer']['channelId'];
            }
        } elseif (($rawList = $this->paramList($params, 'raw')) !== null) {
            foreach ($this->multipleIds($rawList, 'raw') as $realRaw) {
                $result = $this->client->getJsonFromHtml("https://www.youtube.com/$realRaw");
                $ids[] = $result['header']['c4TabbedHeaderRenderer']['channelId'];
            }
        } else {
            throw new ApiException('Required parameters not provided');
        }

        $order = $this->param($params, 'order') ?? 'time';
        if ($this->param($params, 'order') !== null && ! in_array($order, ['time', 'viewCount'], true)) {
            throw new ApiException('Invalid order');
        }

        $continuationToken = '';
        if (($pageToken = $this->param($params, 'pageToken')) !== null) {
            $continuationToken = $pageToken;
            $hasVisitorData = $this->options['shorts'] || $this->options['popular'] || $this->options['recent'];
            if (($hasVisitorData && ! Validators::isContinuationTokenAndVisitorData($continuationToken))
                || (! $hasVisitorData && ! Validators::isContinuationToken($continuationToken))) {
                throw new ApiException('Invalid pageToken');
            }
        }

        return $this->getApi($ids, $order, $continuationToken);
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, mixed>
     */
    protected function getApi(array $ids, string $order, string $continuationToken): array
    {
        $this->returnEmptyItems = false;
        $items = [];

        foreach ($ids as $channelId) {
            $items[] = $this->getItem($channelId, $order, $continuationToken);
            if ($this->returnEmptyItems) {
                return [
                    'kind' => 'youtube#channelListResponse',
                    'etag' => 'NotImplemented',
                    'items' => [],
                ];
            }
        }

        return [
            'kind' => 'youtube#channelListResponse',
            'etag' => 'NotImplemented',
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    protected function getItem(string $id, string $order, string $continuationToken): array
    {
        $item = [
            'kind' => 'youtube#channel',
            'etag' => 'NotImplemented',
            'id' => $id,
        ];
        $continuationTokenProvided = $continuationToken !== '';

        if ($this->options['status']) {
            $result = $this->client->getJsonFromHtml("https://www.youtube.com/channel/$id", forceLanguage: true, verifiesChannelRedirection: true);
            $item['status'] = $result['alerts'][0]['alertRenderer']['text']['simpleText'];
        }

        if ($this->options['upcomingEvents']) {
            $upcomingEvents = [];
            $result = $this->client->getJsonFromHtml("https://www.youtube.com/channel/$id", forceLanguage: true, verifiesChannelRedirection: true);
            $subItems = Innertube::tabs($result)[0]['tabRenderer']['content']['sectionListRenderer']['contents'][0]['itemSectionRenderer']['contents'][0]['shelfRenderer']['content']['horizontalListRenderer']['items'];
            foreach ($subItems as $subItem) {
                $path = 'gridVideoRenderer/upcomingEventData';
                if (Json::pathExists($subItem, $path)) {
                    $subItem = $subItem['gridVideoRenderer'];
                    foreach (['navigationEndpoint', 'menu', 'trackingParams', 'thumbnailOverlays'] as $toRemove) {
                        unset($subItem[$toRemove]);
                    }
                    $upcomingEvents[] = $subItem;
                }
            }
            $item['upcomingEvents'] = $upcomingEvents;
        }

        if ($this->options['shorts']) {
            $visitorData = null;
            if (! $continuationTokenProvided) {
                $result = $this->client->getJsonFromHtml("https://www.youtube.com/channel/$id/shorts", forceLanguage: true, verifiesChannelRedirection: true);
                $visitorData = $this->getVisitorData($result);
                $tab = Innertube::tabByName($result, 'Shorts');
                $tabRenderer = $tab['tabRenderer'];
                $richGridRenderer = $tabRenderer['content']['richGridRenderer'];
                if ($order === 'viewCount') {
                    $nextPageToken = $richGridRenderer['header']['feedFilterChipBarRenderer']['contents'][1]['chipCloudChipRenderer']['navigationEndpoint']['continuationCommand']['token'];
                    if ($nextPageToken !== null) {
                        $continuationToken = urldecode("$nextPageToken,$visitorData");

                        return $this->getItem($id, $order, $continuationToken);
                    }
                }
            } else {
                $result = $this->client->getContinuationJson($continuationToken);
            }
            $shorts = [];
            if (! $continuationTokenProvided) {
                $reelShelfRendererItems = $richGridRenderer['contents'];
            } else {
                $onResponseReceivedActions = $result['onResponseReceivedActions'];
                $onResponseReceivedAction = $onResponseReceivedActions[count($onResponseReceivedActions) - 1];
                $continuationItems = Json::value($onResponseReceivedAction, 'appendContinuationItemsAction', 'reloadContinuationItemsCommand');
                $reelShelfRendererItems = $continuationItems['continuationItems'];
            }
            foreach ($reelShelfRendererItems as $reelShelfRendererItem) {
                if (! array_key_exists('richItemRenderer', $reelShelfRendererItem)) {
                    continue;
                }
                $shortsLockupViewModel = $reelShelfRendererItem['richItemRenderer']['content']['shortsLockupViewModel'];
                $overlayMetadata = $shortsLockupViewModel['overlayMetadata'];
                $reelWatchEndpoint = $shortsLockupViewModel['onTap']['innertubeCommand']['reelWatchEndpoint'];
                $short = [
                    'videoId' => $reelWatchEndpoint['videoId'],
                    'viewCount' => Parsers::intValue($overlayMetadata['secondaryText']['content'], 'view'),
                    'title' => $overlayMetadata['primaryText']['content'],
                    'thumbnail' => $shortsLockupViewModel['thumbnail']['sources'][0],
                    'frame0Thumbnail' => $reelWatchEndpoint['thumbnail']['thumbnails'],
                ];
                if (! $continuationTokenProvided) {
                    $browseEndpoint = $tabRenderer['endpoint']['browseEndpoint'];
                    $short['channelHandle'] = substr($browseEndpoint['canonicalBaseUrl'], 1);
                    $short['channelId'] = $browseEndpoint['browseId'];
                }
                $shorts[] = $short;
            }
            $item['shorts'] = $shorts;
            if ($reelShelfRendererItems !== null && count($reelShelfRendererItems) > 48) {
                $nextPageToken = $reelShelfRendererItems[48]['continuationItemRenderer']['continuationEndpoint']['continuationCommand']['token'];
                $item['nextPageToken'] = urldecode("$nextPageToken,$visitorData");
            }
        }

        if ($this->options['community']) {
            if (! $continuationTokenProvided) {
                $result = $this->client->getJsonFromHtml(
                    'https://www.youtube.com/channel/'.$id.'/'.strtolower(self::COMMUNITY_TAB_NAME),
                    forceLanguage: true,
                    verifiesChannelRedirection: true
                );
            } else {
                $result = $this->client->getContinuationJson($continuationToken);
            }
            $community = [];
            if (! $continuationTokenProvided) {
                $tab = Innertube::tabByName($result, self::COMMUNITY_TAB_NAME);
                $contents = $tab['tabRenderer']['content']['sectionListRenderer']['contents'][0]['itemSectionRenderer']['contents'];
            } else {
                $contents = $result['onResponseReceivedEndpoints'][0]['appendContinuationItemsAction']['continuationItems'];
            }
            foreach ($contents as $content) {
                if (! array_key_exists('backstagePostThreadRenderer', $content)) {
                    continue;
                }
                $community[] = Innertube::communityPostFromContent($content);
            }
            $item['community'] = $community;
            $lastContent = $contents !== null ? end($contents) : null;
            if (is_array($lastContent) && array_key_exists('continuationItemRenderer', $lastContent)) {
                $item['nextPageToken'] = urldecode($lastContent['continuationItemRenderer']['continuationEndpoint']['continuationCommand']['token']);
            }
        }

        if ($this->options['channels']) {
            if (! $continuationTokenProvided) {
                $result = $this->client->getJsonFromHtml("https://www.youtube.com/channel/$id/channels", forceLanguage: true, verifiesChannelRedirection: true);

                $tab = Innertube::tabByName($result, 'Channels');
                $sectionListRenderer = $tab['tabRenderer']['content']['sectionListRenderer'];
                $contents = array_map(fn ($content) => $content['itemSectionRenderer']['contents'][0], $sectionListRenderer['contents']);
                $itemsArray = [];
                foreach ($contents as $content) {
                    if (array_key_exists('shelfRenderer', $content)) {
                        $sectionTitle = $content['shelfRenderer']['title']['runs'][0]['text'];
                        $content = $content['shelfRenderer']['content'];
                        $content = Json::value($content, 'horizontalListRenderer', 'expandedShelfContentsRenderer');
                    } else {
                        $sectionTitle = $sectionListRenderer['subMenu']['channelSubMenuRenderer']['contentTypeSubMenuItems'][0]['title'];
                        $content = $content['gridRenderer'];
                    }
                    $itemsArray[] = [$sectionTitle, $content['items']];
                }
            } else {
                $result = $this->client->getContinuationJson($continuationToken);
                $itemsArray = [[null, Innertube::continuationItems($result)]];
            }
            $channelSections = [];
            foreach ($itemsArray as [$sectionTitle, $items]) {
                $sectionChannels = [];
                $nextPageToken = null;
                $lastChannelItem = ! empty($items) ? end($items) : [];
                $path = 'continuationItemRenderer/continuationEndpoint/continuationCommand/token';
                if (Json::pathExists($lastChannelItem, $path)) {
                    $nextPageToken = urldecode((string) Json::value($lastChannelItem, $path));
                    $items = array_slice($items, 0, count($items) - 1);
                }
                foreach ($items as $sectionChannelItem) {
                    $gridChannelRenderer = Json::value($sectionChannelItem, 'gridChannelRenderer', 'channelRenderer');
                    if ($gridChannelRenderer === null) {
                        goto breakChannelSectionsTreatment;
                    }
                    $thumbnails = [];
                    foreach ($gridChannelRenderer['thumbnail']['thumbnails'] as $thumbnail) {
                        $thumbnail['url'] = 'https://'.substr($thumbnail['url'], 2);
                        $thumbnails[] = $thumbnail;
                    }
                    $subscriberCount = Parsers::intValue($gridChannelRenderer['subscriberCountText']['simpleText'], 'subscriber');
                    $sectionChannels[] = [
                        'channelId' => $gridChannelRenderer['channelId'],
                        'title' => $gridChannelRenderer['title']['simpleText'],
                        'thumbnails' => $thumbnails,
                        'videoCount' => (int) str_replace(',', '', $gridChannelRenderer['videoCountText']['runs'][0]['text']),
                        'subscriberCount' => $subscriberCount,
                    ];
                }
                $channelSections[] = [
                    'title' => $sectionTitle,
                    'sectionChannels' => $sectionChannels,
                    'nextPageToken' => $nextPageToken,
                ];
            }
            breakChannelSectionsTreatment:
            $item['channelSections'] = $channelSections;
        }

        if ($this->options['about']) {
            $result = $this->client->getJsonFromHtml("https://www.youtube.com/channel/$id/about", forceLanguage: true, verifiesChannelRedirection: true);

            $c4TabbedHeaderRenderer = $result['header']['c4TabbedHeaderRenderer'];
            $item['countryChannelId'] = $c4TabbedHeaderRenderer['channelId'];

            Innertube::tabByName($result, 'About');
            $resultCommon = $result['onResponseReceivedEndpoints'][0]['showEngagementPanelEndpoint']['engagementPanel']['engagementPanelSectionListRenderer']['content']['sectionListRenderer']['contents'][0]['itemSectionRenderer']['contents'][0]['aboutChannelRenderer']['metadata']['aboutChannelViewModel'];

            $about['stats'] = [
                'joinedDate' => strtotime(str_replace('Joined ', '', $resultCommon['joinedDateText']['content'])),
                'viewCount' => Parsers::intValue($resultCommon['viewCountText'], 'view'),
                'subscriberCount' => Parsers::intValue($c4TabbedHeaderRenderer['subscriberCountText']['simpleText'], 'subscriber'),
                'videoCount' => Parsers::intValue($resultCommon['videoCountText'], 'video'),
            ];

            $about['description'] = $resultCommon['description'];

            $about['details'] = [
                'location' => $resultCommon['country'],
            ];

            $links = [];
            foreach ($resultCommon['links'] as $linkObject) {
                $linkObject = $linkObject['channelExternalLinkViewModel'];
                $url = $linkObject['link']['commandRuns'][0]['onTap']['innertubeCommand']['urlEndpoint']['url'];
                $urlComponents = parse_url($url);
                parse_str($urlComponents['query'] ?? '', $urlParams);
                $links[] = [
                    'url' => Json::value($urlParams, 'q', defaultValue: $url),
                    'title' => $linkObject['title']['content'],
                    'favicon' => $linkObject['favicon']['sources'],
                ];
            }
            $about['links'] = $links;
            $about['handle'] = substr($result['contents']['twoColumnBrowseResultsRenderer']['tabs'][0]['tabRenderer']['endpoint']['browseEndpoint']['canonicalBaseUrl'], 1);

            $item['about'] = $about;
        }

        if ($this->options['approval']) {
            $result = $this->client->getJsonFromHtml("https://www.youtube.com/channel/$id", forceLanguage: true, verifiesChannelRedirection: true);
            $approvalParts = explode(', ', $result['header']['pageHeaderRenderer']['content']['pageHeaderViewModel']['title']['dynamicTextViewModel']['rendererContext']['accessibilityContext']['label']);
            $item['approval'] = end($approvalParts);
        }

        if ($this->options['snippet']) {
            $result = $this->client->getJsonFromHtml("https://www.youtube.com/channel/$id", verifiesChannelRedirection: true);
            $c4TabbedHeaderRenderer = $result['header']['c4TabbedHeaderRenderer'];
            $c4TabbedHeaderRendererKeys = ['avatar', 'banner', 'tvBanner', 'mobileBanner'];
            $snippet = array_combine(
                $c4TabbedHeaderRendererKeys,
                array_map(fn ($c4TabbedHeaderRendererKey) => $c4TabbedHeaderRenderer[$c4TabbedHeaderRendererKey]['thumbnails'], $c4TabbedHeaderRendererKeys)
            );
            $item['snippet'] = $snippet;
        }

        if ($this->options['membership']) {
            $result = $this->client->getJsonFromHtml("https://www.youtube.com/channel/$id");
            $item['isMembershipEnabled'] = Json::pathExists($result, 'header/c4TabbedHeaderRenderer/sponsorButton')
                || Json::value($result, 'header/pageHeaderRenderer/content/pageHeaderViewModel/actions/flexibleActionsViewModel/actionsRows/0/actions/1/buttonViewModel/targetId', defaultValue: null) === 'sponsorships-button';
        }

        if ($this->options['playlists']) {
            if (! $continuationTokenProvided) {
                $result = $this->client->getJsonFromHtml("https://www.youtube.com/channel/$id/playlists", forceLanguage: true, verifiesChannelRedirection: true);

                $tab = Innertube::tabByName($result, 'Playlists');
                if ($tab === null) {
                    $this->returnEmptyItems = true;

                    return $item;
                }
                $sectionListRenderer = $tab['tabRenderer']['content']['sectionListRenderer'];
                $contents = array_map(fn ($content) => $content['itemSectionRenderer']['contents'][0], $sectionListRenderer['contents']);
                $itemsArray = [];
                foreach ($contents as $content) {
                    if (array_key_exists('shelfRenderer', $content)) {
                        $sectionTitle = $content['shelfRenderer']['title']['runs'][0]['text'];
                        $content = $content['shelfRenderer']['content'];
                        $content = Json::value($content, 'horizontalListRenderer', 'expandedShelfContentsRenderer');
                    } else {
                        $sectionTitle = $sectionListRenderer['subMenu']['channelSubMenuRenderer']['contentTypeSubMenuItems'][0]['title'];
                        $content = $content['gridRenderer'];
                    }
                    $itemsArray[] = [$sectionTitle, $content['items']];
                }
            } else {
                $result = $this->client->getContinuationJson($continuationToken);
                $itemsArray = [[null, Innertube::continuationItems($result)]];
            }

            $c4TabbedHeaderRenderer = $result['header']['c4TabbedHeaderRenderer'];
            $authorChannelName = $c4TabbedHeaderRenderer['title'];
            $authorChannelHandle = $c4TabbedHeaderRenderer['channelHandleText']['runs'][0]['text'];
            $authorChannelApproval = $c4TabbedHeaderRenderer['badges'][0]['metadataBadgeRenderer']['tooltip'];

            $playlistSections = [];
            foreach ($itemsArray as [$sectionTitle, $items]) {
                $sectionPlaylists = [];
                $nextPageToken = null;
                $path = 'continuationItemRenderer/continuationEndpoint/continuationCommand/token';
                $lastItem = ! empty($items) ? end($items) : [];
                if (Json::pathExists($lastItem, $path)) {
                    $nextPageToken = Json::value($lastItem, $path);
                    $items = array_slice($items, 0, count($items) - 1);
                }
                $isCreatedPlaylists = $sectionTitle === 'Created playlists';
                foreach ($items as $sectionPlaylistItem) {
                    if (array_key_exists('showRenderer', $sectionPlaylistItem)) {
                        continue;
                    }

                    $playlistRenderer = Json::value($sectionPlaylistItem, 'gridPlaylistRenderer', defaultValue: Json::value($sectionPlaylistItem, 'playlistRenderer', 'gridShowRenderer'));
                    $runs = $playlistRenderer['shortBylineText']['runs'];
                    if ($isCreatedPlaylists) {
                        $runs = [null];
                    }
                    $authors = ! empty($runs) ? array_values(array_filter(array_map(function ($shortBylineRun) use ($isCreatedPlaylists, $authorChannelName, $authorChannelHandle, $id, $authorChannelApproval, $playlistRenderer) {
                        $shortBylineNavigationEndpoint = $shortBylineRun['navigationEndpoint'];
                        $channelHandle = $shortBylineNavigationEndpoint['commandMetadata']['webCommandMetadata']['url'];

                        return [
                            'channelName' => $isCreatedPlaylists ? $authorChannelName : $shortBylineRun['text'],
                            'channelHandle' => $isCreatedPlaylists ? $authorChannelHandle : (str_starts_with($channelHandle, '/@') ? substr($channelHandle, 1) : null),
                            'channelId' => $isCreatedPlaylists ? $id : $shortBylineNavigationEndpoint['browseEndpoint']['browseId'],
                            'channelApproval' => $isCreatedPlaylists ? $authorChannelApproval : $playlistRenderer['ownerBadges'][0]['metadataBadgeRenderer']['tooltip'],
                        ];
                    }, $runs), fn ($author) => $author['channelName'] !== ', ')) : [];

                    $thumbnailRenderer = $playlistRenderer['thumbnailRenderer'];
                    $isThumbnailAVideo = $thumbnailRenderer === null || array_key_exists('playlistVideoThumbnailRenderer', $thumbnailRenderer);
                    $thumbnailRendererField = 'playlist'.($isThumbnailAVideo ? 'Video' : 'Custom').'ThumbnailRenderer';
                    if (! array_key_exists($thumbnailRendererField, $thumbnailRenderer)) {
                        $thumbnailRendererField = 'showCustomThumbnailRenderer';
                    }
                    $thumbnailVideo = $this->getVideoFromItsThumbnails($thumbnailRenderer[$thumbnailRendererField]['thumbnail'], $isThumbnailAVideo);

                    $firstVideos = $this->getFirstVideos($playlistRenderer);

                    $title = $playlistRenderer['title'];

                    if (array_key_exists('playlistId', $playlistRenderer)) {
                        $playlistId = $playlistRenderer['playlistId'];
                    } else {
                        $browseId = $playlistRenderer['navigationEndpoint']['browseEndpoint']['browseId'];
                        if (str_starts_with($browseId, 'VL')) {
                            $browseId = substr($browseId, 2);
                        }
                        $playlistId = $browseId;
                    }

                    $videoCount = (int) Json::value($playlistRenderer, 'videoCountText', 'thumbnailOverlays/0/thumbnailOverlayBottomPanelRenderer/text')['runs'][0]['text'];

                    $sectionPlaylists[] = [
                        'id' => $playlistId,
                        'thumbnailVideo' => $thumbnailVideo,
                        'firstVideos' => $firstVideos,
                        'title' => Json::value($title, 'runs/0/text', 'simpleText'),
                        'videoCount' => $videoCount,
                        'authors' => $authors,
                        'publishedTimeText' => $playlistRenderer['publishedTimeText']['simpleText'],
                    ];
                }
                $playlistSections[] = [
                    'title' => $sectionTitle,
                    'playlists' => $sectionPlaylists,
                    'nextPageToken' => $nextPageToken,
                ];
            }
            $item['playlistSections'] = $playlistSections;
        }

        if ($this->options['popular']) {
            $getRendererItems = function (array $result): array {
                $contents = Innertube::tabs($result)[0]['tabRenderer']['content']['sectionListRenderer']['contents'];
                $shelfRendererPath = 'itemSectionRenderer/contents/0/shelfRenderer';
                $content = array_values(array_filter($contents, fn ($content) => Json::value($content, $shelfRendererPath)['title']['runs'][0]['text'] == 'Popular'))[0];
                $shelfRenderer = Json::value($content, $shelfRendererPath);

                return $shelfRenderer['content']['gridRenderer']['items'];
            };
            $item['popular'] = $this->getVideos($item, "https://www.youtube.com/channel/$id", $getRendererItems, $continuationToken);
        }

        if ($this->options['recent']) {
            $item['recent'] = $this->getVideos(
                $item,
                "https://www.youtube.com/channel/$id/recent",
                fn (array $result) => Innertube::tabByName($result, 'Recent')['tabRenderer']['content']['sectionListRenderer']['contents'][0]['itemSectionRenderer']['contents'][0]['gridRenderer']['items'],
                $continuationToken
            );
        }

        if ($this->options['letsPlay']) {
            $letsPlay = [];
            $result = $this->client->getJsonFromHtml("https://www.youtube.com/channel/$id/letsplay", forceLanguage: true);
            $gridRendererItems = Innertube::tabByName($result, 'Let\'s play')['tabRenderer']['content']['sectionListRenderer']['contents'][0]['itemSectionRenderer']['contents'][0]['shelfRenderer']['content']['gridRenderer']['items'];
            foreach ($gridRendererItems as $gridRendererItem) {
                $gridPlaylistRenderer = $gridRendererItem['gridPlaylistRenderer'];
                $titleRun = $gridPlaylistRenderer['title']['runs'][0];
                $playlistAuthorRun = $gridPlaylistRenderer['longBylineText']['runs'][0];
                $playlistAuthorBrowseEndpoint = $playlistAuthorRun['navigationEndpoint']['browseEndpoint'];
                $letsPlay[] = [
                    'id' => $gridPlaylistRenderer['playlistId'],
                    'thumbnails' => $gridPlaylistRenderer['thumbnail']['thumbnails'],
                    'title' => $titleRun['text'],
                    'firstVideos' => $this->getFirstVideos($gridPlaylistRenderer),
                    'videoCount' => (int) $gridPlaylistRenderer['videoCountText']['runs'][0]['text'],
                    'authorName' => $playlistAuthorRun['text'],
                    'authorChannelId' => $playlistAuthorBrowseEndpoint['browseId'],
                    'authorChannelHandle' => substr($playlistAuthorBrowseEndpoint['canonicalBaseUrl'], 1),
                ];
            }
            $item['letsPlay'] = $letsPlay;
        }

        return $item;
    }

    /**
     * @param  array<mixed>  $result
     */
    protected function getVisitorData(array $result): string
    {
        return $result['responseContext']['webResponseContextExtensionData']['ytConfigData']['visitorData'];
    }

    /**
     * @param  array<mixed>  $gridRendererItem
     * @return array<string, mixed>
     */
    protected function getVideo(array $gridRendererItem): array
    {
        $gridVideoRenderer = $gridRendererItem['gridVideoRenderer'];
        $run = $gridVideoRenderer['shortBylineText']['runs'][0];
        $browseEndpoint = $run['navigationEndpoint']['browseEndpoint'];
        $title = $gridVideoRenderer['title'];
        $labelParts = explode('views', $title['accessibility']['accessibilityData']['label']);
        $publishedAt = Parsers::publishedAt((string) end($labelParts));

        return [
            'videoId' => $gridVideoRenderer['videoId'],
            'thumbnails' => $gridVideoRenderer['thumbnail']['thumbnails'],
            'title' => $title['runs'][0]['text'],
            'publishedAt' => $publishedAt,
            'views' => Parsers::intFromViewCount($gridVideoRenderer['viewCountText']['simpleText']),
            'channelTitle' => $run['text'],
            'channelId' => $browseEndpoint['browseId'],
            'channelHandle' => substr($browseEndpoint['canonicalBaseUrl'], 1),
            'duration' => Parsers::intFromDuration($gridVideoRenderer['thumbnailOverlays'][0]['thumbnailOverlayTimeStatusRenderer']['text']['simpleText']),
            'approval' => $gridVideoRenderer['ownerBadges'][0]['metadataBadgeRenderer']['tooltip'],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  callable(array<mixed>): array<int, mixed>  $getGridRendererItems
     * @return array<int, array<string, mixed>>
     */
    protected function getVideos(array &$item, string $url, callable $getGridRendererItems, string $continuationToken): array
    {
        $videos = [];
        $gridRendererItem = null;
        $visitorData = '';
        if ($continuationToken === '') {
            $result = $this->client->getJsonFromHtml($url, forceLanguage: true);
            $gridRendererItems = $getGridRendererItems($result);
            $visitorData = $this->getVisitorData($result);
        } else {
            $result = $this->client->getContinuationJson($continuationToken);
            $gridRendererItems = Innertube::continuationItems($result);
        }
        foreach ($gridRendererItems as $gridRendererItem) {
            if (! array_key_exists('continuationItemRenderer', $gridRendererItem)) {
                $videos[] = $this->getVideo($gridRendererItem);
            }
        }
        if ($gridRendererItem !== null && array_key_exists('continuationItemRenderer', $gridRendererItem)) {
            $item['nextPageToken'] = $gridRendererItem['continuationItemRenderer']['continuationEndpoint']['continuationCommand']['token'].','.$visitorData;
        }

        return $videos;
    }

    /**
     * @param  array<mixed>  $videoThumbnails
     * @return array<string, mixed>
     */
    protected function getVideoFromItsThumbnails(array $videoThumbnails, bool $isVideo = true): array
    {
        $videoThumbnails = $videoThumbnails['thumbnails'];
        $videoId = $isVideo ? substr($videoThumbnails[0]['url'], 23, 11) : null;

        return [
            'id' => $videoId,
            'thumbnails' => $videoThumbnails,
        ];
    }

    /**
     * @param  array<mixed>  $playlistRenderer
     * @return array<int, array<string, mixed>>
     */
    protected function getFirstVideos(array $playlistRenderer): array
    {
        $firstVideos = array_key_exists('thumbnail', $playlistRenderer)
            ? [$this->getVideoFromItsThumbnails($playlistRenderer['thumbnail'])]
            : array_map(fn ($videoThumbnails) => $this->getVideoFromItsThumbnails($videoThumbnails), Json::value($playlistRenderer, 'thumbnails', defaultValue: []));

        $sidebarThumbnails = $playlistRenderer['sidebarThumbnails'];
        $secondToFourthVideo = $sidebarThumbnails !== null
            ? array_map(fn ($videoThumbnails) => $this->getVideoFromItsThumbnails($videoThumbnails), $sidebarThumbnails)
            : [];

        return array_merge($firstVideos, $secondToFourthVideo);
    }
}
