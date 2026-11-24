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
          <!-- A company's menu saves itself on every change (2026-11-02); AdminSystem
               keeps Save, which asks before replacing every company's menu. -->
          <a-button v-if="appliesToAll" type="primary" :disabled="!dirty" :loading="saving" @click="save">
            <template #icon><SaveOutlined /></template>
            {{ s('Save') }}
          </a-button>
          <span v-else class="smm-autosave" :class="{ 'smm-autosave-error': saveError }">
            <template v-if="saving"><LoadingOutlined /> {{ s('Saving…') }}</template>
            <a-button v-else-if="saveError" size="small" danger @click="autoSave"><WarningOutlined /> {{ s('Not saved -- try again') }}</a-button>
            <template v-else-if="dirty"><LoadingOutlined /> {{ s('Saving…') }}</template>
            <template v-else-if="savedOnce"><CheckCircleOutlined /> {{ s('All changes saved') }}</template>
            <template v-else>{{ s('Changes are saved automatically') }}</template>
          </span>
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
      <a-alert v-if="dirty && appliesToAll" type="warning" show-icon :message="s('You have unsaved changes -- press Save.')" style="margin-bottom: 12px" />

      <div class="smm-toolbar">
        <a-space>
          <a-button size="small" @click="expandAll">{{ s('Expand all') }}</a-button>
          <a-button size="small" @click="expandedKeys = []">{{ s('Collapse all') }}</a-button>
          <a-button v-if="isSuperAdmin" size="small" @click="openAddModal(null)">{{ s('+ Add top-level item') }}</a-button>
          <!-- A-Z (2026-11-23): every group and, inside each one, its items, at every level, by the name shown. -->
          <a-button size="small" @click="confirmSortAlpha"><SortAscendingOutlined /> {{ s('Sort A-Z') }}</a-button>
          <!-- Filter (2026-11-23): only the items whose name matches, with the groups that hold them opened. -->
          <a-input-search v-model:value="filterText" size="small" allow-clear style="width: 240px"
            :placeholder="s('Filter menus…')" @input="applyFilter" @search="applyFilter" />
          <span v-if="filterText" class="smm-filter-count">{{ s('{n} found', { n: filterMatches }) }}</span>
        </a-space>
      </div>

      <div class="smm-header-row">
      <span></span>
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
          <HolderOutlined class="smm-handle" :title="s('Drag to move')" />
          <span class="smm-col-icon">
            <component :is="dataRef.iconCmp" v-if="dataRef.iconCmp" :size="15" class="smm-icon" />
          </span>
          <span class="smm-col-label">
            <span class="smm-label" :class="{ 'smm-hidden-label': dataRef.hidden }">{{ labelOf(dataRef.entry) }}</span>
            <a-tag v-if="dataRef.type === 'subgroup'" class="smm-tag" :bordered="false">{{ s('group') }}</a-tag>
            <!-- The top menu's name (2026-11-24): the company's own, else the proposal. -->
            <a-tooltip v-if="dataRef.type === 'module' && dataRef.isContainer && shortOf(dataRef) && shortOf(dataRef) !== labelOf(dataRef.entry)" :title="s('Short name (top menu)')">
              <a-tag class="smm-tag" :color="dataRef.entry.shortLabel ? 'cyan' : 'default'" :bordered="false">↑ {{ shortOf(dataRef) }}</a-tag>
            </a-tooltip>
            <a-tag v-if="dataRef.isCustom" class="smm-tag" color="blue" :bordered="false">{{ s('custom') }}</a-tag>
            <a-tooltip v-if="dataRef.notMine" :title="s('Other roles of the company see it; yours has no permission for it.')">
              <a-tag class="smm-tag" color="default" :bordered="false">{{ s('not in your menu') }}</a-tag>
            </a-tooltip>
            <a-tag v-if="saKeys.includes(dataRef.key)" class="smm-tag" color="purple" :bordered="false"><SafetyOutlined /> {{ s('SuperAdmin only') }}</a-tag>
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
            <a-tooltip v-if="dataRef.type === 'module' && dataRef.isContainer" :title="s('Short name (top menu)')">
              <a-button type="text" size="small" @click="openShortModal(dataRef)">
                <template #icon><FontSizeOutlined /></template>
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
            <!-- Seen only by the SuperAdmin, in every company (AdminSystem only, 2026-11-23). -->
            <a-tooltip v-if="appliesToAll" :title="saKeys.includes(dataRef.key) ? s('SuperAdmin only -- click to show it to every role again') : s('Make it visible to the SuperAdmin only')">
              <a-button type="text" size="small" :loading="saBusy === dataRef.key" :class="{ 'smm-sa-on': saKeys.includes(dataRef.key) }" @click="toggleSuperAdminOnly(dataRef)">
                <template #icon><SafetyOutlined /></template>
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
      <div class="smm-move-label">{{ s('Place') }}</div>
      <a-select
        v-model:value="moveTarget" show-search style="width: 100%"
        :options="moveOptions" :filter-option="(input, opt) => opt.label.toLowerCase().includes(input.toLowerCase())"
        @change="moveAfter = '__end__'"
      />
      <template v-if="moveTarget !== undefined">
        <div class="smm-move-label">{{ s('Position') }}</div>
        <a-select v-model:value="moveAfter" style="width: 100%" :options="movePositionOptions" />
      </template>
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

    <!-- Short name for the top menu (2026-11-24): any user who can arrange the menu (it renames nothing). -->
    <a-modal v-model:open="shortModalVisible" :title="shortTarget ? s('Top menu: {name}', { name: labelOf(shortTarget.entry) }) : ''"
      :ok-text="s('Save')" :cancel-text="s('Cancel')" @ok="confirmShort">
      <p class="smm-short-help">{{ s('Shown when the side menu is collapsed and its groups go to the top bar; the full name appears on hover.') }}</p>
      <a-form layout="vertical">
        <a-form-item :label="s('Short name')">
          <a-input v-model:value="shortValue" :maxlength="40" show-count allow-clear :placeholder="shortProposal || ''" @keyup.enter="confirmShort" />
        </a-form-item>
      </a-form>
      <div v-if="shortProposal" class="smm-short-help">
        {{ s('Empty = the proposal: {name}', { name: shortProposal }) }}
        <a-button size="small" type="link" @click="shortValue = shortProposal">{{ s('Use the proposal') }}</a-button>
      </div>
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
 *   (5) levels, so a node can't be dropped somewhere that would push its
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
import { ref, computed, onMounted, onBeforeUnmount, h, watch } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  SaveOutlined, UndoOutlined, EyeOutlined, EyeInvisibleOutlined, DeleteOutlined,
  PlusOutlined, EditOutlined, LinkOutlined, FolderOutlined, OrderedListOutlined,
  ArrowUpOutlined, ArrowDownOutlined, DragOutlined,
  HolderOutlined, LoadingOutlined, CheckCircleOutlined, WarningOutlined, SafetyOutlined, SortAscendingOutlined, FontSizeOutlined,
} from '@ant-design/icons-vue';
import { proposedShort } from '../../config/menuShort';
import PageHeader from '../../components/PageHeader.vue';
import { looseTranslation } from '../../i18n';
import { useLocalText } from '../../lib/useLocalText';
import SMM_ES from './lang/SidebarMenuManager.es.js';
import { MENU_ICONS, fallbackIcon } from '../../config/menuIcons';
import { MENU_ITEM_ICONS } from '../../config/menuItemIcons';
import { nodeId, applyMenuOrder } from '../../lib/menuOrder';
import { buildTopLevelMenuForEditor, hasNestedPositions } from '../../lib/menuTree';
import { MENU } from '../../config/menu';
import { isEntryEnabled, moduleKeysOfEntry, TOGGLEABLE_MODULES, ALL_MODULES_ON } from '../../config/modules';
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
const SUPER_HELP = 'Drag an item by its handle and drop it between two rows (to put it inside a menu, open the menu first), use the arrows to move it up or down, or "Move to…" to pick the place and position from a list. The pencil renames, + adds a submenu item, the eye hides or shows, the trash removes a custom item. Every change is saved automatically (AdminSystem: press Save). Modules turned off for this company are not listed; they keep their place for when they are turned back on.';
const ADMIN_HELP = 'Drag an item by its handle and drop it between two rows (to put it inside a menu, open the menu first), use the arrows to move it up or down, or "Move to…" to pick the place and position from a list. The eye hides or shows an item. Every change is saved automatically. Only a SuperAdmin can add, remove or rename menu items. Modules turned off for your company are not listed.';
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
  // AdminSystem = every module, like its left menu (2026-11-23: B2B and Call center showed in the menu but could
  // not be arranged here, being off in 999's own switches).
  // ALL_MODULES_ON, not null: null still leaves the off-by-default modules (B2B, Call center, Transport...) off.
  if (appliesToAll.value) {
    moduleFlags.value = ALL_MODULES_ON;
  } else if (isSuperAdmin.value) {
    try {
      moduleFlags.value = (await http.get('module_flags'))?.module_flags || null;
    } catch (e) {
      moduleFlags.value = auth.user?.module_flags || null;
    }
  }
  treeData.value = buildTree(auth.user && auth.user.sidebar_menu_order, menuPositions.value);
  if (filterText.value) applyFilter();
}

