<?php

namespace Ekumanov\ClsFix\Tests\unit;

use Ekumanov\ClsFix\ImageDimensions;
use Flarum\Testing\unit\TestCase;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;

class ImageDimensionsTest extends TestCase
{
    private const URL = 'https://example.com/cat.jpg';
    private string $hash;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hash = hash('sha256', self::URL);
        $this->key = 'cls_fix.dim.' . $this->hash;
    }

    #[Test]
    public function get_returns_dims_from_cache_without_touching_db(): void
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('many')
            ->once()
            ->with([$this->key])
            ->andReturn([$this->key => ['width' => 800, 'height' => 600]]);

        $db = m::mock(ConnectionInterface::class);
        $db->shouldNotReceive('table');

        $repo = new ImageDimensions($db, $cache);

        $this->assertSame(['width' => 800, 'height' => 600], $repo->get(self::URL));
    }

    #[Test]
    public function get_falls_back_to_db_on_cache_miss_and_warms_cache(): void
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('many')
            ->once()
            ->andReturn([$this->key => null]);

        $cache->shouldReceive('put')
            ->once()
            ->with($this->key, ['width' => 1024, 'height' => 768], 86400);

        $builder = m::mock(Builder::class);
        $builder->shouldReceive('select')->once()->andReturnSelf();
        $builder->shouldReceive('whereIn')->once()->with('url_hash', [$this->hash])->andReturnSelf();
        $builder->shouldReceive('get')->once()->andReturn(collect([
            (object) ['url_hash' => $this->hash, 'width' => 1024, 'height' => 768],
        ]));

        $db = m::mock(ConnectionInterface::class);
        $db->shouldReceive('table')->once()->with('cls_fix_image_dimensions')->andReturn($builder);

        $repo = new ImageDimensions($db, $cache);

        $this->assertSame(['width' => 1024, 'height' => 768], $repo->get(self::URL));
    }

    #[Test]
    public function get_returns_null_for_unknown_url_and_writes_negative_cache_sentinel(): void
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('many')->once()->andReturn([$this->key => null]);
        $cache->shouldReceive('put')
            ->once()
            ->with($this->key, '0', 300);

        $builder = m::mock(Builder::class);
        $builder->shouldReceive('select')->once()->andReturnSelf();
        $builder->shouldReceive('whereIn')->once()->andReturnSelf();
        $builder->shouldReceive('get')->once()->andReturn(collect([]));

        $db = m::mock(ConnectionInterface::class);
        $db->shouldReceive('table')->once()->andReturn($builder);

        $repo = new ImageDimensions($db, $cache);

        $this->assertNull($repo->get(self::URL));
    }

    #[Test]
    public function get_skips_db_when_cache_holds_the_negative_sentinel(): void
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('many')->once()->andReturn([$this->key => '0']);

        $db = m::mock(ConnectionInterface::class);
        $db->shouldNotReceive('table');

        $repo = new ImageDimensions($db, $cache);

        $this->assertNull($repo->get(self::URL));
    }

    #[Test]
    public function get_many_dedupes_input_urls(): void
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('many')
            ->once()
            // Repeated URL must collapse to a single cache key.
            ->withArgs(fn ($keys) => count($keys) === 1 && $keys[0] === $this->key)
            ->andReturn([$this->key => ['width' => 10, 'height' => 10]]);

        $db = m::mock(ConnectionInterface::class);

        $repo = new ImageDimensions($db, $cache);

        $result = $repo->getMany([self::URL, self::URL, self::URL]);

        $this->assertCount(1, $result);
        $this->assertSame(['width' => 10, 'height' => 10], $result[self::URL]);
    }

    #[Test]
    public function put_upserts_row_and_warms_cache(): void
    {
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('updateOrInsert')
            ->once()
            ->with(
                ['url_hash' => $this->hash],
                m::on(fn ($values) =>
                    $values['url'] === self::URL
                    && $values['width'] === 320
                    && $values['height'] === 240
                    && isset($values['updated_at'])
                ),
            );

        $db = m::mock(ConnectionInterface::class);
        $db->shouldReceive('table')->once()->with('cls_fix_image_dimensions')->andReturn($builder);

        $cache = m::mock(Cache::class);
        $cache->shouldReceive('put')
            ->once()
            ->with($this->key, ['width' => 320, 'height' => 240], 86400);

        $repo = new ImageDimensions($db, $cache);

        $repo->put(self::URL, 320, 240);
    }
}
