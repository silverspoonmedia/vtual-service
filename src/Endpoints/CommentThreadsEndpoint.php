<?php

namespace Silverspoonmedia\VtualService\Endpoints;

use Silverspoonmedia\VtualService\Exceptions\ApiException;
use Silverspoonmedia\VtualService\Support\Json;
use Silverspoonmedia\VtualService\Support\Parsers;
use Silverspoonmedia\VtualService\Support\Validators;

/** Ported from upstream `commentThreads.php`. */
class CommentThreadsEndpoint extends Endpoint
{
    private const REAL_OPTIONS = ['snippet', 'replies'];

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function list(array $params): array
    {
        $part = $this->paramPart($params, 'part');
        if ($part === null) {
            throw new ApiException('Required parameters not provided');
        }

        $this->resolveParts($part, self::REAL_OPTIONS);

        $videoId = $this->param($params, 'videoId');
        if ($videoId !== null && ! Validators::isVideoId($videoId)) {
            throw new ApiException('Invalid videoId');
        }

        $commentId = $this->param($params, 'id');
        if ($commentId !== null && ! Validators::isCommentId($commentId)) {
            throw new ApiException('Invalid id');
        }

        $order = $this->param($params, 'order') ?? 'relevance';
        if (! in_array($order, ['relevance', 'time'], true)) {
            throw new ApiException('Invalid order');
        }

        $continuationToken = '';
        if (($pageToken = $this->param($params, 'pageToken')) !== null) {
            if (! Validators::isContinuationToken($pageToken)) {
                throw new ApiException('Invalid pageToken');
            }
            $continuationToken = $pageToken;
        }

        return $this->getApi($videoId, $commentId, $order, $continuationToken);
    }

