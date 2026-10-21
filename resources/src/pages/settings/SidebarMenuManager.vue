<template>
  <div class="page">
    <PageHeader :title="s('Manage Menu')" :breadcrumb="[s('Settings'), s('Manage Menu')]">
      <template #actions>
        <a-space>
          <!-- A company's menu = AdminSystem's (999) order, groups, names and hidden items. -->
          <a-button v-if="isSuperAdmin && !appliesToAll" :loading="copyingDefault" @click="confirmDefaultOrder">
            <template #icon><OrderedListOutlined /></template>
            {{ s('Default order') }}
          </a-button>
          <a-button v-if="isSuperAdmin" danger :loading="resetting" @click="confirmReset">
            <template #icon><UndoOutlined /></template>
            {{ s('Reset to default') }}
          </a-button>
          <a-button type="primary" :disabled="!dirty" :loading="saving" @click="save">
            <template #icon><SaveOutlined /></template>
            {{ s('Save') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-card>
      <!-- Texts in the active language (lang/SidebarMenuManager.es.js, 2026-10-20). -->
      <a-alert
        type="info"
        show-icon
        :message="s('Arrange the menu')"
        :description="isSuperAdmin
          ? s(SUPER_HELP)
          : s(ADMIN_HELP)"
        style="margin-bottom: 12px"
      />
      <!-- Where a Save / Reset lands: AdminSystem = every company; any
           other company (SuperAdmin acting as it, or its own Admin) = only
           that company. Same rule as MenuPositionController. -->
      <a-alert
        :type="appliesToAll ? 'warning' : 'success'"
        show-icon
        :message="appliesToAll
          ? s('Working as AdminSystem: Save and Reset apply to EVERY company (replacing each company\'s own menu order).')
          : s('Save and Reset apply only to {name}.', { name: actingCompanyName })"
        :description="isSuperAdmin && !appliesToAll
          ? s('To change the menu for every company, pick AdminSystem in the company switcher (top bar) first.')
          : undefined"
        style="margin-bottom: 16px"
      />
      <a-alert v-if="dirty" type="warning" show-icon :message="s('You have unsaved changes -- press Save.')" style="margin-bottom: 12px" />

      <div class="smm-toolbar">
        <a-space>
          <a-button size="small" @click="expandAll">{{ s('Expand all') }}</a-button>
          <a-button size="small" @click="expandedKeys = []">{{ s('Collapse all') }}</a-button>
          <a-button v-if="isSuperAdmin" size="small" @click="openAddModal(null)">{{ s('+ Add top-level item') }}</a-button>
        </a-space>
      </div>

      <div class="smm-header-row">
      <span class="smm-col-icon"></span>
      <span class="smm-col-label">{{ s('Item') }}</span>
      <span class="smm-col-actions">{{ s('Actions') }}</span>
    </div>

    <a-tree
      v-model:expandedKeys="expandedKeys"
      :tree-data="treeData"
      :selectable="false"
      :allow-drop="allowDrop"
      draggable
      block-node
      class="smm-tree"
      @dragstart="onDragStart"
      @dragend="dragging = null"
      @drop="onDrop"
    >
      <template #title="{ dataRef }">
        <span class="smm-node" :class="`smm-${dataRef.type}`">
          <span class="smm-col-icon">
            <component :is="dataRef.iconCmp" v-if="dataRef.iconCmp" :size="15" class="smm-icon" />
          </span>
          <span class="smm-col-label">
            <span class="smm-label" :class="{ 'smm-hidden-label': dataRef.hidden }">{{ labelOf(dataRef.entry) }}</span>
            <a-tag v-if="dataRef.type === 'subgroup'" class="smm-tag" :bordered="false">{{ s('group') }}</a-tag>
            <a-tag v-if="dataRef.isCustom" class="smm-tag" color="blue" :bordered="false">{{ s('custom') }}</a-tag>
            <a-tooltip v-if="dataRef.notMine" :title="s('Other roles of the company see it; yours has no permission for it.')">
              <a-tag class="smm-tag" color="default" :bordered="false">{{ s('not in your menu') }}</a-tag>
            </a-tooltip>
          </span>
          <span class="smm-col-actions" @click.stop>
            <!-- Move without dragging (2026-10-20: drag and drop was hard to use). -->
            <a-tooltip :title="s('Move up')">
              <a-button type="text" size="small" :disabled="!canStep(dataRef, -1)" @click="step(dataRef, -1)">
                <template #icon><ArrowUpOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="s('Move down')">
              <a-button type="text" size="small" :disabled="!canStep(dataRef, 1)" @click="step(dataRef, 1)">
                <template #icon><ArrowDownOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="s('Move to…')">
              <a-button type="text" size="small" @click="openMoveModal(dataRef)">
                <template #icon><DragOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="isSuperAdmin && dataRef.isContainer" :title="s('Add submenu item')">
              <a-button type="text" size="small" @click="openAddModal(dataRef)">
                <template #icon><PlusOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="isSuperAdmin" :title="s('Rename')">
              <a-button type="text" size="small" @click="openRenameModal(dataRef)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="dataRef.hidden ? s('Hidden -- click to show') : s('Click to hide')">
              <a-button type="text" size="small" @click="toggleHidden(dataRef)">
                <template #icon>
                  <EyeInvisibleOutlined v-if="dataRef.hidden" />
                  <EyeOutlined v-else />
                </template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="isSuperAdmin && dataRef.isCustom" :title="s('Remove this custom item')">
              <a-button type="text" size="small" danger @click="removeItem(dataRef)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </span>
        </span>
      </template>
    </a-tree>
    </a-card>

    <!-- Move to... : the place picked from a list instead of dragging. -->
    <a-modal
      v-model:open="moveModalVisible"
      :title="moveNode ? s(MOVE_TITLE, { name: labelOf(moveNode.entry) }) : ''"
      :ok-text="s('Move')"
      :cancel-text="s('Cancel')"
      :ok-button-props="{ disabled: moveTarget === undefined }"
      @ok="confirmMove"
    >
      <a-select
        v-model:value="moveTarget" show-search style="width: 100%"
        :options="moveOptions" :filter-option="(input, opt) => opt.label.toLowerCase().includes(input.toLowerCase())"
      />
      <div class="smm-type-hint">{{ s('It goes at the end of the chosen place; then use the arrows to put it exactly where you want.') }}</div>
    </a-modal>

    <a-modal
      v-model:open="addModalVisible"
      :title="addTarget ? s('Add submenu item -- {name}', { name: labelOf(addTarget.entry) }) : s('Add top-level menu item')"
      :cancel-text="s('Cancel')"
      @ok="confirmAddItem"
      @cancel="resetAddForm"
    >
      <a-form layout="vertical">
        <a-form-item :label="s('Type')" v-if="canAddMenuHere">
          <a-radio-group v-model:value="newItem.type" button-style="solid">
            <a-radio-button value="link"><LinkOutlined /> {{ s('Link') }}</a-radio-button>
            <a-radio-button value="menu"><FolderOutlined /> {{ s('Menu') }}</a-radio-button>
          </a-radio-group>
          <div class="smm-type-hint" v-if="newItem.type === 'menu'">
            {{ s('A menu is just a container -- no route of its own. Add submenu items to it after creating it.') }}
            <template v-if="addTarget">{{ s(GROUP_UNDER, { name: labelOf(addTarget.entry) }) }}</template>
          </div>
        </a-form-item>
        <a-form-item v-else-if="addTarget" :label="s('Type')">
          <div class="smm-type-hint">
            {{ s(DEEP_HINT, { name: labelOf(addTarget.entry), n: addTargetDepth }) }}
          </div>
        </a-form-item>
        <a-form-item :label="s('Label')" required>
          <a-input v-model:value="newItem.label" :placeholder="s('e.g. Help Center')" />
        </a-form-item>
        <a-form-item :label="s('Icon')" v-if="!addTarget || newItem.type === 'menu'">
          <a-select v-model:value="newItem.icon" show-search :filter-option="filterIconOption">
            <a-select-option v-for="opt in iconOptions" :key="opt.value" :value="opt.value">
              <span class="smm-icon-option">
                <component :is="opt.icon" :size="16" />
                <span>{{ opt.label }}</span>
              </span>
            </a-select-option>
          </a-select>
        </a-form-item>
        <template v-if="newItem.type !== 'menu'">
          <a-form-item :label="s('External link')">
            <a-switch v-model:checked="newItem.external" />
          </a-form-item>
          <a-form-item :label="newItem.external ? s('URL') : s('Route')" required>
            <a-input
              v-model:value="newItem.route"
              :placeholder="newItem.external ? 'https://example.com' : '/app/some/page'"
            />
          </a-form-item>
        </template>
      </a-form>
    </a-modal>

    <a-modal
      v-model:open="renameModalVisible"
      :title="s('Rename')"
      :cancel-text="s('Cancel')"
      @ok="confirmRename"
    >
      <a-form layout="vertical">
        <a-form-item :label="s('Label')" required>
          <a-input v-model:value="renameValue" :placeholder="s('Label')" @keyup.enter="confirmRename" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Manage Menu — its own standalone page (route settings/sidebar-menu, not
 * embedded in System Settings), and the target of the left-nav "Manage Menu"
 * entry (config/menu.js, key manage_menu).
 *
 * Per-company: menu_positions.company_id scopes everything, same as every
 * other ScopesToCompany resource. Which company is acted on comes from the
 * GLOBAL company switcher (AppTopbar.vue, SuperAdmin only -- see
 * lib/actingCompany.js / stores/company.js), not anything local to this
 * page. SuperAdmin has full CRUD. A SuperAdmin's save/reset while acting as
 * AdminSystem (company 999) REPLICATES to every company -- that's the
 * platform-wide standard; acting as any real company it changes only that
 * company (MenuPositionController::store()/reset()).
 * Anyone else (a plain Admin) manages only their own company and may only
 * reorder (drag/drop) and hide/show items -- adding, removing, renaming or
 * re-typing an item is SuperAdmin-only, enforced both here (isSuperAdmin
 * gates the relevant buttons) and, authoritatively, server-side in
 * MenuPositionController::store() (a diff against the current DB rows,
 * since an Admin save still round-trips the WHOLE tree through the same
 * endpoint -- the UI gates are a convenience, not the real boundary).
 *
 * Shows the FULL default menu (permission gates ignored: the admin arranges the
 * structure for their company) as a draggable tree. Reordering/nesting persists
 * two ways, and this screen writes BOTH on every save:
 * - sidebar_menu_order (PUT/DELETE, id-tree, menuOrder.js) -- the legacy
 *   mechanism, kept for backward compatibility with installs that only ever
 *   reordered known static entries;
 * - menu_positions (POST, key/parent_key rows, menuTree.js) -- the only
 *   mechanism that can represent a CUSTOM item (one with no config/menu.js
 *   counterpart), at any depth.
 * The first time this screen saves after adding/renaming/hiding anything
 * below the top level, EVERY node in the tree gets a menu_positions row (not
 * just the changed ones) -- see flattenPositions(). From that point on,
 * menuTree.js's hasNestedPositions() flips true and BOTH this editor and the
 * live sidebar (stores/menu.js) stop consulting sidebar_menu_order entirely,
 * since applyMenuOrder() only knows about static entries and would silently
 * drop a custom sub-item.
 *
 * Nesting rules, enforced both as drag feedback (allowDrop) and on drop:
 * - ANY module or sub-group -- built-in or custom -- can be dragged into any
 *   other module/sub-group container, or back out to the root to become a
 *   top-level module. onDrop() relabels its `.type` (module <-> subgroup)
 *   when this happens, since toNode() only sets type from depth at creation
 *   time and never revisits it;
 * - items can live directly under any module or sub-group, at any depth;
 * - the ONLY real constraint is depth: SidebarMenu.vue's live render
 *   (buildChildNode) resolves arbitrarily deep nesting, but
 *   MenuPositionController::store() caps the whole tree at MAX_MENU_DEPTH
 *   (4) levels, so a node can't be dropped somewhere that would push its
 *   own deepest descendant past that -- see isValidParent()'s
 *   depthOf()/subtreeHeight() math. There is no longer a fixed
 *   "no group-inside-group" rule; a sub-group CAN sit inside another
 *   sub-group as long as the resulting depth still fits.
 * "+ Add Submenu" applies the same depth math (via canAddMenuHere) to decide
 * whether creating a new Menu (not just a plain item) makes sense there.
 *
 * This screen only ever arranges the SIDEBAR (menu_positions.location always
 * saves as 'sidebar' -- the column exists for a possible future top-nav/
 * footer admin-UI surface, but that's a separate concern from this screen,
 * not something to expose here; menu_positions.location was never meant for
 * the online store's own navigation menu either -- that's Store -> Menus).
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  SaveOutlined, UndoOutlined, EyeOutlined, EyeInvisibleOutlined, DeleteOutlined,
  PlusOutlined, EditOutlined, LinkOutlined, FolderOutlined, OrderedListOutlined,
  ArrowUpOutlined, ArrowDownOutlined, DragOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { looseTranslation } from '../../i18n';
import { useLocalText } from '../../lib/useLocalText';
import SMM_ES from './lang/SidebarMenuManager.es.js';
import { MENU_ICONS, fallbackIcon } from '../../config/menuIcons';
import { MENU_ITEM_ICONS } from '../../config/menuItemIcons';
import { nodeId, applyMenuOrder } from '../../lib/menuOrder';
import { buildTopLevelMenuForEditor, hasNestedPositions } from '../../lib/menuTree';
import { MENU } from '../../config/menu';
import { isEntryEnabled } from '../../config/modules';
import { spaPathOf } from '../../stores/menu';
import { useAuthStore } from '../../stores/auth';
import { useMenuStore } from '../../stores/menu';
import { useCompanyStore } from '../../stores/company';
import http from '../../lib/http';

const { t, te, locale } = useI18n();
const auth = useAuthStore();
const menuStore = useMenuStore();
// This page's texts in the active language (2026-10-20).
const { s } = useLocalText(SMM_ES);
const SUPER_HELP = 'Use the arrows to move an item up or down, "Move to…" to put it in another module, group or at the top level, or drag and drop it. The pencil renames, + adds a submenu item, the eye hides or shows, the trash removes a custom item. Modules turned off for this company are not listed; they keep their place for when they are turned back on.';
const ADMIN_HELP = 'Use the arrows to move an item up or down, "Move to…" to put it in another module, group or at the top level, or drag and drop it. The eye hides or shows an item. Only a SuperAdmin can add, remove or rename menu items. Modules turned off for your company are not listed.';
const MOVE_TITLE = 'Move "{name}" to';
const GROUP_UNDER = 'It becomes a group under "{name}".';
const DEEP_HINT = '"{name}" is already {n} level(s) deep, so a new menu here would have nowhere for its own items to go -- only a link/route item is allowed.';

// Per-company menu layout: which company is acted on comes from the global
// switcher in AppTopbar.vue (lib/actingCompany.js), attached to every
// request automatically by lib/http.js -- nothing company-specific to wire
// up on this page itself. Everyone but SuperAdmin is locked to their own
// company_id, same as ScopesToCompany/resolveActingCompanyId on the
// backend. A plain Admin may only reorder/hide -- see the template's
// isSuperAdmin gates and MenuPositionController::store()'s matching check.
const isSuperAdmin = computed(() => auth.user?.type === 'SuperAdmin');
// Save/Reset scope (MenuPositionController): SuperAdmin acting as
// AdminSystem (999) -> every company; anyone else -> the acting company only.
const companyStore = useCompanyStore();
const appliesToAll = computed(() => isSuperAdmin.value && Number(companyStore.actingCompanyId) === 999);
const actingCompanyName = computed(() =>
  (isSuperAdmin.value ? companyStore.activeCompanyName : auth.user?.company) || s('this company'));

const saving = ref(false);
const resetting = ref(false);
const dirty = ref(false);
const expandedKeys = ref([]);
const dragging = ref(null);
// Raw rows from GET /api/menu-positions -- hide/custom-item/rename state at
// every level once this screen has done a full-tree save; see menuTree.js.
const menuPositions = ref(null);
// Module switches of the acting company (null = everything on).
const moduleFlags = ref(auth.user?.module_flags || null);

const addModalVisible = ref(false);
const addTarget = ref(null); // tree node to add a submenu item under, or null for top-level
// type: 'link' (a route/URL, addable at any level) or 'menu' (a pure
// container, no route -- top-level only; see confirmAddItem()).
const newItem = ref({ label: '', icon: 'bar-chart', route: '', external: false, type: 'link' });
const iconOptions = Object.keys(MENU_ICONS).map(key => ({ value: key, label: key, icon: MENU_ICONS[key] }));
function filterIconOption(input, option) {
  return option.value.toLowerCase().includes(input.toLowerCase());
}
const renameModalVisible = ref(false);
const renameTarget = ref(null);
const renameValue = ref('');

/* ------------------------------------------------------------- tree data */

// Names exactly as the left menu shows them (SidebarMenu.vue label() / tr()):
// a typed name is translated when it has a translation, keys through vue-i18n,
// then the loose lookup (other upper / lower case, spaces <-> underscores).
function tr(key) {
  if (te(key, locale.value)) return t(key);
  return looseTranslation(key) ?? t(key);
}
function labelOf(entry) {
  if (entry.raw) return looseTranslation(entry.label) ?? entry.label;
  if (entry.labelKeys) return entry.labelKeys.map(k => tr(k)).join(' ');
  return tr(entry.label);
}

/**
 * Menu entry → tree node. Containers keep a children array even when empty.
 * `entry.__key` (set by menuTree.js for anything it resolved) is the real
 * persisted identity of a node -- a custom item's key can't be recomputed
 * from its label/route the way nodeId() derives one for static entries, so
 * this must be preferred over recomputing when present.
 */
function toNode(entry, depth) {
  const isTop = depth === 0;
  const type = isTop ? 'module' : (entry.children ? 'subgroup' : 'item');
  const key = isTop ? entry.key : (entry.__key || nodeId(entry, false));
  const isContainer = Array.isArray(entry.children);
  // Every module/submenu (container) gets a real icon -- its own if set, else
  // a stable "random" one keyed off its own id, rather than rendering blank.
  // Plain leaf items keep their route-keyed icon (or none), unchanged.
  const iconCmp = isContainer
    ? (MENU_ICONS[entry.icon] || fallbackIcon(key))
    : MENU_ITEM_ICONS[entry.to];
  // A module switched off for this company (Settings -> Modules) is HIDDEN here
  // but kept in the tree, so a save keeps its place for when it's turned back on.
  // A company with its own dashboard (school, hospital, real estate) has no
  // general Dashboard in the left menu either (stores/menu.js).
  const ownDash = !!auth.user?.own_dashboard && (entry.key === 'dashboard' || entry.to === '/app/dashboard');
  const off = ownDash || !isEntryEnabled(moduleFlags.value, entry, spaPathOf);
  const node = {
    key,
    type,
    entry,
    moduleOff: off,
    class: off ? 'smm-module-off' : undefined,
    style: off ? { display: 'none' } : undefined,
    // Only containers (had children in the default MENU, or gained a
    // submenu item via this editor) may receive drops.
    isContainer,
    iconCmp,
    hidden: !!entry.hidden,
    isCustom: !!entry.custom,
    // In the company's menu but not in YOURS (your role lacks its permission):
    // tagged, so the editor and your left menu don't look different for no reason.
    notMine: !entry.custom && !!entry.permissions?.length && !isSuperAdmin.value && !entry.permissions.some(p => auth.can(p)),
  };
  if (node.isContainer) {
    node.children = entry.children.map(c => toNode(c, depth + 1));
    // A group whose items are ALL from switched-off modules is empty in the
    // left menu too (e.g. "POS Management" with POS off) -- hide it here as well.
    if (!off && node.children.length && node.children.every(c => c.moduleOff)) {
      node.moduleOff = true;
      node.class = 'smm-module-off';
      node.style = { display: 'none' };
    }
  }
  return node;
}

/**
 * `positions` (menu_positions rows) decide structure/overrides at every
 * level (see menuTree.js); `order` (sidebar_menu_order) then arranges the
 * top level and any still-static children, UNLESS `positions` already covers
 * something below the top level -- once it does, it alone is authoritative
 * (see this file's docblock).
 */
function buildTree(order, positions) {
  const topBase = buildTopLevelMenuForEditor(positions, MENU);
  const arranged = (!hasNestedPositions(positions) && Array.isArray(order) && order.length)
    ? applyMenuOrder(order, topBase)
    : topBase;
  return arranged.map(g => toNode(g, 0));
}

const treeData = ref(buildTree(auth.user && auth.user.sidebar_menu_order, null));

async function loadPositions() {
  try {
    // company_id is attached automatically by lib/http.js from the global
    // switcher -- nothing to pass explicitly here.
    menuPositions.value = await http.get('menu-positions');
  } catch (e) {
    menuPositions.value = null;
  }
  // The acting company's module switches: a SuperAdmin reads the company picked
  // in the switcher (same endpoint as Settings -> Modules); an Admin has their own.
  if (isSuperAdmin.value) {
    try {
      moduleFlags.value = (await http.get('module_flags'))?.module_flags || null;
    } catch (e) {
      moduleFlags.value = auth.user?.module_flags || null;
    }
  }
  treeData.value = buildTree(auth.user && auth.user.sidebar_menu_order, menuPositions.value);
}

onMounted(loadPositions);

function expandAll() {
  const keys = [];
  const walk = list => list.forEach(n => {
    if (n.children) { keys.push(n.key); walk(n.children); }
  });
  walk(treeData.value);
  expandedKeys.value = keys;
}

/* ------------------------------------------------------------ drop rules */

/** key → parent node (undefined for root-level keys). */
const parentByKey = computed(() => {
  const map = new Map();
  const walk = (list, parent) => list.forEach(n => {
    map.set(n.key, parent);
    if (n.children) walk(n.children, n);
  });
  walk(treeData.value, null);
  return map;
});

const dataOf = n => (n && n.dataRef) || n;

// Keep in sync with MenuPositionController::store()'s own depth check --
// that's the real, final backend limit; this just gives immediate feedback
// instead of a rejected save.
const MAX_MENU_DEPTH = 4;

/** node's own depth from the root (1 = top-level). */
function depthOf(node) {
  let d = 1;
  let p = parentByKey.value.get(node.key);
  while (p) { d++; p = parentByKey.value.get(p.key); }
  return d;
}

/** How many levels `node`'s own subtree spans, counting itself as 1 (a leaf
 *  is 1; a container is 1 + its tallest child) -- how much deeper than its
 *  new parent this node's own descendants would end up. */
function subtreeHeight(node) {
  if (!node.children || !node.children.length) return 1;
  return 1 + Math.max(...node.children.map(subtreeHeight));
}

/**
 * May `drag` be placed under `parent` (null = root level)? ANY module or
 * subgroup -- built-in or custom -- can be dragged into any other
 * module/subgroup container, or back out to become a top-level module, as
 * long as its own deepest descendant wouldn't end up past MAX_MENU_DEPTH.
 * SidebarMenu.vue's live render (buildChildNode) resolves arbitrary depth,
 * so this is the ONLY real constraint left -- not a fixed "no
 * group-inside-group" rule. Items always fit (their own height is 1).
 */
function isValidParent(drag, parent) {
  // The top level takes anything (2026-10-20): a single page can sit in the
  // main menu too, like Dashboard.
  if (!parent) return true;
  if (!((parent.type === 'module' || parent.type === 'subgroup') && parent.isContainer)) return false;
  return depthOf(parent) + subtreeHeight(drag) <= MAX_MENU_DEPTH;
}

function onDragStart({ node }) {
  dragging.value = dataOf(node);
}

/** Gap-after an expanded container reads (and renders) as "first child". */
function isExpandedContainer(node) {
  return node.isContainer
    && (node.children || []).length > 0
    && expandedKeys.value.includes(node.key);
}

/** Live feedback while dragging: refuse hovering over invalid positions. */
function allowDrop({ dragNode, dropNode, dropPosition }) {
  const drag = (dragNode && dataOf(dragNode)) || dragging.value;
  if (!drag) return true;
  const over = dataOf(dropNode);
  if (over.key === drag.key) return false;
  // dropPosition 0 = inside dropNode; ±1 = the gap around it.
  if (dropPosition === 0) return isValidParent(drag, over);
  // The gap after an expanded container doubles as its first-child slot.
  if (dropPosition === 1 && isExpandedContainer(over) && isValidParent(drag, over)) return true;
  return isValidParent(drag, parentByKey.value.get(over.key));
}

function onDrop(info) {
  const dragNode = dataOf(info.dragNode);
  const dropNode = dataOf(info.node);
  const dropKey = dropNode.key;
  const dragKey = dragNode.key;

  // Ant reports dropPosition relative to the whole list; normalize to
  // -1 (before dropNode) / 1 (after) for gap drops.
  const dropPos = info.node.pos.split('-');
  const relPos = info.dropPosition - Number(dropPos[dropPos.length - 1]);

  // Where the drag node ends up: as first child of a container (dropped onto
  // it, or into the gap right below its expanded header — where Ant draws the
  // indented indicator), or as a sibling of dropNode. null parent = root.
  const asFirstChild = !info.dropToGap
    || (relPos === 1 && isExpandedContainer(dropNode) && isValidParent(dragNode, dropNode));
  const targetParent = asFirstChild ? dropNode : parentByKey.value.get(dropKey);
  if (!isValidParent(dragNode, targetParent)) {
    const tooDeep = targetParent
      && (targetParent.type === 'module' || targetParent.type === 'subgroup')
      && targetParent.isContainer
      && depthOf(targetParent) + subtreeHeight(dragNode) > MAX_MENU_DEPTH;
    if (tooDeep) {
      message.warning(s(TOO_DEEP, { name: labelOf(dragNode.entry), n: subtreeHeight(dragNode) - 1, max: MAX_MENU_DEPTH }));
    } else {
      message.warning(s('Modules and groups can only go to the top level or directly into a module/group.'));
    }
    return;
  }

  const data = [...treeData.value];
  const walk = (list, key, cb) => {
    for (let i = 0; i < list.length; i++) {
      if (list[i].key === key) return cb(list[i], i, list);
      if (list[i].children && walk(list[i].children, key, cb)) return true;
    }
    return false;
  };

  let dragObj;
  walk(data, dragKey, (item, index, arr) => {
    arr.splice(index, 1);
    dragObj = item;
    return true;
  });
  if (!dragObj) return;

  // Any node crossing the module/subgroup boundary (built-in or custom)
  // needs its type relabeled so downstream rules (isValidParent, the
  // "group" tag, further drag targeting) see it correctly at its new depth
  // -- toNode() only sets type from depth once, at creation, and never
  // revisits it. A CHILDLESS module (e.g. "Sales Return", which has no
  // children of its own) becomes a plain item once nested, not a subgroup --
  // matches exactly what it resolves to after a save/reload round-trip
  // (menuTree.js only gives a node `.children` if it actually has any), so
  // the label doesn't flip on you the moment you save.
  retype(dragObj, targetParent);

  if (asFirstChild) {
    walk(data, dropKey, item => {
      item.children = item.children || [];
      item.children.unshift(dragObj);
      return true;
    });
    if (!expandedKeys.value.includes(dropKey)) expandedKeys.value = [...expandedKeys.value, dropKey];
  } else {
    walk(data, dropKey, (item, index, arr) => {
      arr.splice(relPos === -1 ? index : index + 1, 0, dragObj);
      return true;
    });
  }

  treeData.value = data;
  dirty.value = true;
}

const TOO_DEEP = 'This would nest too deep -- "{name}" has its own items {n} level(s) down, and menus only support {max} levels total.';

/**
 * A node's type follows where it lands: top level = module (a single page at
 * the top level is a childless module, like Dashboard); inside = subgroup for a
 * container, item otherwise.
 */
function retype(node, parent) {
  node.type = !parent ? 'module' : (node.isContainer ? 'subgroup' : 'item');
}

/* ------------------------------------------ move without dragging (2026-10-20) */

/** The sibling list holding `node` (the root list for a top-level node). */
function siblingsOf(node) {
  const parent = parentByKey.value.get(node.key);
  return parent ? parent.children : treeData.value;
}

/** Visible siblings only: modules switched off are skipped by the arrows. */
function canStep(node, dir) {
  const list = siblingsOf(node).filter(n => !n.moduleOff);
  const i = list.findIndex(n => n.key === node.key);
  return i >= 0 && i + dir >= 0 && i + dir < list.length;
}

function step(node, dir) {
  const list = siblingsOf(node);
  const visible = list.filter(n => !n.moduleOff);
  const vi = visible.findIndex(n => n.key === node.key);
  const other = visible[vi + dir];
  if (!other) return;
  const a = list.findIndex(n => n.key === node.key);
  const b = list.findIndex(n => n.key === other.key);
  [list[a], list[b]] = [list[b], list[a]];
  treeData.value = [...treeData.value];
  dirty.value = true;
}

const moveModalVisible = ref(false);
const moveNode = ref(null);
const moveTarget = ref(undefined); // '__root__' or a container key

/** Every place `moveNode` may go: the top level + each container that fits (not itself or inside itself). */
const moveOptions = computed(() => {
  const node = moveNode.value;
  if (!node) return [];
  const own = new Set();
  const collect = n => { own.add(n.key); (n.children || []).forEach(collect); };
  collect(node);
  const opts = [{ value: '__root__', label: s('Top level (main menu)') }];
  const walk = (list, path) => list.forEach(n => {
    if (n.moduleOff || own.has(n.key)) return;
    const name = path ? `${path} › ${labelOf(n.entry)}` : labelOf(n.entry);
    if (n.isContainer && isValidParent(node, n)) opts.push({ value: n.key, label: name });
    if (n.children) walk(n.children, name);
  });
  walk(treeData.value, '');
  return opts;
});

function openMoveModal(node) {
  moveNode.value = node;
  moveTarget.value = undefined;
  moveModalVisible.value = true;
}

function confirmMove() {
  const node = moveNode.value;
  if (!node || moveTarget.value === undefined) return;
  const findByKey = (list, key) => {
    for (const n of list) {
      if (n.key === key) return n;
      const hit = n.children && findByKey(n.children, key);
      if (hit) return hit;
    }
    return null;
  };
  const parent = moveTarget.value === '__root__' ? null : findByKey(treeData.value, moveTarget.value);
  const from = siblingsOf(node);
  from.splice(from.findIndex(n => n.key === node.key), 1);
  retype(node, parent);
  if (parent) {
    parent.children = parent.children || [];
    parent.children.push(node);
    if (!expandedKeys.value.includes(parent.key)) expandedKeys.value = [...expandedKeys.value, parent.key];
  } else {
    treeData.value.push(node);
  }
  treeData.value = [...treeData.value];
  dirty.value = true;
  moveModalVisible.value = false;
}

/* ---------------------------------------------- hide / rename / remove */

function toggleHidden(node) {
  node.hidden = !node.hidden;
  dirty.value = true;
}

/** Every key currently in the tree, at any depth -- for new-key uniqueness. */
function collectAllKeys(list) {
  const keys = new Set();
  const walk = arr => arr.forEach(n => { keys.add(n.key); if (n.children) walk(n.children); });
  walk(list);
  return keys;
}

function removeItem(node) {
  const parent = parentByKey.value.get(node.key);
  if (parent) {
    parent.children = (parent.children || []).filter(n => n.key !== node.key);
  } else {
    treeData.value = treeData.value.filter(n => n.key !== node.key);
  }
  dirty.value = true;
}

function openRenameModal(node) {
  renameTarget.value = node;
  renameValue.value = labelOf(node.entry);
  renameModalVisible.value = true;
}

function confirmRename() {
  const value = renameValue.value.trim();
  if (!value) {
    message.warning(s('Label is required.'));
    return;
  }
  const node = renameTarget.value;
  node.entry = { ...node.entry, label: value, raw: true };
  dirty.value = true;
  renameModalVisible.value = false;
}

/* ------------------------------------------------------------- add item */

function openAddModal(target) {
  addTarget.value = target;
  addModalVisible.value = true;
}

/**
 * A new "Menu" (container) needs room for itself PLUS at least one future
 * item beneath it -- so it fits as long as the target's own depth is at
 * most MAX_MENU_DEPTH - 2 (matches the same math isValidParent() uses for
 * dragging an existing module/subgroup).
 */
const addTargetDepth = computed(() => (addTarget.value ? depthOf(addTarget.value) : 0));
const canAddMenuHere = computed(() => addTargetDepth.value + 2 <= MAX_MENU_DEPTH);

function resetAddForm() {
  newItem.value = { label: '', icon: 'bar-chart', route: '', external: false, type: 'link' };
  addTarget.value = null;
}

function confirmAddItem() {
  const label = newItem.value.label.trim();
  const isMenu = canAddMenuHere.value && newItem.value.type === 'menu';
  const route = newItem.value.route.trim();
  if (!label || (!isMenu && !route)) {
    message.warning(isMenu ? s('Label is required.') : s('Label and route/URL are required.'));
    return;
  }

  const existing = collectAllKeys(treeData.value);
  const base = 'custom_' + (label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'item');
  let key = base;
  for (let i = 1; existing.has(key); i++) key = `${base}_${i}`;

  const target = addTarget.value;
  const entry = {
    key, __key: key, label, raw: true, custom: true, permissions: [],
  };
  if (!target || isMenu) entry.icon = newItem.value.icon;
  if (isMenu) {
    // A pure container: no route, just a place to hold submenu items --
    // dropped/added into via drag or "+ Add Submenu" right after creation.
    entry.children = [];
  } else if (newItem.value.external) {
    entry.href = route;
  } else {
    entry.to = route;
  }

  if (!target) {
    treeData.value = [...treeData.value, toNode(entry, 0)];
  } else {
    target.isContainer = true;
    target.children = target.children || [];
    target.children.push(toNode(entry, 1));
    if (!expandedKeys.value.includes(target.key)) expandedKeys.value = [...expandedKeys.value, target.key];
  }

  dirty.value = true;
  addModalVisible.value = false;
  resetAddForm();
}

/* ------------------------------------------------------------ save/reset */

function serialize(list) {
  return list.map(n => (n.isContainer
    ? { id: n.key, children: serialize(n.children || []) }
    : { id: n.key }));
}

/**
 * The WHOLE tree, at every level, flattened to the shape
 * MenuPositionController::store() expects. Every node gets a row -- not just
 * changed ones -- so this becomes the single source of truth for structure,
 * order and overrides once saved (see this file's docblock).
 */
function flattenPositions(list, parentKey = null) {
  const rows = [];
  list.forEach((n, index) => {
    const row = {
      key: n.key,
      parent_key: parentKey,
      sort_order: index,
      label: (n.isCustom || n.entry.raw) && n.entry.label ? n.entry.label : null,
      icon: n.isCustom ? (n.entry.icon || null) : null,
      route: n.isCustom ? (n.entry.href || n.entry.to || null) : null,
      external: n.isCustom ? !!n.entry.href : false,
      is_custom: !!n.isCustom,
      hidden: !!n.hidden,
    };
    rows.push(row);
    if (n.children && n.children.length) {
      rows.push(...flattenPositions(n.children, n.key));
    }
  });
  return rows;
}

async function save() {
  saving.value = true;
  try {
    const order = serialize(treeData.value);
    // sidebar_menu_order: the acting company's legacy top-level order
    // (Setting::forCompany) -- only consulted while a company has no nested
    // menu_positions rows.
    await http.put('sidebar_menu_order', { order });
    const positions = flattenPositions(treeData.value);
    // company_id attached automatically from the global switcher. Scope is
    // decided server-side (MenuPositionController::store()): a SuperAdmin
    // acting as AdminSystem (999) -> copied to every company; otherwise
    // only the acting company.
    const res = await http.post('menu-positions', { positions });
    menuPositions.value = positions;
    // Live-update the sidebar for this session; other users get it at next boot.
    if (auth.user) auth.user.sidebar_menu_order = order;
    // Refresh the shared menu layout too, so the live sidebar and the role
    // editor (permissions follow the menu order) pick up the new structure
    // without a page reload.
    menuStore.loadPositions();
    dirty.value = false;
    message.success(res?.message || t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || e?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

/* "Default order" (2026-10-20): this company's menu = a copy of AdminSystem's. */
const copyingDefault = ref(false);
function confirmDefaultOrder() {
  Modal.confirm({
    title: s('Default order'),
    content: s('Put {name}\'s menu in the same order as AdminSystem\'s (same groups, names and hidden items, at every level)? Its current order is replaced.', { name: actingCompanyName.value }),
    okText: s('Default order'),
    cancelText: s('Cancel'),
    onOk: copyDefaultOrder,
  });
}
async function copyDefaultOrder() {
  copyingDefault.value = true;
  try {
    const res = await http.post('menu-positions/default', {});
    if (auth.user && res?.sidebar_menu_order) auth.user.sidebar_menu_order = res.sidebar_menu_order;
    await loadPositions();
    menuStore.loadPositions();
    dirty.value = false;
    message.success(res?.message || t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || e?.message || t('InvalidData'));
  } finally {
    copyingDefault.value = false;
  }
}

function confirmReset() {
  Modal.confirm({
    title: s('Reset to default'),
    content: appliesToAll.value
      ? s('Restore the original menu structure for EVERY company? Custom items, renames and hidden items at every level are cleared, everywhere.')
      : s('Restore the original menu structure for {name} only? Its custom items, renames and hidden items are cleared.', { name: actingCompanyName.value }),
    okText: s('Reset to default'),
    cancelText: s('Cancel'),
    okType: 'danger',
    onOk: reset,
  });
}

async function reset() {
  resetting.value = true;
  try {
    await http.delete('sidebar_menu_order');
    if (auth.user) auth.user.sidebar_menu_order = null;
    // Also wipe menu_positions: once it covers anything below the top level
    // it's the authoritative structure/order source (see this file's
    // docblock), so sidebar_menu_order alone can no longer restore the
    // factory layout -- both must go for a true reset. Scope as for save:
    // AdminSystem -> every company, otherwise the acting company only
    // (MenuPositionController::reset()).
    const res = await http.delete('menu-positions');
    menuPositions.value = null;
    treeData.value = buildTree(null, null);
    menuStore.loadPositions();
    dirty.value = false;
    message.success(res?.message || t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || e?.message || t('InvalidData'));
  } finally {
    resetting.value = false;
  }
}
</script>

<style scoped>
.smm-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}
.smm-tree {
  max-width: 900px;
}
.smm-tree :deep(.ant-tree-treenode) {
  padding-bottom: 2px;
}
/* Every row's title (and the header above the tree) shares this exact grid,
   so the label/actions columns line up consistently instead of drifting with
   label length or nesting depth: icon | label (flexible) | actions (fixed).
   Only the label column stretches -- indentation eats into it from the left,
   so the actions column still ends at a consistent position relative to each
   row's own available width. */
.smm-header-row,
.smm-node {
  display: grid;
  grid-template-columns: 22px minmax(0, 1fr) 236px;
  align-items: center;
  gap: 8px;
  width: 100%;
}
.smm-header-row {
  /* Approximates a-tree's own switcher width so the header roughly lines up
     with top-level rows; nested rows shift further right with their indent,
     same as any tree -- see this file's docblock comment on the grid. */
  padding: 4px 0 8px 24px;
  font-size: 12px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.45);
  border-bottom: 1px solid #f0f0f0;
  margin-bottom: 4px;
}
.smm-node {
  line-height: 24px;
}
.smm-col-icon {
  display: flex;
  align-items: center;
  justify-content: center;
}
.smm-col-label {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
  overflow: hidden;
}
.smm-col-actions {
  display: flex;
  align-items: center;
  gap: 2px;
}
.smm-icon {
  flex: none;
  opacity: 0.75;
}
.smm-icon-option {
  display: flex;
  align-items: center;
  gap: 8px;
}
.smm-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.smm-module .smm-label {
  font-weight: 600;
}
.smm-subgroup .smm-label {
  font-weight: 500;
}
.smm-tag {
  flex: none;
  font-size: 10px;
  line-height: 16px;
  opacity: 0.7;
}
.smm-hidden-label {
  opacity: 0.5;
  text-decoration: line-through;
}
.smm-type-hint {
  margin-top: 6px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}
</style>
