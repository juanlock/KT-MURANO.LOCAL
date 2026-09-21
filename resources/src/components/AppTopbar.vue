<template>
  <div class="topbar-inner">
    <!-- Left: sidebar toggle -->
    <a-button type="text" class="icon-btn" aria-label="Toggle menu" @click="$emit('toggle-sidebar')">
      <template #icon>
        <MenuUnfoldOutlined v-if="collapsed" />
        <MenuFoldOutlined v-else />
      </template>
    </a-button>

    <!-- Right: actions -->
    <a-space :size="4">
      <!-- POS -->
      <a-button v-if="auth.can('Pos_view')" type="primary" class="pos-btn" @click="$router.push('/pos')">
        <template #icon><CalculatorOutlined /></template>
        <span class="hide-sm">POS</span>
      </a-button>

      <!-- Install PWA — only while the browser is offering installation -->
      <a-tooltip v-if="pwaCanInstall" :title="$t('Install_App')">
        <a-button type="text" class="icon-btn install-btn" @click="promptInstall">
          <template #icon><DownloadOutlined /></template>
        </a-button>
      </a-tooltip>

      <!-- Today's summary -->
      <a-tooltip :title="$t('Todays_Summary')">
        <a-button type="text" class="icon-btn" @click="summaryOpen = true">
          <template #icon><PieChartOutlined /></template>
        </a-button>
      </a-tooltip>

      <!-- Dark mode -->
      <a-tooltip :title="ui.dark ? 'Light Mode' : 'Dark Mode'">
        <a-button type="text" class="icon-btn" @click="ui.toggleDark()">
          <template #icon>
            <BulbFilled v-if="ui.dark" />
            <BulbOutlined v-else />
          </template>
        </a-button>
      </a-tooltip>

      <!-- Fullscreen (hidden on phones — not meaningful there) -->
      <a-tooltip title="Fullscreen">
        <a-button type="text" class="icon-btn fullscreen-btn" @click="toggleFullScreen">
          <template #icon>
            <FullscreenExitOutlined v-if="isFullscreen" />
            <FullscreenOutlined v-else />
          </template>
        </a-button>
      </a-tooltip>

      <!-- Language -->
      <a-dropdown v-if="auth.showLanguage" placement="bottomRight">
        <a-button type="text" class="icon-btn"><template #icon><GlobalOutlined /></template></a-button>
        <template #overlay>
          <a-menu :selected-keys="[ui.locale]" class="lang-menu" @click="({ key }) => ui.setLocale(key)">
            <a-menu-item v-for="l in languages" :key="l.value">
              <span class="lang-row">
                <img v-if="l.image" :src="l.image" :alt="l.label" class="flag" />
                <span v-else>{{ l.flag }}</span>
                <span>{{ l.label }}</span>
              </span>
            </a-menu-item>
          </a-menu>
        </template>
      </a-dropdown>

      <!-- Notifications -->
      <a-dropdown v-model:open="notifOpen" placement="bottomRight" :trigger="['click']">
        <!-- Badge wraps the icon as button CONTENT, not via the #icon slot:
             Ant sizes that slot for a bare icon and clips the count bubble.
             count 0 renders nothing, matching legacy's v-if on the badge. -->
        <a-button type="text" class="icon-btn">
          <a-badge :count="pending.total" size="small" :offset="[4, -2]">
            <BellOutlined class="bell" />
          </a-badge>
        </a-button>
        <template #overlay>
          <div class="notif-menu" :class="{ 'notif-menu--dark': ui.dark }">
            <div class="notif-head">
              <span class="notif-title">{{ $t('Notifications') }}</span>
              <span v-if="pending.total" class="notif-pill">{{ pending.total }}</span>
            </div>

            <div class="notif-list">
              <button
                v-for="item in pending.items" :key="item.key"
                type="button" class="notif-item" @click="goPending(item)"
              >
                <span class="notif-ic" :class="{ 'notif-ic--warn': item.severity === 'warning' }">
                  <WarningFilled v-if="item.severity === 'warning'" />
                  <BellOutlined v-else />
                </span>
                <span class="notif-body">
                  <span class="notif-item-title">{{ item.count }} {{ $t(item.label_key) }}</span>
                  <span class="notif-item-sub">{{ $t('Waiting_for_action') }}</span>
                </span>
                <RightOutlined class="notif-chev" />
              </button>

              <div v-if="!pending.items.length" class="notif-empty">
                <span class="notif-empty-ic"><BellOutlined /></span>
                <span class="notif-empty-title">{{ $t('No_notifications') }}</span>
                <span class="notif-empty-sub">{{ $t('You_are_all_caught_up') }}</span>
              </div>
            </div>
          </div>
        </template>
      </a-dropdown>

      <!-- User -->
      <span class="topbar-divider" aria-hidden="true"></span>
      <a-dropdown v-model:open="userMenuOpen" placement="bottomRight" :trigger="['click']">
        <a-button type="text" class="user-btn">
          <a-space :size="8">
            <a-avatar :size="28" :src="auth.avatarUrl" class="user-avatar" />
            <span class="hide-sm user-name">{{ auth.username || 'User' }}</span>
            <DownOutlined class="user-caret" :class="{ open: userMenuOpen }" />
          </a-space>
        </a-button>
        <template #overlay>
          <div class="account-menu" :class="{ 'account-menu--dark': ui.dark }">
            <div class="acct-header">
              <a-avatar :size="40" :src="auth.avatarUrl" />
              <div class="acct-id">
                <div class="acct-name">{{ auth.username || 'User' }}</div>
                <div v-if="maskedEmail" class="acct-email">{{ maskedEmail }}</div>
              </div>
            </div>

            <div class="acct-sep" role="separator"></div>

            <button type="button" class="acct-item" @click="go('/profile')">
              <UserOutlined class="acct-ic" />
              <span>{{ $t('profil') }}</span>
            </button>
            <button
              v-if="auth.can('setting_system')" type="button" class="acct-item"
              @click="go('/settings/system')"
            >
              <SettingOutlined class="acct-ic" />
              <span>{{ $t('Settings') }}</span>
            </button>
            <button v-if="auth.can('Pos_view')" type="button" class="acct-item" @click="go('/pos')">
              <DesktopOutlined class="acct-ic" />
              <span>Open POS</span>
            </button>

            <div class="acct-sep" role="separator"></div>

            <div class="acct-label">Appearance</div>
            <div class="theme-seg" role="radiogroup" aria-label="Appearance">
              <button
                type="button" role="radio" :aria-checked="ui.themeMode === 'light'"
                :class="{ active: ui.themeMode === 'light' }" @click="ui.setThemeMode('light')"
              >
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                  <circle cx="12" cy="12" r="4" />
                  <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
                </svg>
                Light
              </button>
              <button
                type="button" role="radio" :aria-checked="ui.themeMode === 'dark'"
                :class="{ active: ui.themeMode === 'dark' }" @click="ui.setThemeMode('dark')"
              >
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true">
                  <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
                </svg>
                Dark
              </button>
              <button
                type="button" role="radio" :aria-checked="ui.themeMode === 'auto'"
                :class="{ active: ui.themeMode === 'auto' }" @click="ui.setThemeMode('auto')"
              >
                <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
                  <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2" />
                  <path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor" />
                </svg>
                Auto
              </button>
            </div>

            <div class="acct-sep" role="separator"></div>

            <button type="button" class="acct-item acct-danger" @click="signOut">
              <LogoutOutlined class="acct-ic" />
              <span>{{ $t('logout') }}</span>
            </button>
          </div>
        </template>
      </a-dropdown>
    </a-space>

    <!-- Today's summary drawer -->
    <TodaySummary :open="summaryOpen" @close="summaryOpen = false" />
  </div>
