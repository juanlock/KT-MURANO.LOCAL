import { createApp, defineAsyncComponent } from 'vue';
import { createPinia } from 'pinia';
import Antd from 'ant-design-vue';

import 'ant-design-vue/dist/reset.css';
// Icon font only (.bi-* classes) — category icons are stored as these classes.
import 'bootstrap-icons/font/bootstrap-icons.css';
import './assets/theme.css';

import App from './App.vue';
import router from './router';
import { goBack } from './lib/goBack';
import PermLink from './components/PermLink.vue';
import { CompanyDatePicker, CompanyRangePicker } from './components/CompanyDatePicker';
import { i18n, loadLocale, storedLocale } from './i18n';
// Arms the beforeinstallprompt listener before the topbar exists (see file).
import './lib/pwaInstall';
import { warmOfflineAssets } from './lib/swWarm';

// Renderer-level crashes never reach app.config.errorHandler, so catch them here.
// "ResizeObserver loop ..." is a benign browser notice (Ant's table/layout use
// ResizeObserver); it breaks nothing and would otherwise drown real errors.
const BENIGN = /^ResizeObserver loop/;
window.addEventListener('error', e => {
    if (BENIGN.test(e.message || '')) return;
    console.error('[murano] uncaught:', e.message, '\n', e.error && e.error.stack);
});
window.addEventListener('unhandledrejection', e => {
    console.error('[murano] unhandled promise rejection:', e.reason);
});

// After a rebuild the old hashed chunks are deleted, so a tab loaded before the
// deploy 404s on its first lazy import (typically an Export button or a route
// change) and the action just dies. Route chunks are handled by router.onError,
// which reloads AT THE TARGET route (see router/index.js) — reloading in place
// here made navigation look stuck on the page being left. Give the router a
// beat to redirect; only non-route imports (Export buttons, charts) fall
// through to the in-place reload. The guard flag stops a reload loop if the
// new page still can't fetch chunks.
window.addEventListener('vite:preloadError', e => {
    e.preventDefault();
    setTimeout(() => {
        if (window.__muranoChunkRedirect) return;
        if (sessionStorage.getItem('murano-chunk-reload') === '1') return;
        sessionStorage.setItem('murano-chunk-reload', '1');
        window.location.reload();
    }, 100);
});

const app = createApp(App);

// Name the failing component and print the message/stack as strings — object
// logs collapse in the console and hide exactly what we need.
app.config.errorHandler = (err, instance, info) => {
    const type = instance?.$?.type || instance?.$options || {};
    const name = type.name || type.__name || type.__file || 'unknown component';
    const chain = [];
    for (let p = instance?.$?.parent; p; p = p.parent) {
        const pt = p.type || {};
        chain.push(pt.name || pt.__name || pt.__file || '?');
    }
    console.error(
        `[murano] error in <${name}> (${info})`
        + `\n  message: ${err?.message}`
        + `\n  parents: ${chain.join(' < ') || '(root)'}`
        + `\n  stack:\n${err?.stack || '(none)'}`
    );
};

app.use(createPinia());
app.use(i18n);
app.use(router);
// Details pages' Back: to the page the user came from (lib/goBack.js).
app.config.globalProperties.$goBack = fallback => goBack(router, fallback);
// <PermLink permission="Sales_view">: a link only when the role may open the page, else plain text.
app.component('PermLink', PermLink);
app.use(Antd);
// Dates in the company's format (Peru DD-MM-YYYY, USA MM-DD-YYYY) that can be
// typed: replaces Ant's <a-date-picker> / <a-range-picker> everywhere (2026-10-25).
app.component('ADatePicker', CompanyDatePicker);
app.component('ARangePicker', CompanyRangePicker);
// Same global <apexchart> tag the plugin would register, but async: apexcharts
// (~550 kB min) stays out of the entry and loads as its own cached chunk only
// when a chart page renders.
app.component('apexchart', defineAsyncComponent(() => import('vue3-apexcharts')));

// Mount only when the first paint can be complete: translations loaded AND the
// initial route fully resolved (auth check + page chunk). Until then the boot
// loader in next.blade.php stays on screen — mounting replaces it. Without
// router.isReady() the app mounts into a blank shell while the guard awaits
// auth, which reads as a broken refresh.
Promise.allSettled([loadLocale(storedLocale()), router.isReady()]).then(() => {
    // Booted fine — arm the chunk-failure reload again for the next deploy.
    sessionStorage.removeItem('murano-chunk-reload');
    app.mount('#vue3-app');
    // Pre-cache every built chunk in the service worker (delayed, best-effort)
    // so an offline reload can open screens that were never visited.
    warmOfflineAssets();
});
