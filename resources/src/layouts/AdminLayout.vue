<template>
  <a-layout style="min-height: 100vh">
    <!-- ================= Sider ================= -->
    <!-- trigger:null — the topbar hamburger is the only collapse control,
         as in the legacy layout; Ant's built-in bottom trigger would double up. -->
    <!-- Sidebar layout is chosen in the customizer. Only the "dark" layout is
         forced dark (with a right border); the rest follow the app theme. -->
    <!-- Backdrop behind the off-canvas sidebar on mobile. -->
    <div v-if="isMobile && mobileOpen" class="sidebar-backdrop" @click="mobileOpen = false"></div>

    <a-layout-sider
      :collapsed="effectiveCollapsed"
      collapsible
      :trigger="null"
      :width="siderWidth"
      :collapsed-width="collapsedWidth"
      :theme="siderTheme"
      class="sider"
      :class="{
        'sider--dark': siderTheme === 'dark',
        'sidebar-right-border': ui.sidebarLayout === 'dark',
        'sider--mobile': isMobile,
        'sider--mobile-open': isMobile && mobileOpen,
      }"
    >
      <!-- Real branding from settings, like the legacy sidebar: uploaded logo
           or a company-initial placeholder, plus the company name (hidden when
           the admin set hide_site_name). Clicking returns to the dashboard. -->
      <div class="brand" @click="goHome">
        <template v-if="auth.showSidebarLogo">
          <img v-if="auth.logoUrl" :src="auth.logoUrl" alt="logo" class="brand-logo" :style="auth.sidebarLogoStyle" />
          <span v-else class="brand-initial">{{ auth.brandInitial }}</span>
        </template>
        <span v-if="!effectiveCollapsed && auth.companyName" class="brand-name">{{ auth.companyName }}</span>
      </div>
      <!-- Navigation style: two-pane rail + submenu panel (large), flat
           sectioned list with no submenus (flat), or the Ant inline accordion
           (default and dark, which differ only in theme). -->
      <SidebarLarge
        v-if="ui.sidebarLayout === 'large'"
        :theme="siderTheme" :collapsed="effectiveCollapsed"
      />
      <SidebarFlat
        v-else-if="ui.sidebarLayout === 'flat'"
        :theme="siderTheme" :collapsed="effectiveCollapsed"
      />
      <SidebarMenu v-else :theme="siderTheme" :collapsed="effectiveCollapsed" />
    </a-layout-sider>

    <a-layout>
      <!-- ================= Header ================= -->
      <a-layout-header class="topbar" :class="{ 'topbar--dark': ui.dark }">
        <AppTopbar
          :collapsed="collapsed"
          @toggle-sidebar="onToggleSidebar"
          @logout="logout"
        />
      </a-layout-header>

      <!-- ================= Content ================= -->
      <a-layout-content class="content">
        <!-- Loader over the content only (sidebar + header stay put) while a
             route's chunk/guard resolves. Opaque so the previous page is hidden
             — only the spinner shows — until the new one is ready. -->
        <div v-if="navLoading" class="content-loader" :style="{ background: ui.dark ? '#141414' : '#f5f5f5' }">
          <a-spin size="large" />
        </div>
        <!-- Keyed by path: pages resolve their endpoint/filters at setup, so
             sibling routes sharing a component (the payment reports) must
             remount rather than reuse the previous one's state. -->
        <router-view :key="$route.path" />
      </a-layout-content>

      <!-- Single-line footer: the admin's custom text (settings → General),
           falling back to the default copyright line. Version pinned top-right. -->
      <a-layout-footer class="footer">
        <span v-if="version" class="footer-version">v{{ version }}</span>
        <template v-if="auth.footerText">{{ auth.footerText }}</template>
        <template v-else>© {{ year }} {{ auth.developedBy }}. {{ tf('All_rights_reserved', 'All rights reserved') }}.</template>
      </a-layout-footer>
    </a-layout>

    <!-- Theme customizer (left drawer + floating handle), like legacy. -->
    <Customizer />
  </a-layout>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import SidebarMenu from '../components/SidebarMenu.vue';
import SidebarLarge from '../components/SidebarLarge.vue';
import SidebarFlat from '../components/SidebarFlat.vue';
import AppTopbar from '../components/AppTopbar.vue';
import Customizer from '../components/Customizer.vue';
import { useUiStore } from '../stores/ui';
import { useAuthStore } from '../stores/auth';
import { t as tf } from '../i18n';
import { navLoading } from '../lib/progress';

const ui = useUiStore();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

