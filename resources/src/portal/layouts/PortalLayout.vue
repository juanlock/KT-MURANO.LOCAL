<template>
  <!-- Portal shell, as a Vue layout -->
  <div class="page">
    <!-- ── Side navigation ── -->
    <aside class="navbar navbar-vertical navbar-expand-lg rst-sidenav">
      <div class="container-fluid">
        <div class="navbar-brand navbar-brand-autodark py-3 d-flex align-items-center justify-content-between">
          <router-link to="/dashboard" class="text-decoration-none d-flex align-items-center gap-2" @click="closeDrawer">
            <BrandMark />
          </router-link>
          <button type="button" class="btn btn-icon rst-tool d-lg-none" :aria-label="tr('close_menu', 'Close menu')" :title="tr('close_menu', 'Close menu')" @click="closeDrawer">
            <i class="ti ti-x"></i>
          </button>
        </div>

        <div class="collapse navbar-collapse" id="rst-sidenav-menu">
          <ul class="navbar-nav pt-lg-2 pb-4">
            <template v-for="group in navGroups" :key="group.heading || 'main'">
              <li v-if="group.heading" class="rst-nav-heading">{{ tr(group.heading, group.fallback) }}</li>
              <li v-for="item in group.items" :key="item.to" class="nav-item">
                <router-link :to="item.to" class="nav-link" :class="{ active: isActive(item.to) }" @click="closeDrawer">
                  <i :class="`ti ti-${item.icon}`"></i>
                  <span>{{ $t(item.label) }}</span>
                  <span v-if="item.badge" class="badge bg-orange-lt ms-auto">{{ item.badge }}</span>
                </router-link>
              </li>
            </template>
          </ul>
        </div>
      </div>
    </aside>

    <!-- Dims the page while the mobile drawer is open; click to close. -->
    <div class="rst-sidenav-backdrop d-print-none" aria-hidden="true" @click="closeDrawer"></div>

    <div class="page-wrapper">
      <!-- ── Topbar ── -->
      <header class="navbar navbar-expand-md d-print-none">
        <div class="container-xl">
          <button
            type="button"
            class="btn btn-icon rst-tool me-2"
            :aria-label="toggleLabel"
            :title="toggleLabel"
            aria-controls="rst-sidenav-menu"
            :aria-expanded="sidenavOpen ? 'true' : 'false'"
            @click="toggleSidenav"
          >
            <i class="ti ti-menu-2"></i>
          </button>

          <router-link to="/dashboard" class="rst-topbar-brand d-lg-none me-2">
            <BrandMark />
          </router-link>

          <form class="rst-search d-none d-md-block" role="search" @submit.prevent="submitSearch">
            <div class="input-icon">
              <span class="input-icon-addon"><i class="ti ti-search"></i></span>
              <input v-model="search" type="search" class="form-control" :placeholder="tr('search_placeholder', 'Search…')" :aria-label="tr('search', 'Search')">
            </div>
          </form>

          <div class="ms-auto d-flex align-items-center gap-1">
            <router-link v-if="Number(alerts.due_total) > 0" to="/invoices" class="btn btn-primary btn-sm d-none d-md-inline-flex me-2">
              <i class="ti ti-cash me-1"></i>{{ tr('pay_now', 'Pay now') }}
            </router-link>

            <LanguageMenu />

            <button type="button" class="btn btn-icon rst-tool d-none d-md-inline-flex" :title="tr('toggle_fullscreen', 'Toggle fullscreen')" :aria-label="tr('toggle_fullscreen', 'Toggle fullscreen')" @click="toggleFullscreen">
              <i class="ti ti-arrows-maximize"></i>
            </button>

            <button type="button" class="btn btn-icon rst-tool" :title="tr('toggle_theme', 'Toggle theme')" :aria-label="tr('toggle_theme', 'Toggle theme')" @click="toggleTheme">
              <i class="ti ti-moon-stars"></i>
            </button>

            <!-- Notifications -->
            <div class="dropdown">
              <button type="button" class="btn btn-icon rst-tool rst-bell" data-bs-toggle="dropdown" data-bs-auto-close="outside" :aria-label="tr('notifications', 'Notifications')" :title="tr('notifications', 'Notifications')">
                <i class="ti ti-bell"></i>
                <span v-if="alerts.items.length" class="rst-bell-dot">{{ alerts.items.length > 9 ? '9+' : alerts.items.length }}</span>
              </button>
              <div class="dropdown-menu dropdown-menu-end rst-menu rst-menu-wide">
                <div class="rst-menu-head">
                  <span class="rst-menu-title">{{ tr('notifications', 'Notifications') }}</span>
                  <span v-if="alerts.items.length" class="rst-menu-count">{{ alerts.items.length }} {{ tr('new', 'new') }}</span>
                </div>
                <template v-if="alerts.items.length">
                  <router-link v-for="(alert, i) in alerts.items" :key="i" :to="alert.url" class="rst-alert">
                    <span class="rst-alert-icon" :class="`rst-alert-${alert.tone}`"><i :class="`ti ${alert.icon}`"></i></span>
                    <span class="rst-alert-body">
                      <span class="rst-alert-title">{{ alert.title }}</span>
                      <span class="rst-alert-meta">{{ alert.meta }}</span>
                    </span>
                  </router-link>
                </template>
                <div v-else class="rst-menu-empty">
                  <i class="ti ti-bell-check"></i>
                  <div>{{ tr('all_caught_up', 'You are all caught up.') }}</div>
                </div>
              </div>
            </div>

            <div class="vr mx-2 d-none d-md-block opacity-25"></div>

            <!-- Account -->
            <div class="dropdown">
              <a href="#" class="rst-user-chip" data-bs-toggle="dropdown" :aria-label="tr('open_user_menu', 'Open user menu')" @click.prevent>
                <span class="avatar avatar-sm rounded-circle rst-avatar-accent">{{ initials }}</span>
                <span class="rst-user-meta d-none d-md-flex flex-column">
                  <span class="fw-semibold">{{ clientName }}</span>
                  <small class="text-secondary">{{ clientEmail }}</small>
                </span>
                <i class="ti ti-chevron-down text-secondary d-none d-md-inline"></i>
              </a>
              <div class="dropdown-menu dropdown-menu-end rst-menu">
                <div class="rst-menu-user">
                  <span class="avatar rounded-circle rst-avatar-accent">{{ initials }}</span>
                  <span class="rst-menu-user-body">
                    <span class="rst-menu-user-name">{{ clientName }}</span>
                    <span class="rst-menu-user-mail">{{ clientEmail }}</span>
                    <span class="rst-menu-role">{{ $t('client') }}</span>
                  </span>
                </div>
                <div class="dropdown-divider"></div>
                <router-link class="dropdown-item" to="/profile"><i class="ti ti-user"></i>{{ $t('profile') }}</router-link>
                <router-link class="dropdown-item" to="/statement"><i class="ti ti-report-money"></i>{{ $t('nav_statement') }}</router-link>
                <button type="button" class="dropdown-item" @click="openCustomizer"><i class="ti ti-palette"></i>{{ tr('customize', 'Customize') }}</button>
                <div class="dropdown-divider"></div>
                <button type="button" class="dropdown-item rst-item-danger" @click="logout"><i class="ti ti-logout"></i>{{ $t('logout') }}</button>
              </div>
            </div>
          </div>
        </div>
      </header>

      <!-- ── Page header: pretitle, title, breadcrumbs, actions ── -->
      <div class="page-header d-print-none">
        <div class="container-xl">
          <div class="row g-2 align-items-center">
            <div class="col">
              <div v-if="page.pretitle" class="rst-pretitle">{{ page.pretitle }}</div>
              <h2 class="page-title">{{ page.title }}</h2>
            </div>
            <div class="col-auto d-none d-sm-block">
              <nav aria-label="breadcrumbs">
                <ol class="rst-crumbs">
                  <li>
                    <router-link to="/dashboard"><i class="ti ti-home"></i>{{ $t('nav_home') }}</router-link>
                  </li>
                  <template v-for="(crumb, i) in page.crumbs" :key="i">
                    <li><i class="ti ti-chevron-right rst-crumb-sep"></i></li>
                    <li><router-link :to="crumb.to || '#'">{{ crumb.label }}</router-link></li>
                  </template>
                  <li v-if="page.title"><i class="ti ti-chevron-right rst-crumb-sep"></i></li>
                  <li v-if="page.title" aria-current="page"><span class="rst-crumb-now">{{ page.title }}</span></li>
                </ol>
              </nav>
            </div>
            <div class="col-auto d-print-none">
              <div id="portal-page-actions" class="pc-page-actions btn-list"></div>
            </div>
          </div>
        </div>
      </div>

      <div class="page-body">
        <div class="container-xl">
          <router-view />
        </div>
      </div>

      <footer class="rst-footer d-print-none">
        <div class="container-xl d-flex flex-wrap align-items-center justify-content-between gap-2 py-3 small">
          <span class="text-secondary">© {{ year }} {{ brand.name }} — {{ tr('all_rights_reserved', 'All rights reserved.') }}</span>
          <span class="d-flex align-items-center gap-3">
            <span class="rst-footer-tag">{{ $t('client_portal') }}</span>
            <router-link to="/help" class="text-secondary text-decoration-none">{{ $t('nav_help') }}</router-link>
          </span>
        </div>
      </footer>
    </div>

    <Customizer />
  </div>