onMounted(loadPositions);
// The company switcher can finish loading (or change) after this page opened: AdminSystem's "every module" and the
// company's own menu follow it.
watch(() => companyStore.actingCompanyId, (v, old) => { if (String(v) !== String(old)) loadPositions(); });

/* ------------------------------------------------------ A-Z (2026-11-23) */
// Every level sorted by the name shown in the current language (accents ignored); the Dashboard stays first.
function confirmSortAlpha() {
  Modal.confirm({
    title: s('Sort A-Z'),
    content: s('Sort every menu group and, inside each one, its items alphabetically, at every level? The Dashboard stays first.')
      + (appliesToAll.value ? ' ' + s('Then press Save.') : ''),
    okText: s('Sort A-Z'),
    cancelText: s('Cancel'),
    onOk: sortAlpha,
  });
}
function sortAlpha() {
  const collator = new Intl.Collator(locale.value || 'es', { sensitivity: 'base', numeric: true });
  const sortList = (list, top) => {
    list.sort((a, b) => {
      if (top) {
        const da = a.key === 'dashboard' ? 0 : 1;
        const db = b.key === 'dashboard' ? 0 : 1;
        if (da !== db) return da - db;
      }
      return collator.compare(labelOf(a.entry), labelOf(b.entry));
    });
    list.forEach(n => { if (n.children?.length) sortList(n.children, false); });
  };
  sortList(treeData.value, true);
  treeData.value = [...treeData.value];
  changed();
  message.success(appliesToAll.value ? s('Sorted. Press Save to apply it.') : s('Sorted.'));
}

