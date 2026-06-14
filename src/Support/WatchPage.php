<?php

namespace Silverspoonmedia\VtualService\Support;

/**
 * Safe accessors for YouTube watch-page Innertube JSON (`ytInitialData`).
 *
 * YouTube frequently reshuffles renderer names and drops optional metadata rows.
 * These helpers locate the common renderers without assuming fixed array indexes.
 */
final class WatchPage
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function contents(?array $json): array
    {
        $contents = Json::value($json, 'contents/twoColumnWatchNextResults/results/results/contents');

        return is_array($contents) ? $contents : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function primaryRenderer(?array $json): ?array
    {
        foreach (self::contents($json) as $content) {
            if (isset($content['videoPrimaryInfoRenderer']) && is_array($content['videoPrimaryInfoRenderer'])) {
                return $content['videoPrimaryInfoRenderer'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function secondaryRenderer(?array $json): ?array
    {
        foreach (self::contents($json) as $content) {
            if (isset($content['videoSecondaryInfoRenderer']) && is_array($content['videoSecondaryInfoRenderer'])) {
                return $content['videoSecondaryInfoRenderer'];
            }
        }

        return null;
    }

    /**
     * Depth-first search for the first node keyed by `$key`.
     *
     * @return array<string, mixed>|null
     */
    public static function findFirst(?array $json, string $key): ?array
    {
        if ($json === null) {
            return null;
        }

        if (array_key_exists($key, $json) && is_array($json[$key])) {
            return $json[$key];
        }

        foreach ($json as $value) {
            if (! is_array($value)) {
                continue;
            }

            $found = self::findFirst($value, $key);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function snippet(?array $json): ?array
    {
        $primary = self::primaryRenderer($json);
        $secondary = self::secondaryRenderer($json);

        if ($primary === null) {
            return null;
        }

        $publishedText = Json::value($primary, 'dateText/simpleText');
        $title = Json::value($primary, 'title/runs/0/text');

        if ($title === null) {
            return null;
        }

        $description = null;
        if ($secondary !== null) {
            $description = Json::value($secondary, 'attributedDescription/content', defaultValue: Json::value($secondary, 'description/body/text'));
        }

        return [
            'publishedAt' => is_string($publishedText) && $publishedText !== '' ? strtotime($publishedText) : null,
            'description' => $description,
            'title' => $title,
        ];
    }

    /**
     * Film / show metadata when YouTube exposes `richMetadataRenderer`.
     *
     * @return array<string, mixed>|null
     */
    public static function activity(?array $json): ?array
    {
        $activity = self::findFirst($json, 'richMetadataRenderer');
        if ($activity === null) {
            return null;
        }

        $name = Json::value($activity, 'title/simpleText', defaultValue: Json::value($activity, 'title/runs/0/text'));
        if ($name === null) {
            return null;
        }

        return [
            'name' => $name,
            'year' => Json::value($activity, 'subtitle/simpleText'),
            'thumbnails' => Json::value($activity, 'thumbnail/thumbnails', defaultValue: []),
            'channelId' => Json::value($activity, 'endpoint/browseEndpoint/browseId'),
        ];
    }

  /**
     * @return array<int, array<string, mixed>>
     */
    public static function metadataRows(?array $json): array
    {
        $secondary = self::secondaryRenderer($json);
        if ($secondary === null) {
            return [];
        }

        $rows = Json::value($secondary, 'metadataRowContainer/metadataRowContainerRenderer/rows');

        return is_array($rows) ? $rows : [];
    }

    public static function explicitLyrics(?array $json): bool
    {
        $rows = self::metadataRows($json);
        if ($rows === []) {
            return false;
        }

        $last = end($rows);

        return is_array($last)
            && (Json::value($last, 'metadataRowRenderer/contents/0/simpleText') === 'Explicit lyrics');
    }

    /**
     * @return array{viewCount: int, likeCount: int}|null
     */
    public static function statistics(?array $json): ?array
    {
        $primary = self::primaryRenderer($json);
        if ($primary === null) {
            return null;
        }

        $accessibilityText = Json::value(
            $primary,
            'videoActions/menuRenderer/topLevelButtons/0/segmentedLikeDislikeButtonViewModel/likeButtonViewModel/likeButtonViewModel/toggleButtonViewModel/toggleButtonViewModel/defaultButtonViewModel/buttonViewModel/accessibilityText',
            defaultValue: ''
        );

        $likeCount = 0;
        if (is_string($accessibilityText)) {
            preg_match('/like this video along with ([\d,]+) other people/', $accessibilityText, $matches);
            $likeCount = Parsers::intValue($matches[1] ?? '0');
        }

        $viewText = Json::value(
            $json,
            'playerOverlays/playerOverlayRenderer/videoDetails/playerOverlayVideoDetailsRenderer/subtitle/runs/2/text',
            defaultValue: '0'
        );

        return [
            'viewCount' => Parsers::intValue(is_string($viewText) ? $viewText : '0', 'view'),
            'likeCount' => $likeCount,
        ];
    }

    /**
     * @return string|null
     */
    public static function location(?array $json): ?string
    {
        $primary = self::primaryRenderer($json);

        return Json::value($primary, 'superTitleLink/runs/0/text');
    }

    public static function isMemberOnly(?array $json): bool
    {
        $primary = self::primaryRenderer($json);

        return is_array($primary) && array_key_exists('badges', $primary);
    }

    public static function isPremium(?array $json): bool
    {
        return Json::pathExists($json, 'contents/twoColumnWatchNextResults/secondaryResults/secondaryResults/offerModule');
    }
}
