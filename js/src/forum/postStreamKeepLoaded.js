import coreTrimMatches from './util/coreTrimMatches';

/**
 * When you scroll deep into a discussion, core unloads the posts two pages back
 * (`PostStreamState#_loadNext` moves `visibleStart` forward). Removing those
 * nodes re-lays out the stream, and the posts that are actually on screen are
 * re-created in the process — a layout shift of ~1.0, plus a visible jump that
 * costs the reader their place. Reported as flarum/framework#5008.
 *
 * Keep the posts instead. They are already loaded and rendered; leaving them in
 * the DOM costs nothing to fetch and removes the jump entirely. Above
 * MAX_LOADED_POSTS we hand back to core so that a very long thread still cannot
 * grow the DOM without bound.
 *
 * This replaces a core method, so it installs itself only while core's own
 * implementation still matches the one we copied — see `coreTrimMatches`. When
 * #5008 is fixed upstream, the check fails and this file quietly does nothing,
 * leaving core's fix in charge.
 */
const MAX_LOADED_POSTS = 200;

function loadNextWithoutUnloading() {
    const loadCount = this.constructor.loadCount;

    // Core's _loadNext without the block that unloads earlier posts.
    const start = this.visibleEnd;
    const end = (this.visibleEnd = this.sanitizeIndex(this.visibleEnd + loadCount));

    this.loadPage(start, end);
}

export default function extendPostStreamKeepLoaded() {
    // PostStreamState is lazy-loaded. The override has to land on the prototype
    // before a stream is constructed, because the constructor throttle-binds
    // `_loadNext` — an initializer is early enough.
    flarum.reg.onLoad('core', 'forum/states/PostStreamState', (PostStreamState) => {
        const proto = PostStreamState && PostStreamState.prototype;
        const original = proto && proto._loadNext;

        if (!coreTrimMatches(original, loadNextWithoutUnloading, PostStreamState.loadCount)) {
            // Core no longer trims the way we compensate for. Leave it alone.
            return;
        }

        proto._loadNext = function () {
            const loadCount = this.constructor.loadCount;

            // Too much in the DOM already — let core trim, jump and all.
            if (this.visibleEnd - this.visibleStart + loadCount > MAX_LOADED_POSTS) {
                return original.call(this);
            }

            return loadNextWithoutUnloading.call(this);
        };
    });
}
