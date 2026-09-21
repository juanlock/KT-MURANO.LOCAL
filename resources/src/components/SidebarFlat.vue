<template>
  <div class="flat" :class="[theme, { collapsed }]">
    <template v-for="(row, i) in rows" :key="i">
      <!-- Section / sub-section headings — hidden when collapsed. -->
      <div v-if="row.type === 'header' && !collapsed" class="flat-header">{{ row.label }}</div>
      <div v-else-if="row.type === 'subheader' && !collapsed" class="flat-subheader">{{ row.label }}</div>

      <!-- Flat item: icon + label, no accordion. -->
      <a
        v-else-if="row.type === 'item'"
        class="flat-item" :class="{ active: isActive(row.entry) }"
        :title="collapsed ? row.label : undefined"
        @click="go(row.entry)"
      >
        <component :is="row.icon" :size="18" class="flat-icon" />
        <span v-if="!collapsed" class="flat-label">{{ row.label }}</span>
      </a>
    </template>
  </div>
</template>

<script setup>
/**
 * Flat sidebar — top-level modules become non-collapsible SECTION HEADERS with
 * their items listed flat underneath, no submenu/accordion. Nested sub-groups
 * become sub-headers. Everything is visible at once and scrolls.
 *
 * Item icons: each item gets its own icon, keyed by path from MENU_ITEM_ICONS
 * (extracted from the legacy sidebar), falling back to the module's icon when a
 * path isn't listed. Section headers have none, matching the reference design.
 * Same MENU data, permission gate and resolveTarget routing as the other
 * sidebars. Collapsed (the hamburger) drops to an icon-only rail with the label
 * as a hover title.
 */
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { resolveTarget } from '../config/menu';
import { MENU_ICONS } from '../config/menuIcons';
import { MENU_ITEM_ICONS } from '../config/menuItemIcons';
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
/** The item's own icon (by path), falling back to its module's icon. */
function iconFor(entry, groupIconName) {
  return MENU_ITEM_ICONS[entry.to] || MENU_ICONS[groupIconName];
}

/** Flatten a group's children into item/subheader rows, dropping empty ones. */
function sectionRows(children, groupIconName) {
  const out = [];
  for (const c of children) {
    if (!allowed(c)) continue;
    if (c.children) {
      const sub = sectionRows(c.children, groupIconName);
      if (sub.some(r => r.type === 'item')) {
        out.push({ type: 'subheader', label: label(c) });
        out.push(...sub);
      }
    } else {
      out.push({ type: 'item', label: label(c), icon: iconFor(c, groupIconName), entry: c });
    }
  }
  return out;
}

const rows = computed(() => {
  const all = [];
  for (const g of menuStore.menu) {
    if (!allowed(g)) continue;
    if (g.children) {
      const sec = sectionRows(g.children, g.icon);
      // Skip a header with no visible items under it.
      if (sec.some(r => r.type === 'item')) {
        all.push({ type: 'header', label: label(g) });
        all.push(...sec);
      }
    } else {
      // A top-level leaf (e.g. Dashboard) is just an item.
      all.push({ type: 'item', label: label(g), icon: iconFor(g, g.icon), entry: g });
    }
  }
  return all;
});

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
</script>

<style scoped>
.flat {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 8px 0 16px;
}
.flat.collapsed {
  padding: 8px 0;
}
.flat-header {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  opacity: 0.5;
  padding: 16px 20px 6px;
}
.flat-subheader {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  opacity: 0.4;
  padding: 10px 20px 4px 28px;
}
.flat-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 9px 20px;
  cursor: pointer;
  font-size: 13px;
  color: inherit;
  white-space: nowrap;
}
.flat.collapsed .flat-item {
  justify-content: center;
  padding: 10px 0;
}
.flat-icon {
  flex: none;
  opacity: 0.85;
}
.flat-label {
  overflow: hidden;
  text-overflow: ellipsis;
}
.flat-item:hover {
  background: rgba(109, 40, 217, 0.08);
}
.flat-item.active {
  background: rgba(109, 40, 217, 0.14);
  color: #6d28d9;
  font-weight: 600;
}

/* Dark sider (the default look). */
.flat.dark {
  color: rgba(255, 255, 255, 0.75);
}
.flat.dark .flat-item:hover {
  background: rgba(255, 255, 255, 0.08);
  color: #fff;
}
.flat.dark .flat-item.active {
  background: rgba(109, 40, 217, 0.28);
  color: #fff;
}
</style>
