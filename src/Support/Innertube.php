<?php

namespace Silverspoonmedia\VtualService\Support;

/**
 * Helpers for navigating common Innertube response shapes.
 *
 * Ported from upstream `common.php`:
 *   getTabs, getTabByName, getContinuationItems, getCommunityPostFromContent.
 *
 * These are shared by several endpoints (channels, community, search, ...),
 * so they live in one place rather than being duplicated per service.
 */
class Innertube
{
    /**
     * Upstream: getTabs()
     *
     * @param  array<mixed>  $result
     * @return array<int, mixed>
     */
    public static function tabs(array $result): array
    {
        return $result['contents']['twoColumnBrowseResultsRenderer']['tabs'] ?? [];
    }

    /**
     * Upstream: getTabByName()
     *
     * @param  array<mixed>  $result
     * @return array<mixed>|null
     */
    public static function tabByName(array $result, string $tabName): ?array
    {
        if (! array_key_exists('contents', $result)) {
            return null;
        }

        foreach (self::tabs($result) as $tab) {
            if (Json::value($tab, 'tabRenderer/title') === $tabName) {
                return $tab;
            }
        }

        return null;
    }

    /**
     * Upstream: getContinuationItems()
     *
     * @param  array<mixed>  $result
     * @return array<int, mixed>
     */
    public static function continuationItems(array $result): array
    {
        return $result['onResponseReceivedActions'][0]['appendContinuationItemsAction']['continuationItems'] ?? [];
    }

    /**
     * Build a normalized community post payload from a thread content node.
     *
     * Upstream: getCommunityPostFromContent()
     *
     * @param  array<mixed>  $content
     * @return array<string, mixed>
     */
    public static function communityPostFromContent(array $content): array
    {
        $backstagePost = $content['backstagePostThreadRenderer']['post'];
        $common = Json::value($backstagePost, 'backstagePostRenderer', 'sharedPostRenderer');

        $id = $common['postId'];
        $channelId = $common['authorEndpoint']['browseEndpoint']['browseId'];

        $contentText = [];
        $textContent = Json::value($common, 'contentText', 'content');
        $url = null;
        foreach (($textContent['runs'] ?? []) as $textCommon) {
            $contentTextItem = ['text' => $textCommon['text']];
            if (array_key_exists('navigationEndpoint', $textCommon)) {
                $navigationEndpoint = $textCommon['navigationEndpoint'];
                $url = Json::value($navigationEndpoint, 'commandMetadata/webCommandMetadata/url', 'browseEndpoint/canonicalBaseUrl');
                $contentTextItem['url'] = str_starts_with((string) $url, 'https://www.youtube.com/redirect?')
                    ? $url
                    : "https://www.youtube.com$url";
            }
            $contentText[] = $contentTextItem;
        }

        $backstageAttachment = $common['backstageAttachment'] ?? [];

        $images = [];
        if (array_key_exists('backstageImageRenderer', $backstageAttachment)) {
            $images = [$backstageAttachment['backstageImageRenderer']['image']];
        } elseif (array_key_exists('postMultiImageRenderer', $backstageAttachment)) {
            foreach ($backstageAttachment['postMultiImageRenderer']['images'] as $image) {
                $images[] = $image['backstageImageRenderer']['image'];
            }
        }

        $videoId = Json::value($backstageAttachment, 'videoRenderer/videoId');
        $date = $common['publishedTimeText']['runs'][0]['text'];
        $edited = str_ends_with($date, ' (edited)');
        $date = str_replace([' (edited)', 'shared '], '', $date);
        $sharedPostId = Json::value($common, 'originalPost/backstagePostRenderer/postId');

        $poll = null;
        if (array_key_exists('pollRenderer', $backstageAttachment)) {
            $pollRenderer = $backstageAttachment['pollRenderer'];
            $choices = [];
            foreach ($pollRenderer['choices'] as $choice) {
                $returnedChoice = $choice['text']['runs'][0];
                $returnedChoice['image'] = $choice['image'];
                $returnedChoice['voteRatio'] = $choice['voteRatioIfNotSelected'];
                $choices[] = $returnedChoice;
            }
            $totalVotesStr = $pollRenderer['totalVotes']['simpleText'];
            $totalVotes = (int) str_replace([' vote', ' votes'], '', $totalVotesStr);
            $poll = ['choices' => $choices, 'totalVotes' => $totalVotes];
        }

        $likes = Parsers::intValue((string) Json::value($common, 'voteCount/simpleText', null, 0), 'vote');

        $commentsPath = 'actionButtons/commentActionButtonsRenderer/replyButton/buttonRenderer';
        $commentsCommon = Json::pathExists($common, $commentsPath) ? Json::value($common, $commentsPath) : $common;

        $post = [
            'id' => $id,
            'channelId' => $channelId,
            'channelName' => $common['authorText']['runs'][0]['text'],
            'channelHandle' => substr($common['authorEndpoint']['browseEndpoint']['canonicalBaseUrl'], 1),
            'channelThumbnails' => array_map(function ($thumbnail) {
                $thumbnail['url'] = 'https:'.$thumbnail['url'];

                return $thumbnail;
            }, $common['authorThumbnail']['thumbnails']),
            'date' => $date,
            'contentText' => $contentText,
            'likes' => $likes,
            'videoId' => $videoId,
            'images' => $images,
            'poll' => $poll,
            'edited' => $edited,
            'sharedPostId' => $sharedPostId,
        ];

        if (is_array($commentsCommon) && array_key_exists('text', $commentsCommon)) {
            $post['commentsCount'] = Parsers::intValue($commentsCommon['text']['simpleText']);
        }

        return $post;
    }
}