</template>

<script setup>
/**
 * Topbar — the Ant-native port of legacy VerticalTopNav.vue, feature for
 * feature: sidebar toggle, POS shortcut (Pos_view), dark mode, fullscreen,
 * language picker, quantity-alert bell and the user menu.
 *
 * Two details carried over from legacy rather than reinvented:
 * - the language list comes from GET /languages ([{name, locale, flag}]) with
 *   flag IMAGES under /flags/, and the whole picker disappears when the user's
 *   show_language flag is off. SUPPORTED_LOCALES (emoji) is the fallback when
 *   that endpoint is unavailable, so the picker never renders empty.
 * - the bell only links through when there are alerts AND the user holds
 *   Reports_quantity_alerts, matching legacy's nested guards.
 *
 * Legacy reloaded the page after a language change (a Vue 2 workaround);
 * loadLocale() fetches the new bundle reactively, so no reload here.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import {
  MenuFoldOutlined, MenuUnfoldOutlined, CalculatorOutlined, BulbOutlined,
  BulbFilled, FullscreenOutlined, FullscreenExitOutlined, GlobalOutlined,
  BellOutlined, DownOutlined, PieChartOutlined, UserOutlined, SettingOutlined,
  DesktopOutlined, LogoutOutlined, WarningFilled, RightOutlined,
  DownloadOutlined,
} from '@ant-design/icons-vue';
import TodaySummary from './TodaySummary.vue';
import { canInstall as pwaCanInstall, promptInstall } from '../lib/pwaInstall';
import { useAuthStore } from '../stores/auth';
import { usePendingWorkStore } from '../stores/pendingWork';
import { useUiStore } from '../stores/ui';
import { SUPPORTED_LOCALES } from '../i18n';
import http from '../lib/http';

defineProps({
  collapsed: { type: Boolean, default: false },
});
const emit = defineEmits(['toggle-sidebar', 'logout']);

const auth = useAuthStore();
const ui = useUiStore();
const pending = usePendingWorkStore();
const router = useRouter();

const summaryOpen = ref(false);

// ---------------- notifications ----------------

const notifOpen = ref(false);

// The bell now lists every pending process, not just stock alerts. The
// server already filters each entry by the viewer's permission, so there is
// no second guard here.
// A custom panel (not a-menu) doesn't auto-close on click, so close it here.
function goPending(item) {
  notifOpen.value = false;
  if (item?.route) router.push(item.route);
}

// Refresh when the panel is opened, so counts are current the moment they
// are looked at rather than whenever the last poll happened.
watch(notifOpen, open => { if (open) pending.fetch(true); });

// ---------------- account menu ----------------

const userMenuOpen = ref(false);

// "a••••@gmail.com" — enough to recognise the account without exposing it.
const maskedEmail = computed(() => {
  const email = auth.user?.email || '';
  const at = email.indexOf('@');
  if (at <= 0) return email;
  return `${email[0]}••••${email.slice(at)}`;
});

function go(path) {
  userMenuOpen.value = false;
  router.push(path);
}
function signOut() {
  userMenuOpen.value = false;
  emit('logout');
}

// ---------------- languages ----------------

const remoteLanguages = ref([]);

const languages = computed(() =>
  remoteLanguages.value.length
    ? remoteLanguages.value
    : SUPPORTED_LOCALES.map(l => ({ value: l.value, label: l.label, flag: l.flag, image: null }))
);

onMounted(() => pending.fetch());

onMounted(async () => {
  try {
    const data = await http.get('languages');
    if (Array.isArray(data) && data.length) {
      remoteLanguages.value = data.map(l => ({
        value: l.locale,
        label: l.name,
        flag: '',
        image: l.flag ? `/flags/${l.flag}` : null,
      }));
    }
  } catch (e) { /* fall back to SUPPORTED_LOCALES */ }
});

