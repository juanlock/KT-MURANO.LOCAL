/**
 * Minimal top progress bar for route navigations (auth check + lazy chunk +
 * whatever the guard awaits) — the same feedback legacy got from NProgress,
 * without the dependency. Deliberately stateless: a redirected navigation
 * fires beforeEach twice but afterEach once, so start() simply (re)starts the
 * bar and done() finishes it whenever the final navigation settles.
 */

import { ref } from 'vue';

let bar = null;
let trickleTimer = null;

/**
 * Reactive "route is navigating" flag the AdminLayout reads to cover the whole
 * content area with a spinner (sidebar/header stay put) during SPA navigation.
 * ON IMMEDIATELY so the old page — breadcrumb, title, everything — is hidden
 * the instant a route change starts, and the spinner shows for the whole move.
 * An instant cached navigation toggles it off before the browser paints, so
 * nothing flashes on those.
 */
export const navLoading = ref(false);

function ensureBar() {
    if (bar) return bar;
    bar = document.createElement('div');
    bar.setAttribute('role', 'progressbar');
    Object.assign(bar.style, {
        position: 'fixed',
        top: '0',
        left: '0',
        height: '2px',
        width: '0%',
        background: '#6d28d9',
        boxShadow: '0 0 6px rgba(109, 40, 217, 0.6)',
        zIndex: '9999',
        opacity: '0',
        transition: 'width 0.25s ease, opacity 0.3s ease',
        pointerEvents: 'none',
    });
    document.body.appendChild(bar);
    return bar;
}

export function start() {
    const el = ensureBar();
    clearInterval(trickleTimer);
    el.style.opacity = '1';
    if ((parseFloat(el.style.width) || 0) < 10) el.style.width = '10%';
    // Trickle toward 90% so long loads still look alive.
    trickleTimer = setInterval(() => {
        const current = parseFloat(el.style.width) || 0;
        if (current < 90) el.style.width = `${current + (90 - current) * 0.08}%`;
    }, 200);

    // Cover the content and show the spinner immediately, for the whole
    // navigation. On an instant cached nav done() fires before a paint, so the
    // cover toggles off without a visible flash.
    navLoading.value = true;
}

export function done() {
    navLoading.value = false;
    if (!bar) return;
    clearInterval(trickleTimer);
    trickleTimer = null;
    bar.style.width = '100%';
    setTimeout(() => {
        // A new navigation may have started meanwhile — leave its bar alone.
        if (!bar || trickleTimer) return;
        bar.style.opacity = '0';
        setTimeout(() => {
            if (bar && !trickleTimer) bar.style.width = '0%';
        }, 300);
    }, 150);
}
