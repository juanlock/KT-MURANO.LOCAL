<template>
  <div class="large" :class="{ 'panel-hidden': collapsed }">
    <!-- Primary rail: one entry per top-level module (icon + label). -->
    <div class="rail" :class="theme">
      <div
        v-for="g in groups" :key="g.key"
        class="rail-item" :class="{ active: activeKey === g.key }"
        @mouseenter="onHover(g)"
        @click="onRail(g)"
      >
        <component :is="iconCmp(g.icon)" :size="20" />
        <span class="rail-label">{{ label(g) }}</span>
      </div>
    </div>

    <!-- Secondary panel: the active module's sub-items. -->
    <div v-if="!collapsed" class="panel" :class="theme" @mouseleave="hoverKey = null">
      <div v-if="activeGroup" class="panel-title">{{ label(activeGroup) }}</div>

      <div class="panel-body">
        <template v-for="child in activeChildren" :key="child.key || child.label">
          <!-- A child that itself has children is a sub-section header. -->
          <template v-if="child.children">
            <div class="panel-subhead">{{ label(child) }}</div>
            <a
              v-for="leaf in visibleChildren(child)" :key="leaf.key || leaf.label"
              class="panel-link" :class="{ active: isActive(leaf) }"
              @click="go(leaf)"
            >{{ label(leaf) }}</a>
          </template>

          <a
            v-else class="panel-link" :class="{ active: isActive(child) }"
            @click="go(child)"
          >{{ label(child) }}</a>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Large sidebar — the Vue 3 port of the legacy largeSidebar/Sidebar.vue: a
 * master–detail rail. The left rail lists the top-level modules; hovering (or
 * clicking) one shows its sub-items in the secondary panel. On load the panel
 * opens to the module that owns the current route.
 *
 * Same data and rules as SidebarMenu — MENU from config/menu.js, the "any of
 * these permissions" gate, and resolveTarget deciding whether a link routes in
 * this app or deep-links back to the legacy SPA. When collapsed (the hamburger)
 * only the rail shows.
 */
import { ref, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { resolveTarget } from '../config/menu';
import { MENU_ICONS } from '../config/menuIcons';
import { useAuthStore } from '../stores/auth';
import { useMenuStore } from '../stores/menu';

defineProps({
  theme: { type: String, default: 'light' },
  collapsed: { type: Boolean, default: false },
});

const { t } = useI18n();
const auth = useAuthStore();
// The default MENU arranged per the admin-saved order (Settings → Sidebar Menu).
const menuStore = useMenuStore();
const route = useRoute();
const router = useRouter();

function allowed(entry) {
  if (!entry.permissions || !entry.permissions.length) return true;
  return entry.permissions.some(p => auth.can(p));
}
function label(entry) {
  if (entry.raw) return entry.label;
  // Composed labels mirror legacy (e.g. {{$t('Add')}} {{$t('Customer')}}).
  if (entry.labelKeys) return entry.labelKeys.map(k => t(k)).join(' ');
  return t(entry.label);
}
function iconCmp(name) {
  return MENU_ICONS[name];
}

const groups = computed(() => menuStore.menu.filter(allowed));

/** Children of a sub-group, permission-filtered. */
function visibleChildren(node) {
  return (node.children || []).filter(allowed);
}

// The panel follows the hovered module, falling back to the route's module.
const hoverKey = ref(null);
const routeKey = ref(null);
const activeKey = computed(() => hoverKey.value || routeKey.value || groups.value[0]?.key);
const activeGroup = computed(() => groups.value.find(g => g.key === activeKey.value) || null);
const activeChildren = computed(() => (activeGroup.value?.children || []).filter(allowed));

function onHover(g) {
  if (g.children) hoverKey.value = g.key;
}
function onRail(g) {
  // A leaf module (e.g. Dashboard) navigates; a parent just opens its panel.
  if (g.children) hoverKey.value = g.key;
  else go(g);
}

function target(entry) {
  return entry.href ? { type: 'external', path: entry.href } : resolveTarget(entry.to);
}
function go(entry) {
  const tgt = target(entry);
  if (!tgt.path) return;
  if (tgt.type === 'next') router.push(tgt.path);
  // External links (online store, client portal) open in a new tab.
  else if (tgt.type === 'external') window.open(tgt.path, '_blank', 'noopener');
  else window.location.href = tgt.path; // legacy SPA
}
function isActive(entry) {
  const tgt = target(entry);
  return tgt.type === 'next' && tgt.path === route.path;
}

// Open the panel to whichever module owns the current route.
function findRouteGroup(path) {
  const hit = (node) => {
    for (const child of node.children || []) {
      if (child.children) { if (hit(child)) return true; }
      else {
        const tgt = target(child);
        if (tgt.type === 'next' && tgt.path === path) return true;
      }
    }
    return false;
  };
  const g = groups.value.find(grp => grp.children && hit(grp));
  return g ? g.key : null;
}

watch(
  [() => route.path, groups],
  ([path]) => { routeKey.value = findRouteGroup(path); },
  { immediate: true }
);
</script>

<style scoped>
.large {
  display: flex;
  flex: 1;
  min-height: 0;
}
.rail {
  width: 88px;
  flex: none;
  overflow-y: auto;
  border-inline-end: 1px solid rgba(5, 5, 5, 0.06);
}
.rail.dark {
  border-inline-end-color: rgba(253, 253, 253, 0.1);
}
.rail-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 12px 4px;
  cursor: pointer;
  font-size: 11px;
  text-align: center;
  color: rgba(0, 0, 0, 0.7);
  transition: background 0.15s;
}
.rail.dark .rail-item {
  color: rgba(255, 255, 255, 0.75);
}
.rail-item:hover,
.rail-item.active {
  background: rgba(109, 40, 217, 0.1);
  color: #6d28d9;
}
.rail.dark .rail-item:hover,
.rail.dark .rail-item.active {
  color: #b794f6;
}
.rail-label {
  line-height: 1.2;
  word-break: break-word;
}
.panel {
  flex: 1;
  min-width: 0;
  overflow-y: auto;
  padding: 8px 0;
}
.panel.dark {
  color: rgba(255, 255, 255, 0.75);
}
.panel-title {
  font-weight: 700;
  font-size: 15px;
  padding: 12px 16px 8px;
}
.panel-subhead {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  opacity: 0.55;
  padding: 12px 16px 4px;
}
.panel-link {
  display: block;
  padding: 8px 16px;
  cursor: pointer;
  font-size: 13px;
  color: inherit;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.panel-link:hover {
  background: rgba(109, 40, 217, 0.08);
}
.panel.dark .panel-link:hover {
  background: rgba(255, 255, 255, 0.08);
  color: #fff;
}
.panel-link.active {
  background: rgba(109, 40, 217, 0.14);
  color: #6d28d9;
  font-weight: 600;
}
.panel.dark .panel-link.active {
  background: rgba(109, 40, 217, 0.28);
  color: #fff;
}
</style>
