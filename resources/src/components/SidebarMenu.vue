<template>
  <a-menu
    v-model:selectedKeys="selectedKeys"
    v-model:openKeys="openKeys"
    class="sidebar-menu"
    mode="inline"
    :inline-indent="16"
    :inline-collapsed="collapsed"
    :theme="theme"
    :items="items"
    :style="{ '--sb-primary': ui.primaryColor }"
    @click="onClick"
  />
</template>

<script setup>
/**
 * The full admin navigation, built from config/menu.js (generated from the
 * legacy Sidebar.vue). Items the user lacks permission for are hidden, using
 * the same "any of these permissions" rule as the old sidebar.
 *
 * Migrated pages route inside this app; everything else deep-links back into
 * the legacy SPA, so the sidebar covers the whole product during the migration.
 */
import { computed, ref, watch, h } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { resolveTarget } from '../config/menu';
import { MENU_ICONS } from '../config/menuIcons';
import { MENU_ITEM_ICONS } from '../config/menuItemIcons';
import { useAuthStore } from '../stores/auth';
import { useUiStore } from '../stores/ui';
import { useMenuStore } from '../stores/menu';
import { usePendingWorkStore } from '../stores/pendingWork';

const props = defineProps({
  theme: { type: String, default: 'light' },
  // Compact icon-rail mode; Ant switches the menu to icons + flyout popups.
  collapsed: { type: Boolean, default: false },
});

const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
// The default MENU arranged per the admin-saved order (Settings → Sidebar Menu).
const menuStore = useMenuStore();
// Pending-work counts, so a menu row can show what is waiting behind it.
const pending = usePendingWorkStore();
const route = useRoute();
const router = useRouter();

const selectedKeys = ref([]);
const openKeys = ref([]);

/** Legacy rule: no permissions listed = always visible; otherwise ANY match. */
function allowed(entry) {
  if (!entry.permissions || !entry.permissions.length) return true;
  return entry.permissions.some(p => auth.can(p));
}

/**
 * Literal labels (raw) bypass i18n — they had no key in the old sidebar.
 * Some entries compose several keys, exactly as legacy did (e.g. the People
 * "Add" links rendered {{$t('Add')}} {{$t('Customer')}} → "Add Customer").
 */
function label(entry) {
  if (entry.raw) return entry.label;
  if (entry.labelKeys) return entry.labelKeys.map(k => t(k)).join(' ');
  return t(entry.label);
}

function icon(name) {
  const Cmp = MENU_ICONS[name];
  return Cmp ? () => h(Cmp, { size: 16 }) : undefined;
}

/**
 * Labels are PLAIN STRINGS on purpose.
 *
 * `label` accepts a string or a VNode, but a VNode here comes from a computed
 * and would be reused across re-renders (every navigation changes
 * selectedKeys), which Vue does not allow — it throws in the render function.
 * A function isn't an option either: a-menu stringifies it into the label.
 * The "migrated" marker is applied with CSS instead (see the style block),
 * keyed off the `|next|` in the item key.
 */
function labelNode(entry) {
  const text = label(entry);
  // `items` is a computed, so the badge updates by itself as the counts
  // refresh — and disappears once the work is processed.
  const count = entry.badge ? (pending.byKey[entry.badge] || 0) : 0;
  if (!count) return text;

  return () => h('span', { class: 'sb-row' }, [
    h('span', { class: 'sb-row-label' }, text),
    h('span', { class: 'sb-row-badge' }, count > 99 ? '99+' : String(count)),
  ]);
}

/**
 * Per-item icon, keyed by path from MENU_ITEM_ICONS (the icons the legacy
 * sidebar used for each row), falling back to the module's own icon so every
 * submenu item shows one.
 */
function itemIcon(entry, groupIconName) {
  const Cmp = MENU_ITEM_ICONS[entry.to] || MENU_ICONS[groupIconName];
  return Cmp ? () => h(Cmp, { size: 15 }) : undefined;
}

/** Keys encode the target, so click handling needs no lookup. */
function leafItem(groupKey, entry, groupIconName) {
  const target = entry.href
    ? { type: 'external', path: entry.href }
    : resolveTarget(entry.to);
  return {
    key: `${groupKey}|${target.type}|${target.path}`,
    label: labelNode(entry),
    icon: itemIcon(entry, groupIconName),
  };
}

/** Whether a group would render ≥1 visible child (permission-aware) WITHOUT
 *  building the child nodes — decides if it's a submenu. Mirrors the old
 *  filter: a leaf counts if allowed; a sub-group counts if any of its leaves is. */
function hasVisibleChildren(group) {
  return (group.children || []).some(c =>
    c.children ? c.children.some(allowed) : allowed(c)
  );
}

/** Build a group's child nodes (leaves + sub-groups), eagerly. */
function buildGroupChildren(group) {
  return group.children
    .filter(allowed)
    .map(child => {
      if (child.children) {
        if (!child.children.some(allowed)) return null;
        const subKey = `${group.key}|group|${child.label}`;
        // Third-level items: no icon — the indent alone marks the level, and
        // the saved width lets the full name show (see the CSS wrap rule).
        const subItems = child.children.filter(allowed)
          .map(x => ({ ...leafItem(group.key, x, group.icon), icon: undefined }));
        // A sub-group may carry its own icon; otherwise it inherits the module's.
        return { key: subKey, icon: icon(child.icon || group.icon), label: label(child), children: subItems };
      }
      return leafItem(group.key, child, group.icon);
    })
    .filter(Boolean);
}