</template>

<script>
import http from '../lib/http';
import { page } from '../lib/page';
import { brand, initials } from '../lib/ui';
import { themeState, toggleTheme, setSidenavCollapsed } from '../lib/theme';
import BrandMark from '../components/BrandMark.vue';
import LanguageMenu from '../components/LanguageMenu.vue';
import Customizer from '../components/Customizer.vue';

export default {
  components: { BrandMark, LanguageMenu, Customizer },
  data() {
    return {
      page,
      brand: brand(),
      theme: themeState,
      clientName: '',
      clientEmail: '',
      search: '',
      drawerOpen: false,
      isDesktop: window.matchMedia('(min-width: 992px)').matches,
      year: new Date().getFullYear(),
      alerts: { items: [], due_total: 0 },
      // Sidebar navigation groups; labels are portal.php keys.
      navGroups: [
        { heading: null, items: [{ to: '/dashboard', label: 'nav_home', icon: 'layout-dashboard' }] },
        { heading: 'nav_billing', fallback: 'Billing', items: [
          { to: '/invoices', label: 'nav_invoices', icon: 'file-invoice' },
          { to: '/payments', label: 'nav_payments', icon: 'credit-card' },
          { to: '/statement', label: 'nav_statement', icon: 'report-money' },
        ] },
        { heading: 'nav_services', fallback: 'Services', items: [
          { to: '/quotations', label: 'nav_quotations', icon: 'file-description' },
          { to: '/appointments', label: 'nav_appointments', icon: 'calendar-event' },
          { to: '/contracts', label: 'nav_contracts', icon: 'file-certificate' },
        ] },
        { heading: 'nav_support', fallback: 'Support', items: [
          { to: '/help', label: 'nav_help', icon: 'help-circle' },
          { to: '/profile', label: 'nav_profile', icon: 'user-circle' },
        ] },
      ],
    };
  },
  computed: {
    initials() { return initials(this.clientName || 'A'); },
    sidenavOpen() { return this.isDesktop ? !this.theme.collapsed : this.drawerOpen; },
    toggleLabel() {
      if (!this.isDesktop) return this.tr('open_menu', 'Open menu');
      return this.theme.collapsed ? this.tr('expand_sidebar', 'Expand sidebar') : this.tr('collapse_sidebar', 'Collapse sidebar');
    },
  },
  watch: {
    '$route.fullPath'() { this.closeDrawer(); },
    drawerOpen(v) { document.documentElement.classList.toggle('rst-sidenav-open', v); },
  },
  mounted() {
    this.mq = window.matchMedia('(min-width: 992px)');
    this.onMq = (e) => { this.isDesktop = e.matches; this.closeDrawer(); };
    this.mq.addEventListener('change', this.onMq);
    document.addEventListener('keydown', this.onKeydown);
    this.loadMe();
    this.loadAlerts();
  },
  beforeUnmount() {
    this.mq.removeEventListener('change', this.onMq);
    document.removeEventListener('keydown', this.onKeydown);
    document.documentElement.classList.remove('rst-sidenav-open');
  },
  methods: {
    toggleTheme,
    isActive(to) {
      const p = this.$route.path;
      return p === to || p.startsWith(to + '/');
    },
    toggleSidenav() {
      if (this.isDesktop) setSidenavCollapsed(!this.theme.collapsed);
      else this.drawerOpen = !this.drawerOpen;
    },
    closeDrawer() { this.drawerOpen = false; },
    onKeydown(e) { if (e.key === 'Escape' && this.drawerOpen) this.closeDrawer(); },
    toggleFullscreen() {
      if (document.fullscreenElement) document.exitFullscreen();
      else document.documentElement.requestFullscreen();
    },
    openCustomizer() { document.dispatchEvent(new CustomEvent('portal:open-customizer')); },
    submitSearch() {
      const q = this.search.trim();
      if (!q) return;
      this.$router.push({ path: '/invoices', query: { q } });
    },
    async loadMe() {
      try {
        const { data } = await http.get('/portal/me');
        const pc = data && data.portal_client;
        this.clientName = (pc && pc.client && pc.client.name) || this.$t('account');
        this.clientEmail = (pc && (pc.email || pc.portal_email)) || (pc && pc.client && pc.client.email) || '';
      } catch (_) {
        this.clientName = this.$t('account');
      }
    },
    async loadAlerts() {
      try {
        const { data } = await http.get('/portal/notifications');
        this.alerts = { items: Array.isArray(data.items) ? data.items : [], due_total: Number(data.due_total) || 0 };
      } catch (_) { /* the bell just stays quiet */ }
    },
    async logout() {
      await http.post('/portal/logout');
      window.location.href = '/portal/login';
    },
  },
};
</script>