// ---------------- fullscreen ----------------

const isFullscreen = ref(false);

function syncFullscreen() {
  isFullscreen.value = !!(
    document.fullscreenElement
    || document.webkitFullscreenElement
    || document.mozFullScreenElement
    || document.msFullscreenElement
  );
}

/** Legacy Util.toggleFullScreen, vendor prefixes and all. */
function toggleFullScreen() {
  const doc = document;
  const el = doc.documentElement;
  const request = el.requestFullscreen || el.mozRequestFullScreen
    || el.webkitRequestFullScreen || el.msRequestFullscreen;
  const cancel = doc.exitFullscreen || doc.mozCancelFullScreen
    || doc.webkitExitFullscreen || doc.msExitFullscreen;
  if (!request || !cancel) return;

  if (isFullscreen.value) cancel.call(doc);
  else request.call(el);
}

onMounted(() => document.addEventListener('fullscreenchange', syncFullscreen));
onBeforeUnmount(() => document.removeEventListener('fullscreenchange', syncFullscreen));
</script>

<style scoped>
/* a-layout-header sets line-height 64px, which stretches inline children —
   reset it here so the buttons and badge sit on their natural height. */
.topbar-inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  line-height: normal;
}
.bell {
  font-size: 17px;
}

/* Uniform soft icon buttons: 36px rounded squares, primary-tint hover and a
   small press squash — one consistent target size across the whole toolbar. */
.icon-btn {
  width: 36px;
  height: 36px;
  padding: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  font-size: 17px;
  color: rgba(0, 0, 0, 0.6);
  transition: background 0.2s ease, color 0.2s ease, transform 0.1s ease;
}
.icon-btn:hover {
  background: rgba(109, 40, 217, 0.08) !important;
  color: #6d28d9 !important;
}
.icon-btn:active {
  transform: scale(0.92);
}

/* Install offer keeps a resting primary tint so it reads as an invitation,
   not just another toggle. It disappears once the app is installed. */
.install-btn {
  color: #6d28d9;
  background: rgba(109, 40, 217, 0.08);
}
:global(.topbar--dark .install-btn) {
  color: #c4b5fd;
  background: rgba(139, 92, 246, 0.16);
}

