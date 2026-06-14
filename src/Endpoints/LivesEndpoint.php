<?php

namespace Silverspoonmedia\VtualService\Endpoints;

use Silverspoonmedia\VtualService\Exceptions\ApiException;
use Silverspoonmedia\VtualService\Support\Validators;

/** Ported from upstream `lives.php`. */
class LivesEndpoint extends Endpoint
{
    private const REAL_OPTIONS = ['donations', 'sponsorshipGifts', 'memberships', 'poll'];

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function list(array $params): array
    {
        $part = $this->paramPart($params, 'part');
        $idList = $this->paramList($params, 'id');

        if ($part === null || $idList === null) {
            throw new ApiException('Required parameters not provided');
        }

        $options = $this->resolveParts($part, self::REAL_OPTIONS);
        $ids = $this->multipleIds($idList);
        foreach ($ids as $realId) {
            if (! Validators::isVideoId($realId)) {
                throw new ApiException('Invalid id');
            }
        }

        return [
            'kind' => 'youtube#videoListResponse',
            'etag' => 'NotImplemented',
            'items' => array_map(fn (string $videoId) => $this->item($videoId, $options), $ids),
        ];
    }

    /** @param array<string, bool> $options @return array<string, mixed> */
    protected function item(string $id, array $options): array
    {
        $opts = [
            'http' => [
                'user_agent' => $this->config->userAgent(),
                'header' => ['Accept-Language: en'],
            ],
        ];
        $result = $this->client->getJsonFromHtml("https://www.youtube.com/live_chat?v=$id", $opts, 'window["ytInitialData"]', '');
        $actions = $result['contents']['liveChatRenderer']['actions'] ?? [];

        $item = ['kind' => 'youtube#video', 'etag' => 'NotImplemented', 'id' => $id];

        if ($options['donations']) {
            $donations = [];
            foreach ($actions as $action) {
                $donation = $action['addLiveChatTickerItemAction']['item']['liveChatTickerPaidMessageItemRenderer']['showItemEndpoint']['showLiveChatItemEndpoint']['renderer']['liveChatPaidMessageRenderer'] ?? null;
                if ($donation !== null) {
                    $donations[] = $donation;
                }
            }
            $item['donations'] = $donations;
        }

        $cleanAuthorBadge = function (array $authorBadgeRaw): array {
            $renderer = $authorBadgeRaw['liveChatAuthorBadgeRenderer'];

            return [
                'tooltip' => $renderer['tooltip'],
                'customThumbnail' => $renderer['customThumbnail']['thumbnails'],
            ];
        };

        $cleanMembershipOrSponsorship = function (array $raw, bool $isMembership) use ($cleanAuthorBadge): array {
            $common = $isMembership ? $raw : $raw['header']['liveChatSponsorshipsHeaderRenderer'];
            $primaryText = implode('', array_map(fn ($run) => $run['text'], $common[$isMembership ? 'headerPrimaryText' : 'primaryText']['runs']));
            $subText = $raw['headerSubtext']['simpleText'];

            return [
                'id' => $raw['id'],
                'timestamp' => (int) $raw['timestampUsec'],
                'authorChannelId' => $raw['authorExternalChannelId'],
                'authorName' => $common['authorName']['simpleText'],
                'authorPhoto' => $common['authorPhoto']['thumbnails'],
                'primaryText' => $primaryText,
                'subText' => $subText,
                'authorBadges' => array_map($cleanAuthorBadge, $common['authorBadges']),
            ];
        };

        if ($options['sponsorshipGifts']) {
            $sponsorshipGifts = [];
            foreach ($actions as $action) {
                $gift = $action['addChatItemAction']['item']['liveChatSponsorshipsGiftPurchaseAnnouncementRenderer'] ?? null;
                if ($gift !== null) {
                    $sponsorshipGifts[] = $cleanMembershipOrSponsorship($gift, false);
                }
            }
            $item['sponsorshipGifts'] = $sponsorshipGifts;
        }

        if ($options['memberships']) {
            $memberships = [];
            foreach ($actions as $action) {
                $membership = $action['addChatItemAction']['item']['liveChatMembershipItemRenderer'] ?? null;
                if ($membership !== null) {
                    $memberships[] = $cleanMembershipOrSponsorship($membership, true);
                }
            }
            $item['memberships'] = $memberships;
        }

        if ($options['poll']) {
            $poll = null;
            $firstAction = $actions[0] ?? [];
            if (array_key_exists('showLiveChatActionPanelAction', $firstAction)) {
                $pollRenderer = $firstAction['showLiveChatActionPanelAction']['panelToShow']['liveChatActionPanelRenderer']['contents']['pollRenderer'];
                $pollHeaderRenderer = $pollRenderer['header']['pollHeaderRenderer'];
                $liveChatPollStateEntity = $result['frameworkUpdates']['entityBatchUpdate']['mutations'][0]['payload']['liveChatPollStateEntity'];
                $metadataTextRuns = explode(' • ', $liveChatPollStateEntity['metadataText']['runs'][0]['text']);
                $poll = [
                    'question' => $liveChatPollStateEntity['collapsedMetadataText']['runs'][2]['text'],
                    'choices' => array_map(fn ($choiceText, $choiceRatio) => [
                        'text' => $choiceText['text']['runs'][0]['text'],
                        'voteRatio' => $choiceRatio['value']['voteRatio'],
                    ], $pollRenderer['choices'], $liveChatPollStateEntity['pollChoiceStates']),
                    'channelName' => $metadataTextRuns[0],
                    'timestamp' => str_replace("\u{00a0}", ' ', $metadataTextRuns[1]),
                    'totalVotes' => (int) str_replace(' votes', '', $metadataTextRuns[2]),
                    'channelThumbnails' => $pollHeaderRenderer['thumbnail']['thumbnails'],
                ];
            }
            $item['poll'] = $poll;
        }

        return $item;
    }
}
