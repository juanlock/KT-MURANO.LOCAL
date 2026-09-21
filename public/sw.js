/*
 * Stocky POS Service Worker
 *
 * Goals:
 *   - Make the POS installable and bootable offline (app shell).
 *   - NEVER interfere with the existing offline-sync logic in
 *     resources/src/pos-compat/util.js (offlinePos + shadowStock) and
 *     resources/src/pages/pos/PosPage.vue (trySyncOfflineSales).
 *
 * Non-negotiable rules:
 *   1. Only GET requests are intercepted. All POST/PUT/PATCH/DELETE pass
 *      straight through to the network so axios errors still fire and the
 *      Vue-side offline queue (pos_offline_sales_v1) still works.
 *   2. /api/* and /sanctum/* are always network-only. No caching, no
 *      fallback. A stale /api/ping response would corrupt offline sync.
 *   3. Login/logout/password/setup/update/portal routes pass through.
 *   4. If anything goes wrong, flip KILL_SWITCH to true and redeploy.
 *      Existing clients will self-unregister on next load.
 */

// Bump this when deploying changes so old caches are purged.
const VERSION = 'stocky-pwa-v9';

// Configurable storefront base path, passed on the registration URL as
// ?store_base=/shop (or '/' when the store runs from the root domain).
// Old registrations without the param keep the legacy default.
const STORE_BASE = (() => {
  try {
    const raw = new URL(self.location.href).searchParams.get('store_base');
    if (!raw) return '/online_store';
    const clean = '/' + raw.replace(/^\/+|\/+$/g, '');
    return clean; // '/' means root-domain mode
  } catch (e) {
    return '/online_store';
  }
})();

// In root-domain mode the store owns every path except these system prefixes
// (mirrors store_reserved_paths() in app/Support/store_settings.php).
const NON_STORE_PREFIXES = [
  '/api', '/setup', '/update', '/system-update', '/password', '/login', '/logout',
  '/next', '/dashboard-next', '/portal', '/recruit', '/api-docs', '/csrf-token',
  '/session', '/invoice', '/customer-display', '/quickbooks', '/google-calendar',
  '/pwa', '/storage', '/vendor', '/images', '/css', '/js', '/fonts', '/pwa_images',
];
const STATIC_CACHE = `${VERSION}-static`;
const SHELL_CACHE = `${VERSION}-shell`;

// Emergency kill switch — set to true and redeploy to disable PWA globally.
const KILL_SWITCH = false;

// Offline fallback for navigation requests when network + cache both fail.
const OFFLINE_URL = '/offline.html';

// Precache only tiny, stable assets. Everything else is cached lazily on
// first successful GET.
const PRECACHE_URLS = [
  OFFLINE_URL,
  '/manifest.webmanifest',
  '/pwa_images/pwa-icon-192.png',
  '/pwa_images/pwa-icon-512.png',
];

// URL prefixes / patterns that must NEVER be cached or served from cache.
// These are the genuinely dynamic/sensitive paths: APIs, auth mutations,
// installation/update flows, realtime transports.
// Navigational shells for /portal, /customer-display, /online_store, etc. are
// intentionally NOT here so each surface can boot offline.
// /login, /logout, /password are network-only so a cached HTML shell cannot
// serve a stale CSRF token (was causing 419 on login submit).
const NETWORK_ONLY_PREFIXES = [
  '/api/',
  '/sanctum/',
  '/login',
  '/logout',
  '/password',
  '/setup',
  '/update',
  '/broadcasting/',
  '/livewire/',
];

// Vite's build manifest must never be served from cache: swWarm.js reads it to
// learn which files the CURRENT build produced. A cached copy from an earlier
// build makes the page ask the worker to warm chunk URLs that no longer exist,
// producing a burst of 404s whose HTTP referer is this script's URL.
const MANIFEST_PATH = '/js/.vite/manifest.json';

// Content-hashed output of the admin (Stocky Next) Vite build. Everything the
// build emits under /js/ lands in one of these; /js/portal/, /js/customer-display/
// and /js/storefront.* come from other builds and are deliberately excluded.
const BUILD_OUTPUT_RE = /^\/js\/(chunks\/|assets\/|app\.)/;