/* POS shortcut as a pill so it reads as the toolbar's one primary action. */
.pos-btn {
  border-radius: 999px;
  padding-inline: 14px;
  margin-right: 4px;
}

/* Thin divider separating the icon cluster from the account chip. */
.topbar-divider {
  width: 1px;
  height: 22px;
  background: #ececee;
  margin: 0 6px;
}

/* Account chip: avatar with a primary ring + name in a pill. */
.user-btn {
  height: 38px;
  padding: 3px 10px 3px 5px;
  border-radius: 999px;
  transition: background 0.2s ease;
}
.user-btn:hover {
  background: rgba(0, 0, 0, 0.045) !important;
}
.user-avatar {
  border: 2px solid rgba(109, 40, 217, 0.35);
}
.user-name {
  font-weight: 500;
}
.user-caret {
  font-size: 10px;
  color: rgba(0, 0, 0, 0.4);
  transition: transform 0.2s ease;
}
.user-caret.open {
  transform: rotate(180deg);
}

/* ---------------- account dropdown panel ---------------- */
.account-menu {
  width: 252px;
  padding: 6px;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 6px 24px rgba(0, 0, 0, 0.12), 0 0 0 1px rgba(0, 0, 0, 0.05);
  font-size: 14px;
  color: rgba(0, 0, 0, 0.88);
}
.acct-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 10px 12px;
}
.acct-id {
  min-width: 0;
}
.acct-name {
  font-weight: 600;
  line-height: 1.25;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.acct-email {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  margin-top: 2px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.acct-sep {
  height: 1px;
  background: rgba(0, 0, 0, 0.06);
  margin: 4px 2px;
}
.acct-item {
  display: flex;
  align-items: center;
  gap: 11px;
  width: 100%;
  padding: 9px 10px;
  border: 0;
  background: none;
  border-radius: 9px;
  cursor: pointer;
  color: inherit;
  text-align: left;
  font-size: 14px;
  transition: background 0.15s ease;
}
.acct-item:hover {
  background: rgba(0, 0, 0, 0.05);
}
.acct-ic {
  font-size: 15px;
  opacity: 0.65;
}
.acct-danger {
  color: #ff4d4f;
}
.acct-danger .acct-ic {
  opacity: 1;
}
.acct-danger:hover {
  background: rgba(255, 77, 79, 0.08);
}
.acct-label {
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  opacity: 0.5;
  padding: 6px 10px 4px;
}
.theme-seg {
  display: flex;
  gap: 4px;
  margin: 2px 8px 8px;
  padding: 3px;
  background: rgba(128, 128, 128, 0.13);
  border-radius: 10px;
}
.theme-seg button {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 6px 0;
  border: 0;
  border-radius: 8px;
  background: transparent;
  cursor: pointer;
  font-size: 12.5px;
  color: inherit;
  opacity: 0.72;
  transition: background 0.15s ease, opacity 0.15s ease;
}
.theme-seg button.active {
  background: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
  opacity: 1;
  font-weight: 500;
}

/* Panel teleports to <body>, so darkness comes from the ui store, not the
   topbar wrapper class. */
.account-menu--dark {
  background: #1f1f1f;
  color: rgba(255, 255, 255, 0.85);
  box-shadow: 0 6px 24px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.08);
}
.account-menu--dark .acct-email {
  color: rgba(255, 255, 255, 0.45);
}
.account-menu--dark .acct-sep {
  background: rgba(255, 255, 255, 0.08);
}
.account-menu--dark .acct-item:hover {
  background: rgba(255, 255, 255, 0.08);
}
.account-menu--dark .theme-seg button.active {
  background: #3a3a3c;
  box-shadow: none;
}

/* Dark header keeps the same shapes with light-on-dark tints. */
:global(.topbar--dark .icon-btn) {
  color: rgba(255, 255, 255, 0.75);
}
:global(.topbar--dark .icon-btn:hover) {
  background: rgba(255, 255, 255, 0.12) !important;
  color: #fff !important;
}
:global(.topbar--dark .topbar-divider) {
  background: rgba(255, 255, 255, 0.16);
}
:global(.topbar--dark .user-btn:hover) {
  background: rgba(255, 255, 255, 0.12) !important;
}
:global(.topbar--dark .user-caret) {
  color: rgba(255, 255, 255, 0.5);
}
.lang-row {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.flag {
  width: 20px;
  height: 14px;
  object-fit: cover;
  border-radius: 2px;
}
.lang-menu {
  max-height: 320px;
  overflow-y: auto;
  min-width: 200px;
}

/* ---------------- notifications dropdown panel ----------------
   Same shell as .account-menu (radius, shadow, 6px gutter) so the two panels
   hanging off the same toolbar read as one component. */
.notif-menu {
  width: min(320px, calc(100vw - 24px));
  padding: 6px;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 6px 24px rgba(0, 0, 0, 0.12), 0 0 0 1px rgba(0, 0, 0, 0.05);
  font-size: 14px;
  color: rgba(0, 0, 0, 0.88);
}
.notif-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 8px 10px 10px;
}
.notif-title {
  font-weight: 600;
  line-height: 1.25;
}
.notif-pill {
  font-size: 11px;
  font-weight: 600;
  line-height: 1;
  padding: 4px 8px;
  border-radius: 999px;
  color: #6d28d9;
  background: rgba(109, 40, 217, 0.1);
}
.notif-list {
  max-height: 320px;
  overflow-y: auto;
}

/* Row: icon tile + two-line text + chevron, on one hover surface. */
.notif-item {
  display: flex;
  align-items: flex-start;
  gap: 11px;
  width: 100%;
  padding: 9px 10px;
  border: 0;
  background: none;
  border-radius: 10px;
  cursor: pointer;
  color: inherit;
  text-align: left;
  font-size: 14px;
  transition: background 0.15s ease;
}
.notif-item:hover {
  background: rgba(0, 0, 0, 0.05);
}
.notif-ic {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 9px;
  font-size: 15px;
  /* Amber, not brand purple — a stock alert is a warning, not a nav item. */
  color: #d97706;
  background: rgba(217, 119, 6, 0.12);
}
.notif-body {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
}
.notif-item-title {
  font-weight: 500;
  line-height: 1.3;
}
.notif-item-sub {
  font-size: 12px;
  line-height: 1.35;
  color: rgba(0, 0, 0, 0.45);
}
.notif-chev {
  flex: none;
  margin-top: 9px;
  font-size: 11px;
  color: rgba(0, 0, 0, 0.25);
  transition: transform 0.15s ease, color 0.15s ease;
}
.notif-item:hover .notif-chev {
  color: rgba(0, 0, 0, 0.5);
  transform: translateX(2px);
}

/* Empty state carries the same weight as a row so the panel never looks broken. */
.notif-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  padding: 22px 12px 26px;
  text-align: center;
}
.notif-empty-ic {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  margin-bottom: 8px;
  border-radius: 50%;
  font-size: 19px;
  color: rgba(0, 0, 0, 0.3);
  background: rgba(0, 0, 0, 0.05);
}
.notif-empty-title {
  font-weight: 500;
}
.notif-empty-sub {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}
.notif-foot {
  display: block;
  width: 100%;
  margin-top: 4px;
  padding: 9px 10px;
  border: 0;
  border-top: 1px solid rgba(0, 0, 0, 0.06);
  border-radius: 0 0 9px 9px;
  background: none;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  color: #6d28d9;
  transition: background 0.15s ease;
}
.notif-foot:hover {
  background: rgba(109, 40, 217, 0.07);
}

