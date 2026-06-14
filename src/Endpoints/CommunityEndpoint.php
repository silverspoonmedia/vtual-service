<?php

namespace Silverspoonmedia\VtualService\Endpoints;

use Silverspoonmedia\VtualService\Exceptions\ApiException;
use Silverspoonmedia\VtualService\Support\Innertube;
use Silverspoonmedia\VtualService\Support\Protobuf;
use Silverspoonmedia\VtualService\Support\Validators;

/** Ported from upstream `community.php`. */
class CommunityEndpoint extends Endpoint
{
    private const REAL_OPTIONS = ['snippet'];

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function list(array $params): array
    {
        $part = $this->paramPart($params, 'part');
        $postId = $this->param($params, 'id');
        $channelId = $this->param($params, 'channelId');

        if ($part === null || $postId === null || $channelId === null) {
            throw new ApiException('Required parameters not provided');
        }

        $this->resolveParts($part, self::REAL_OPTIONS);

        if (! Validators::isPostId($postId)) {
            throw new ApiException('Invalid postId');
        }
        if (! Validators::isChannelId($channelId)) {
            throw new ApiException('Invalid channelId');
        }

        $order = $this->param($params, 'order') ?? 'relevance';
        if (! in_array($order, ['relevance', 'time'], true)) {
            throw new ApiException('Invalid order');
        }

        return $this->getApi($postId, $channelId, $order, $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function getApi(string $postId, string $channelId, string $order, array $params): array
    {
        $sapisid = (string) ($params['community_sapisid'] ?? $this->config->raw('community.sapisid', ''));
        $secure3psid = (string) ($params['community_secure_3psid'] ?? $this->config->raw('community.secure_3psid', ''));

        if ($sapisid === '' || $secure3psid === '') {
            throw new ApiException('Community endpoint requires configured SAPISID cookies (VTUAL_COMMUNITY_SAPISID / VTUAL_COMMUNITY_SECURE_3PSID).');
        }

        $currentTime = time();
        $origin = 'https://www.youtube.com';
        $sapisidHash = "{$currentTime}_" . sha1("$currentTime $sapisid $origin");

        $rawData = [
            'context' => ['client' => ['clientName' => 'WEB', 'clientVersion' => $this->config->musicVersion()]],
            'browseId' => $channelId,
            'params' => Protobuf::communityParams($postId),
        ];

        $headers = [
            'Content-Type: application/json',
            "Origin: $origin",
            "Authorization: SAPISIDHASH $sapisidHash",
            'Cookie: __Secure-3PSID=' . $secure3psid . '; __Secure-3PAPISID=' . $sapisid,
        ];

        $result = $this->client->postInnertube('https://www.youtube.com/youtubei/v1/browse', $rawData, $headers);
        $contents = Innertube::tabByName($result ?? [], 'Community')['tabRenderer']['content']['sectionListRenderer']['contents'];
        $content = $contents[0]['itemSectionRenderer']['contents'][0];
        $post = Innertube::communityPostFromContent($content);
        $continuationToken = urldecode($contents[1]['itemSectionRenderer']['contents'][0]['continuationItemRenderer']['continuationEndpoint']['continuationCommand']['token']);

        if ($order === 'time') {
            $continuationResult = $this->client->getContinuationJson($continuationToken);
            $continuationToken = urldecode($continuationResult['onResponseReceivedEndpoints'][0]['reloadContinuationItemsCommand']['continuationItems'][0]['commentsHeaderRenderer']['sortMenu']['sortFilterSubMenuRenderer']['subMenuItems'][1]['serviceEndpoint']['continuationCommand']['token']);
        }

        $post['comments'] = ['nextPageToken' => $continuationToken];

        return [
            'kind' => 'youtube#communityListResponse',
            'etag' => 'NotImplemented',
            'items' => [[
                'kind' => 'youtube#community',
                'etag' => 'NotImplemented',
                'id' => $postId,
                'snippet' => $post,
            ]],
        ];
    }
}
