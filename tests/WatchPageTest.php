<?php

use Silverspoonmedia\VtualService\Support\WatchPage;

it('extracts snippet from watch page contents', function () {
    $json = [
        'contents' => [
            'twoColumnWatchNextResults' => [
                'results' => [
                    'results' => [
                        'contents' => [
                            [
                                'videoPrimaryInfoRenderer' => [
                                    'dateText' => ['simpleText' => 'Jan 1, 2024'],
                                    'title' => ['runs' => [['text' => 'Hello']]],
                                ],
                            ],
                            [
                                'videoSecondaryInfoRenderer' => [
                                    'attributedDescription' => ['content' => 'Desc'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    expect(WatchPage::snippet($json))->toBe([
        'publishedAt' => strtotime('Jan 1, 2024'),
        'description' => 'Desc',
        'title' => 'Hello',
    ]);
});

it('returns null activity when rich metadata is absent', function () {
    $json = [
        'contents' => [
            'twoColumnWatchNextResults' => [
                'results' => [
                    'results' => [
                        'contents' => [
                            ['videoSecondaryInfoRenderer' => [
                                'metadataRowContainer' => [
                                    'metadataRowContainerRenderer' => ['collapsedItemCount' => 0],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ],
    ];

    expect(WatchPage::activity($json))->toBeNull();
});

it('extracts activity from nested rich metadata', function () {
    $json = [
        'contents' => [
            'twoColumnWatchNextResults' => [
                'results' => [
                    'results' => [
                        'contents' => [
                            [
                                'videoSecondaryInfoRenderer' => [
                                    'metadataRowContainer' => [
                                        'metadataRowContainerRenderer' => [
                                            'rows' => [[
                                                'richMetadataRowRenderer' => [
                                                    'contents' => [[
                                                        'richMetadataRenderer' => [
                                                            'title' => ['simpleText' => 'Film'],
                                                            'subtitle' => ['simpleText' => '2020'],
                                                            'thumbnail' => ['thumbnails' => [['url' => 'https://example.com/x.jpg']]],
                                                            'endpoint' => ['browseEndpoint' => ['browseId' => 'UCxyz']],
                                                        ],
                                                    ]],
                                                ],
                                            ]],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    expect(WatchPage::activity($json))->toBe([
        'name' => 'Film',
        'year' => '2020',
        'thumbnails' => [['url' => 'https://example.com/x.jpg']],
        'channelId' => 'UCxyz',
    ]);
});

it('returns false for explicit lyrics when metadata rows are missing', function () {
    expect(WatchPage::explicitLyrics([
        'contents' => [
            'twoColumnWatchNextResults' => [
                'results' => [
                    'results' => [
                        'contents' => [[
                            'videoSecondaryInfoRenderer' => [
                                'metadataRowContainer' => [
                                    'metadataRowContainerRenderer' => [],
                                ],
                            ],
                        ]],
                    ],
                ],
            ],
        ],
    ]))->toBeFalse();
});