/* Panel teleports to <body>, so darkness comes from the ui store. */
.notif-menu--dark {
  background: #1f1f1f;
  color: rgba(255, 255, 255, 0.85);
  box-shadow: 0 6px 24px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.08);
}
.notif-menu--dark .notif-pill {
  color: #c4b5fd;
  background: rgba(139, 92, 246, 0.2);
}
.notif-menu--dark .notif-item:hover {
  background: rgba(255, 255, 255, 0.08);
}
.notif-menu--dark .notif-ic {
  color: #fbbf24;
  background: rgba(251, 191, 36, 0.16);
}
.notif-menu--dark .notif-item-sub,
.notif-menu--dark .notif-empty-sub {
  color: rgba(255, 255, 255, 0.45);
}
.notif-menu--dark .notif-chev {
  color: rgba(255, 255, 255, 0.3);
}
.notif-menu--dark .notif-item:hover .notif-chev {
  color: rgba(255, 255, 255, 0.6);
}
.notif-menu--dark .notif-empty-ic {
  color: rgba(255, 255, 255, 0.35);
  background: rgba(255, 255, 255, 0.08);
}
.notif-menu--dark .notif-foot {
  border-top-color: rgba(255, 255, 255, 0.08);
  color: #c4b5fd;
}
.notif-menu--dark .notif-foot:hover {
  background: rgba(139, 92, 246, 0.14);
}
@media (max-width: 575px) {
  .hide-sm {
    display: none;
  }
  /* Trim the toolbar so it never overflows a phone width. */
  .fullscreen-btn {
    display: none;
  }
  .icon-btn {
    padding: 4px 6px;
  }
}
</style>
