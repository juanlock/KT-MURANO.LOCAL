/**
 * Offline asset warming.
 *
 * The service worker (public/sw.js) caches static assets lazily — only files
 * the browser has actually requested. Lazily-loaded route chunks the cashier
 * never visited would therefore 404 offline. After boot, this module reads the
 * Vite build manifest and asks the SW (message {type: 'CACHE_URLS'}) to
 * pre-cache every built JS/CSS/asset file, so a full offline reload can load
 * ANY screen, not just previously visited ones.
 *
 * Best-effort by design: no SW, offline, or a failed fetch just means the
 * cache stays as it was.
 */

const MANIFEST_URL = '/js/.vite/manifest.json';
// Delay so warming never competes with the app's own boot requests.
const WARM_DELAY_MS = 8000;

let warmed = false;

export function warmOfflineAssets() {
    if (warmed) return;
    warmed = true;
    try {
        if (typeof window === 'undefined' || !('serviceWorker' in navigator)) return;
        window.setTimeout(run, WARM_DELAY_MS);
    } catch (e) { /* never break the app over cache warming */ }
}

async function run() {
    try {
        if (window.navigator && window.navigator.onLine === false) return;
        // navigator.serviceWorker.ready resolves only once a SW is active for
        // this scope; controller can still be null on the very first visit
        // (page loaded before the SW claimed it) — warm on the next boot then.
        await navigator.serviceWorker.ready;
        const controller = navigator.serviceWorker.controller;
        if (!controller) return;

        const res = await fetch(MANIFEST_URL, {
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!res.ok) return;
        const manifest = await res.json();
        if (!manifest || typeof manifest !== 'object') return;

        // Manifest paths are relative to the build outDir (public/js).
        const urls = new Set();
        Object.values(manifest).forEach((entry) => {
            if (!entry || typeof entry !== 'object') return;
            if (entry.file) urls.add('/js/' + entry.file);
            (entry.css || []).forEach((f) => urls.add('/js/' + f));
            (entry.assets || []).forEach((f) => urls.add('/js/' + f));
        });
        if (urls.size) {
            controller.postMessage({ type: 'CACHE_URLS', urls: Array.from(urls) });
        }
    } catch (e) { /* best-effort */ }
}