/* ---------------------------------------------------- filter (2026-11-23) */
// Hides (display: none) the rows that don't match -- nothing is removed, so moving / hiding / saving still works on
// the whole menu. A group that matches shows all of its items; an item that matches shows the groups above it.
const filterText = ref('');
const filterMatches = ref(0);
const norm = v => String(v || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
function applyFilter() {
  const q = norm(filterText.value).trim();
  let found = 0;
  const open = [];
  // Returns true when something in `list` (or below it) matches by its own name.
  const walk = (list, parentMatched) => {
    let any = false;
    list.forEach((n) => {
      const self = !!q && !n.moduleOff && norm(labelOf(n.entry)).includes(q);
      if (self) found++;
      const below = n.children ? walk(n.children, parentMatched || self) : false;
      n.filterOut = !(!q || self || below || parentMatched);
      n.style = (n.moduleOff || n.filterOut) ? { display: 'none' } : undefined;
      if (below) open.push(n.key);
      if (self || below) any = true;
    });
    return any;
  };
  walk(treeData.value, false);
  filterMatches.value = found;
  if (q) expandedKeys.value = open;
  treeData.value = [...treeData.value];
}

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
// 5 since 2026-11-20 (user: Products & Services, 4 levels of its own, couldn't go into "Main modules").
const MAX_MENU_DEPTH = 5;

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
  // dropPosition 0 = inside dropNode; ±1 = the gap around it. Inside a CLOSED
  // menu is refused (2026-11-02: passing over a module swallowed the item into
  // it): dragging only places between rows -- into a menu = between its open
  // items or right under its open header (Ant's "inside" of an expanded node,
  // drawn as an indented line); "Move to…" does the rest.
  if (dropPosition === 0) return isExpandedContainer(over) && isValidParent(drag, over);
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
  // Onto a closed row never nests (allowDrop refuses it); onto an open menu =
  // its first item.
  if (!info.dropToGap && !isExpandedContainer(dropNode)) return;
  const asFirstChild = (!info.dropToGap || relPos === 1) && isExpandedContainer(dropNode) && isValidParent(dragNode, dropNode);
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
  changed();
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
  changed();
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

// Where inside the chosen place (2026-11-02): first, after a given item, or last.
const moveAfter = ref('__end__');

function findByKey(list, key) {
  for (const n of list) {
    if (n.key === key) return n;
    const hit = n.children && findByKey(n.children, key);
    if (hit) return hit;
  }
  return null;
}

/** The visible items of the chosen place, as "first / after X / last". */
const movePositionOptions = computed(() => {
  if (moveTarget.value === undefined || !moveNode.value) return [];
  const list = moveTarget.value === '__root__'
    ? treeData.value
    : (findByKey(treeData.value, moveTarget.value)?.children || []);
  const opts = [{ value: '__start__', label: s('At the beginning') }];
  list.filter(n => !n.moduleOff && n.key !== moveNode.value.key)
    .forEach(n => opts.push({ value: n.key, label: s('After "{name}"', { name: labelOf(n.entry) }) }));
  opts.push({ value: '__end__', label: s('At the end') });
  return opts;
});

function openMoveModal(node) {
  moveNode.value = node;
  moveTarget.value = undefined;
  moveAfter.value = '__end__';
  moveModalVisible.value = true;
}

function confirmMove() {
  const node = moveNode.value;
  if (!node || moveTarget.value === undefined) return;
  const parent = moveTarget.value === '__root__' ? null : findByKey(treeData.value, moveTarget.value);
  const from = siblingsOf(node);
  from.splice(from.findIndex(n => n.key === node.key), 1);
  retype(node, parent);
  if (parent) parent.children = parent.children || [];
  const to = parent ? parent.children : treeData.value;
  const after = moveAfter.value;
  const at = after === '__start__' ? 0
    : after === '__end__' ? to.length
      : to.findIndex(n => n.key === after) + 1 || to.length;
  to.splice(at, 0, node);
  if (parent && !expandedKeys.value.includes(parent.key)) expandedKeys.value = [...expandedKeys.value, parent.key];
  treeData.value = [...treeData.value];
  changed();
  moveModalVisible.value = false;
}

/* ---------------------------------------------- hide / rename / remove */

function toggleHidden(node) {
  node.hidden = !node.hidden;
  changed();
}

/* ------------------------------------------- "SuperAdmin only" (2026-11-23) */
// Install-wide list of menu keys only the SuperAdmin sees (MenuPositionController::superAdminOnly).
const saKeys = ref([...(auth.user?.superadmin_only_menu || [])]);
const saBusy = ref(null);
const moduleLabel = k => TOGGLEABLE_MODULES.find(m => m.key === k)?.label || k;
function permissionsOf(entry) {
  const out = new Set();
  const walk = e => { (e.permissions || []).forEach(p => out.add(p)); (e.children || []).forEach(walk); };
  walk(entry);
  return [...out];
}
async function saveSuperAdminOnly(node, on) {
  saBusy.value = node.key;
  try {
    const res = await http.post('menu-positions/superadmin-only', { key: node.key, on, apply_to_all: 1 });
    saKeys.value = res.keys || [];
    if (auth.user) auth.user.superadmin_only_menu = [...saKeys.value];
    message.success(on ? s('Now only the SuperAdmin sees "{name}".', { name: labelOf(node.entry) }) : s('"{name}" is visible again to the roles that can open it.', { name: labelOf(node.entry) }));
  } catch (e) {
    message.error(e?.response?.data?.message || s('Could not save'));
  } finally {
    saBusy.value = null;
  }
}
async function toggleSuperAdminOnly(node) {
  if (saKeys.value.includes(node.key)) return saveSuperAdminOnly(node, false);
  const name = labelOf(node.entry);
  const modules = moduleKeysOfEntry(node.entry, spaPathOf);
  const permissions = permissionsOf(node.entry);
  let inUse = [];
  saBusy.value = node.key;
  try {
    inUse = (await http.get('menu-positions/superadmin-impact', { modules: modules.join(','), permissions: permissions.join(',') }))?.companies || [];
  } catch (e) {
    saBusy.value = null;
    return message.error(e?.response?.data?.message || s('Could not check where it is used'));
  }
  saBusy.value = null;
  // In use (its module is on somewhere, or someone can open it): the warning with the detail, company by company.
  const detail = inUse.length
    ? h('div', [
      h('p', s('"{name}" belongs to a module in use. If you go on, only the SuperAdmin will see it, in every company:', { name })),
      modules.length ? h('p', [h('b', s('Modules') + ': '), modules.map(moduleLabel).join(', ')]) : null,
      h('table', { class: 'smm-impact' }, [
        h('thead', h('tr', [h('th', s('Company')), h('th', s('Status')), h('th', s('Module on')), h('th', s('Logins that open it'))])),
        h('tbody', inUse.map(c => h('tr', [
          h('td', c.name),
          h('td', c.live ? s('Production') : 'Demo'),
          h('td', c.modules.length ? c.modules.map(moduleLabel).join(', ') : '—'),
          h('td', { style: 'text-align:right' }, String(c.users)),
        ]))),
      ]),
    ])
    : s('"{name}" is not in use in any company. Only the SuperAdmin will see it, in every company.', { name });
  Modal.confirm({
    title: inUse.length ? s('Warning: "{name}" is in use', { name }) : s('Make "{name}" SuperAdmin only?', { name }),
    content: detail,
    width: inUse.length ? 680 : 460,
    okText: s('Yes, SuperAdmin only'),
    okType: inUse.length ? 'danger' : 'primary',
    cancelText: s('Cancel'),
    onOk: () => saveSuperAdminOnly(node, true),
  });
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
  changed();
}

/* ---------------------------------------- short name for the top menu (2026-11-24) */

const shortModalVisible = ref(false);
const shortTarget = ref(null);
const shortValue = ref('');
const shortProposal = computed(() => (shortTarget.value ? proposedShort(shortTarget.value.key, locale.value) : null));
/** What the top bar shows for a top-level group: the company's own short name, else the proposal. */
function shortOf(node) {
  return node.entry.shortLabel || proposedShort(node.key, locale.value);
}
function openShortModal(node) {
  shortTarget.value = node;
  shortValue.value = node.entry.shortLabel || '';
  shortModalVisible.value = true;
}
function confirmShort() {
  const node = shortTarget.value;
  const v = (shortValue.value || '').trim();
  // The proposal typed back = no own name (the proposal keeps following the code).
  node.entry = { ...node.entry, shortLabel: v && v !== shortProposal.value ? v : undefined };
  changed();
  shortModalVisible.value = false;
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
  changed();
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

  changed();
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
      short_label: n.entry.shortLabel || null,
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

// AdminSystem: Save reaches EVERY company -- ask first (user's rule, 2026-10-25); a
// company's own menu (its admin's or superadmin/<company>'s) is otherwise never touched.
// The companies with their own menu ("Menú propio" in Companies, on for those in production) are left out
// (2026-11-23): the confirmation says how many it reaches and which ones keep their menu.
async function ownMenuInfo() {
  try { return await http.get('menu-positions/own-menu'); } catch (e) { return { own: [], shared: null }; }
}
function ownMenuNote(info) {
  if (!info.own?.length) return null;
  return h('div', { style: 'margin-top:8px' }, [
    h('b', s('Not touched (own menu):') + ' '),
    info.own.map(c => c.name + (c.live ? ' (' + s('Production') + ')' : '')).join(', '),
  ]);
}
async function save() {
  if (!appliesToAll.value) return doSave();
  const info = await ownMenuInfo();
  Modal.confirm({
    title: s('Apply to the companies without their own menu?'),
    width: 560,
    content: h('div', [
      h('p', s('You are working as AdminSystem: this menu (order, locations, groups, names and hidden items) will REPLACE the menu of {n} companies (the ones without their own menu, like the demos). Continue?', { n: info.shared ?? '?' })),
      ownMenuNote(info),
    ]),
    okText: s('Yes, apply'),
    cancelText: s('Cancel'),
    okType: 'danger',
    onOk: doSave,
  });
}

/*
 * Autosave (user's rule, 2026-11-02): a company's menu is saved a moment after
 * every change -- no Save button. AdminSystem keeps Save + its confirmation,
 * since a save there replaces every company's menu. A change made while a save
 * is running is saved right after it; leaving the page saves what's pending.
 */
const saveError = ref(false);
const savedOnce = ref(false);
let changeSeq = 0;
let autoTimer = null;
let saveAgain = false;
function changed() {
  changeSeq++;
  dirty.value = true;
  if (appliesToAll.value) return;
  clearTimeout(autoTimer);
  autoTimer = setTimeout(autoSave, 600);
}
async function autoSave() {
  autoTimer = null;
  if (saving.value) { saveAgain = true; return; }
  await doSave(true);
  if (saveAgain) { saveAgain = false; autoSave(); }
}
onBeforeUnmount(() => {
  if (autoTimer) { clearTimeout(autoTimer); autoSave(); }
});

async function doSave(auto = false) {
  saving.value = true;
  const seq = changeSeq;
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
    const res = await http.post('menu-positions', { positions, ...(appliesToAll.value ? { apply_to_all: 1 } : {}) });
    menuPositions.value = positions;
    // Live-update the sidebar for this session; other users get it at next boot.
    if (auth.user) auth.user.sidebar_menu_order = order;
    // Refresh the shared menu layout too, so the live sidebar and the role
    // editor (permissions follow the menu order) pick up the new structure
    // without a page reload.
    menuStore.loadPositions();
    dirty.value = seq !== changeSeq;
    saveError.value = false;
    savedOnce.value = true;
    if (!auto) message.success(res?.message || t('Successfully_Updated'));
  } catch (e) {
    saveError.value = true;
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
  clearTimeout(autoTimer); autoTimer = null; // a pending autosave must not overwrite it
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

async function confirmReset() {
  const info = appliesToAll.value ? await ownMenuInfo() : null;
  Modal.confirm({
    title: s('Reset to default'),
    width: appliesToAll.value ? 560 : undefined,
    content: appliesToAll.value
      ? h('div', [
        h('p', s('Restore the original menu structure for the {n} companies without their own menu (and AdminSystem)? Custom items, renames and hidden items at every level are cleared there.', { n: info.shared ?? '?' })),
        ownMenuNote(info),
      ])
      : s('Restore the original menu structure for {name} only? Its custom items, renames and hidden items are cleared.', { name: actingCompanyName.value }),
    okText: s('Reset to default'),
    cancelText: s('Cancel'),
    okType: 'danger',
    onOk: reset,
  });
}

async function reset() {
  clearTimeout(autoTimer); autoTimer = null; // a pending autosave must not overwrite it
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
    const res = await http.delete('menu-positions', appliesToAll.value ? { apply_to_all: 1 } : undefined);
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
.smm-handle {
  cursor: grab;
  color: #8c8c8c;
}
.smm-autosave {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: #52c41a;
  font-size: 13px;
}
.smm-autosave-error { color: #ff4d4f; }
.smm-move-label {
  margin: 10px 0 4px;
  font-weight: 500;
}
.smm-move-label:first-child { margin-top: 0; }
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
  /* handle | icon | label | actions (2026-11-02: the drag handle has its own column) */
  grid-template-columns: 16px 22px minmax(0, 1fr) 236px;
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
.smm-short-help {
  font-size: 12px;
  opacity: 0.7;
  margin-bottom: 8px;
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
.smm-sa-on {
  color: #722ed1 !important;
}
.smm-filter-count {
  font-size: 12px;
  opacity: 0.7;
}
.smm-type-hint {
  margin-top: 6px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}
</style>

<!-- The "SuperAdmin only" warning lives in a Modal (outside this component): unscoped. -->
<style>
.smm-impact { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 6px; }
.smm-impact th, .smm-impact td { border-bottom: 1px solid rgba(0, 0, 0, .08); padding: 4px 6px; text-align: left; }
.smm-impact th { font-weight: 600; }
</style>
