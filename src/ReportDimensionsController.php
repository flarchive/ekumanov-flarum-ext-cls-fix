<?php

namespace Ekumanov\ClsFix;

use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/cls-fix/dimensions
 * Body: { url: string, width: int, height: int }
 *
 * Records natural dimensions reported by the client after an image loads, so
 * subsequent visitors get a correctly-sized placeholder server-side and avoid
 * any layout shift.
 *
 * Authenticated users only — guests can benefit from the cache passively but
 * cannot write to it. Several layers of defence (rate limit per user, tight
 * dimension bounds, aspect-ratio sanity, first-write protection) keep the
 * endpoint safe from abuse by a logged-in attacker. See README "Security
 * model" for the full threat analysis.
 */
class ReportDimensionsController implements RequestHandlerInterface
{
    private const MAX_URL_LEN = 2048;

    // 16384 covers anything a real browser will decode (Chrome's max canvas
    // dimension is 32767 but real-world images max around 16k). Tighter than
    // the column limit so absurd values get rejected before they reach the DB.
    private const MAX_DIM = 16384;

    // Block extreme aspect ratios. 20:1 and 1:20 already cover wide banners
    // and tall infographics; anything beyond is almost certainly bogus.
    private const MAX_RATIO = 20.0;

    // Per-user rate limit: 60 reports per rolling 60s window. A page with 60
    // uncached images can fully seed itself in one visit; sustained traffic
    // beyond that is abuse.
    private const RATE_LIMIT_WINDOW = 60;
    private const RATE_LIMIT_MAX = 60;

    // Reports within ±2px of an existing cached value are considered a
    // confirmation, not a change — covers minor browser-to-browser rounding
    // (e.g. devicePixelRatio quirks).
    private const TOLERANCE_PX = 2;

    // Auto-mute: every silent-rejected flip attempt (non-admin trying to change
    // an established cache entry to materially different dims) is a "strike".
    // Honest browsers almost never produce strikes thanks to TOLERANCE_PX, so
    // accruing several is a strong signal the account is poisoning the cache.
    // Once the threshold is crossed, the user's rate-limit budget is set to
    // sentinel-full for MUTE_DURATION, so further reports get 429 and the
    // client JS auto-suppresses for the rest of each page-view.
    private const STRIKE_WINDOW = 3600;     // 1h rolling
    private const STRIKE_THRESHOLD = 5;
    private const MUTE_DURATION = 3600;     // 1h

    public function __construct(
        private readonly ImageDimensions $dimensions,
        private readonly Cache $cache,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            throw new PermissionDeniedException();
        }

        // 1) Per-user rate limit. Cache-only counter; the small TOCTOU window
        //    is acceptable (worst case a handful of extra requests slip through).
        $rlKey = 'cls_fix.rl.' . $actor->id;
        $count = (int) $this->cache->get($rlKey, 0);
        if ($count >= self::RATE_LIMIT_MAX) {
            return new JsonResponse(['errors' => [['code' => 'rate_limited']]], 429);
        }
        $this->cache->put($rlKey, $count + 1, self::RATE_LIMIT_WINDOW);

        // 2) Parse + structural validation.
        $body = (array) $request->getParsedBody();
        $url = (string) Arr::get($body, 'url', '');
        $width = (int) Arr::get($body, 'width', 0);
        $height = (int) Arr::get($body, 'height', 0);

        if ($url === '' || strlen($url) > self::MAX_URL_LEN) {
            return self::error('invalid_url');
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return self::error('invalid_url');
        }

        if ($width < 1 || $width > self::MAX_DIM || $height < 1 || $height > self::MAX_DIM) {
            return self::error('invalid_dimensions');
        }

        // 3) Aspect-ratio sanity.
        $ratio = $width / $height;
        if ($ratio > self::MAX_RATIO || $ratio < (1 / self::MAX_RATIO)) {
            return self::error('invalid_dimensions');
        }

        // 4) First-write protection. Once a URL has cached dimensions, a single
        //    user can't flip them — only admins (or matching reports) get to
        //    update. Returns 204 silently on rejected updates so a probing
        //    client can't tell which value is "locked".
        $existing = $this->dimensions->get($url);
        if ($existing !== null) {
            $matches = abs($existing['width'] - $width) <= self::TOLERANCE_PX
                && abs($existing['height'] - $height) <= self::TOLERANCE_PX;
            if (! $matches && ! $actor->isAdmin()) {
                // 5) Auto-mute on repeat strikes. Increment a per-user
                //    counter; cross the threshold and the account's rate-limit
                //    budget is filled to mute them for an hour.
                $strikeKey = 'cls_fix.susp.' . $actor->id;
                $strikes = (int) $this->cache->get($strikeKey, 0) + 1;
                $this->cache->put($strikeKey, $strikes, self::STRIKE_WINDOW);
                if ($strikes >= self::STRIKE_THRESHOLD) {
                    $this->cache->put($rlKey, PHP_INT_MAX, self::MUTE_DURATION);
                }
                return new EmptyResponse(204);
            }
        }

        $this->dimensions->put($url, $width, $height);

        return new EmptyResponse(204);
    }

    private static function error(string $code): JsonResponse
    {
        return new JsonResponse(['errors' => [['code' => $code]]], 422);
    }
}
