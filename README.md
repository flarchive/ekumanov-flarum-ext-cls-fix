# CLS Fix for Flarum 2.0

Eliminates the page jumping that happens while images in posts are loading. No configuration needed — install it, and your forum stops bouncing.

## What problem does this solve?

You open a discussion. The text is there, you start reading… and a moment later an image finishes loading further up the page. Everything jumps down. You lose your place. You misclick.

That jump is called **Cumulative Layout Shift** (CLS). It is one of the most-complained-about user experience issues on Flarum forums, and it happens because the browser does not know how tall an image will be until the file actually arrives. Until then, it reserves zero space — then the image lands and everything below it is shoved out of the way.

Google also uses CLS as one of its three [Core Web Vitals](https://web.dev/articles/vitals) for ranking pages. A high CLS score hurts SEO. So fixing it is good for visitors *and* for search visibility.

This extension fixes CLS for inline post images by reserving the right amount of space *before* each image starts loading.

## Features

- **Zero layout shift on inline post images** — every image gets a correctly-sized placeholder before it loads.
- **Self-healing dimension cache** — the first visitor to a page reports the real image sizes back to your server; everyone after them gets a perfect placeholder.
- **No configuration** — install, enable, done. No admin settings, no permissions to grant.
- **No background workers required** — does not use Flarum's queue or scheduler. Works on any host, including shared hosting.
- **Works alongside FoF Upload, rich embeds, and other image extensions** — images that already declare their size are used as-is. Nothing is re-rendered.
- **Cloudflare-friendly** — client-side reporting is throttled (one request at a time, 150 ms apart) and self-suppresses on `429` / `503` so it cannot trigger rate limits.
- **Lazy-loaded images** — sets `loading="lazy"` on inline post images so the browser only fetches images near the viewport.
- **Works everywhere posts render** — discussions, notifications, mention previews, search snippets, and the composer preview.
- **Negative cache** — unknown URLs are sentinel-cached for 5 minutes so missing entries do not hammer the database.

## Requirements

- **Flarum 2.0** (not compatible with Flarum 1.x)
- **PHP 8.2+**
- A working cache driver — see *Cache driver recommendations* below.

### Cache driver recommendations

The extension reads the dimension cache on every post render that contains an image. The cost of that read depends on your Flarum cache driver:

| Cache driver | Recommended forum size | Why |
|---|---|---|
| **Redis** or **Memcached** | Any size, especially busy forums | One network round-trip per render, served from RAM. Effectively free. |
| **File** (Flarum's default) | Small to medium forums | Filesystem stat per key. Fine in absolute terms; can become measurable on hosts with slow disks under heavy concurrency. |
| **Array** / no cache | Not recommended | Forces a DB query on every render. Avoid for any production forum. |

Flarum sets up a real cache by default, so most forums fall into rows 1 or 2. If your forum is high-traffic and you're still on the file cache, this is a good moment to switch to Redis — both this extension and Flarum core will benefit.

## Installation

```bash
composer require ekumanov/flarum-ext-cls-fix
php flarum migrate
php flarum cache:clear
```

Then enable the extension in the admin panel under **Extensions > CLS Fix**.

That is the entire setup. There is nothing to configure.

## Updating

```bash
composer update ekumanov/flarum-ext-cls-fix
php flarum migrate
php flarum cache:clear
```

## How it works (engineering details)

This section is for the technically curious. You do not need to read it to use the extension.

### The core idea

CSS has had `aspect-ratio` for years, but Flarum's image BBCode does not emit width and height attributes, so the browser has nothing to compute the ratio from. The extension's job is to make sure every `<img>` is wrapped in a placeholder element whose height is reserved up front.

There are three sources of truth for an image's natural dimensions, in priority order:

1. **The post markup itself** — if the image was inserted with explicit width and height (e.g. by FoF Upload or a rich-embed extension), those values are used directly.
2. **A persistent server-side cache** — the extension keeps a small table (`cls_fix_image_dimensions`) keyed by `sha256(url)`, mapping known URLs to their natural pixel size.
3. **A 16/9 fallback** — if neither of the above applies, the placeholder uses a sensible default ratio while the image loads. This only ever happens once per image URL across the entire forum, because the client immediately reports back the real dimensions and the next visitor hits case 2.

### Render-time dimension injection

`InjectImageDimensions` is registered as a TextFormatter render callback. Every time Flarum renders post XML to HTML, this hook runs.

It opens with a fast path: `if (! str_contains($xml, '<IMG')) return $xml;`. Plain-text and image-less posts pay zero overhead beyond a substring scan.

For posts that do contain images, the hook parses the XML once with `DOMDocument`, runs an XPath query to find IMG tags missing dimensions:

```php
//IMG[not(@width) or not(@height) or @width="0" or @height="0"]
```

Note this **explicitly skips** images that already have valid width/height attrs. That means images inserted by other extensions (FoF Upload, rich embeds) are not touched at all — no DOM mutation, no cache lookup, no JS reporting.

For the candidates that need lookup, the hook collects all URLs and does a **single batch** call to the dimensions repository. That is one Redis MGET (or one DB `WHERE IN`) for the whole post, regardless of how many images it contains. Found dimensions are stamped onto the IMG nodes as `width` and `height` attributes; the modified DOM is then serialized back via `saveXML($dom->documentElement)`, which preserves Flarum's expected XML format (no XML declaration leaks through).

If nothing changes (no candidates, no cache hits), the original XML string is returned unchanged — no expensive serialization round-trip.

### The dimension cache

`ImageDimensions` wraps a single small table:

```sql
CREATE TABLE cls_fix_image_dimensions (
    url_hash CHAR(64) PRIMARY KEY,        -- sha256 hex of the URL
    url      TEXT,
    width    INT UNSIGNED,
    height   INT UNSIGNED,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

The primary key is the URL hash, not the URL itself. This keeps the index narrow and predictable even when URLs are very long (signed S3, redirect chains, image CDNs with huge query strings).

Around the table sits a two-tier cache (Flarum's `Illuminate\Contracts\Cache\Repository`):

- **Positive cache** — TTL 24 h. A hit returns `{width, height}` and skips the DB entirely.
- **Negative cache** — TTL 5 min. Stored as a sentinel string `'0'`. Prevents repeated DB lookups for URLs that genuinely are not in the table (typical on a freshly-installed forum or for hotlinked images that visitors have not yet loaded).

`getMany()` uses `$cache->many()` so a post with 40 images is still one cache round-trip. DB fallback is a single `WHERE url_hash IN (...)` query.

`put()` is an upsert (`updateOrInsert`), so the cache is **self-healing**: if an image's dimensions ever genuinely change, the next legitimate report overwrites the old entry. There is no garbage-collection task and nothing to prune.

### XSL template (the placeholder element)

`ConfigureFormatter` replaces the IMG XSL template with a wrapper:

```xsl
<span class="cls-img-wrap">
    <xsl:attribute name="style">
        <xsl:choose>
            <xsl:when test="@width and @height and number(@width) > 0 and number(@height) > 0">aspect-ratio: <xsl:value-of select="@width"/> / <xsl:value-of select="@height"/>;</xsl:when>
            <xsl:otherwise>aspect-ratio: var(--cls-img-ratio, 16 / 9);</xsl:otherwise>
        </xsl:choose>
    </xsl:attribute>
    <xsl:if test="not(@width) or not(@height) or number(@width) = 0 or number(@height) = 0">
        <xsl:attribute name="data-cls-needs-dims">1</xsl:attribute>
    </xsl:if>
    <img class="cls-img" loading="lazy">…</img>
</span>
```

Two things to notice:

1. The wrapper's inline `style` carries the placeholder ratio. If width and height attrs are present (either from the original markup or stamped on by `InjectImageDimensions`), it is exact. Otherwise it falls back to a CSS variable `--cls-img-ratio` whose default is `16 / 9`.
2. The `data-cls-needs-dims="1"` flag is only added when no dimensions are known. The client JS uses this flag as its trigger to report back — wrappers without the flag never produce a network request.

### Client-side reporter

`js/src/forum/index.js` watches for `.cls-img-wrap[data-cls-needs-dims="1"]` elements. When an image inside one finishes loading, it reads `naturalWidth` and `naturalHeight` and posts them to the server.

Several layers of throttling and back-pressure:

- **Single-flight queue**: only one `POST` is in flight at a time, with `150 ms` of spacing between requests. An image-heavy page does not fire 40 simultaneous requests.
- **Auto-suppression on rate limits**: if the server (or Cloudflare) responds with `429` or `503`, the queue is drained and no further reports are made for the rest of the page-view. The next page load resets state.
- **Per-URL deduplication**: a URL is reported at most once per page-view.
- **Guest skip**: guests cannot write to the cache, so the JS does not bother making the request for them.
- **MutationObserver**: handles wrappers that arrive after initial render (lazy-loaded post stream items, search results, composer preview).

### API endpoint

`POST /api/cls-fix/dimensions` accepts `{ url, width, height }` and writes through to the cache. It is:

- **Authenticated only** — guests get `403`. This is basic spam protection; visitors can still benefit from a populated cache passively, they just cannot write to it.
- **Strictly validated** — URL must be ≤ 2048 chars and `http(s)://`; dimensions must be `1..32767`. Bad input gets `422`. CSRF token required (uses Flarum's standard middleware).
- **Idempotent** — `updateOrInsert` on the URL hash. Repeat reports are harmless and self-healing if dimensions change.

### Security model

The reporting endpoint (`POST /api/cls-fix/dimensions`) is the only write path into the cache. Several layers of defence keep it safe from abuse.

**Trust boundary**: only authenticated, CSRF-validated browser sessions can write. Guests get `403`. This already eliminates anonymous floods and naive curl-based attacks. The remaining attacker is a logged-in user (or compromised account) running a script in their browser.

**Defences against that attacker**:

1. **Per-user rate limit** — at most **60 reports per rolling 60-second window** per user account, enforced server-side via the cache. A normal page load with up to 60 uncached images can fully seed itself in one visit; sustained traffic beyond that is rejected with `429`. The client JS auto-suppresses on `429`, so honest clients self-throttle without retry-storming.
2. **Tight dimension bounds** — width and height must be `1..16384` px. Anything wider or taller is rejected with `422`. The DB column allows up to 65535 but we cap earlier to keep absurd values out of the cache entirely.
3. **Aspect-ratio sanity** — ratios outside the range `1:20` to `20:1` are rejected. This rules out things like `16384 × 1` strips that would create visually broken placeholders without being caught by the dimension cap alone.
4. **First-write protection** — once a URL has cached dimensions, only one of these conditions allows them to change:
    - The new report matches the cached value within ±2 px (counted as a normal confirmation, accounts for `devicePixelRatio` rounding).
    - The reporter is an admin.
    
    Any other update is silently accepted (returns `204`) but **not** written. This means a single malicious account cannot flip an established cache entry to bad values. The silent response also doesn't reveal to a probing client which value is locked or who locked it.
5. **Auto-mute on repeat poisoning attempts** — every silent-rejected flip increments a per-user "strikes" counter (1 h rolling window). At **5 strikes**, the account's rate-limit budget is filled to a sentinel value for an hour, effectively muting them; the client JS auto-suppresses on the resulting `429`s, so honest pages still render fine for that user. Honest browsers almost never produce a strike thanks to the ±2 px tolerance, so false positives are essentially zero. Self-recovers after the window expires — no permanent state, nothing for an admin to manage.
6. **No image fetch** — the server never downloads or probes the URL. There is no SSRF surface; the image stays a strict client-side concern.
7. **No effect on other extensions' images** — the render hook only injects dims into IMG tags that are missing them. Images already declaring width/height (FoF Upload, rich embeds) are skipped entirely, so cache-poisoning a hotlink to such an image has zero render-time effect.
8. **Hash-based primary key** — the table is keyed by `sha256(url)`, never by raw URL string. There is no SQL-injectable column for the writer to influence beyond the bounds-checked inputs above, and no XSS surface (the URL is never echoed back).

**Known residual risk**: a logged-in attacker who is the **first** ever reporter for a brand-new image URL can seed a wrong value. From that point on, first-write protection prevents anyone but an admin from overriding it, and the auto-mute catches anyone who tries repeatedly. The unprotected window is therefore narrow — only "race-to-poison-first" — and is bounded by the rate limit (max 60 unique URLs per minute per account). In practice an admin who notices a wrong placeholder can simply load the image themselves to overwrite the entry, or a future version may add a CLI flush command for batch cleanup.

### What this does *not* do

- It does not download or probe images server-side. Dimensions only enter the cache via legitimate client reports from authenticated users who actually loaded the image in their browser.
- It does not alter the image file or proxy the request. The browser still fetches the image directly from its origin.
- It does not require a queue worker, a cron job, or any background process.
- It does not modify or rewrite images that already declare their size in markup.

### Performance summary

| Path | Cost |
|---|---|
| Render of an image-less post | One `str_contains` substring scan. Negligible. |
| Render of a post with images, all cached | One cache MGET. Microseconds. |
| Render of a post with new images | One cache MGET + one DB `WHERE IN`. Milliseconds. |
| Client report (per new image, per first-time visitor) | One `POST`, throttled. Self-stops if origin pushes back. |

Worst case (a forum-wide cold start with hundreds of unique image URLs) is bounded: each URL produces at most one POST per page-view per visitor, spaced 150 ms apart, suppressed on rate limit. The cache fills naturally as visitors browse, and steady-state cost drops to "one MGET per render."

## Links

- [Packagist](https://packagist.org/packages/ekumanov/flarum-ext-cls-fix)
- [Report Issues](https://github.com/ekumanov/flarum-ext-cls-fix/issues)

## License

MIT