/**
 * Three-level menu (group > sub-group > item). Children are built eagerly so a
 * submenu's content is already in the DOM when it opens — expanding then plays
 * a single smooth animation. (An earlier lazy version built children only on
 * open, which made the submenu animate empty and then re-expand once the items
 * arrived — the "double open" jump.) Performance comes from the scoped
 * scrollbar CSS in AdminLayout, not from withholding the nodes.
 */
const items = computed(() => {
  const out = [];
  for (const group of menuStore.menu) {
    if (!allowed(group)) continue;
    if (group.children && hasVisibleChildren(group)) {
      out.push({ key: group.key, icon: icon(group.icon), label: label(group), children: buildGroupChildren(group) });
    } else if (group.to) {
      const tgt = resolveTarget(group.to);
      out.push({ key: `${group.key}|${tgt?.type}|${tgt?.path}`, icon: icon(group.icon), label: labelNode(group) });
    }
  }
  return out;
});

/**
 * path → { key, opens[] } built straight from the menu tree (NOT the lazy
 * `items`, whose closed groups have no children to search). Lets a route change
 * highlight the active item and open its ancestor group/sub-group even while
 * they're collapsed. Computed: item keys embed the parent group key, which
 * changes when the admin moves an item to another module.
 */
const routeIndex = computed(() => {
  const idx = {};
  const addLeaf = (groupKey, entry, opens) => {
    if (entry.href) return;
    const tgt = resolveTarget(entry.to);
    if (tgt?.type === 'next') idx[tgt.path] = { key: `${groupKey}|next|${tgt.path}`, opens };
  };
  for (const group of menuStore.menu) {
    if (!group.children) { addLeaf(group.key, group, []); continue; }
    for (const child of group.children) {
      if (child.children) {
        const subKey = `${group.key}|group|${child.label}`;
        child.children.forEach(leaf => addLeaf(group.key, leaf, [group.key, subKey]));
      } else {
        addLeaf(group.key, child, [group.key]);
      }
    }
  }
  return idx;
});

function onClick({ key }) {
  const [, type, path] = String(key).split('|');
  if (!path) return;
  if (type === 'next') router.push(path);
  // External links (online store, client portal) open in a new tab; the legacy
  // SPA (/#/app/...) navigates in place.
  else if (type === 'external') window.open(path, '_blank', 'noopener');
  else window.location.href = path;
}

// Highlight the active route and open its ancestor groups. Uses routeIndex (not
// the lazy `items`, whose closed groups have no children to search), so it works
// even before those groups are expanded/rendered. Accordion-style: only the
// active chain stays open.
watch(
  [() => route.path, routeIndex],
  ([path]) => {
    const meta = routeIndex.value[path];
    selectedKeys.value = meta ? [meta.key] : [];
    if (!meta || !meta.opens.length) return;
    const missing = meta.opens.filter(k => !openKeys.value.includes(k));
    if (missing.length) openKeys.value = [...openKeys.value, ...missing];
  },
  { immediate: true }
);

// Accordion: opening a submenu closes the previously open one at the same
// level. Keys encode ancestry (sub-group keys are `${group}|group|${label}`),
// so the newly opened key keeps only itself plus its parent chain.
watch(openKeys, (nv, ov) => {
  const added = nv.filter(k => !(ov || []).includes(k));
  if (!added.length) return;
  const key = String(added[added.length - 1]);
  const next = key.includes('|group|') ? [key.split('|')[0], key] : [key];
  if (next.length !== nv.length || next.some((k, i) => k !== nv[i])) {
    openKeys.value = next;
  }
});
</script>

