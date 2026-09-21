<template>
  <a-config-provider :theme="theme" :direction="ui.direction" :locale="antLocale">
    <router-view />
  </a-config-provider>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
import { theme as antTheme } from 'ant-design-vue';
import enUS from 'ant-design-vue/es/locale/en_US';
import frFR from 'ant-design-vue/es/locale/fr_FR';
import esES from 'ant-design-vue/es/locale/es_ES';
import arEG from 'ant-design-vue/es/locale/ar_EG';
import { useUiStore } from './stores/ui';

const ui = useUiStore();
ui.initTheme();

// ---------------------------------------------------------------- keepalive
// The SPA authenticates API calls with Passport's laravel_token cookie, whose
// expiry (= session.lifetime, configurable in System Settings → Security) is
// only refreshed by requests through the 'web' middleware group — i.e. full
// page loads. Without this ping an actively-working cashier is logged out a
// fixed interval after the page was opened. Pinging /session/keepalive while
// the user is active slides both the web session and the Passport cookie
// forward, turning the timeout into a true inactivity timeout.
const KEEPALIVE_EVERY_MS = 10 * 60 * 1000; // ping cadence
let lastActivityAt = Date.now();
let keepaliveTimer = null;
const markActivity = () => { lastActivityAt = Date.now(); };

onMounted(() => {
  window.addEventListener('pointerdown', markActivity, { passive: true });
  window.addEventListener('keydown', markActivity, { passive: true });
  keepaliveTimer = setInterval(() => {
    // Only ping when the user actually did something since the last tick —
    // an abandoned terminal should still time out as configured.
    if (Date.now() - lastActivityAt > KEEPALIVE_EVERY_MS) return;
    fetch('/session/keepalive', { credentials: 'same-origin', cache: 'no-store' }).catch(() => {});
  }, KEEPALIVE_EVERY_MS);
});

onUnmounted(() => {
  window.removeEventListener('pointerdown', markActivity);
  window.removeEventListener('keydown', markActivity);
  if (keepaliveTimer) clearInterval(keepaliveTimer);
});

const ANT_LOCALES = { en: enUS, fr: frFR, es: esES, ar: arEG };
const antLocale = computed(() => ANT_LOCALES[ui.locale] || enUS);

// Ant's own dark algorithm drives every component; no custom dark CSS needed.
const theme = computed(() => ({
  algorithm: ui.dark ? antTheme.darkAlgorithm : antTheme.defaultAlgorithm,
  token: {
    // Driven by the customizer (persisted in the ui store); defaults to brand.
    colorPrimary: ui.primaryColor,
    borderRadius: 10,
    // Light app background behind the content; dark mode keeps its own.
    ...(ui.dark ? {} : { colorBgLayout: '#fafafa' }),
    // No fontFamily override: `inherit` made every component (tables included)
    // pick up the page font instead of Ant's own stack. Leaving the token unset
    // means tables/forms/menus use Ant Design's default typography.
  },
}));
</script>
