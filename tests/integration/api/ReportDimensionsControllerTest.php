<?php

namespace Ekumanov\ClsFix\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use PHPUnit\Framework\Attributes\Test;

class ReportDimensionsControllerTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const URL = 'https://example.com/foo.jpg';
    private const PATH = '/api/cls-fix/dimensions';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ekumanov-cls-fix');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
            ],
        ]);
    }

    private function postReport(string $url, int $width, int $height, ?int $authenticatedAs)
    {
        $request = $this->request('POST', self::PATH, [
            'authenticatedAs' => $authenticatedAs,
            'json' => ['url' => $url, 'width' => $width, 'height' => $height],
        ]);

        // Guest requests need a CSRF token; the auth helper auto-bypasses it for users.
        if ($authenticatedAs === null) {
            $request = $this->requestWithCsrfToken($request);
        }

        return $this->send($request);
    }

    private function cache(): Cache
    {
        return $this->app()->getContainer()->make(Cache::class);
    }

    private function rowCount(): int
    {
        return $this->database()->table('cls_fix_image_dimensions')->count();
    }

    #[Test]
    public function guest_cannot_report_dimensions(): void
    {
        $response = $this->postReport(self::URL, 800, 600, null);

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertSame(0, $this->rowCount());
    }

    #[Test]
    public function valid_report_from_member_persists_dimensions(): void
    {
        $response = $this->postReport(self::URL, 800, 600, 2);

        $this->assertEquals(204, $response->getStatusCode());

        $row = $this->database()->table('cls_fix_image_dimensions')
            ->where('url_hash', hash('sha256', self::URL))
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(800, (int) $row->width);
        $this->assertSame(600, (int) $row->height);
    }

    #[Test]
    public function rejects_non_http_url_scheme(): void
    {
        $response = $this->postReport('javascript:alert(1)', 100, 100, 2);

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertStringContainsString('invalid_url', (string) $response->getBody());
        $this->assertSame(0, $this->rowCount());
    }

    #[Test]
    public function rejects_oversized_url(): void
    {
        $longUrl = 'https://example.com/'.str_repeat('a', 2100).'.jpg';

        $response = $this->postReport($longUrl, 100, 100, 2);

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertStringContainsString('invalid_url', (string) $response->getBody());
    }

    #[Test]
    public function rejects_zero_or_negative_dimensions(): void
    {
        $response = $this->postReport(self::URL, 0, 100, 2);
        $this->assertEquals(422, $response->getStatusCode());

        $response = $this->postReport(self::URL, 100, -1, 2);
        $this->assertEquals(422, $response->getStatusCode());
    }

    #[Test]
    public function rejects_dimensions_above_safety_ceiling(): void
    {
        $response = $this->postReport(self::URL, 20000, 100, 2);

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertStringContainsString('invalid_dimensions', (string) $response->getBody());
    }

    #[Test]
    public function rejects_extreme_aspect_ratios(): void
    {
        $response = $this->postReport(self::URL, 5000, 100, 2);

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertStringContainsString('invalid_dimensions', (string) $response->getBody());
    }

    #[Test]
    public function established_cache_entry_cannot_be_flipped_by_member(): void
    {
        $this->postReport(self::URL, 800, 600, 2);
        $this->assertSame(800, (int) $this->database()->table('cls_fix_image_dimensions')->first()->width);

        $response = $this->postReport(self::URL, 50, 50, 2);

        // Silent rejection — 204, not 422 — so the attacker can't probe.
        $this->assertEquals(204, $response->getStatusCode());

        $row = $this->database()->table('cls_fix_image_dimensions')->first();
        $this->assertSame(800, (int) $row->width);
        $this->assertSame(600, (int) $row->height);
    }

    #[Test]
    public function admin_can_overwrite_an_established_cache_entry(): void
    {
        $this->postReport(self::URL, 800, 600, 2);

        $response = $this->postReport(self::URL, 1600, 1200, 1);

        $this->assertEquals(204, $response->getStatusCode());

        $row = $this->database()->table('cls_fix_image_dimensions')->first();
        $this->assertSame(1600, (int) $row->width);
        $this->assertSame(1200, (int) $row->height);
    }

    #[Test]
    public function within_tolerance_report_does_not_count_as_a_strike(): void
    {
        $this->postReport(self::URL, 800, 600, 2);

        // ±2 px confirmation — accepted as a match, no silent reject, no strike.
        $response = $this->postReport(self::URL, 801, 599, 2);
        $this->assertEquals(204, $response->getStatusCode());

        $strikes = (int) $this->cache()->get('cls_fix.susp.2', 0);
        $this->assertSame(0, $strikes);
    }

    #[Test]
    public function repeated_strikes_auto_mute_the_user(): void
    {
        $this->postReport(self::URL, 800, 600, 2);

        // Pre-load four strikes so the next mismatched report is the fifth.
        $this->cache()->put('cls_fix.susp.2', 4, 3600);

        $silent = $this->postReport(self::URL, 50, 50, 2);
        $this->assertEquals(204, $silent->getStatusCode(), 'fifth strike still gets silent reject');

        // Auto-mute kicks in: the user's rate-limit budget is now saturated.
        $muted = $this->postReport('https://example.com/other.jpg', 100, 100, 2);
        $this->assertEquals(429, $muted->getStatusCode(), 'subsequent report should be rate-limited (muted)');
    }

    #[Test]
    public function rate_limit_blocks_at_threshold(): void
    {
        // Pre-fill the bucket to one short of the limit.
        $this->cache()->put('cls_fix.rl.2', 59, 60);

        // 60th request: still allowed.
        $this->assertEquals(204, $this->postReport(self::URL, 800, 600, 2)->getStatusCode());

        // 61st request: blocked.
        $blocked = $this->postReport('https://example.com/other.jpg', 100, 100, 2);
        $this->assertEquals(429, $blocked->getStatusCode());
        $this->assertStringContainsString('rate_limited', (string) $blocked->getBody());
    }
}
