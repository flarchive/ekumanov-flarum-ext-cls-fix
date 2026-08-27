import { override } from 'flarum/common/extend';

/**
 * When you scroll deep into a discussion, core unloads the posts two pages back
 * (`PostStreamState#_loadNext` moves `visibleStart` forward). Removing those
 * nodes re-lays out the stream, and the posts that are actually on screen are
 * re-created in the process — a layout shift of ~1.0, plus a visible jump that
 * costs the reader their place.
 *
 * Keep the posts instead. They are already loaded and rendered; leaving them in
 * the DOM costs nothing to fetch and removes the jump entirely. Above
 * MAX_LOADED_POSTS we hand back to core so that a very long thread still cannot
 * grow the DOM without bound.
 */
const MAX_LOADED_POSTS = 200;

export default function extendPostStreamKeepLoaded() {
    // PostStreamState is lazy-loaded, so override it by module path. This has to
    // land on the prototype before a stream is constructed, because the
    // constructor throttle-binds `_loadNext` — an initializer is early enough.
    override('flarum/forum/states/PostStreamState', '_loadNext', function (original) {
        const loadCount = (this.constructor && this.constructor.loadCount) || 20;

        // Too much in the DOM already — let core trim, jump and all.
        if (this.visibleEnd - this.visibleStart + loadCount > MAX_LOADED_POSTS) {
            return original();
        }

        // Core's _loadNext without the block that unloads earlier posts.
        const start = this.visibleEnd;
        const end = (this.visibleEnd = this.sanitizeIndex(this.visibleEnd + loadCount));

        this.loadPage(start, end);
    });
}