    /** @return array<string, mixed> */
    protected function getApi(?string $videoId, ?string $commentId, string $order, string $continuationToken, bool $simulatedContinuation = false): array
    {
        if ($commentId !== null) {
            $result = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$videoId&lc=$commentId");
            $continuationToken = $result['contents']['twoColumnWatchNextResults']['results']['results']['contents'][3]['itemSectionRenderer']['contents'][0]['continuationItemRenderer']['continuationEndpoint']['continuationCommand']['token'];
        }

        $continuationTokenProvided = $continuationToken !== '';

        if ($continuationTokenProvided) {
            $rawData = [
                'context' => ['client' => ['clientName' => 'WEB', 'clientVersion' => $this->config->musicVersion()]],
                'continuation' => $continuationToken,
            ];
            $endpoint = $videoId !== null ? 'next' : 'browse';
            $result = $this->client->postInnertube("https://www.youtube.com/youtubei/v1/$endpoint?key=".$this->config->uiKey(), $rawData);

            if ($order === 'time' && $simulatedContinuation) {
                $continuationToken = $result['onResponseReceivedEndpoints'][0]['reloadContinuationItemsCommand']['continuationItems'][0]['commentsHeaderRenderer']['sortMenu']['sortFilterSubMenuRenderer']['subMenuItems'][1]['serviceEndpoint']['continuationCommand']['token'];

                return $this->getApi($videoId, $commentId, 'time', $continuationToken);
            }
        } else {
            $result = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$videoId");
            $contents = $result['contents']['twoColumnWatchNextResults']['results']['results']['contents'];
            $continuationToken = end($contents)['itemSectionRenderer']['contents'][0]['continuationItemRenderer']['continuationEndpoint']['continuationCommand']['token'] ?? '';
            if ($continuationToken !== '') {
                return $this->getApi($videoId, $commentId, $order, $continuationToken, true);
            }
        }

        $answerItems = [];

        foreach ($result['frameworkUpdates']['entityBatchUpdate']['mutations'] ?? [] as $item) {
            $payload = $item['payload'];
            if (array_key_exists('engagementToolbarStateEntityPayload', $payload)) {
                $answerItems[$item['entityKey']]['snippet']['topLevelComment']['snippet']['creatorHeart'] = $payload['engagementToolbarStateEntityPayload']['heartState'] === 'TOOLBAR_HEART_STATE_HEARTED';
            }
            if (! array_key_exists('commentEntityPayload', $payload)) {
                continue;
            }

            $comment = $payload['commentEntityPayload'];
            $properties = $comment['properties'];
            $author = $comment['author'];
            $toolbar = $comment['toolbar'];
            $publishedAt = $properties['publishedTime'];
            $count = 0;
            $publishedAt = str_replace(' (edited)', '', $publishedAt, $count);

            $internalSnippet = [
                'content' => $properties['content']['content'],
                'publishedAt' => $publishedAt,
                'wasEdited' => $count > 0,
                'authorChannelId' => $author['channelId'],
                'authorHandle' => $author['displayName'],
                'authorName' => str_replace('❤ by ', '', $toolbar['heartActiveTooltip']),
                'authorAvatar' => $comment['avatar']['image']['sources'][0],
                'isCreator' => $author['isCreator'],
                'isArtist' => $author['isArtist'],
                'likeCount' => Parsers::intValue($toolbar['likeCountLiked']),
                'totalReplyCount' => (int) $toolbar['replyCount'],
                'videoCreatorHasReplied' => false,
                'isPinned' => false,
            ];

            $threadCommentId = $properties['commentId'];
            $answerItems[$properties['toolbarStateKey']] = [
                'kind' => 'youtube#commentThread',
                'etag' => 'NotImplemented',
                'id' => $threadCommentId,
                'snippet' => [
                    'topLevelComment' => [
                        'kind' => 'youtube#comment',
                        'etag' => 'NotImplemented',
                        'id' => $threadCommentId,
                        'snippet' => $internalSnippet,
                    ],
                ],
            ];
        }

        $continuationItems = $result['onResponseReceivedEndpoints'][1]['reloadContinuationItemsCommand']['continuationItems'] ?? [];
        foreach ($continuationItems as $item) {
            $commentThreadRenderer = $item['commentThreadRenderer'] ?? null;
            if ($commentThreadRenderer === null) {
                continue;
            }
            $toolbarStateKey = $commentThreadRenderer['commentViewModel']['commentViewModel']['toolbarStateKey'];
            if (Json::pathExists($commentThreadRenderer, 'replies/commentRepliesRenderer/viewRepliesCreatorThumbnail')) {
                $answerItems[$toolbarStateKey]['snippet']['topLevelComment']['snippet']['videoCreatorHasReplied'] = true;
            }
            if (Json::pathExists($commentThreadRenderer, 'commentViewModel/commentViewModel/pinnedText')) {
                $answerItems[$toolbarStateKey]['snippet']['topLevelComment']['snippet']['isPinned'] = true;
            }
            if ($toolbarStateKey !== null) {
                $answerItems[$toolbarStateKey]['snippet']['topLevelComment']['snippet']['nextPageToken'] = $commentThreadRenderer['replies']['commentRepliesRenderer']['contents'][0]['continuationItemRenderer']['continuationEndpoint']['continuationCommand']['token'];
            }
        }

        $answerItems = array_values($answerItems);
        $nextContinuationToken = $continuationItems[20]['continuationItemRenderer']['continuationEndpoint']['continuationCommand']['token'] ?? '';

        $answer = [
            'kind' => 'youtube#commentThreadListResponse',
            'etag' => 'NotImplemented',
            'pageInfo' => [
                'totalResults' => (int) $result['onResponseReceivedEndpoints'][0]['reloadContinuationItemsCommand']['continuationItems'][0]['commentsHeaderRenderer']['countText']['runs'][0]['text'],
                'resultsPerPage' => 20,
            ],
        ];
        if ($nextContinuationToken !== '') {
            $answer['nextPageToken'] = $nextContinuationToken;
        }
        $answer['items'] = $answerItems;

        return $answer;
    }
}
