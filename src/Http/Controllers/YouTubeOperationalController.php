<?php

namespace Silverspoonmedia\VtualService\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Silverspoonmedia\VtualService\Exceptions\ApiException;
use Silverspoonmedia\VtualService\Support\Config;
use Silverspoonmedia\VtualService\VtualService;

/**
 * HTTP layer mirroring upstream .htaccess rewrite rules.
 *
 * Each action forwards query parameters to the matching endpoint service and
 * returns JSON with the same pretty-print style as the native project.
 */
class YouTubeOperationalController extends Controller
{
    public function __construct(
        protected VtualService $service,
        protected Config $config,
    ) {}

    public function channels(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->channels($request->query()));
    }

    public function videos(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->videos($request->query()));
    }

    public function search(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->search($request->query()));
    }

    public function playlists(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->playlists($request->query()));
    }

    public function playlistItems(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->playlistItems($request->query()));
    }

    public function commentThreads(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->commentThreads($request->query()));
    }

    public function community(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->community($request->query()));
    }

    public function lives(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->lives($request->query()));
    }

    public function liveChats(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->liveChats($request->query()));
    }

    public function addKey(Request $request): Response
    {
        try {
            $this->assertInstanceKey($request);
        } catch (ApiException $e) {
            return response(json_encode($e->toArray(), JSON_PRETTY_PRINT), $e->apiCode())
                ->header('Content-Type', 'application/json; charset=UTF-8');
        }

        $key = $request->query('key');
        if ($key === null) {
            return response('', 200)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        try {
            $message = $this->service->addKey((string) $key, $request->query('forceSecret'));
        } catch (ApiException $e) {
            return response(json_encode($e->toArray(), JSON_PRETTY_PRINT), $e->apiCode())
                ->header('Content-Type', 'application/json; charset=UTF-8');
        }

        return response($message, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function noKey(Request $request, string $path = ''): JsonResponse
    {
        try {
            $this->assertInstanceKey($request);
            $apiPath = $path;
            if ($request->getQueryString()) {
                $apiPath .= '?' . $request->getQueryString();
            }
            $result = $this->service->noKey($apiPath, $request->has('monitoring'));
        } catch (ApiException $e) {
            return response()->json($e->toArray(), $e->apiCode(), [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return response()->json(json_decode($result['body'], true), 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** @param callable(): array<string, mixed> $callback */
    protected function respond(callable $callback): JsonResponse
    {
        try {
            $this->assertInstanceKey(request());
            $data = $callback();
        } catch (ApiException $e) {
            return response()->json($e->toArray(), $e->apiCode(), [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return response()->json($data, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    protected function assertInstanceKey(Request $request): void
    {
        $required = $this->config->restrictUsageToKey();
        if ($required === '') {
            return;
        }

        $provided = $request->query('instanceKey');
        if ($provided === null) {
            throw new ApiException('This instance requires that you provide the appropriate <code>instanceKey</code> parameter!');
        }
        if ($provided !== $required) {
            throw new ApiException("The provided <code>instanceKey</code> isn't correct!");
        }
    }
}
