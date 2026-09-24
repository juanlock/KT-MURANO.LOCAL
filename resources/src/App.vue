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
import { hexToRgb } from './lib/colorUtils';

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
const theme = computed(() => {
  const [r, g, b] = hexToRgb(ui.primaryColor);
  return {
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
    components: {
      // Ant Design only fills the background for type="primary" buttons --
      // every other button (Back, Cancel, filters, row actions, dashed...)
      // renders on a plain white/solid card background by default, which is
      // what read as "background white" across the app. Fluent's own
      // secondary-button convention (Microsoft themes) is a light tint of
      // the brand colour instead, so default/dashed buttons get that here.
      //
      // ant-design-vue 4.2.6's Button has no dedicated component-token set
      // (its ComponentToken interface is empty) -- genDefaultButtonStyle()
      // reads the shared alias tokens colorBgContainer/colorBorder directly,
      // so those are what must be overridden. Doing it under
      // components.Button (not the global token above) scopes the override
      // to Button's own generated CSS only -- it does not leak into Card,
      // Table, Input, etc., which also read colorBgContainer globally.
      // Hover/active already read colorPrimaryHover/colorPrimaryActive,
      // which already follow ui.primaryColor, so no override needed there.
      Button: {
        colorBgContainer: `rgba(${r}, ${g}, ${b}, 0.10)`,
        colorBorder: `rgba(${r}, ${g}, ${b}, 0.35)`,
      },
      // The "Dark Menu" Sidebar Layout (and the sidebar while the whole app
      // is in dark mode) renders Ant's Layout Sider with theme="dark", which
      // reads colorBgHeader/colorBgTrigger -- fixed navy (#001529/#002140)
      // by default, regardless of the chosen Primary Color. Using the exact
      // Primary Color here (not a darkened shade of it) is what makes the
      // sidebar actually BE that colour; SidebarMenu.vue's watchEffect picks
      // the matching text colour (white/black) for contrast.
      Layout: {
        colorBgHeader: ui.primaryColor,
        colorBgTrigger: ui.primaryColor,
      },
      // NOTE: deliberately NOT overriding components.Menu here. Tried that
      // (colorItemBg etc.) to recolor the dark sidebar's actual item list --
      // ant-design-vue's genComponentStyleHook merges a components.Menu
      // override into the SHARED token *before* it branches into light vs.
      // dark theme styles, so the override silently painted every
      // LIGHT-themed <a-menu> in the app (dropdown menus, the Customizer
      // drawer's own Language list) with the dark background too, while
      // their text stayed black -- unreadable. Layout's colorBgHeader above
      // doesn't have this problem because Sider-light has its own separate,
      // more specific CSS rule (.ant-layout-sider-light) that wins outright;
      // Menu's dark/light split isn't a separate rule, it's the SAME shared
      // token diverging only via a hardcoded literal in the dark branch, so
      // there's no equivalent escape hatch. Fixed via plain scoped CSS
      // instead (see SidebarMenu.vue's --sb-bg custom property), which only
      // touches .ant-menu-dark and can't leak into light menus.
    },
  };
});
</script>
