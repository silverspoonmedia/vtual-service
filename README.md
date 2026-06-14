# vtual-service

Laravel package port of [YouTube operational API](https://github.com/Benjamin-Loison/YouTube-operational-API) — scrapes YouTube when the official Data API v3 fails or runs out of quota.

Upstream source lives in `sources/YouTube-operational-API/` (git clone for `git pull` updates). Package logic is in `src/`.

**Full porting reference:** [docs/PORTING.md](docs/PORTING.md)

## Installation

```bash
composer require silverspoonmedia/vtual-service
```

Publish config (optional):

```bash
php artisan vendor:publish --tag=vtual-service-config
```

## HTTP API (mirrors upstream `.htaccess` routes)

With default prefix `youtube`:

| Route | Upstream file |
|---|---|
| `GET /youtube/channels` | `channels.php` |
| `GET /youtube/videos` | `videos.php` |
| `GET /youtube/search` | `search.php` |
| `GET /youtube/playlists` | `playlists.php` |
| `GET /youtube/playlistItems` | `playlistItems.php` |
| `GET /youtube/commentThreads` | `commentThreads.php` |
| `GET /youtube/community` | `community.php` |
| `GET /youtube/lives` | `lives.php` |
| `GET /youtube/liveChats` | `liveChats.php` |
| `GET /youtube/addKey` | `addKey.php` |
| `GET /youtube/noKey/{path}` | `noKey/index.php` |

Disable routes: `VTUAL_ROUTES_ENABLED=false`

## Outbound proxy & Tor

All scraping goes through `YouTubeClient`. Configure a proxy so Google/YouTube see the proxy egress IP, not your server.

| `VTUAL_PROXY_TYPE` | Transport | Notes |
|---|---|---|
| `http` (default) | PHP streams | HTTP CONNECT |
| `socks5` / `socks5h` | cURL (`ext-curl` required) | use `socks5h` for remote DNS |
| `tor` | cURL → local Tor SOCKS | preset `127.0.0.1:9050` |

When Tor is used (`VTUAL_PROXY_TYPE=tor`, or `socks5h` aimed at the Tor host/port), egress is verified via [check.torproject.org](https://check.torproject.org/) before any scrape. If the outbound IP is not a Tor exit, scraping is **aborted** (HTTP 503) — fail-closed.

## Programmatic usage

```php
use Silverspoonmedia\VtualService\Facades\VtualService;

$response = VtualService::videos([
    'part' => 'snippet',
    'id' => 'dQw4w9WgXcQ',
]);
```

## Configuration

See `config/vtual-service.php`. Key env vars:

- `VTUAL_KEYS_FILE` — API key pool for no-key proxy (default: `storage/app/vtual-service/keys.txt`)
- `VTUAL_RESTRICT_USAGE_TO_KEY` — require `instanceKey` query param
- `VTUAL_ROUTES_PREFIX` — route prefix (default `youtube`)
- `VTUAL_HTTPS_PROXY` — single proxy URL (`http://user:pass@host:port` or `socks5h://host:port`)
- `VTUAL_HTTPS_PROXY_ADDRESS` / `VTUAL_HTTPS_PROXY_PORT` / `VTUAL_HTTPS_PROXY_USERNAME` / `VTUAL_HTTPS_PROXY_PASSWORD` — upstream-compatible proxy fields
- `VTUAL_PROXY_TYPE` — `http` (default), `socks5`, `socks5h`, or `tor`
- `VTUAL_PROXY_ENABLED` — set `false` to bypass proxy even when configured
- `VTUAL_TOR_HOST` / `VTUAL_TOR_PORT` — Tor SOCKS endpoint (default `127.0.0.1:9050`)
- `VTUAL_TOR_VERIFY_EGRESS` — verify Tor exit IP before scraping (default `true`; aborts if not Tor)
- `VTUAL_TOR_VERIFY_URL` — Tor check endpoint (default `https://check.torproject.org/api/ip`)
- `VTUAL_TOR_VERIFY_CACHE_SECONDS` — cache successful egress check (default `300`)
- `VTUAL_COMMUNITY_SAPISID` / `VTUAL_COMMUNITY_SECURE_3PSID` — cookies for community post endpoint

## Future upstream patching

After `git pull` in Source:

```sh
cd sources/YouTube-operational-API
php internal/diff-checker.php status --report=last-pull
```

Apply `[ADDED]` / `[MODIFIED]` / `[DELETED]` changes to the mapped package classes (see [docs/PORTING.md](docs/PORTING.md)), run `composer test`, then refresh `internal/baseline.txt`.

## Testing

```bash
composer test
```

## License

MIT — see [LICENSE.md](LICENSE.md). Upstream YouTube operational API is separate; respect its license and YouTube ToS when deploying.
