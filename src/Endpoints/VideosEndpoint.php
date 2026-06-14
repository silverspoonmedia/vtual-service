<?php

namespace Silverspoonmedia\VtualService\Endpoints;

use Silverspoonmedia\VtualService\Exceptions\ApiException;
use Silverspoonmedia\VtualService\Support\Json;
use Silverspoonmedia\VtualService\Support\Parsers;
use Silverspoonmedia\VtualService\Support\Validators;

/** Ported from upstream `videos.php`. */
class VideosEndpoint extends Endpoint
{
    private const REAL_OPTIONS = [
        'id', 'status', 'contentDetails', 'music', 'short', 'impressions', 'musics',
        'isPaidPromotion', 'isPremium', 'isMemberOnly', 'mostReplayed', 'qualities',
        'captions', 'location', 'chapters', 'isOriginal', 'isRestricted', 'snippet',
        'clip', 'activity', 'explicitLyrics', 'statistics',
    ];

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function list(array $params): array
    {
        $part = $this->paramPart($params, 'part');
        $idList = $this->paramList($params, 'id');
        $clipIdList = $this->paramList($params, 'clipId');

        if ($part === null || ($idList === null && $clipIdList === null)) {
            throw new ApiException('Required parameters not provided');
        }

        $options = $this->resolveParts($part, self::REAL_OPTIONS);
        $isClip = $clipIdList !== null;
        $field = $isClip ? 'clipId' : 'id';
        $ids = $this->multipleIds($isClip ? $clipIdList : $idList, $field);

        foreach ($ids as $realId) {
            if ((! $isClip && ! Validators::isVideoId($realId)) && ! Validators::isClipId($realId)) {
                throw new ApiException("Invalid $field");
            }
        }

        if ($options['impressions'] && (! isset($params['SAPISIDHASH']) || ! Validators::isSapisidHash((string) $params['SAPISIDHASH']))) {
            throw new ApiException('Invalid SAPISIDHASH');
        }

        return [
            'kind' => 'youtube#videoListResponse',
            'etag' => 'NotImplemented',
            'items' => array_map(fn (string $videoId) => $this->item($videoId, $options, $params, $isClip), $ids),
        ];
    }

    /** @return array<mixed>|null */
    protected function playerJson(string $rawData, bool $music = false): ?array
    {
        $headers = ['Content-Type: application/json', 'Accept-Language: en'];
        if ($music) {
            $headers[] = 'Referer: https://music.youtube.com';
        }
        $opts = ['http' => ['method' => 'POST', 'header' => $headers, 'content' => $rawData]];

        return $this->client->getJson('https://'.($music ? 'music' : 'www').'.youtube.com/youtubei/v1/player?key='.$this->config->uiKey(), $opts);
    }