// Static asset patterns that are safe to cache (cache-first).
function isStaticAsset(url) {
  const p = url.pathname;
  if (p === MANIFEST_PATH || p.startsWith('/js/.vite/')) return false;
  return (
    p.startsWith('/js/') ||
    p.startsWith('/css/') ||
    p.startsWith('/fonts/') ||
    p.startsWith('/images/') ||
    p.startsWith('/flags/') ||
    p.startsWith('/audio/') ||
    p.startsWith('/vendor/') ||
    p.startsWith('/assets_setup/') ||
    p === '/favicon.ico' ||
    p === '/robots.txt'
  );
}

function isNetworkOnly(url) {
  const p = url.pathname;
  for (let i = 0; i < NETWORK_ONLY_PREFIXES.length; i++) {
    if (p === NETWORK_ONLY_PREFIXES[i] || p.startsWith(NETWORK_ONLY_PREFIXES[i])) {
      return true;
    }
  }
  return false;
}

self.addEventListener('install', (event) => {
  if (KILL_SWITCH) {
    self.skipWaiting();
    return;
  }
  event.waitUntil(
    (async () => {
      const cache = await caches.open(STATIC_CACHE);
      // Use individual adds so a single 404 doesn't abort the whole install.
      await Promise.all(
        PRECACHE_URLS.map((u) =>
          cache.add(new Request(u, { cache: 'reload' })).catch(() => null)
        )
      );
      await self.skipWaiting();
    })()
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      if (KILL_SWITCH) {
        // Self-destruct: remove caches and unregister.
        const keys = await caches.keys();
        await Promise.all(keys.map((k) => caches.delete(k)));
        await self.registration.unregister();
        const clientsList = await self.clients.matchAll({ type: 'window' });
        clientsList.forEach((c) => c.navigate(c.url));
        return;
      }
      // Purge old versioned caches.
      const keys = await caches.keys();
      await Promise.all(
        keys.map((k) => {
          if (k !== STATIC_CACHE && k !== SHELL_CACHE) {
            return caches.delete(k);
          }
          return null;
        })
      );
      await self.clients.claim();
    })()
  );
});

// Allow page code to ask the SW to skip waiting (used for update prompts).
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
  // Cache-warming request from the page: the SPA posts the full list of built
  // asset URLs (from /js/.vite/manifest.json) after boot, so lazily-loaded
  // chunks that were never visited still open offline. GET, same-origin,
  // static-path URLs only — everything else is ignored.
  if (event.data && event.data.type === 'CACHE_URLS' && Array.isArray(event.data.urls)) {
    const urls = event.data.urls.filter((u) => {
      if (typeof u !== 'string') return false;
      try {
        const parsed = new URL(u, self.location.origin);
        return parsed.origin === self.location.origin && isStaticAsset(parsed);
      } catch (e) {
        return false;
      }
    });
    if (!urls.length) return;
    event.waitUntil(
      (async () => {
        const cache = await caches.open(STATIC_CACHE);
        await Promise.all(
          urls.map(async (u) => {
            try {
              const request = new Request(u);
              if (await cache.match(request)) return;
              const resp = await fetch(request);
              if (resp && resp.ok && resp.status === 200 && resp.type === 'basic') {
                await cache.put(request, resp);
              }
            } catch (e) {
              // Best-effort: a missing chunk just stays uncached.
            }
          })
        );

        // The manifest the page just read is the authoritative file list for
        // the build that is live RIGHT NOW, so any build output still cached
        // but absent from it belongs to a previous build. Vite deletes those
        // files on rebuild, so keeping them means serving code that no longer
        // exists and re-requesting URLs that can only 404.
        const keep = new Set();
        urls.forEach((u) => {
          try { keep.add(new URL(u, self.location.origin).pathname); } catch (e) { /* skip */ }
        });
        let pruned = 0;
        for (const req of await cache.keys()) {
          let path;
          try { path = new URL(req.url).pathname; } catch (e) { continue; }
          if (BUILD_OUTPUT_RE.test(path) && !keep.has(path)) {
            await cache.delete(req);
            pruned++;
          }
        }
        if (pruned) {
          // The stored HTML shells reference the files just deleted, so
          // replaying one offline would boot a build that is gone — a blank
          // screen. Drop them; the next online navigation stores a fresh one.
          await caches.delete(SHELL_CACHE);
        }
      })()
    );
  }
});