<style scoped>
/* A menu row that carries a pending-work count. */
.sb-row {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.sb-row-label {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
}
.sb-row-badge {
  flex: none;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 9px;
  background: var(--sb-primary, #6c5ce7);
  color: #fff;
  font-size: 11px;
  font-weight: 600;
  line-height: 18px;
  text-align: center;
}

/* Takes the leftover height in the sider's flex column and scrolls on its own,
   so the last groups (Settings, AI Reports, Reports) stay reachable. */
.sidebar-menu {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  border-inline-end: none;
  /* The last row would otherwise sit flush against the container's bottom
     edge (the flat/large layouts already pad theirs). The safe-area term
     clears the iOS home indicator in the off-canvas mobile sidebar. */
  padding-bottom: calc(16px + env(safe-area-inset-bottom, 0px));
}
/* Divider between the light sidebar and the content (dark sider keeps none). */
.sidebar-menu.ant-menu-light {
  border-right: 1px solid #ececee;
}

/* Toggle arrow hugs the right edge (Ant's default sits at 16px). */
.sidebar-menu :deep(.ant-menu-submenu-arrow) {
  inset-inline-end: 8px;
}

/* ============ Submenu guide rail ============
   Each open group draws one continuous vertical line down the left of its
   items, so the hierarchy reads at a glance instead of as a flat indented
   list. The active item lights up its slice of the line in the primary
   colour; hovering shows a fainter slice. Only affects the expanded inline
   menu — collapsed mode uses flyout popups, which are untouched. */
.sidebar-menu :deep(.ant-menu-sub.ant-menu-inline) {
  position: relative;
  /* Distinct inset background so the open submenu reads as a nested panel. */
  background: rgba(0, 0, 0, 0.025);
}
/* Open/close submenus INSTANTLY — Ant's height expand/collapse motion over a
   250-item menu is what read as slow; killing the transition makes it snap. */
.sidebar-menu :deep(.ant-menu-sub.ant-menu-inline),
.sidebar-menu :deep(.ant-motion-collapse),
.sidebar-menu :deep(.ant-motion-collapse-legacy),
.sidebar-menu :deep(.ant-menu-submenu-arrow) {
  transition: none !important;
  animation: none !important;
}
.sidebar-menu.ant-menu-dark :deep(.ant-menu-sub.ant-menu-inline) {
  background: rgba(0, 0, 0, 0.22);
}
/* The guide line itself, sitting just under the parent group's label. */
.sidebar-menu :deep(.ant-menu-sub.ant-menu-inline)::before {
  content: '';
  position: absolute;
  top: 2px;
  bottom: 2px;
  inset-inline-start: 18px;
  width: 2px;
  border-radius: 2px;
  background: rgba(0, 0, 0, 0.1);
  pointer-events: none;
}
/* A submenu item needs positioning context for its own rail slice. */
.sidebar-menu :deep(.ant-menu-sub.ant-menu-inline .ant-menu-item),
.sidebar-menu :deep(.ant-menu-sub.ant-menu-inline .ant-menu-submenu-title) {
  position: relative;
}
/* Hover: a faint primary slice of the rail beside the item. */
.sidebar-menu :deep(.ant-menu-sub.ant-menu-inline .ant-menu-item:hover)::after {
  content: '';
  position: absolute;
  top: 7px;
  bottom: 7px;
  inset-inline-start: 18px;
  width: 2px;
  border-radius: 2px;
  background: var(--sb-primary, #6d28d9);
  opacity: 0.45;
  pointer-events: none;
}
/* Active item: a solid primary slice of the rail (overrides the hover slice). */
.sidebar-menu :deep(.ant-menu-sub.ant-menu-inline .ant-menu-item-selected)::after {
  content: '';
  position: absolute;
  top: 5px;
  bottom: 5px;
  inset-inline-start: 18px;
  width: 3px;
  border-radius: 2px;
  background: var(--sb-primary, #6d28d9);
  opacity: 1;
  pointer-events: none;
}

/* Dark sider: lift the rail so it stays visible on the dark background. */
.sidebar-menu.ant-menu-dark :deep(.ant-menu-sub.ant-menu-inline)::before {
  background: rgba(255, 255, 255, 0.14);
}

/* ---- Third-level ul (a sub-group's list) ----
   The whole panel shifts right and carries a REAL left border (the pseudo rail
   sat exactly over the parent's line, so it never showed). No icons at this
   depth, tighter indent, and labels wrap so the full name always shows. */
.sidebar-menu :deep(.ant-menu-sub.ant-menu-inline .ant-menu-sub.ant-menu-inline) {
  margin-inline-start: 14px;
  border-inline-start: 2px solid rgba(0, 0, 0, 0.12);
  background: transparent;
}
.sidebar-menu.ant-menu-dark :deep(.ant-menu-sub.ant-menu-inline .ant-menu-sub.ant-menu-inline) {
  border-inline-start-color: rgba(255, 255, 255, 0.16);
  background: transparent;
}
/* The inherited pseudo rail is redundant inside the nested panel. */
.sidebar-menu :deep(.ant-menu-sub.ant-menu-inline .ant-menu-sub.ant-menu-inline)::before {
  display: none;
}
.sidebar-menu :deep(.ant-menu-sub.ant-menu-inline .ant-menu-sub.ant-menu-inline .ant-menu-item) {
  padding-inline-start: 14px !important;
  height: auto;
  min-height: 36px;
  line-height: 1.35;
  padding-top: 7px;
  padding-bottom: 7px;
}
.sidebar-menu :deep(.ant-menu-sub.ant-menu-inline .ant-menu-sub.ant-menu-inline .ant-menu-item .ant-menu-title-content) {
  white-space: normal;
  overflow: visible;
  text-overflow: unset;
}
/* Hover/active slices sit ON the ul border — grey line everywhere, primary
   slice on the hovered/active row. */
.sidebar-menu :deep(.ant-menu-sub.ant-menu-inline .ant-menu-sub.ant-menu-inline .ant-menu-item:hover)::after,
.sidebar-menu :deep(.ant-menu-sub.ant-menu-inline .ant-menu-sub.ant-menu-inline .ant-menu-item-selected)::after {
  inset-inline-start: -2px;
}
</style>