// Sidebar layout lives in the ui store so the customizer and the topbar
// hamburger drive the same state and it persists. Writable so Ant's own
// v-model:collapsed (e.g. keyboard) stays consistent.
const collapsed = computed({
  get: () => ui.sidebarCollapsed,
  set: v => ui.setSidebarCollapsed(v),
});

// ---- Responsive: below 992px the sidebar becomes an off-canvas overlay ----
const isMobile = ref(false);
const mobileOpen = ref(false);
let mq = null;
function syncMq(e) {
  isMobile.value = e.matches;
  if (!e.matches) mobileOpen.value = false; // leaving mobile: close the drawer
}

// On mobile the sidebar is always full-width (never the icon rail); the
// hamburger controls visibility (mobileOpen) instead of collapse.
const effectiveCollapsed = computed(() => (isMobile.value ? false : collapsed.value));

function onToggleSidebar() {
  if (isMobile.value) mobileOpen.value = !mobileOpen.value;
  else ui.toggleSidebar();
}
function goHome() {
  router.push('/dashboard');
  mobileOpen.value = false;
}
// Any navigation closes the mobile drawer.
watch(() => route.path, () => { mobileOpen.value = false; });

onMounted(() => {
  mq = window.matchMedia('(max-width: 991px)');
  syncMq(mq);
  mq.addEventListener('change', syncMq);
});
onBeforeUnmount(() => mq && mq.removeEventListener('change', syncMq));

// The large layout needs room for the rail + submenu panel; collapsed it drops
// to the rail only. The default layout keeps the familiar 240 / 72 icon rail.
const isLarge = computed(() => ui.sidebarLayout === 'large');
const siderWidth = computed(() => (isLarge.value ? 300 : 240));
const collapsedWidth = computed(() => (isLarge.value ? 88 : 72));
// The "dark" layout is always dark; every other layout follows the app theme.
const siderTheme = computed(() =>
  ui.sidebarLayout === 'dark' || ui.dark ? 'dark' : 'light'
);
const year = computed(() => new Date().getFullYear());
// System version injected as a meta tag by next.blade.php (from version.txt).
const version = document.querySelector('meta[name="app-version"]')?.getAttribute('content') || '';

async function logout() {
  try { window.localStorage.removeItem('stocky_auth_cache_v1'); } catch (e) {}
  try {
    await fetch('/logout', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
  } finally {
    window.location.href = '/login';
  }
}
</script>

<style scoped>
/* 32 modules — far taller than any viewport, and Reports is the last one.
   The sider is pinned full-height and its inner container is a flex column so
   the menu itself scrolls (Ant renders the menu inside .ant-layout-sider-children,
   so overflow on the sider alone does not reliably scroll it). */
.sider {
  height: 100vh;
  position: sticky;
  top: 0;
}
/* Subtle divider between the dark rail and the light content. */
.sidebar-right-border {
  border-inline-end: 1px solid rgba(255, 255, 255, 0.08);
  box-shadow: 2px 0 8px rgba(0, 0, 0, 0.12);
}
.sider :deep(.ant-layout-sider-children) {
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* Show the scrollbar only while the pointer is over the sidebar. The gutter
   stays reserved (thin / fixed width) so nothing shifts when it appears — only
   the thumb's colour toggles.

   Scoped to the ACTUAL scroll containers (one per sidebar layout) instead of a
   universal `*`: a `:deep(*)` rule forces the browser to match + restyle every
   node in the sidebar (1000+ with 250+ menu items) on each layout change — the
   submenu open/close animation then recalculated styles across the whole tree
   every frame, which was the lag. Targeting the ~4 scroll wrappers removes it. */
.sider :deep(.sidebar-menu),
.sider :deep(.flat),
.sider :deep(.rail),
.sider :deep(.panel) {
  scrollbar-width: thin;
  scrollbar-color: transparent transparent;
}
.sider:hover :deep(.sidebar-menu),
.sider:hover :deep(.flat),
.sider:hover :deep(.rail),
.sider:hover :deep(.panel) {
  scrollbar-color: rgba(0, 0, 0, 0.22) transparent;
}
.sider--dark:hover :deep(.sidebar-menu),
.sider--dark:hover :deep(.flat),
.sider--dark:hover :deep(.rail),
.sider--dark:hover :deep(.panel) {
  scrollbar-color: rgba(255, 255, 255, 0.28) transparent;
}
.sider :deep(.sidebar-menu)::-webkit-scrollbar,
.sider :deep(.flat)::-webkit-scrollbar,
.sider :deep(.rail)::-webkit-scrollbar,
.sider :deep(.panel)::-webkit-scrollbar {
  width: 6px;
  height: 6px;
}
.sider :deep(.sidebar-menu)::-webkit-scrollbar-track,
.sider :deep(.flat)::-webkit-scrollbar-track,
.sider :deep(.rail)::-webkit-scrollbar-track,
.sider :deep(.panel)::-webkit-scrollbar-track {
  background: transparent;
}
.sider :deep(.sidebar-menu)::-webkit-scrollbar-thumb,
.sider :deep(.flat)::-webkit-scrollbar-thumb,
.sider :deep(.rail)::-webkit-scrollbar-thumb,
.sider :deep(.panel)::-webkit-scrollbar-thumb {
  background: transparent;
  border-radius: 3px;
}
.sider:hover :deep(.sidebar-menu)::-webkit-scrollbar-thumb,
.sider:hover :deep(.flat)::-webkit-scrollbar-thumb,
.sider:hover :deep(.rail)::-webkit-scrollbar-thumb,
.sider:hover :deep(.panel)::-webkit-scrollbar-thumb {
  background: rgba(0, 0, 0, 0.22);
}
.sider--dark:hover :deep(.sidebar-menu)::-webkit-scrollbar-thumb,
.sider--dark:hover :deep(.flat)::-webkit-scrollbar-thumb,
.sider--dark:hover :deep(.rail)::-webkit-scrollbar-thumb,
.sider--dark:hover :deep(.panel)::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.28);
}
.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 16px 20px;
  cursor: pointer;
  border-right: 1px solid #ececee;
  border-bottom: 1px solid #ececee;
}
/* Same tint as .topbar--dark's border; covers app dark mode AND the "dark
   sidebar in a light app" layout, both of which set sider--dark. */