self.addEventListener('fetch', (event) => {
  if (KILL_SWITCH) return;

  const request = event.request;

  // Rule 1: Only handle GET. Everything else bypasses the SW entirely.
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // Rule 2: Same-origin only. Let the browser handle cross-origin requests
  // (CDNs, analytics, etc.) normally.
  if (url.origin !== self.location.origin) return;

  // Rule 3: Never touch API / auth / dynamic routes.
  if (isNetworkOnly(url)) return;

  // Navigation requests: network-first with SPA-shell + offline fallback.
  const isNavigation =
    request.mode === 'navigate' ||
    (request.destination === 'document') ||
    (request.headers.get('accept') || '').includes('text/html');

  if (isNavigation) {
    event.respondWith(handleNavigation(request));
    return;
  }

  // Static assets: cache-first with background refresh.
  if (isStaticAsset(url)) {
    event.respondWith(handleStatic(request));
    return;
  }

  // Anything else: default to network, no caching.
});

// Map a navigation URL to a "surface" shell key so each PWA surface
// (POS/admin, portal, customer-display, store, login) is cached independently
// and a user landing on one surface offline doesn't accidentally see another
// surface's HTML shell.
function shellKeyFor(url) {
  const p = url.pathname;
  if (p === '/portal' || p.startsWith('/portal/')) return '/__shell_portal__';
  if (p === '/customer-display' || p.startsWith('/customer-display/')) return '/__shell_customer_display__';
  // The Vue 3 admin SPA is served by exactly ONE route: /next/{any?}. Every
  // other authenticated document (invoice prints, /pwa, setup screens, the
  // legacy dashboard) is a page in its own right — letting one of those
  // overwrite '/__shell_app__' means a later offline hit on /next/pos restores
  // an invoice, i.e. a blank app. Returning null = "cache no shell for this".
  if (p === '/next' || p.startsWith('/next/')) return '/__shell_app__';
  if (STORE_BASE === '/') {
    // Root-domain store: everything except system prefixes is the storefront.
    // (/customer/* hosts the store auth pages in this mode.)
    const isSystem = NON_STORE_PREFIXES.some(pre => p === pre || p.startsWith(pre + '/'));
    return isSystem ? null : '/__shell_store__';
  }
  if (p === STORE_BASE || p.startsWith(STORE_BASE + '/')) return '/__shell_store__';
  return null;
}

async function handleNavigation(request) {
  const url = new URL(request.url);
  const shellKey = shellKeyFor(url);
  try {
    const networkResponse = await fetch(request);
    if (
      networkResponse &&
      networkResponse.ok &&
      networkResponse.status === 200 &&
      networkResponse.type === 'basic' &&
      shellKey
    ) {
      const cache = await caches.open(SHELL_CACHE);
      cache.put(shellKey, networkResponse.clone()).catch(() => {});
    }
    return networkResponse;
  } catch (e) {
    // A page with no shell of its own (see shellKeyFor) must not be answered
    // with some other surface's HTML — go straight to the offline page.
    const shell = shellKey
      ? await (await caches.open(SHELL_CACHE)).match(shellKey)
      : null;
    if (shell) return shell;
    const staticCache = await caches.open(STATIC_CACHE);
    const offline = await staticCache.match(OFFLINE_URL);
    if (offline) return offline;
    return new Response(
      '<h1>Offline</h1><p>Application is offline and no cached shell is available yet.</p>',
      { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
    );
  }
}

async function handleStatic(request) {
  const cache = await caches.open(STATIC_CACHE);
  const cached = await cache.match(request);
  if (cached) {
    // Revalidate in background without blocking the response.
    fetch(request)
      .then((resp) => {
        if (resp && resp.ok && resp.status === 200 && resp.type === 'basic') {
          cache.put(request, resp.clone()).catch(() => {});
        }
      })
      .catch(() => {});
    return cached;
  }
  try {
    const resp = await fetch(request);
    if (resp && resp.ok && resp.status === 200 && resp.type === 'basic') {
      cache.put(request, resp.clone()).catch(() => {});
    }
    return resp;
  } catch (e) {
    // No cache, no network — let the request fail naturally.
    return Response.error();
  }
}
