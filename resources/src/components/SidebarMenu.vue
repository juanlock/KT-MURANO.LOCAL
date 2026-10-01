<template>
  <a-menu
    :key="menuVersion"
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
import { computed, onMounted, ref, watch, watchEffect, h } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { resolveTarget } from '../config/menu';
import { MENU_ICONS, fallbackIcon } from '../config/menuIcons';
import { MENU_ITEM_ICONS } from '../config/menuItemIcons';
import { useAuthStore } from '../stores/auth';
import { useUiStore } from '../stores/ui';
import { useMenuStore } from '../stores/menu';
import { usePendingWorkStore } from '../stores/pendingWork';
import { useCompanyStore } from '../stores/company';
import { isLightColor } from '../lib/colorUtils';
import { isMenuEntryAllowed } from '../lib/menuVisibility';

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
// Drives systemOnly entries' visibility -- see isMenuEntryAllowed().
const company = useCompanyStore();
const route = useRoute();
const router = useRouter();

// Dark Menu's background is the EXACT chosen Primary Color (no darkening) --
// applied via plain CSS below (.ant-menu-dark rules), not an ant-design-vue
// Menu component-token override: that route was tried first and had to be
// reverted because ant-design-vue merges a components.Menu override into the
// token BEFORE it branches into light vs. dark styles, so it leaked the dark
// background onto every LIGHT-themed <a-menu> in the app too (with no
// matching text-colour change -- black text on a dark fill). These CSS vars
// only ever apply where the .ant-menu-dark class is actually present, so
// there's no equivalent leak.
//
// Set on :root (not just this element's inline style) because a collapsed
// sidebar's submenu flyouts render via Vue Teleport to document.body --
// outside this element's DOM subtree, so a locally-scoped custom property
// would not inherit into them.
//
// Text/icon colour: 'auto' (default) picks white or black for contrast
// against whatever colour was chosen, same idea as picking a photo's caption
// colour off its background -- a bright pick (e.g. #00b294) gets dark text,
// a deep one gets white text. ui.sidebarTextMode lets it be forced either way
// from the Customizer for the rare colour auto doesn't call right.
const sbIsLight = computed(() => isLightColor(ui.primaryColor));
const sbText = computed(() => {
  if (ui.sidebarTextMode === 'white') return 'rgba(255, 255, 255, 0.95)';
  if (ui.sidebarTextMode === 'black') return 'rgba(0, 0, 0, 0.88)';
  return sbIsLight.value ? 'rgba(0, 0, 0, 0.88)' : 'rgba(255, 255, 255, 0.95)';
});
// A translucent layer of the TEXT colour reads as a highlight against any
// background lightness -- used for hover/selected rows instead of a second
// fixed colour, so it never has to fight the chosen Primary Color for
// contrast the way a same-hue highlight would.
const sbOverlay = computed(() => (sbText.value.includes('255, 255, 255') ? 'rgba(255, 255, 255, 0.16)' : 'rgba(0, 0, 0, 0.08)'));
const sbOverlayStrong = computed(() => (sbText.value.includes('255, 255, 255') ? 'rgba(255, 255, 255, 0.26)' : 'rgba(0, 0, 0, 0.14)'));
watchEffect(() => {
  const root = document.documentElement.style;
  root.setProperty('--sb-bg', ui.primaryColor);
  root.setProperty('--sb-sub-bg', ui.primaryColor);
  root.setProperty('--sb-text', sbText.value);
  root.setProperty('--sb-overlay', sbOverlay.value);
  root.setProperty('--sb-overlay-strong', sbOverlayStrong.value);
  // Menu Font (Customizer): applies regardless of light/dark theme, not just
  // Dark Menu -- a row-text-size/weight preference, not a contrast fix. Also
  // set on :root so the Teleported flyout popup (collapsed sidebar) matches.
  root.setProperty('--sb-font-size', `${ui.sidebarFontSize}px`);
  root.setProperty('--sb-font-weight', ui.sidebarBold ? '700' : '400');
});

const selectedKeys = ref([]);
const openKeys = ref([]);

// Admin-configured top-level layout (Settings -> Manage Menus). No-op today
// since menu_positions is empty until that screen exists -- see menu.js's
// `menu` getter, which falls back to the untouched default+per-user-order
// behavior whenever nothing has been saved there.
onMounted(() => menuStore.loadPositions());

/** Legacy rule: no permissions listed = always visible; otherwise ANY match. */
function allowed(entry) {
  return isMenuEntryAllowed(entry, { can: p => auth.can(p), actingCompanyId: company.actingCompanyId, isSuperAdmin: auth.user?.type === 'SuperAdmin', hiddenByRole: key => (auth.permissions || []).includes('menu_hide:' + key) });
}

