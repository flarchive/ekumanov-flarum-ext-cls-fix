/**
 * Decides whether it is safe for us to replace core's `PostStreamState#_loadNext`.
 *
 * We replace that method with a copy of core's own implementation minus the block
 * that unloads earlier posts (flarum/framework#5008). A copy of someone else's
 * method is only safe while the original still does what the copy does — the day
 * core changes `_loadNext`, a silent replacement would throw their fix away and
 * keep our stale semantics.
 *
 * So instead of trusting a version number, run both implementations against
 * identical stub states and compare what they did. We install our override only
 * when the *only* difference is the trimming we mean to remove. Anything else —
 * a different page requested, a different `visibleEnd`, a field core writes that
 * we do not, a method shape we cannot call — means core has moved on, and we
 * stand down and leave core alone.
 *
 * @param {Function} coreLoadNext The pristine `_loadNext` from core's prototype.
 * @param {Function} ourLoadNext Our replacement, called the same way.
 * @param {number} loadCount Core's `PostStreamState.loadCount`.
 * @returns {boolean} true when our override is a safe substitute.
 */
export default function coreTrimMatches(coreLoadNext, ourLoadNext, loadCount) {
    if (typeof coreLoadNext !== 'function' || !Number.isInteger(loadCount) || loadCount < 1) return false;

    const makeState = () => {
        const pagesRequested = [];

        return {
            // Three pages in, which is where core's trim kicks in.
            visibleStart: 0,
            visibleEnd: loadCount * 3,
            pagesLoading: 1,
            loadPageTimeouts: {},
            sanitizeIndex: (index) => index,
            loadPage: (...args) => pagesRequested.push(args),
            pagesRequested,
            // Our implementation reads the page size off the class; core reads
            // its own static. Give both stubs the same number.
            constructor: { loadCount },
        };
    };

    const core = makeState();
    const ours = makeState();

    try {
        coreLoadNext.call(core);
        ourLoadNext.call(ours);
    } catch (e) {
        // Either implementation needs something our stub does not model: assume
        // core has changed shape and keep our hands off it.
        return false;
    }

    // Core must have done the one thing we are removing...
    if (!(core.visibleStart > 0)) return false;
    // ...and we must not have done it.
    if (ours.visibleStart !== 0) return false;
    // Everything else has to match: same page requested, same window end.
    if (core.visibleEnd !== ours.visibleEnd) return false;
    if (JSON.stringify(core.pagesRequested) !== JSON.stringify(ours.pagesRequested)) return false;

    // And core must not touch any state we would fail to maintain.
    const coreKeys = Object.keys(core).sort().join(',');
    const ourKeys = Object.keys(ours).sort().join(',');

    return coreKeys === ourKeys;
}
