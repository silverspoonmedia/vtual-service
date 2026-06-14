<?php

namespace Silverspoonmedia\VtualService\Support;

/**
 * Input validation regexes.
 *
 * Direct port of the `is*()` family of functions in upstream `common.php`.
 * Each method returns true when the input matches YouTube's id/handle/token
 * shape so endpoints can reject malformed parameters early.
 */
class Validators
{
    /** Upstream: checkRegex() */
    public static function matches(string $regex, string $str): bool
    {
        return preg_match("/^$regex$/", $str) === 1;
    }

    public static function isContinuationToken(string $token): bool
    {
        return self::matches('[\w=\-_]+', $token);
    }

    public static function isContinuationTokenAndVisitorData(string $token): bool
    {
        return self::matches('[\w=\-_]+,[\w=\-_]*', $token);
    }

    public static function isPlaylistId(string $playlistId): bool
    {
        return self::matches('[\w\-_]+', $playlistId);
    }

    /** Upstream leaves cId unrestricted. */
    public static function isCId(string $cId): bool
    {
        return true;
    }

    public static function isUsername(string $username): bool
    {
        return self::matches('\w+', $username);
    }

    public static function isChannelId(string $channelId): bool
    {
        return self::matches('UC[\w\-_]{22}', $channelId);
    }

    public static function isVideoId(string $videoId): bool
    {
        return self::matches('[\w\-_]{11}', $videoId);
    }

    /** 'é' is a valid hashtag, so upstream keeps this unrestricted. */
    public static function isHashtag(string $hashtag): bool
    {
        return true;
    }

    public static function isSapisidHash(string $sapisidHash): bool
    {
        return self::matches('[1-9]\d{9}_[a-f\d]{40}', $sapisidHash);
    }

    /** Upstream leaves query unrestricted. */
    public static function isQuery(string $q): bool
    {
        return true;
    }

    public static function isClipId(string $clipId): bool
    {
        return self::matches('Ug[\w\-_]{34}', $clipId);
    }

    public static function isEventType(string $eventType): bool
    {
        return in_array($eventType, ['completed', 'live', 'upcoming'], true);
    }

    public static function isPositiveInteger(string $s): bool
    {
        return preg_match('/^\d+$/', $s) === 1;
    }

    public static function isYouTubeDataApiV3Key(string $key): bool
    {
        return self::matches('AIzaSy[A-D][\w\-_]{32}', $key);
    }

    public static function isHandle(string $handle): bool
    {
        return self::matches('@[\w\-_.]{3,}', $handle);
    }

    public static function isPostId(string $postId): bool
    {
        return self::matches('Ug[w-z][\w\-_]{16}4AaABCQ', $postId)
            || self::matches('Ugkx[\w\-_]{32}', $postId);
    }

    public static function isCommentId(string $commentId): bool
    {
        return self::matches('Ug[w-z][\w\-_]{16}4AaABAg(|.[\w\-]{22})', $commentId);
    }
}
