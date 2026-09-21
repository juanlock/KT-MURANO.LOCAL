/**
 * Client Portal — Vue 3 SPA on the portal design system.
 *
 * Styling: Tabler (Bootstrap 5) is linked by portal.blade.php (LTR/RTL copy
 * made by vite.portal.config.js); this bundle adds the Tabler icon webfont,
 * the portal stylesheet (portal.css: the design-system rules followed by
 * a few portal-only overrides). Tabler's JS gives us Bootstrap
 * dropdowns for the topbar menus.
 *
 * i18n: messages come from resources/lang/{locale}/portal.php via
 * GET /api/portal/translations/{locale}; the starting locale is injected by
 * the server as window.__PORTAL_LOCALE__ (see ./i18n.js) and loaded BEFORE
 * mounting so the first paint is already translated.
 */
import '@tabler/core/dist/js/tabler.js';
import '@tabler/icons-webfont/dist/tabler-icons.css';
import './portal.css';

import { createApp } from 'vue';
import App from './App.vue';
import router from './router';
import { i18n, loadLocale, initialLocale, enumLabel } from './i18n';
import { initTheme } from './lib/theme';
import { setPage } from './lib/page';

async function boot() {
  initTheme();
  await loadLocale(initialLocale());

  const app = createApp(App);
  app.use(router);
  app.use(i18n);

  // Shared helpers for API enum values (statuses, statement types, job types):
  //   {{ statusLabel(inv.payment_status) }}   -> $t('status_paid') or the raw value
  //   {{ enumLabel('type_', e.type) }}        -> $t('type_invoice') or the raw value
  app.mixin({
    methods: {
      enumLabel(prefix, value) {
        return enumLabel(prefix, value);
      },
      statusLabel(value) {
        return enumLabel('status_', value);
      },
      // $t() returns the key itself when a translation is missing; fall back
      // to English so a stale locale file never shows raw keys.
      tr(key, fallback, params) {
        const v = params ? this.$t(key, params) : this.$t(key);
        return v && v !== key ? v : fallback;
      },
      // Views describe their header through pageMeta(); re-applied when the
      // locale changes and whenever a view calls applyPage() after loading.
      applyPage() {
        if (typeof this.pageMeta === 'function') setPage(this.pageMeta());
      },
    },
    created() {
      if (typeof this.pageMeta === 'function') {
        this.applyPage();
        this.$watch(() => i18n.global.locale.value, () => this.applyPage());
      }
    },
  });

  app.mount('#portal-app');
}

boot();