.sider--dark .brand {
  border-right-color: rgba(253, 253, 253, 0.12);
  border-bottom-color: rgba(253, 253, 253, 0.12);
}
.brand-logo {
  width: 32px;
  height: 32px;
  object-fit: contain;
  border-radius: 6px;
  flex: none;
}
.brand-initial {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: #6d28d9;
  color: #fff;
  font-weight: 700;
  font-size: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
}
.brand-name {
  font-size: 16px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
/* Light text only when the sider is dark; otherwise it inherits dark text. */
.sider--dark .brand-name {
  color: rgba(255, 255, 255, 0.92);
}
.topbar {
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 16px;
  border-bottom: 1px solid #ececee;
}
.topbar--dark {
  background: #141414;
  border-bottom-color: rgba(253, 253, 253, 0.12);
}
.content {
  position: relative;
  margin: 24px 16px;
}
/* Covers only the content area (not the sidebar/header) during navigation.
   Extends past the content margins so no stale page peeks around the edges. */
.content-loader {
  position: absolute;
  inset: -24px -16px;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding-top: 18vh;
  z-index: 50;
}
.footer {
  position: relative;
  text-align: center;
  color: #999;
  background: transparent;
  /* Override Ant's default 24px 50px — one line needs far less height. */
  padding: 10px 50px;
  font-size: 12px;
  line-height: 1.4;
}
.footer-version {
  position: absolute;
  top: 10px;
  right: 16px;
  font-size: 11px;
  opacity: 0.75;
}

/* ============================ Responsive ============================ */
/* Below 992px the sider slides in over the content instead of pushing it. */
.sidebar-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  z-index: 190;
}
.sider--mobile {
  position: fixed !important;
  left: 0;
  top: 0;
  z-index: 200;
  /* dvh, not vh: on mobile 100vh is the LARGE viewport (toolbars retracted),
     so while the URL/bottom bar is showing the sider's bottom — and with it
     the bottom of the scrolling menu — sits under the browser chrome and the
     last group (Reports) can't be scrolled into view. `bottom: 0` was no help:
     top + bottom + height is over-constrained, so height wins and bottom is
     ignored. vh first as the fallback for browsers without dvh. */
  height: 100vh;
  height: 100dvh;
  transform: translateX(-100%);
  transition: transform 0.25s ease;
  box-shadow: 2px 0 16px rgba(0, 0, 0, 0.25);
}
.sider--mobile-open {
  transform: translateX(0);
}

@media (max-width: 767px) {
  .content {
    margin: 16px 12px;
  }
  .footer {
    padding: 10px 16px;
  }
  .footer-version {
    position: static;
    display: block;
    margin-bottom: 2px;
  }
}
</style>