    /** @param array<string, bool> $options @param array<string, mixed> $params @return array<string, mixed> */
    protected function item(string $id, array $options, array $params, bool $isClip): array
    {
        $result = null;
        if ($options['status'] || $options['contentDetails']) {
            $result = $this->playerJson(json_encode([
                'videoId' => $id,
                'context' => ['client' => ['clientName' => 'WEB_EMBEDDED_PLAYER', 'clientVersion' => $this->config->clientVersion()]],
            ]));
        }

        $item = ['kind' => 'youtube#video', 'etag' => 'NotImplemented', 'id' => $id];

        if ($options['status']) {
            $item['status'] = [
                'embeddable' => ($result['playabilityStatus']['status'] ?? null) === 'OK',
                'removedByTheUploader' => ($result['playabilityStatus']['errorScreen']['playerErrorMessageRenderer']['subreason']['runs'][0]['text'] ?? null) === 'This video has been removed by the uploader',
            ];
        }

        if ($options['contentDetails']) {
            $item['contentDetails'] = ['duration' => (int) ($result['videoDetails']['lengthSeconds'] ?? 0)];
        }

        if ($options['music']) {
            $musicResult = $this->playerJson(json_encode([
                'videoId' => $id,
                'context' => ['client' => ['clientName' => 'WEB_REMIX', 'clientVersion' => $this->config->clientVersion()]],
            ]), true);
            $item['music'] = ['available' => ($musicResult['playabilityStatus']['status'] ?? null) === 'OK'];
        }

        if ($options['short']) {
            $item['short'] = ['available' => ! $this->client->isRedirection("https://www.youtube.com/shorts/$id")];
        }

        if ($options['impressions']) {
            $headers = [
                'x-origin: https://studio.youtube.com',
                'authorization: SAPISIDHASH '.$params['SAPISIDHASH'],
                'Content-Type:',
                'Cookie: HSID=A4BqSu4moNA0Be1N9; SSID=AA0tycmNyGWo-Z_5v; APISID=a; SAPISID=zRbK-_14V7wIAieP/Ab_wY1sjLVrKQUM2c; SID=HwhYm6rJKOn_3R9oOrTNDJjpHIiq9Uos0F5fv4LPdMRSqyVHA1EDZwbLXo0kuUYAIN_MUQ.',
            ];
            $rawData = ['screenConfig' => ['entity' => ['videoId' => $id]], 'desktopState' => ['tabId' => 'ANALYTICS_TAB_ID_REACH']];
            $opts = ['http' => ['method' => 'POST', 'header' => $headers, 'content' => json_encode($rawData)]];
            $json = $this->client->getJson('https://studio.youtube.com/youtubei/v1/analytics_data/get_screen?key='.$this->config->uiKey(), $opts);
            $item['impressions'] = $json['cards'][0]['keyMetricCardData']['keyMetricTabs'][0]['primaryContent']['total'];
        }

        if ($options['musics']) {
            $json = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$id", ['http' => ['header' => ['Accept-Language: en']]]);
            $musics = [];
            $engagementPanel = Json::firstNodeContainingPath($json['engagementPanels'] ?? [], 'engagementPanelSectionListRenderer/content/structuredDescriptionContentRenderer/items');
            $cards = Json::value($engagementPanel, 'engagementPanelSectionListRenderer/content/structuredDescriptionContentRenderer/items', null, []);
            $structured = Json::firstNodeContainingPath($cards, 'horizontalCardListRenderer/cards');
            foreach (Json::value($structured, 'horizontalCardListRenderer/cards', null, []) as $card) {
                $vm = $card['videoAttributeViewModel'];
                $music = ['image' => $vm['image']['sources'][0]['url'], 'videoId' => $vm['onTap']['innertubeCommand']['watchEndpoint']['videoId']];
                $runs = $vm['overflowMenuOnTap']['innertubeCommand']['confirmDialogEndpoint']['content']['confirmDialogRenderer']['dialogMessages'][0]['runs'];
                for ($i = 0; $i < count($runs); $i += 4) {
                    $music[strtolower($runs[$i]['text'])] = $runs[$i + 2]['text'];
                }
                $musics[] = $music;
            }
            $item['musics'] = $musics;
        }

        if ($isClip) {
            $json = $this->client->getJsonFromHtml("https://www.youtube.com/clip/$id", forceLanguage: true);
            if ($options['id']) {
                $item['videoId'] = $json['currentVideoEndpoint']['watchEndpoint']['videoId'];
            }
            if ($options['clip']) {
                $path = 'engagementPanelSectionListRenderer/onShowCommands/0/showEngagementPanelScrimAction/onClickCommands/0/commandExecutorCommand/commands/3/openPopupAction/popup/notificationActionRenderer/actionButton/buttonRenderer/command/commandExecutorCommand/commands/1/loopCommand';
                foreach ($json['engagementPanels'] ?? [] as $panel) {
                    if (Json::pathExists($panel, $path)) {
                        $loop = Json::value($panel, $path);
                        $attr = $panel['engagementPanelSectionListRenderer']['content']['clipSectionRenderer']['contents'][0]['clipAttributionRenderer'];
                        $createdText = explode(' · ', $attr['createdText']['simpleText']);
                        $item['clip'] = [
                            'title' => $attr['title']['runs'][0]['text'],
                            'startTimeMs' => (int) $loop['startTimeMs'],
                            'endTimeMs' => (int) $loop['endTimeMs'],
                            'viewCount' => Parsers::intValue($createdText[0], 'view'),
                            'publishedAt' => $createdText[1],
                        ];
                        break;
                    }
                }
            }
        }

        if ($options['isPaidPromotion']) {
            $json = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$id", scriptVariable: 'ytInitialPlayerResponse');
            $item['isPaidPromotion'] = array_key_exists('paidContentOverlay', $json ?? []);
        }

        if ($options['isPremium']) {
            $json = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$id");
            $item['isPremium'] = array_key_exists('offerModule', $json['contents']['twoColumnWatchNextResults']['secondaryResults']['secondaryResults'] ?? []);
        }

        if ($options['isMemberOnly']) {
            $json = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$id");
            $item['isMemberOnly'] = array_key_exists('badges', $json['contents']['twoColumnWatchNextResults']['results']['results']['contents'][0]['videoPrimaryInfoRenderer'] ?? []);
        }

        if ($options['mostReplayed']) {
            $json = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$id", forceLanguage: true);
            $mostReplayed = null;
            foreach (($json['frameworkUpdates']['entityBatchUpdate']['mutations'] ?? []) as $mutation) {
                if (Json::pathExists($mutation, 'payload/macroMarkersListEntity/markersList/markersDecoration')) {
                    $mostReplayed = Json::value($mutation, 'payload/macroMarkersListEntity/markersList');
                    foreach (array_keys($mostReplayed['markers']) as $markerIndex) {
                        unset($mostReplayed['markers'][$markerIndex]['durationMillis']);
                        $mostReplayed['markers'][$markerIndex]['startMillis'] = (int) $mostReplayed['markers'][$markerIndex]['startMillis'];
                    }
                    $decorations = $mostReplayed['markersDecoration']['timedMarkerDecorations'];
                    foreach (array_keys($decorations) as $decorationIndex) {
                        foreach (['label', 'icon', 'decorationTimeMillis'] as $key) {
                            unset($decorations[$decorationIndex][$key]);
                        }
                    }
                    $mostReplayed['timedMarkerDecorations'] = $decorations;
                    foreach (['markerType', 'markersMetadata', 'markersDecoration'] as $key) {
                        unset($mostReplayed[$key]);
                    }
                    break;
                }
            }
            $item['mostReplayed'] = $mostReplayed;
        }

        if ($options['qualities']) {
            $json = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$id", scriptVariable: 'ytInitialPlayerResponse');
            $qualities = [];
            foreach (($json['streamingData']['adaptiveFormats'] ?? []) as $quality) {
                if (array_key_exists('qualityLabel', $quality) && ! in_array($quality['qualityLabel'], $qualities, true)) {
                    $qualities[] = $quality['qualityLabel'];
                }
            }
            $item['qualities'] = $qualities;
        }

        if ($options['captions']) {
            $json = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$id", scriptVariable: 'ytInitialPlayerResponse', forceLanguage: true);
            $item['captions'] = array_map(fn ($caption) => [
                'name' => $caption['name']['simpleText'],
                'languageCode' => $caption['languageCode'],
                'kind' => $caption['kind'],
            ], $json['captions']['playerCaptionsTracklistRenderer']['captionTracks'] ?? []);
        }

        if ($options['location']) {
            $json = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$id");
            $item['location'] = $json['contents']['twoColumnWatchNextResults']['results']['results']['contents'][0]['videoPrimaryInfoRenderer']['superTitleLink']['runs'][0]['text'];
        }

        if ($options['chapters']) {
            $json = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$id");
            $chapters = [];
            $auto = false;
            foreach (($json['engagementPanels'] ?? []) as $panel) {
                if (($panel['engagementPanelSectionListRenderer']['panelIdentifier'] ?? null) === 'engagement-panel-macro-markers-description-chapters') {
                    $contents = $panel['engagementPanelSectionListRenderer']['content']['macroMarkersListRenderer']['contents'] ?? null;
                    if ($contents !== null) {
                        $auto = array_key_exists('macroMarkersInfoItemRenderer', $contents[0]);
                        foreach (array_slice($contents, $auto ? 1 : 0) as $chapter) {
                            $chapter = $chapter['macroMarkersListItemRenderer'];
                            $chapters[] = ['title' => $chapter['title']['simpleText'], 'time' => Parsers::intFromDuration($chapter['timeDescription']['simpleText']), 'thumbnails' => $chapter['thumbnail']['thumbnails']];
                        }
                    }
                    break;
                }
            }
            $item['chapters'] = ['areAutoGenerated' => $auto, 'chapters' => $chapters];
        }

        if ($options['isOriginal']) {
            $html = $this->client->getRemote("https://www.youtube.com/watch?v=$id");
            $item['isOriginal'] = str_contains($html, 'xtags='.urlencode('acont=original'));
        }

        if ($options['isRestricted']) {
            $json = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$id", ['http' => ['header' => ['Cookie: PREF=f2=8000000']]], 'ytInitialPlayerResponse');
            $item['isRestricted'] = array_key_exists('isBlockedInRestrictedMode', $json['playabilityStatus'] ?? []);
        }

        if ($options['snippet']) {
            $json = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$id", forceLanguage: true);
            $contents = $json['contents']['twoColumnWatchNextResults']['results']['results']['contents'];
            $item['snippet'] = [
                'publishedAt' => strtotime($contents[0]['videoPrimaryInfoRenderer']['dateText']['simpleText']),
                'description' => $contents[1]['videoSecondaryInfoRenderer']['attributedDescription']['content'],
                'title' => $contents[0]['videoPrimaryInfoRenderer']['title']['runs'][0]['text'],
            ];
        }

        if ($options['statistics']) {
            $json = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$id", forceLanguage: true);
            preg_match('/like this video along with ([\d,]+) other people/', $json['contents']['twoColumnWatchNextResults']['results']['results']['contents'][0]['videoPrimaryInfoRenderer']['videoActions']['menuRenderer']['topLevelButtons'][0]['segmentedLikeDislikeButtonViewModel']['likeButtonViewModel']['likeButtonViewModel']['toggleButtonViewModel']['toggleButtonViewModel']['defaultButtonViewModel']['buttonViewModel']['accessibilityText'] ?? '', $viewCount);
            $item['statistics'] = [
                'viewCount' => Parsers::intValue($json['playerOverlays']['playerOverlayRenderer']['videoDetails']['playerOverlayVideoDetailsRenderer']['subtitle']['runs'][2]['text'] ?? '0', 'view'),
                'likeCount' => Parsers::intValue($viewCount[1] ?? '0'),
            ];
        }

        if ($options['activity']) {
            $json = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$id", forceLanguage: true);
            $activity = $json['contents']['twoColumnWatchNextResults']['results']['results']['contents'][1]['videoSecondaryInfoRenderer']['metadataRowContainer']['metadataRowContainerRenderer']['rows'][0]['richMetadataRowRenderer']['contents'][0]['richMetadataRenderer'];
            $item['activity'] = ['name' => $activity['title']['simpleText'], 'year' => $activity['subtitle']['simpleText'], 'thumbnails' => $activity['thumbnail']['thumbnails'], 'channelId' => $activity['endpoint']['browseEndpoint']['browseId']];
        }

        if ($options['explicitLyrics']) {
            $json = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$id");
            $rows = $json['contents']['twoColumnWatchNextResults']['results']['results']['contents'][1]['videoSecondaryInfoRenderer']['metadataRowContainer']['metadataRowContainerRenderer']['rows'] ?? null;
            $item['explicitLyrics'] = $rows !== null && (end($rows)['metadataRowRenderer']['contents'][0]['simpleText'] ?? null) === 'Explicit lyrics';
        }

        return $item;
    }
}
