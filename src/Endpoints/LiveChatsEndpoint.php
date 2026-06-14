<?php

namespace Silverspoonmedia\VtualService\Endpoints;

use Silverspoonmedia\VtualService\Exceptions\ApiException;
use Silverspoonmedia\VtualService\Support\Parsers;
use Silverspoonmedia\VtualService\Support\Validators;

/** Ported from upstream `liveChats.php`. */
class LiveChatsEndpoint extends Endpoint
{
    private const REAL_OPTIONS = ['snippet', 'participants'];

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function list(array $params): array
    {
        $part = $this->paramPart($params, 'part');
        $idList = $this->paramList($params, 'id');

        if ($part === null || $idList === null || ($this->partIncludes($part, 'snippet') && $this->param($params, 'time') === null)) {
            throw new ApiException('Required parameters not provided');
        }

        $options = $this->resolveParts($part, self::REAL_OPTIONS);
        if ($this->partIncludes($part, 'snippet') && ! Validators::isPositiveInteger((string) $params['time'])) {
            throw new ApiException('Invalid time');
        }

        $ids = $this->multipleIds($idList);
        foreach ($ids as $realId) {
            if (! Validators::isVideoId($realId)) {
                throw new ApiException('Invalid id');
            }
        }

        return [
            'kind' => 'youtube#videoListResponse',
            'etag' => 'NotImplemented',
            'items' => array_map(fn (string $videoId) => $this->item($videoId, $options, (string) ($params['time'] ?? '0')), $ids),
        ];
    }

    /** @param array<string, bool> $options @return array<string, mixed> */
    protected function item(string $id, array $options, string $time): array
    {
        $result = $this->client->getJsonFromHtml("https://www.youtube.com/watch?v=$id");
        $continuation = $result['contents']['twoColumnWatchNextResults']['conversationBar']['liveChatRenderer']['continuations'][0]['reloadContinuationData']['continuation'];

        $rawData = [
            'context' => ['client' => ['clientName' => 'WEB', 'clientVersion' => $this->config->musicVersion()]],
            'continuation' => (string) $continuation,
            'currentPlayerState' => ['playerOffsetMs' => $time],
        ];
        $opts = ['http' => ['header' => ['Content-Type: application/json'], 'method' => 'POST', 'content' => json_encode($rawData)]];
        $replay = $this->client->getJson('https://www.youtube.com/youtubei/v1/live_chat/get_live_chat_replay?key='.$this->config->uiKey(), $opts);

        $item = ['kind' => 'youtube#video', 'etag' => 'NotImplemented', 'id' => $id];

        if ($options['snippet']) {
            $snippet = [];
            foreach (($replay['continuationContents']['liveChatContinuation']['actions'] ?? []) as $action) {
                $replayChatItemAction = $action['replayChatItemAction'] ?? null;
                $renderer = $replayChatItemAction['actions'][0]['addChatItemAction']['item']['liveChatTextMessageRenderer'] ?? null;
                if ($renderer !== null) {
                    $snippet[] = [
                        'id' => urldecode($renderer['id']),
                        'message' => $renderer['message']['runs'],
                        'authorName' => $renderer['authorName']['simpleText'],
                        'authorThumbnails' => $renderer['authorPhoto']['thumbnails'],
                        'timestampAbsoluteUsec' => (int) $renderer['timestampUsec'],
                        'authorChannelId' => $renderer['authorExternalChannelId'],
                        'timestamp' => Parsers::intFromDuration($renderer['timestampText']['simpleText']),
                        'videoOffsetTimeMsec' => (int) $replayChatItemAction['videoOffsetTimeMsec'],
                    ];
                }
            }
            $item['snippet'] = $snippet;
        }

        if ($options['participants']) {
            $opts = ['http' => ['header' => ['User-Agent: '.$this->config->userAgent()]]];
            $liveChat = $this->client->getJsonFromHtml("https://www.youtube.com/live_chat?continuation=$continuation", $opts, 'window["ytInitialData"]', '');
            $item['participants'] = array_slice($liveChat['continuationContents']['liveChatContinuation']['actions'] ?? [], 1);
        }

        return $item;
    }
}
