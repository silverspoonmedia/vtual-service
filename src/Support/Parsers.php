<?php

namespace Silverspoonmedia\VtualService\Support;

/**
 * Numeric / date string parsing helpers.
 *
 * Ported from upstream `common.php`. The upstream versions of getIntValue()
 * and getPublishedAt() used eval() to evaluate arithmetic strings; that is
 * reimplemented here without eval() to avoid arbitrary code execution while
 * keeping identical results.
 */
class Parsers
{
    /**
     * Parse abbreviated counts like "1.2K subscribers" / "No views" -> int.
     *
     * Upstream: getIntValue()
     */
    public static function intValue(string $unitCount, string $unit = ''): int
    {
        $unitCount = str_replace(',', '', $unitCount);

        if ($unit !== '') {
            $unitCount = str_replace(" {$unit}s", '', $unitCount);
            $unitCount = str_replace(" $unit", '', $unitCount);
        }

        if ($unitCount === 'No') {
            $unitCount = '0';
        }

        $unitCount = trim($unitCount);

        // Resolve a single trailing magnitude suffix (K/M/B) without eval().
        $multiplier = 1;
        $last = strtoupper(substr($unitCount, -1));
        $magnitudes = ['K' => 1_000, 'M' => 1_000_000, 'B' => 1_000_000_000];
        if (isset($magnitudes[$last])) {
            $multiplier = $magnitudes[$last];
            $unitCount = substr($unitCount, 0, -1);
        }

        // Upstream allowed underscore digit separators.
        $unitCount = str_replace('_', '', $unitCount);

        if (! is_numeric($unitCount)) {
            return (int) $unitCount;
        }

        return (int) round(((float) $unitCount) * $multiplier);
    }

    /**
     * Parse a view-count string like "1,234 views" / "No views" -> int.
     *
     * Upstream: getIntFromViewCount()
     */
    public static function intFromViewCount(string $viewCount): int
    {
        if ($viewCount === 'No views') {
            return 0;
        }

        foreach ([',', ' views', 'view'] as $toRemove) {
            $viewCount = str_replace($toRemove, '', $viewCount);
        }

        return (int) $viewCount;
    }

    /**
     * Parse a duration like "1:02:03" or "-3:21:09:09" into total seconds.
     *
     * Upstream: getIntFromDuration()
     */
    public static function intFromDuration(string $timeStr): int
    {
        $isNegative = ($timeStr[0] ?? '') === '-';
        if ($isNegative) {
            $timeStr = substr($timeStr, 1);
        }

        $timeParts = array_map('intval', explode(':', $timeStr));
        // Pad on the left to [days, hours, minutes, seconds].
        while (count($timeParts) < 4) {
            array_unshift($timeParts, 0);
        }
        [$days, $hours, $minutes, $seconds] = array_slice($timeParts, -4);

        $timeInt = $days * 86400 + $hours * 3600 + $minutes * 60 + $seconds;

        return ($isNegative ? -1 : 1) * $timeInt;
    }

    /**
     * Convert a relative "3 days ago" string into an absolute unix timestamp.
     *
     * Upstream: getPublishedAt() (reimplemented without eval()).
     */
    public static function publishedAt(string $publishedAtRaw, ?int $now = null): int
    {
        $now ??= time();

        $units = [
            'years' => 31_104_000,
            'year' => 31_104_000,
            'months' => 2_592_000,
            'month' => 2_592_000,
            'weeks' => 604_800,
            'week' => 604_800,
            'days' => 86_400,
            'day' => 86_400,
            'hours' => 3_600,
            'hour' => 3_600,
            'minutes' => 60,
            'minute' => 60,
            'seconds' => 1,
            'second' => 1,
        ];

        $str = str_replace(['ago', ','], '', $publishedAtRaw);

        // Find "<number> <unit>" pairs and sum their second-equivalents.
        if (preg_match_all('/(\d+)\s*([a-zA-Z]+)/', $str, $matches, PREG_SET_ORDER)) {
            $elapsed = 0;
            foreach ($matches as $match) {
                $count = (int) $match[1];
                $unit = strtolower($match[2]);
                if (isset($units[$unit])) {
                    $elapsed += $count * $units[$unit];
                }
            }

            return $now - $elapsed;
        }

        return $now;
    }

    /**
     * URL-safe base64 without padding.
     *
     * Upstream: base64url_encode()
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
