<?php

namespace Ekumanov\ClsFix;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

class ImageDimensions
{
    private const TABLE = 'cls_fix_image_dimensions';
    private const CACHE_PREFIX = 'cls_fix.dim.';
    private const CACHE_TTL_HIT = 86400;   // 24h
    private const CACHE_TTL_MISS = 300;    // 5m — avoids hammering DB for unknown URLs
    private const MISS_SENTINEL = '0';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Cache $cache,
    ) {}

    /**
     * @return array{width:int,height:int}|null
     */
    public function get(string $url): ?array
    {
        $result = $this->getMany([$url]);
        return $result[$url] ?? null;
    }

    /**
     * Batch lookup. Missing entries are omitted from the result map.
     *
     * @param string[] $urls
     * @return array<string, array{width:int,height:int}>
     */
    public function getMany(array $urls): array
    {
        if (empty($urls)) {
            return [];
        }

        $urls = array_values(array_unique($urls));
        $keyFor = fn (string $u) => self::CACHE_PREFIX . self::hash($u);

        $cacheKeys = array_map($keyFor, $urls);
        $cached = $this->cache->many($cacheKeys);

        $result = [];
        $missing = [];
        $missingHashes = [];

        foreach ($urls as $url) {
            $key = $keyFor($url);
            $value = $cached[$key] ?? null;

            if ($value === self::MISS_SENTINEL) {
                continue; // negative cache hit
            }
            if (is_array($value) && isset($value['width'], $value['height'])) {
                $result[$url] = ['width' => (int) $value['width'], 'height' => (int) $value['height']];
                continue;
            }

            $missing[] = $url;
            $missingHashes[self::hash($url)] = $url;
        }

        if (empty($missing)) {
            return $result;
        }

        $rows = $this->db->table(self::TABLE)
            ->select(['url_hash', 'width', 'height'])
            ->whereIn('url_hash', array_keys($missingHashes))
            ->get();

        $foundHashes = [];
        foreach ($rows as $row) {
            $url = $missingHashes[$row->url_hash] ?? null;
            if ($url === null || $row->width === null || $row->height === null) {
                continue;
            }
            $dim = ['width' => (int) $row->width, 'height' => (int) $row->height];
            $result[$url] = $dim;
            $this->cache->put($keyFor($url), $dim, self::CACHE_TTL_HIT);
            $foundHashes[$row->url_hash] = true;
        }

        // Cache negative results to avoid repeated DB hits for the same unknown URLs.
        foreach ($missingHashes as $hash => $url) {
            if (! isset($foundHashes[$hash])) {
                $this->cache->put($keyFor($url), self::MISS_SENTINEL, self::CACHE_TTL_MISS);
            }
        }

        return $result;
    }

    public function put(string $url, int $width, int $height): void
    {
        $hash = self::hash($url);
        $now = date('Y-m-d H:i:s');

        $this->db->table(self::TABLE)->updateOrInsert(
            ['url_hash' => $hash],
            ['url' => $url, 'width' => $width, 'height' => $height, 'updated_at' => $now],
        );

        $this->cache->put(
            self::CACHE_PREFIX . $hash,
            ['width' => $width, 'height' => $height],
            self::CACHE_TTL_HIT,
        );
    }

    private static function hash(string $url): string
    {
        return hash('sha256', $url);
    }
}
