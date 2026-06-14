<?php

namespace Silverspoonmedia\VtualService\Support;

/**
 * Slash-delimited array path access.
 *
 * Direct port of the upstream `doesPathExist()` / `getValue()` helpers from
 * `common.php`. YouTube's Innertube responses are deeply nested associative
 * arrays, so almost every endpoint reaches into them through these two calls.
 */
class Json
{
    /**
     * Whether `$path` (e.g. "a/b/0/c") resolves inside `$json`.
     *
     * Upstream: doesPathExist()
     *
     * @param  array<mixed>|null  $json
     */
    public static function pathExists(?array $json, string $path): bool
    {
        if ($json === null) {
            return false;
        }

        $parts = explode('/', $path);
        $partsCount = count($parts);

        if ($partsCount === 1) {
            return array_key_exists($path, $json);
        }

        return array_key_exists($parts[0], $json)
            && is_array($json[$parts[0]])
            && self::pathExists($json[$parts[0]], implode('/', array_slice($parts, 1)));
    }

    /**
     * Resolve `$path` inside `$json` with optional fallback path / default.
     *
     * Upstream: getValue()
     *
     * @param  array<mixed>|null  $json
     */
    public static function value(?array $json, string $path, ?string $defaultPath = null, mixed $defaultValue = null): mixed
    {
        if (! self::pathExists($json, $path)) {
            return $defaultPath !== null ? self::value($json, $defaultPath, null, $defaultValue) : $defaultValue;
        }

        $parts = explode('/', $path);
        $partsCount = count($parts);

        if ($partsCount === 1) {
            return $json[$path];
        }

        return self::value($json[$parts[0]], implode('/', array_slice($parts, 1)));
    }

    /**
     * First node in `$nodes` that contains `$path`.
     *
     * Upstream: getFirstNodeContainingPath()
     *
     * @param  array<int, array<mixed>>  $nodes
     * @return array<mixed>|null
     */
    public static function firstNodeContainingPath(array $nodes, string $path): ?array
    {
        foreach ($nodes as $node) {
            if (self::pathExists($node, $path)) {
                return $node;
            }
        }

        return null;
    }
}
