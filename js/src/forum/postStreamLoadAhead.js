import { extend } from 'flarum/common/extend';

/**
 * Core only asks for the next page of posts once the end of the loaded stream
 * comes within 300px of the bottom of the viewport. On a phone that request is
 * still in flight when the site footer scrolls into view, so the arriving posts
 * shove the footer off screen — a single ~0.9 layout shift, which on its own is
 * enough to make the whole page view "poor" for CLS.
 *
 * Asking for the same page a viewport and a half earlier makes the stream grow
 * while everything below it is still off screen, and off-screen movement costs
 * nothing. No extra requests: the same pages are fetched, just sooner.
 * Reported as flarum/framework#5007.
 *
 * This one cannot clash with a fix upstream: it only ever adds a call that core
 * itself makes, it runs after core's own check, and `loadPage` increments
 * `pagesLoading` synchronously — so if core asks for the page first (which a
 * viewport-scaled look-ahead upstream would), we return before doing anything.
 * Worst case once core is fixed, this is a redundant no-op.
 */
const LOOK_AHEAD_VIEWPORTS = 1.5;

export default function extendPostStreamLoadAhead() {
    // PostStream is lazy-loaded, so extend it by module path rather than by import.
    extend('flarum/forum/components/PostStream', 'loadPostsIfNeeded', function () {
        const stream = this.stream;

        if (!stream || stream.paused || stream.pagesLoading) return;
        if (typeof stream.count !== 'function' || stream.visibleEnd >= stream.count()) return;

        const last = this.element && this.element.querySelector('.PostStream-item[data-index="' + (stream.visibleEnd - 1) + '"]');
        if (!last) return;

        const lookAhead = Math.max(300, window.innerHeight * LOOK_AHEAD_VIEWPORTS);

        if (last.getBoundingClientRect().bottom < window.innerHeight + lookAhead) {
            stream.loadNext();
        }
    });
}