// Sidebar "filter menus" search box (below the logo, AdminLayout.vue).
// Empty query = every existing allowed()/hasVisibleDescendant() check below
// behaves exactly as it always did (matchesSearch is a no-op then).
const searchQuery = computed(() => (ui.menuSearchQuery || '').trim().toLowerCase());
function matchesSearch(entry) {
  if (!searchQuery.value) return true;
  return label(entry).toLowerCase().includes(searchQuery.value);
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

/** Same as icon(), but for a top-level module: falls back to a stable
 * "random" icon (keyed off its own id) instead of rendering blank when it
 * has no icon of its own or an icon name that no longer resolves. Named
 * distinctly from the `moduleIcon` PARAMETER used below (a plain icon-name
 * string threaded through buildChildNode) to avoid shadowing confusion. */
function topLevelIcon(group) {
  const Cmp = MENU_ICONS[group.icon] || fallbackIcon(group.key);
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

  // A VNode, NOT a render function: a-menu's items API only accepts a string
  // or a VNode for `label` -- a function got stringified and its source code
  // showed up as the label (e.g. Online Store with pending orders).
  return h('span', { class: 'sb-row' }, [
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

/**
 * Keys encode the target, so click handling needs no lookup. `groupKey` is
 * always the TOP-LEVEL module's key regardless of how deep this leaf sits --
 * stable even if an intermediate subgroup gets renamed or the leaf moves
 * between subgroups within the same module.
 */
function leafItem(groupKey, entry, withIcon) {
  const target = entry.href
    ? { type: 'external', path: entry.href }
    : resolveTarget(entry.to);
  return {
    // The `|system` suffix on systemOnly entries is a CSS hook (see the
    // style block: Ant reflects the raw key verbatim as data-menu-id, so a
    // plain attribute selector can style/tag this row) -- NOT a UI-driven
    // choice. It must also be added in routeIndex's addLeaf() below, which
    // independently builds the SAME key from the SAME entry for
    // highlighting the active item; if the two ever disagree, navigating to
    // a systemOnly page stops highlighting/auto-expanding it in the sidebar.
    key: `${groupKey}|${target.type}|${target.path}${entry.systemOnly ? '|system' : ''}`,
    label: labelNode(entry),
    icon: withIcon ? itemIcon(entry, withIcon) : undefined,
  };
}

/** Whether `entry` (a container) has >=1 visible leaf anywhere beneath it, at
 *  any depth -- decides if it (or an ancestor) should render as a submenu.
 *  Also the search filter: matchesSearch() is a no-op with an empty query,
 *  so this is unchanged when nothing is typed in the sidebar search box. */
function hasVisibleDescendant(entry) {
  return (entry.children || []).some(c => (c.children ? hasVisibleDescendant(c) : (allowed(c) && matchesSearch(c))));
}

/**
 * Builds one child node (leaf or nested subgroup), recursively, at any
 * depth -- module > subgroup > subgroup > ... > item, matching what the
 * Manage Menu editor's tree and menu_positions already support structurally.
 * `groupKey` = the top-level module's key (leaf-key prefix, see leafItem()).
 * `containerKey` = the immediate parent container's key, used to derive
 * THIS node's own subgroup key if it turns out to be a container.
 * `moduleIcon` = the module's icon, shown only on items/subgroups directly
 * under it (depth 0) -- anything nested deeper relies on indent alone,
 * matching the original 2-level design's convention.
 */
function buildChildNode(entry, groupKey, containerKey, moduleIcon, depth) {
  if (entry.children) {
    if (!hasVisibleDescendant(entry)) return null;
    // __key is menuTree.js's stable identity for every node (set on both
    // static and custom entries) -- prefer it over label so a rename doesn't
    // shift this subgroup's Ant Menu key out from under its own open/select
    // state. label is only a defensive fallback.
    const subKey = `${containerKey}|group|${entry.__key || entry.label}`;
    return {
      key: subKey,
      icon: icon(entry.icon) || (depth === 0 ? icon(moduleIcon) : undefined),
      label: label(entry),
      children: entry.children
        .map(c => buildChildNode(c, groupKey, subKey, moduleIcon, depth + 1))
        .filter(Boolean),
    };
  }
  if (!allowed(entry)) return null;
  if (!matchesSearch(entry)) return null;
  return leafItem(groupKey, entry, depth === 0 ? moduleIcon : undefined);
}

/** Build a module's child nodes (leaves + arbitrarily-nested sub-groups), eagerly. */
function buildGroupChildren(group) {
  return group.children
    .map(child => buildChildNode(child, group.key, group.key, group.icon, 0))
    .filter(Boolean);
}

/**
 * Arbitrary-depth menu (group > subgroup > subgroup > ... > item). Children
 * are built eagerly so a submenu's content is already in the DOM when it
 * opens — expanding then plays a single smooth animation. (An earlier lazy
 * version built children only on open, which made the submenu animate empty
 * and then re-expand once the items arrived — the "double open" jump.)
 * Performance comes from the scoped scrollbar CSS in AdminLayout, not from
 * withholding the nodes.
 */
const items = computed(() => {
  const out = [];
  for (const group of menuStore.menu) {
    if (!allowed(group)) continue;
    if (group.children && group.children.length === 0) {
      // A genuinely empty container (e.g. a "Menu" just created via Manage
      // Menu, nothing added to it yet) -- `[] && hasVisibleDescendant()`
      // would be truthy-but-false and fall through every branch below,
      // making it invisible with no way to confirm it was even created.
      // Show it as an empty submenu instead; it fills in once items get
      // added to it. Hidden while searching -- it can never match anything.
      if (!searchQuery.value) {
        out.push({ key: group.key, icon: topLevelIcon(group), label: label(group), children: [] });
      }
    } else if (group.children && hasVisibleDescendant(group)) {
      out.push({ key: group.key, icon: topLevelIcon(group), label: label(group), children: buildGroupChildren(group) });
    } else if (group.to) {
      if (!matchesSearch(group)) continue;
      const tgt = resolveTarget(group.to);
      out.push({ key: `${group.key}|${tgt?.type}|${tgt?.path}`, icon: topLevelIcon(group), label: labelNode(group) });
    } else if (group.href) {
      // A childless top-level entry with an external link only -- e.g. a
      // custom item added via Settings -> Manage Menus with "external" set.
      if (!matchesSearch(group)) continue;
      out.push({ key: `${group.key}|external|${group.href}`, icon: topLevelIcon(group), label: labelNode(group) });
    }
  }
  return out;
});

/**
 * Forces a-menu to fully remount (not patch) whenever the top-level item SET
 * changes shape -- e.g. once auth.permissions/menuStore.positions finish
 * loading after the sidebar's first paint (which renders with whatever is
 * available synchronously, per allowed()'s fail-open-on-`failed`-only rule).
 * Without this, a-menu can keep a stale item's rendered label/DOM attached to
 * a newly-appearing key when the array is patched in place rather than
 * replaced -- reproducible: a freshly permission-gated top-level item can
 * render with a stray label left over from whatever WAS at that array
 * position on the first, pre-auth-load pass, until something forces a full
 * re-render (observed via a manual page reload). Keying on the item keys
 * (not just .length) also catches same-count reorders/relabels.
 */
const menuVersion = computed(() => items.value.map(i => i.key).join('|'));

/**
 * path → { key, opens[] } built straight from the menu tree (NOT the lazy
 * `items`, whose closed groups have no children to search). Lets a route
 * change highlight the active item and open its FULL ancestor chain, at any
 * depth, even while collapsed. Computed: item keys embed the parent group
 * key, which changes when the admin moves an item to another module.
 */
const routeIndex = computed(() => {
  const idx = {};
  const addLeaf = (groupKey, entry, opens) => {
    if (entry.href) return;
    const tgt = resolveTarget(entry.to);
    // Must match leafItem()'s key exactly -- see the comment there.
    if (tgt?.type === 'next') idx[tgt.path] = { key: `${groupKey}|next|${tgt.path}${entry.systemOnly ? '|system' : ''}`, opens };
  };
  const walk = (entry, groupKey, containerKey, opens) => {
    if (entry.children) {
      const subKey = `${containerKey}|group|${entry.__key || entry.label}`;
      const nextOpens = [...opens, subKey];
      entry.children.forEach(c => walk(c, groupKey, subKey, nextOpens));
    } else {
      addLeaf(groupKey, entry, opens);
    }
  };
  for (const group of menuStore.menu) {
    if (!group.children) { addLeaf(group.key, group, []); continue; }
    group.children.forEach(c => walk(c, group.key, group.key, [group.key]));
  }
  return idx;
});

/** Every group/subgroup key (at any depth) that currently has a matching
 *  descendant, so a search can expand every branch holding a result at once
 *  instead of the accordion's normal "one open branch" behaviour. */
function collectMatchingGroupKeys(entry, containerKey, acc) {
  if (!entry.children) return;
  const subKey = `${containerKey}|group|${entry.__key || entry.label}`;
  if (hasVisibleDescendant(entry)) {
    acc.push(subKey);
    entry.children.forEach(c => collectMatchingGroupKeys(c, subKey, acc));
  }
}
const searchOpenKeys = computed(() => {
  if (!searchQuery.value) return [];
  const acc = [];
  for (const group of menuStore.menu) {
    if (!allowed(group) || !group.children || !hasVisibleDescendant(group)) continue;
    acc.push(group.key);
    group.children.forEach(c => collectMatchingGroupKeys(c, group.key, acc));
  }
  return acc;
});
// Expand every matching branch while searching; restore the normal
// route-based expansion (single active chain) the moment the box is cleared.
watch(searchQuery, (q) => {
  if (q) {
    openKeys.value = searchOpenKeys.value;
  } else {
    const meta = routeIndex.value[route.path];
    openKeys.value = meta ? meta.opens : [];
  }
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

/**
 * A subgroup key is its own full ancestor chain, self-qualified at creation
 * (`${containerKey}|group|${ownId}` — see buildChildNode()/routeIndex()), so
 * splitting on the `|group|` separator and re-joining progressively recovers
 * every ancestor key, root to leaf, at any depth.
 */
function ancestorChain(key) {
  const parts = key.split('|group|');
  const chain = [parts[0]];
  for (let i = 1; i < parts.length; i++) chain.push(`${chain[i - 1]}|group|${parts[i]}`);
  return chain;
}

// Accordion: opening a submenu closes the previously open one at the same
// level. The newly opened key keeps its full ancestor chain (root module
// through every nested subgroup down to itself) so a deep group stays
// reachable, while unrelated branches still collapse. Suspended while
// searching -- the search watcher above intentionally opens every matching
// branch at once, which this would otherwise immediately collapse back down
// to just the last one.
watch(openKeys, (nv, ov) => {
  if (searchQuery.value) return;
  const added = nv.filter(k => !(ov || []).includes(k));
  if (!added.length) return;
  const key = String(added[added.length - 1]);
  const next = key.includes('|group|') ? ancestorChain(key) : [key];
  if (next.length !== nv.length || next.some((k, i) => k !== nv[i])) {
    openKeys.value = next;
  }
});
</script>

<style scoped>
/* Dark sidebar background, following the chosen Primary Color (see the
   watchEffect above) instead of Ant's fixed navy. Scoped to .ant-menu-dark
   specifically -- this component also renders with the LIGHT theme (default
   sidebar layout), and must not touch that. The flyout popup rule covers a
   collapsed sidebar's submenus, which Ant renders via Teleport to
   document.body -- outside this element's normal DOM subtree, but still
   reachable by a :root-level custom property. */
.sidebar-menu.ant-menu-dark {
  background: var(--sb-bg) !important;
}
:global(.ant-menu-submenu-popup.ant-menu-dark),
:global(.ant-menu-submenu-popup .ant-menu.ant-menu-dark) {
  background: var(--sb-sub-bg) !important;
}
/* Text/icon colour: ant-design-vue bakes Menu's dark-theme text colours as
   literal computed values (not var() references), derived from the GLOBAL
   colorTextLightSolid token -- always white, regardless of how light the
   chosen Primary Color is. Overriding here instead of via that token keeps
   the change scoped to just this dark sidebar (colorTextLightSolid is also
   used for e.g. every Primary button's label app-wide).
   :deep() is required on every selector below: .ant-menu-item etc. are
   rendered INSIDE the <a-menu> by Ant's own implementation, not written in
   this component's template, so Vue's `scoped` attribute never lands on
   them -- an un-:deep()'d rule silently matches nothing (confirmed: this is
   exactly why the background override above worked immediately, targeting
   the <a-menu> root element itself, while a first pass at this text-colour
   rule without :deep() compiled fine but never actually applied). The popup
   selectors stay :global() (Teleported to document.body, outside this
   component's tree entirely -- :deep() cannot reach there, :global() skips
   scoping altogether). */
.sidebar-menu.ant-menu-dark :deep(.ant-menu-item),
.sidebar-menu.ant-menu-dark :deep(.ant-menu-submenu-title),
.sidebar-menu.ant-menu-dark :deep(.ant-menu-item-group-title),
:global(.ant-menu-submenu-popup.ant-menu-dark .ant-menu-item),
:global(.ant-menu-submenu-popup.ant-menu-dark .ant-menu-submenu-title) {
  color: var(--sb-text) !important;
}
.sidebar-menu.ant-menu-dark :deep(.ant-menu-item .anticon),
.sidebar-menu.ant-menu-dark :deep(.ant-menu-submenu-title .anticon),
:global(.ant-menu-submenu-popup.ant-menu-dark .anticon) {
  color: var(--sb-text) !important;
}
/* Hover/selected: a translucent layer of the TEXT colour, not a second fixed
   colour -- reads as a highlight regardless of how light/dark the chosen
   Primary Color is (a same-hue highlight would vanish against its own
   background when background = the exact colour, as it now is). */
.sidebar-menu.ant-menu-dark :deep(.ant-menu-item:not(.ant-menu-item-selected):hover),
.sidebar-menu.ant-menu-dark :deep(.ant-menu-submenu-title:hover),
:global(.ant-menu-submenu-popup.ant-menu-dark .ant-menu-item:hover) {
  background: var(--sb-overlay) !important;
  color: var(--sb-text) !important;
}
.sidebar-menu.ant-menu-dark :deep(.ant-menu-item-selected),
:global(.ant-menu-submenu-popup.ant-menu-dark .ant-menu-item-selected) {
  background: var(--sb-overlay-strong) !important;
  color: var(--sb-text) !important;
}

/* Menu Font (Customizer: row size + bold) -- applies to BOTH the light
   Default layout and Dark Menu, and to the collapsed sidebar's Teleported
   flyout popups (the :global() rules), not just the dark theme like the
   colour rules above. */
.sidebar-menu :deep(.ant-menu-item),
.sidebar-menu :deep(.ant-menu-submenu-title),
:global(.ant-menu-submenu-popup .ant-menu-item),
:global(.ant-menu-submenu-popup .ant-menu-submenu-title) {
  font-size: var(--sb-font-size, 14px) !important;
  font-weight: var(--sb-font-weight, 400) !important;
}

/* A menu row that carries a pending-work count. :deep()/:global() like the
   rules above -- the label VNode renders INSIDE <a-menu> (and in the
   collapsed sidebar's Teleported flyout), where the scoped attribute never
   lands; without it the badge was unstyled and read as part of the name
   ("Online Orders3"). */
.sidebar-menu :deep(.sb-row),
:global(.ant-menu-submenu-popup .sb-row) {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.sidebar-menu :deep(.sb-row-label),
:global(.ant-menu-submenu-popup .sb-row-label) {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
}
.sidebar-menu :deep(.sb-row-badge),
:global(.ant-menu-submenu-popup .sb-row-badge) {
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

/* systemOnly entries (e.g. Login Page Settings) -- install-wide, not scoped
   to whichever company is currently acting, so they get their own distinct
   color + a "Global" tag rather than blending in with every other
   (company-scoped) item. Applied via a plain attribute selector on the raw
   key Ant reflects as data-menu-id (leafItem() appends a `|system` suffix
   to the key for exactly this purpose) -- NOT via label content: a-menu's
   items-array API only accepts a string or VNode for `label`; a function
   (tried first) gets stringified into literal text instead of invoked as a
   render callback (see the "Labels are PLAIN STRINGS on purpose" comment
   above labelNode() -- this is what it's warning about). Explicit colors
   here win over the ancestor's --sb-text !important rule despite not using
   !important themselves -- inheritance always loses to any rule that
   targets the element directly. */
.sidebar-menu :deep([data-menu-id$="|system"]) {
  position: relative;
}
.sidebar-menu :deep([data-menu-id$="|system"] .ant-menu-title-content) {
  color: #b45309;
  font-weight: 600;
}
.sidebar-menu :deep([data-menu-id$="|system"])::after {
  content: 'Global';
  position: absolute;
  top: 50%;
  inset-inline-end: 12px;
  transform: translateY(-50%);
  padding: 0 6px;
  border-radius: 4px;
  background: rgba(180, 83, 9, 0.14);
  color: #b45309;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  line-height: 16px;
  pointer-events: none;
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

/* Dark Menu: the rail is normally the Primary Color itself -- now that the
   sidebar's OWN background is that exact same colour, a same-colour rail
   would be invisible against it. Use the sidebar's text colour instead,
   which is already chosen specifically to contrast with that background. */
.sidebar-menu.ant-menu-dark :deep(.ant-menu-sub.ant-menu-inline .ant-menu-item:hover)::after {
  background: var(--sb-text);
  opacity: 0.55;
}
.sidebar-menu.ant-menu-dark :deep(.ant-menu-sub.ant-menu-inline .ant-menu-item-selected)::after {
  background: var(--sb-text);
}

/* Dark Menu: lift the guide line so it stays visible against whatever
   Primary Color was chosen, light or dark. */
.sidebar-menu.ant-menu-dark :deep(.ant-menu-sub.ant-menu-inline)::before {
  background: var(--sb-overlay);
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
  border-inline-start-color: var(--sb-overlay);
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
