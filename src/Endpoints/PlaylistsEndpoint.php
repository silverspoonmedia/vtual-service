<?php

namespace Silverspoonmedia\VtualService\Endpoints;

use Silverspoonmedia\VtualService\Exceptions\ApiException;
use Silverspoonmedia\VtualService\Support\Innertube;
use Silverspoonmedia\VtualService\Support\Parsers;
use Silverspoonmedia\VtualService\Support\Validators;

/**
 * Ported from upstream `playlists.php`.
 *
 * Returns `youtube#playlistListResponse` shaped data for one or more playlist
 * ids, supporting the `snippet` and `statistics` parts.
 */
class PlaylistsEndpoint extends Endpoint
{
    private const REAL_OPTIONS = ['snippet', 'statistics'];

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
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
            if (! Validators::isPlaylistId($realId)) {
                throw new ApiException('Invalid id');
            }
        }

        $items = array_map(fn ($playlistId) => $this->item($playlistId, $options), $ids);

        return [
            'kind' => 'youtube#playlistListResponse',
            'etag' => 'NotImplemented',
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, bool>  $options
     * @return array<string, mixed>
     */
    protected function item(string $id, array $options): array
    {
        $result = $this->client->getJsonFromHtml("https://www.youtube.com/playlist?list=$id", forceLanguage: true);

        $item = [
            'kind' => 'youtube#playlist',
            'etag' => 'NotImplemented',
        ];

        if ($options['snippet']) {
            $item['snippet'] = [
                'title' => $result['metadata']['playlistMetadataRenderer']['title'],
            ];
        }

        if ($options['statistics']) {
            $viewCount = Parsers::intFromViewCount(
                $result['sidebar']['playlistSidebarRenderer']['items'][0]['playlistSidebarPrimaryInfoRenderer']['stats'][1]['simpleText']
            );
            $videoCount = (int) str_replace(',', '', $result['header']['playlistHeaderRenderer']['numVideosText']['runs'][0]['text']);
            $item['statistics'] = [
                'viewCount' => $viewCount,
                'videoCount' => $videoCount,
            ];
        }

        return $item;
    }
}
