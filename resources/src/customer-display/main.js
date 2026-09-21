/**
 * Customer Display — Vue 3 port of the legacy customer-display.js entry.
 * Standalone second-screen app: mounts CustomerDisplay into #customer-display,
 * receives cart updates over Laravel Echo (pos-cart.{screen}) with a polling
 * fallback to GET /api/pos/customer-display/last-cart.
 *
 * Kept deliberately light — no router, no Ant Design — same as the Vue 2 entry.
 * Uses the admin app's fetch wrapper (lib/http) instead of axios so no extra
 * dependency is pulled in.
 */
import { createApp } from 'vue';
import { createI18n } from 'vue-i18n';
import CustomerDisplay from './CustomerDisplay.vue';
import http from '../lib/http';

/**
 * Translations come from the same public endpoint the admin app uses:
 *   GET /api/translations/{locale} -> flat { key: value }
 * with the active language in localStorage under 'language' (shared key).
 * globalInjection keeps this.$t / this.$i18n working in the Options-API screen.
 */
function storedLocale() {
  try {
    return window.localStorage.getItem('language') || 'en';
  } catch (e) {
    return 'en';
  }
}

async function boot() {
  const locale = storedLocale();
  let messages = {};
  try {
    messages = (await http.get(`translations/${locale}`)) || {};
  } catch (e) {
    // No fallback — the same behaviour the Vue 2 loader had.
  }

  const i18n = createI18n({
    legacy: false,
    globalInjection: true,
    locale,
    fallbackLocale: 'en',
    messages: { [locale]: messages },
    missingWarn: false,
    fallbackWarn: false,
    silentTranslationWarn: true,
  });

  createApp(CustomerDisplay).use(i18n).mount('#customer-display');
}

boot();
