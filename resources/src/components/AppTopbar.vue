<template>
  <div class="topbar-inner">
    <!-- Left: sidebar toggle -->
    <a-button type="text" class="icon-btn" aria-label="Toggle menu" @click="$emit('toggle-sidebar')">
      <template #icon>
        <MenuUnfoldOutlined v-if="collapsed" />
        <MenuFoldOutlined v-else />
      </template>
    </a-button>

    <!-- The company's logo and name next to the toggle while the side menu (and
         its brand) is hidden or collapsed, so the header is never left empty. -->
    <div v-if="showBrand" class="tb-brand" @click="$router.push('/')">
      <img v-if="auth.logoUrl" :src="auth.logoUrl" alt="" class="tb-brand-logo" />
      <span v-else class="tb-brand-initial">{{ auth.brandInitial }}</span>
      <!-- The name always (auth.companyName follows the sidebar's own "show name" switch). -->
      <span v-if="auth.user?.company" class="tb-brand-name">{{ auth.user.company }}</span>
    </div>

    <!-- Right: actions (icons placed by the user's order, Profile -> Header icons) -->
    <div class="tb-actions">
      <!-- Company switcher (SuperAdmin only) -- every page/form acts on
           whichever company is selected here until changed again. -->
      <a-select
        v-if="company.isSuperAdmin"
        :value="company.actingCompanyId"
        :options="companyOptions"
        class="company-switch"
        :dropdown-match-select-width="false"
        @change="onCompanyChange"
      />

      <!-- POS -->
      <!-- POS Settings -> "Show POS button in header"; the account menu keeps its POS entry. -->
      <span v-if="auth.can('Pos_view') && auth.headerIcon('pos') && auth.moduleEnabled('pos')" class="tb-slot" :style="{ order: auth.headerOrder('pos') }">
        <a-button type="primary" class="pos-btn" @click="$router.push('/pos')">
          <template #icon><CalculatorOutlined /></template>
          <span class="hide-sm">POS</span>
        </a-button>
      </span>

      <!-- Portfolio tablet catalogs (2026-10-25): like POS, opens the menu / models on the tablet. -->
      <span v-if="catalogs.length && auth.headerIcon('catalog') && (auth.can('portfolio_manage') || auth.can('Pos_view'))" class="tb-slot" :style="{ order: auth.headerOrder('catalog') }">
        <a-button v-if="catalogs.length === 1" class="pos-btn cat-btn" @click="openCatalog(catalogs[0])">
          <template #icon><TabletOutlined /></template>
          <span class="hide-sm">{{ catalogs[0].type === 'menu' ? (ui.locale === 'es' ? 'Carta' : 'Menu') : (ui.locale === 'es' ? 'Catálogo' : 'Catalog') }}</span>
        </a-button>
        <a-dropdown v-else :trigger="['click']">
          <a-button class="pos-btn cat-btn"><template #icon><TabletOutlined /></template><span class="hide-sm">{{ catalogsLabel }}</span></a-button>
          <template #overlay>
            <a-menu @click="({ key }) => openCatalog(catalogs.find(x => String(x.id) === String(key)))">
              <a-menu-item v-for="x in catalogs" :key="x.id">{{ x.name }}</a-menu-item>
            </a-menu>
          </template>
        </a-dropdown>
      </span>

      <!-- Customize — theme/layout/language drawer (moved here from the old
           floating bottom-right handle), matching legacy's setting to hide it. -->
      <span v-if="auth.headerIcon('customize')" class="tb-slot" :style="{ order: auth.headerOrder('customize') }">
        <a-tooltip :title="$t('Settings')">
          <a-button type="text" class="icon-btn customize-btn" @click="ui.toggleCustomizer()">
            <template #icon><SettingOutlined /></template>
          </a-button>
        </a-tooltip>
      </span>

      <!-- Install PWA — only while the browser is offering installation -->
      <span v-if="pwaCanInstall && auth.headerIcon('install')" class="tb-slot" :style="{ order: auth.headerOrder('install') }">
        <a-tooltip :title="$t('Install_App')">
          <a-button type="text" class="icon-btn install-btn" @click="promptInstall">
            <template #icon><DownloadOutlined /></template>
          </a-button>
        </a-tooltip>
      </span>

      <!-- Today's summary -->
      <!-- Profit and stock at cost: roles with the Profit report only (owners / admins / managers). -->
      <span v-if="auth.headerIcon('summary') && auth.moduleEnabled('sales') && auth.can('Reports_profit')" class="tb-slot" :style="{ order: auth.headerOrder('summary') }">
        <a-tooltip title="Como Vamos!">
          <a-button type="text" class="icon-btn summary-btn" @click="summaryOpen = true">
            <template #icon><PieChartOutlined /></template>
          </a-button>
        </a-tooltip>
      </span>

      <!-- Fullscreen (hidden on phones — not meaningful there) -->
      <span v-if="auth.headerIcon('fullscreen')" class="tb-slot" :style="{ order: auth.headerOrder('fullscreen') }">
        <a-tooltip title="Fullscreen">
          <a-button type="text" class="icon-btn fullscreen-btn" @click="toggleFullScreen">
            <template #icon>
              <FullscreenExitOutlined v-if="isFullscreen" />
              <FullscreenOutlined v-else />
            </template>
          </a-button>
        </a-tooltip>
      </span>

      <!-- User manual: this page or the whole manual (company modules + role). -->
      <span v-if="auth.headerIcon('manual')" class="tb-slot" :style="{ order: auth.headerOrder('manual') }">
        <a-tooltip :title="ui.locale === 'es' ? 'Manual de usuario' : 'User manual'">
          <a-button type="text" class="icon-btn manual-btn" aria-label="User manual" @click="manualOpen = true">
            <template #icon><OpenBookIcon style="font-size: 19px" /></template>
          </a-button>
        </a-tooltip>
      </span>

      <!-- Language -->
      <span v-if="auth.headerIcon('language')" class="tb-slot" :style="{ order: auth.headerOrder('language') }">
        <a-dropdown placement="bottomRight">
          <a-button type="text" class="icon-btn lang-btn"><template #icon><GlobalOutlined /></template></a-button>
          <template #overlay>
            <a-menu :selected-keys="[ui.locale]" class="lang-menu" @click="({ key }) => ui.setLocale(key, auth.user?.id)">
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
      </span>

      <!-- Notifications -->
      <span v-if="auth.headerIcon('notifications')" class="tb-slot" :style="{ order: auth.headerOrder('notifications') }">
        <a-dropdown v-model:open="notifOpen" placement="bottomRight" :trigger="['click']">
          <!-- Badge wraps the icon as button CONTENT, not via the #icon slot:
               Ant sizes that slot for a bare icon and clips the count bubble.
               count 0 renders nothing, matching legacy's v-if on the badge. -->
          <a-button type="text" class="icon-btn notif-btn">
            <a-badge :count="notifTotal" size="small" :offset="[4, -2]">
              <BellOutlined class="bell" />
            </a-badge>
          </a-button>
          <template #overlay>
            <div class="notif-menu" :class="{ 'notif-menu--dark': ui.dark }">
              <div class="notif-head">
                <span class="notif-title">{{ $t('Notifications') }}</span>
                <span v-if="notifTotal" class="notif-pill">{{ notifTotal }}</span>
              </div>

              <div class="notif-list">
                <button
                  v-for="item in notifItems" :key="item.key"
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

                <div v-if="!notifItems.length" class="notif-empty">
                  <span class="notif-empty-ic"><BellOutlined /></span>
                  <span class="notif-empty-title">{{ $t('No_notifications') }}</span>
                  <span class="notif-empty-sub">{{ $t('You_are_all_caught_up') }}</span>
                </div>
              </div>
            </div>
          </template>
        </a-dropdown>
      </span>

      <!-- Clock in / Clock out: own attendance for today (attendance_self). -->
      <span v-if="canClock && auth.headerIcon('clock')" class="tb-slot" :style="{ order: auth.headerOrder('clock') }">
        <a-tooltip :title="clock.in ? `Clocked in since ${clock.since} — click to clock out` : 'Clock in'">
          <a-button type="text" class="icon-btn clock-btn" :class="{ 'clock-btn--in': clock.in }" :loading="clock.busy" @click="toggleClock">
            <template #icon><FieldTimeOutlined /></template>
            <span v-if="clock.in" class="clock-since hide-sm">{{ clock.since }}</span>
          </a-button>
        </a-tooltip>
      </span>

      <!-- Log out in one click (same as the user menu's: POS close-out check included). -->
      <span v-if="auth.headerIcon('logout')" class="tb-slot" :style="{ order: auth.headerOrder('logout') }">
        <a-tooltip :title="$t('logout')">
          <a-button type="text" class="icon-btn logout-btn" :aria-label="$t('logout')" @click="signOut">
            <template #icon><LogoutOutlined /></template>
          </a-button>
        </a-tooltip>
      </span>

      <!-- A SuperAdmin working as this company's admin (login chooser): back to SuperAdmin. -->
      <a-tooltip v-if="auth.user?.superadmin_switch" :title="`${$t('Back_to_SuperAdmin') !== 'Back_to_SuperAdmin' ? $t('Back_to_SuperAdmin') : (isEs ? 'Volver a SuperAdmin' : 'Back to SuperAdmin')} (${auth.user.superadmin_switch.name})`">
        <a-button size="small" class="sa-back" style="order: 99" :loading="saBackBusy" @click="backToSuperAdmin">
          <RollbackOutlined /> <span class="hide-sm">SuperAdmin</span>
        </a-button>
      </a-tooltip>

      <!-- A company admin opened this login (Company logins -> Open): back to the admin. -->
      <a-tooltip v-if="auth.user?.admin_switch" :title="`${isEs ? 'Volver a' : 'Back to'} ${auth.user.admin_switch.name}`">
        <a-button size="small" class="sa-back admin-back" style="order: 99" :loading="adminBackBusy" @click="backToAdmin">
          <RollbackOutlined /> <span>{{ isEs ? 'Estás como' : 'You are' }} {{ auth.username }} · {{ isEs ? 'Volver a admin' : 'Back to admin' }}</span>
        </a-button>
      </a-tooltip>

      <!-- User -->
      <span class="topbar-divider" aria-hidden="true" style="order: 100"></span>
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
            <!-- The person's card (2026-11-03): picture / name open Profile. -->
            <div class="acct-header">
              <a-avatar :size="40" :src="auth.avatarUrl" class="acct-link" :title="$t('profil')" @click="go('/profile')" />
              <div class="acct-id">
                <div class="acct-name acct-link" :title="$t('profil')" @click="go('/profile')">{{ auth.username || 'User' }}</div>
                <div v-if="auth.user?.email" class="acct-email">{{ auth.user.email }}</div>
                <div v-if="card" class="acct-info">
                  <div v-if="card.username" class="acct-dim">{{ card.username }}</div>
                  <div v-if="card.role || typeLabel"><b>{{ card.role }}</b><template v-if="card.role && typeLabel"> - </template>{{ typeLabel }}</div>
                  <div v-if="card.department || card.job">{{ [card.department, card.job].filter(Boolean).join(' · ') }}</div>
                  <div class="acct-dim">
                    <EnvironmentOutlined /> {{ card.locations === null ? (isEs ? 'Todas las ubicaciones' : 'All locations') : (card.locations.join(', ') || (isEs ? 'Sin ubicación' : 'No location')) }}
                  </div>
                </div>
              </div>
            </div>

            <div class="acct-sep" role="separator"></div>
            <button
              v-if="auth.can('setting_system')" type="button" class="acct-item"
              @click="go('/settings/system')"
            >
              <SettingOutlined class="acct-ic" />
              <span>{{ $t('Settings') }}</span>
            </button>
            <!-- Same gate as the header POS button: with the POS module off the router answers "not found". -->
            <button v-if="auth.can('Pos_view') && auth.moduleEnabled('pos')" type="button" class="acct-item" @click="go('/pos')">
              <DesktopOutlined class="acct-ic" />
              <span>Open POS</span>
            </button>
            <!-- The company's logins, roles and standard passwords: the company admin only. -->
            <button v-if="canSeeLogins" type="button" class="acct-item" @click="userMenuOpen = false; loginsOpen = true">
              <KeyOutlined class="acct-ic" />
              <span>{{ ui.locale === 'es' ? 'Logins de la empresa' : 'Company logins' }}</span>
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
    </div>

    <!-- Today's summary drawer -->
    <TodaySummary :open="summaryOpen" @close="summaryOpen = false" />
    <ManualDrawer :open="manualOpen" @close="manualOpen = false" />
    <LoginsDrawer v-if="canSeeLogins" :open="loginsOpen" @close="loginsOpen = false" />
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
  MenuFoldOutlined, MenuUnfoldOutlined, CalculatorOutlined, TabletOutlined,
  FullscreenOutlined, FullscreenExitOutlined, GlobalOutlined,
  BellOutlined, DownOutlined, PieChartOutlined, EnvironmentOutlined, SettingOutlined,
  DesktopOutlined, LogoutOutlined, WarningFilled, RightOutlined, FieldTimeOutlined,
  DownloadOutlined, RollbackOutlined,
} from '@ant-design/icons-vue';
import TodaySummary from './TodaySummary.vue';
import ManualDrawer from './ManualDrawer.vue';
import LoginsDrawer from './LoginsDrawer.vue';
import { KeyOutlined } from '@ant-design/icons-vue';
import OpenBookIcon from './icons/OpenBookIcon.vue';
import { canInstall as pwaCanInstall, promptInstall } from '../lib/pwaInstall';
import { useAuthStore } from '../stores/auth';
import { usePendingWorkStore } from '../stores/pendingWork';
import { moduleKeyForPath } from '../config/modules';
import { useUiStore } from '../stores/ui';
import { useCompanyStore } from '../stores/company';
import { SUPPORTED_LOCALES } from '../i18n';
import http from '../lib/http';
import { message } from 'ant-design-vue';

defineProps({
  collapsed: { type: Boolean, default: false },
  showBrand: { type: Boolean, default: false },
});
const emit = defineEmits(['toggle-sidebar', 'logout']);

// ---------------- Clock in / Clock out (own attendance) ----------------
const canClock = computed(() => auth.user?.type !== 'SuperAdmin' && auth.can('attendance_self'));
const clock = ref({ in: false, since: null, busy: false });
async function loadClock() {
  if (!canClock.value) return;
  try {
    const d = await http.get('attendance_self');
    clock.value.in = !!d.clocked_in;
    clock.value.since = d.since || null;
  } catch (e) { /* not linked to an employee: button stays as "Clock in" */ }
}
async function toggleClock() {
  clock.value.busy = true;
  try {
    const d = await http.post('attendance_self/clock', {});
    clock.value.in = !!d.clocked_in;
    clock.value.since = d.clocked_in ? d.time : null;
    message.success(d.clocked_in ? `Clocked in at ${d.time}.` : `Clocked out at ${d.time} — ${d.total_work} worked today.`);
  } catch (e) {
    message.error(e?.data?.message || 'Could not record the attendance.');
  } finally {
    clock.value.busy = false;
  }
}

const auth = useAuthStore();
const ui = useUiStore();
// Clock in / out: today's state once the user (and their permissions) are known.
watch(canClock, v => { if (v) loadClock(); }, { immediate: true });
const pending = usePendingWorkStore();
const company = useCompanyStore();
const router = useRouter();

// ---------------- company switcher ----------------

const companyOptions = computed(() => company.companies.map(c => ({ value: c.id, label: `🏢 ${c.name}` })));

function onCompanyChange(id) {
  company.setCompany(id);
  // Every page's own data is fetched on its own mount/watchers, not from a
  // central store this topbar could refresh directly -- a reload is the
  // simplest way to guarantee the whole app re-fetches under the new company.
  window.location.reload();
}

onMounted(() => company.load());

// Portfolio tablet catalogs of the acting company (header button, like POS).
const catalogs = ref([]);
const catalogsLabel = computed(() => (ui.locale === 'es' ? 'Catálogos' : 'Catalogs'));
async function loadCatalogs() {
  if (!auth.user || !(auth.can('portfolio_manage') || auth.can('Pos_view')) || !auth.moduleEnabled('portfolio')) {
    catalogs.value = [];
    return;
  }
  try {
    catalogs.value = (await http.get('portfolio/catalogs/launch')).catalogs || [];
  } catch (e) {
    catalogs.value = [];
  }
}
function openCatalog(x) {
  if (x) window.open(x.url, 'murano-catalog-' + x.id);
}
onMounted(loadCatalogs);
watch(() => company.actingCompanyId, loadCatalogs);

const summaryOpen = ref(false);
const manualOpen = ref(false);
const loginsOpen = ref(false);
// Company logins list (2026-10-25): only the company's admin login with the role's permission.
const canSeeLogins = computed(() => auth.user?.type === 'admin' && auth.can('company_logins_view'));

// ---------------- notifications ----------------

const notifOpen = ref(false);

// The bell now lists every pending process, not just stock alerts. The
// server filters each entry by permission and company module; this is the
// second guard (user's rule, 2026-10-20): an alert is shown only when the
// page it opens exists and the user's role / company may open it -- never a
// link to "no permission".
const notifItems = computed(() => pending.items.filter(item => {
  if (!item?.route) return false;
  const r = router.resolve(item.route);
  if (!r.matched.length || r.name === 'not-found') return false;
  if (auth.user?.type === 'SuperAdmin') return true;
  const perm = r.meta?.permission;
  const perms = Array.isArray(perm) ? perm : (perm ? [perm] : []);
  if (perms.length && !perms.some(p => auth.can(p))) return false;
  const mod = moduleKeyForPath(r.path);
  return !mod || auth.moduleEnabled(mod);
}));
const notifTotal = computed(() => notifItems.value.reduce((n, i) => n + (Number(i.count) || 0), 0));
// A custom panel (not a-menu) doesn't auto-close on click, so close it here.
function goPending(item) {
  notifOpen.value = false;
  if (item?.route) router.push(item.route);
}

// Refresh when the panel is opened, so counts are current the moment they
// are looked at rather than whenever the last poll happened.
watch(notifOpen, open => { if (open) pending.fetch(true); });

// ---------------- SuperAdmin working as this admin ----------------
// Set by the login chooser (LoginChooseController); POST /login/back switches
// this browser back to the SuperAdmin and opens the chooser again.

const saBackBusy = ref(false);
const isEs = computed(() => String(ui.locale || '').startsWith('es'));
async function backToSuperAdmin() {
  saBackBusy.value = true;
  try {
    const xsrf = decodeURIComponent((document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const res = await fetch('/login/back', {
      method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf },
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || res.statusText);
    try { window.localStorage.removeItem('stocky_auth_cache_v1'); } catch (e) { /* storage unavailable */ }
    window.location.replace(data.redirect || '/login/choose');
  } catch (e) {
    message.error(e.message || 'Could not go back to SuperAdmin.');
    saBackBusy.value = false;
  }
}

// Company logins -> Open (2026-10-25): POST /login/as-back returns this browser to the admin.
const adminBackBusy = ref(false);
async function backToAdmin() {
  adminBackBusy.value = true;
  try {
    const data = await http.webPost('login/as-back');
    try { window.localStorage.removeItem('stocky_auth_cache_v1'); } catch (e) { /* storage unavailable */ }
    window.location.replace(data?.redirect || '/');
  } catch (e) {
    message.error(e?.data?.message || 'Could not go back to the admin.');
    adminBackBusy.value = false;
  }
}

// ---------------- account menu ----------------

const userMenuOpen = ref(false);

// The person's card (2026-11-03): full email, role, user type, department / job, locations.
const card = computed(() => auth.user?.account_card || null);
const USER_TYPES = {
  superadmin: ['SuperAdmin', 'SuperAdmin'], admin: ['Administrator', 'Administrador'], employee: ['Employee', 'Empleado'],
  customer: ['Customer', 'Cliente'], supplier: ['Supplier', 'Proveedor'], sales_agent: ['Sales agent', 'Vendedor'], online_store: ['Store customer', 'Cliente de la tienda'],
};
const typeLabel = computed(() => {
  const t = String(card.value?.type || '');
  const pair = USER_TYPES[t.toLowerCase()];
  return pair ? pair[isEs.value ? 1 : 0] : t;
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

/* Each header button its own color on a soft tint (hover: a bit stronger),
   so they're told apart at a glance -- Log out in red. */
.customize-btn { color: #1677ff; background: rgba(22, 119, 255, 0.09); }
.summary-btn { color: #7c3aed; background: rgba(124, 58, 237, 0.09); }
.fullscreen-btn { color: #0d9488; background: rgba(13, 148, 136, 0.09); }
.lang-btn { color: #16a34a; background: rgba(22, 163, 74, 0.09); }
.manual-btn { color: #4f46e5; background: rgba(79, 70, 229, 0.09); }
.manual-btn:hover { color: #4f46e5 !important; background: rgba(79, 70, 229, 0.18) !important; }
:global(.topbar--dark .manual-btn) { color: #a5b4fc; background: rgba(165, 180, 252, 0.14); }
.notif-btn { color: #ea580c; background: rgba(234, 88, 12, 0.09); }
/* The badge wrapper resets the color: the bell takes the button's. */
.notif-btn :deep(.ant-badge), .notif-btn .bell { color: inherit; }
.logout-btn { color: #dc2626; background: rgba(220, 38, 38, 0.09); }
/* Clock: grey when out, green (with the clock-in time) while clocked in. */
.clock-btn { color: #64748b; background: rgba(100, 116, 139, 0.10); }
.clock-btn--in { color: #16a34a; background: rgba(22, 163, 74, 0.14); width: auto !important; padding: 0 10px !important; gap: 6px; }
.clock-since { font-size: 12px; font-weight: 600; }
.customize-btn:hover { color: #1677ff !important; background: rgba(22, 119, 255, 0.18) !important; }
.summary-btn:hover { color: #7c3aed !important; background: rgba(124, 58, 237, 0.18) !important; }
.fullscreen-btn:hover { color: #0d9488 !important; background: rgba(13, 148, 136, 0.18) !important; }
.lang-btn:hover { color: #16a34a !important; background: rgba(22, 163, 74, 0.18) !important; }
.notif-btn:hover { color: #ea580c !important; background: rgba(234, 88, 12, 0.18) !important; }
.logout-btn:hover { color: #fff !important; background: #dc2626 !important; }
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

/* Company logo + name beside the toggle when the side menu is hidden / collapsed
   (margin-right: auto keeps it on the left, the actions on the right). */
.tb-brand {
  display: flex; align-items: center; gap: 10px; min-width: 0;
  margin: 0 auto 0 8px; padding-right: 12px; cursor: pointer;
}
.tb-brand-logo { height: 34px; max-width: 120px; width: auto; object-fit: contain; flex: none; }
.tb-brand-initial {
  width: 34px; height: 34px; border-radius: 8px; flex: none; display: flex; align-items: center; justify-content: center;
  background: #e6f0ff; color: #1677ff; font-weight: 700;
}
.tb-brand-name { font-weight: 600; font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 320px; }
@media (max-width: 767px) { .tb-brand-name { display: none; } }

/* Company switcher (SuperAdmin only) -- tinted like the install-btn so it
   reads as its own distinct, always-visible context control rather than a
   generic form field lost among the other icon buttons. */
.company-switch {
  min-width: 150px;
  margin-right: 4px;
}
.company-switch :deep(.ant-select-selector) {
  border-radius: 999px !important;
  background: rgba(109, 40, 217, 0.08) !important;
  border-color: transparent !important;
  font-weight: 500;
}
:global(.topbar--dark .company-switch .ant-select-selector) {
  background: rgba(139, 92, 246, 0.16) !important;
  color: #c4b5fd;
}

/* Thin divider separating the icon cluster from the account chip. */
/* Right-hand actions: a flex row so each icon can take the user's order
   (auth.headerOrder); the company switcher stays first, the user menu last. */
.tb-actions {
  display: flex;
  align-items: center;
  gap: 4px;
}
.tb-slot {
  display: inline-flex;
  align-items: center;
}
.tb-actions > :last-child {
  order: 101;
}
/* SuperAdmin working as this admin: amber, so it's never mistaken for a normal login. */
.sa-back {
  margin-inline-end: 6px;
  border-color: #f59e0b;
  color: #b45309;
  background: #fffbeb;
  font-weight: 600;
}
.sa-back:hover { border-color: #d97706 !important; color: #92400e !important; }

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
.acct-link { cursor: pointer; }
.acct-name.acct-link:hover { color: #1677ff; text-decoration: underline; }
.acct-info { font-size: 12px; margin-top: 4px; line-height: 1.45; }
.acct-dim { opacity: 0.6; }
.acct-header {
  display: flex;
  align-items: flex-start;
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
/* Dark header: the same colors, lighter. */
:global(.topbar--dark .customize-btn) { color: #69b1ff; background: rgba(105, 177, 255, 0.14); }
:global(.topbar--dark .summary-btn) { color: #c4b5fd; background: rgba(196, 181, 253, 0.14); }
:global(.topbar--dark .fullscreen-btn) { color: #5eead4; background: rgba(94, 234, 212, 0.14); }
:global(.topbar--dark .lang-btn) { color: #86efac; background: rgba(134, 239, 172, 0.14); }
:global(.topbar--dark .notif-btn) { color: #fdba74; background: rgba(253, 186, 116, 0.14); }
:global(.topbar--dark .logout-btn) { color: #fca5a5; background: rgba(252, 165, 165, 0.14); }
:global(.topbar--dark .logout-btn:hover) { color: #fff !important; background: #dc2626 !important; }
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
  /* Trim the toolbar so it never overflows a phone width (theme settings and
     today's summary stay on tablets / computers). */
  .fullscreen-btn,
  .customize-btn,
  .summary-btn {
    display: none;
  }
  .icon-btn {
    padding: 4px 6px;
  }
}
</style>
