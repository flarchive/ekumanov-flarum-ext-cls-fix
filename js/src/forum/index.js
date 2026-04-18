import app from 'flarum/forum/app';

app.initializers.add('ekumanov/flarum-ext-cls-fix', () => {
    const PROCESSED = 'clsProcessed';
    const reported = new Set();
    const queue = [];
    let inFlight = false;
    let suppressed = false; // becomes true on 429/503 — back off until next page load

    function applyRatio(wrap, img) {
        const w = img.naturalWidth;
        const h = img.naturalHeight;
        if (w > 0 && h > 0) {
            wrap.style.setProperty('--cls-img-ratio', w + ' / ' + h);
            maybeReportDims(wrap, img, w, h);
        }
    }

    function maybeReportDims(wrap, img, w, h) {
        if (suppressed) return;
        if (wrap.dataset.clsNeedsDims !== '1') return;
        const url = img.currentSrc || img.src;
        if (!url || reported.has(url)) return;
        if (!app.session || !app.session.user) return; // guest

        reported.add(url);
        queue.push({ url: url, width: w, height: h });
        drainQueue();
    }

    function drainQueue() {
        if (inFlight || suppressed || queue.length === 0) return;
        const item = queue.shift();
        inFlight = true;

        const apiUrl = (app.forum && app.forum.attribute && app.forum.attribute('apiUrl')) || '/api';
        const csrf = (app.session && app.session.csrfToken) || '';

        fetch(apiUrl + '/cls-fix/dimensions', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify(item),
        }).then((res) => {
            if (res.status === 429 || res.status === 503) {
                // Origin or edge is throttling us — stop hammering for the rest of the session.
                suppressed = true;
                queue.length = 0;
            }
        }).catch(() => {
            reported.delete(item.url); // allow retry on next event
        }).finally(() => {
            inFlight = false;
            // Small spacing between posts so we don't burst the API on image-heavy pages.
            if (queue.length > 0) setTimeout(drainQueue, 150);
        });
    }

    function processWrap(wrap) {
        if (wrap.dataset[PROCESSED]) return;
        wrap.dataset[PROCESSED] = '1';
        const img = wrap.querySelector('img.cls-img');
        if (!img) return;

        if (img.complete && img.naturalWidth > 0) {
            applyRatio(wrap, img);
            return;
        }

        const onLoad = () => applyRatio(wrap, img);
        const onError = () => wrap.classList.add('cls-img-wrap--error');
        img.addEventListener('load', onLoad, { once: true });
        img.addEventListener('error', onError, { once: true });
    }

    function processSubtree(root) {
        if (root.nodeType !== 1) return;
        if (root.classList && root.classList.contains('cls-img-wrap')) {
            processWrap(root);
        }
        if (root.querySelectorAll) {
            root.querySelectorAll('.cls-img-wrap').forEach(processWrap);
        }
    }

    processSubtree(document.body);

    new MutationObserver((mutations) => {
        for (const m of mutations) {
            m.addedNodes.forEach(processSubtree);
        }
    }).observe(document.documentElement, { childList: true, subtree: true });
});
