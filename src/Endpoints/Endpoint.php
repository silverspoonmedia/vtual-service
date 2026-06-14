<?php

namespace Silverspoonmedia\VtualService\Endpoints;

use Silverspoonmedia\VtualService\Exceptions\ApiException;
use Silverspoonmedia\VtualService\Http\YouTubeClient;
use Silverspoonmedia\VtualService\Support\Config;

/**
 * Base class for every ported endpoint service.
 *
 * Holds the shared HTTP client + config and ports the upstream part-validation
 * and multiple-id helpers (verifyMultipleIds / getMultipleIds from common.php).
 *
 * Endpoints receive their parameters as a plain associative array (the moral
 * equivalent of the upstream `$_GET`) and return a decoded array instead of
 * echoing JSON, so they are reusable from controllers, jobs, or tests.
 *
 * List parameters (`part`, `id`, …) accept either a comma-separated string
 * (HTTP query style) or an array of strings (preferred for programmatic use).
 */
abstract class Endpoint
{
    public function __construct(
        protected YouTubeClient $client,
        protected Config $config,
    ) {}

    /**
     * Resolve which parts were requested.
     *
     * @param  string|array<int, string>|null  $part
     * @param  array<int, string>  $realOptions
     * @return array<string, bool>
     */
    protected function resolveParts(string|array|null $part, array $realOptions): array
    {
        $options = array_fill_keys($realOptions, false);

        if ($part === null) {
            return $options;
        }

        foreach ($this->normalizeList($part) as $p) {
            if (! in_array($p, $realOptions, true)) {
                throw new ApiException("Invalid part $p");
            }
            $options[$p] = true;
        }

        return $options;
    }

    /**
     * Normalize a list parameter and enforce instance limits.
     *
     * @param  string|array<int, string>  $value
     * @return array<int, string>
     */
    protected function multipleIds(string|array $value, string $field = 'id'): array
    {
        $ids = $this->normalizeList($value);
        if ($ids === []) {
            throw new ApiException("Missing $field");
        }

        $this->verifyMultipleIds($ids, $field);

        return $ids;
    }

    /**
     * @param  array<int, string>  $ids
     */
    protected function verifyMultipleIds(array $ids, string $field = 'id'): void
    {
        if (count($ids) >= 2 && ! $this->config->multipleIdsEnabled()) {
            throw new ApiException("Multiple {$field}s are disabled on this instance");
        }

        if (count($ids) > $this->config->multipleIdsMaximum()) {
            throw new ApiException("Too many $field");
        }
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            return $this->trimList(explode(',', $value));
        }

        if (! is_array($value)) {
            throw new ApiException('Invalid list parameter');
        }

        $items = [];
        foreach ($value as $item) {
            if ($item === null || $item === '') {
                continue;
            }

            $string = trim((string) $item);
            if ($string === '') {
                continue;
            }

            if (str_contains($string, ',')) {
                array_push($items, ...$this->trimList(explode(',', $string)));
            } else {
                $items[] = $string;
            }
        }

        return $items;
    }

    /**
     * @param  array<int, string>  $items
     * @return array<int, string>
     */
    protected function trimList(array $items): array
    {
        return array_values(array_filter(array_map('trim', $items), fn (string $item) => $item !== ''));
    }

    /**
     * @param  string|array<int, string>|null  $part
     */
    protected function partIncludes(string|array|null $part, string $needle): bool
    {
        return in_array($needle, $this->normalizeList($part), true);
    }

    /**
     * Read a scalar parameter, returning null when missing/blank.
     *
     * @param  array<string, mixed>  $params
     */
    protected function param(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            throw new ApiException("Parameter $key must be a string");
        }

        return (string) $value;
    }

    /**
     * Read a list parameter (`part`, `id`, …) as a normalized string array.
     *
     * @param  array<string, mixed>  $params
     * @return array<int, string>|null
     */
    protected function paramList(array $params, string $key): ?array
    {
        if (! array_key_exists($key, $params)) {
            return null;
        }

        $list = $this->normalizeList($params[$key]);

        return $list === [] ? null : $list;
    }

    /**
     * Read `part` (or another list field) in its raw string-or-array form.
     *
     * @param  array<string, mixed>  $params
     * @return string|array<int, string>|null
     */
    protected function paramPart(array $params, string $key = 'part'): string|array|null
    {
        if (! array_key_exists($key, $params)) {
            return null;
        }

        $value = $params[$key];

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_string($value) || is_array($value)) {
            return $value;
        }

        throw new ApiException("Parameter $key must be a string or array");
    }
}
