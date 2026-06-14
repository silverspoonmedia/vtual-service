<?php

require __DIR__ . '/../vendor/autoload.php';

use Silverspoonmedia\VtualService\Endpoints\VideosEndpoint;
use Silverspoonmedia\VtualService\Http\YouTubeClient;
use Silverspoonmedia\VtualService\Support\Config;

$id = $argv[1] ?? 'HC-yway4mrE';
$parts = array_slice($argv, 2);
if ($parts === []) {
    $parts = [
        'snippet', 'statistics', 'contentDetails', 'status', 'music', 'short',
        'captions', 'qualities', 'chapters', 'location', 'activity', 'explicitLyrics',
        'isPaidPromotion', 'isPremium', 'isMemberOnly', 'isOriginal', 'isRestricted', 'mostReplayed',
    ];
}

$config = new Config([
    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/120.0',
    'proxy' => ['enabled' => false],
]);
$endpoint = new VideosEndpoint(new YouTubeClient($config), $config);

foreach ($parts as $part) {
    try {
        $result = $endpoint->list(['part' => $part, 'id' => $id]);
        $keys = array_keys(array_diff_key($result['items'][0], array_flip(['kind', 'etag', 'id'])));
        echo "OK  part=$part fields=".implode(',', $keys)."\n";
    } catch (Throwable $e) {
        echo "FAIL part=$part ".$e->getMessage()."\n";
    }
}
