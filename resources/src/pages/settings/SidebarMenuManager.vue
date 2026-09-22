<template>
  <a-card>
    <a-alert
      type="info"
      show-icon
      message="Drag and drop to rearrange the menu"
      description="Reorder modules, reorder items inside a module, or drag an item into a different module. Modules always stay at the top level and items cannot become modules. Use the pencil to rename a module, group or item, the + to add a submenu item inside a module or group, the eye icon to hide one, or the trash icon to remove a custom item. The Location field (modules only) tags where a module belongs (Sidebar / Top Nav / Footer) -- Top Nav and Footer aren't rendered anywhere yet, so items tagged with either just leave the sidebar until that surface exists. The saved arrangement applies to every user."
      style="margin-bottom: 16px"
    />

    <div class="smm-toolbar">
      <a-space>
        <a-button size="small" @click="expandAll">Expand all</a-button>
        <a-button size="small" @click="expandedKeys = []">Collapse all</a-button>
        <a-button size="small" @click="openAddModal(null)">+ Add Top-Level Item</a-button>
      </a-space>
      <a-space>
        <a-button danger :loading="resetting" @click="confirmReset">
          <template #icon><UndoOutlined /></template>
          {{ $t('Reset_to_Default') }}
        </a-button>
        <a-button type="primary" :disabled="!dirty" :loading="saving" @click="save">
          <template #icon><SaveOutlined /></template>
          {{ $t('submit') }}
        </a-button>
      </a-space>
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
          <component :is="dataRef.iconCmp" v-if="dataRef.iconCmp" :size="15" class="smm-icon" />
          <span class="smm-label" :class="{ 'smm-hidden-label': dataRef.hidden }">{{ labelOf(dataRef.entry) }}</span>
          <a-tag v-if="dataRef.type === 'subgroup'" class="smm-tag" :bordered="false">group</a-tag>
          <a-tag v-if="dataRef.isCustom" class="smm-tag" color="blue" :bordered="false">custom</a-tag>
          <span class="smm-row-actions" @click.stop>
            <a-select
              v-if="dataRef.type === 'module'"
              size="small"
              class="smm-location"
              :value="dataRef.location || 'sidebar'"
              :options="locationOptions"
              @change="v => setLocation(dataRef, v)"
            />
            <a-tooltip v-if="dataRef.isContainer" title="Add submenu item">
              <a-button type="text" size="small" @click="openAddModal(dataRef)">
                <template #icon><PlusOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip title="Rename">
              <a-button type="text" size="small" @click="openRenameModal(dataRef)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="dataRef.hidden ? 'Hidden -- click to show' : 'Click to hide'">
              <a-button type="text" size="small" @click="toggleHidden(dataRef)">
                <template #icon>
                  <EyeInvisibleOutlined v-if="dataRef.hidden" />
                  <EyeOutlined v-else />
                </template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="dataRef.isCustom" title="Remove this custom item">
              <a-button type="text" size="small" danger @click="removeItem(dataRef)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </span>
        </span>
      </template>
    </a-tree>

    <a-modal
      v-model:open="addModalVisible"
      :title="addTarget ? `Add Submenu Item -- ${labelOf(addTarget.entry)}` : 'Add Top-Level Menu Item'"
      @ok="confirmAddItem"
      @cancel="resetAddForm"
    >
      <a-form layout="vertical">
        <a-form-item label="Label" required>
          <a-input v-model:value="newItem.label" placeholder="e.g. Help Center" />
        </a-form-item>
        <a-form-item label="Icon" v-if="!addTarget">
          <a-select v-model:value="newItem.icon" :options="iconOptions" />
        </a-form-item>
        <a-form-item label="External link">
          <a-switch v-model:checked="newItem.external" />
        </a-form-item>
        <a-form-item :label="newItem.external ? 'URL' : 'Route'" required>
          <a-input
            v-model:value="newItem.route"
            :placeholder="newItem.external ? 'https://example.com' : '/app/some/page'"
          />
        </a-form-item>
      </a-form>
    </a-modal>

    <a-modal
      v-model:open="renameModalVisible"
      title="Rename"
      @ok="confirmRename"
    >
      <a-form layout="vertical">
        <a-form-item label="Label" required>
          <a-input v-model:value="renameValue" placeholder="Label" @keyup.enter="confirmRename" />
        </a-form-item>
      </a-form>
    </a-modal>
  </a-card>
</template>

<script setup>
/**
 * Manage Menu — the "sidebar" section of System Settings, and the target of
 * the left-nav "Manage Menu" entry (config/menu.js, key manage_menu).
 *
 * Shows the FULL default menu (permission gates ignored: the admin arranges the
 * structure for everyone) as a draggable tree. Reordering/nesting persists two
 * ways, and this screen writes BOTH on every save:
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
 * - modules (top level) can only be reordered, never nested;
 * - items can live directly under any module, or inside a sub-group;
 * - sub-groups (Payments, Real Estate, Leave request) move as one unit and only
 *   sit directly under a module — no group-inside-group.
 * The same rule caps what "+ Add Submenu" can create: a plain item, directly
 * under whichever module/subgroup row it was opened from.
 *
 * Each module also carries a `location` tag (Sidebar / Top Nav / Footer,
 * stored on its menu_positions row). Only Sidebar renders live today -- Top
 * Nav and Footer have no UI surface yet, so tagging a module with either just
 * removes it from the sidebar until that follow-up work exists. Location is
 * module-only; sub-items render wherever their module does.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  SaveOutlined, UndoOutlined, EyeOutlined, EyeInvisibleOutlined, DeleteOutlined,
  PlusOutlined, EditOutlined,
} from '@ant-design/icons-vue';
import { MENU_ICONS } from '../../config/menuIcons';
import { MENU_ITEM_ICONS } from '../../config/menuItemIcons';
import { nodeId, applyMenuOrder } from '../../lib/menuOrder';
import { buildTopLevelMenuForEditor, hasNestedPositions } from '../../lib/menuTree';
import { MENU } from '../../config/menu';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const auth = useAuthStore();

const saving = ref(false);
const resetting = ref(false);
const dirty = ref(false);
const expandedKeys = ref([]);
const dragging = ref(null);
// Raw rows from GET /api/menu-positions -- hide/custom-item/rename state at
// every level once this screen has done a full-tree save; see menuTree.js.
const menuPositions = ref(null);

const addModalVisible = ref(false);
const addTarget = ref(null); // tree node to add a submenu item under, or null for top-level
const newItem = ref({ label: '', icon: 'bar-chart', route: '', external: false });
const iconOptions = Object.keys(MENU_ICONS).map(key => ({ value: key, label: key }));
const locationOptions = [
  { value: 'sidebar', label: 'Sidebar' },
  { value: 'topnav', label: 'Top Nav' },
  { value: 'footer', label: 'Footer' },
];

const renameModalVisible = ref(false);
const renameTarget = ref(null);
const renameValue = ref('');

/* ------------------------------------------------------------- tree data */

function labelOf(entry) {
  if (entry.raw) return entry.label;
  if (entry.labelKeys) return entry.labelKeys.map(k => t(k)).join(' ');
  return t(entry.label);
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
  const node = {
    key,
    type,
    entry,
    // Only containers (had children in the default MENU, or gained a
    // submenu item via this editor) may receive drops.
    isContainer: Array.isArray(entry.children),
    iconCmp: isTop ? MENU_ICONS[entry.icon] : MENU_ITEM_ICONS[entry.to],
    hidden: !!entry.hidden,
    isCustom: !!entry.custom,
    location: isTop ? (entry.location || 'sidebar') : undefined,
  };
  if (node.isContainer) node.children = entry.children.map(c => toNode(c, depth + 1));
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

onMounted(async () => {
  try {
    menuPositions.value = await http.get('menu-positions');
  } catch (e) {
    menuPositions.value = null;
  }
  treeData.value = buildTree(auth.user && auth.user.sidebar_menu_order, menuPositions.value);
});

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

/** May a node of `type` be placed under `parent` (null = root level)? */
function isValidParent(type, parent) {
  if (!parent) return type === 'module';
  if (type === 'module') return false;
  if (parent.type === 'module') return parent.isContainer;
  if (parent.type === 'subgroup') return type === 'item';
  return false;
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
  if (dropPosition === 0) return isValidParent(drag.type, over);
  // The gap after an expanded container doubles as its first-child slot.
  if (dropPosition === 1 && isExpandedContainer(over) && isValidParent(drag.type, over)) return true;
  return isValidParent(drag.type, parentByKey.value.get(over.key));
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
    || (relPos === 1 && isExpandedContainer(dropNode) && isValidParent(dragNode.type, dropNode));
  const targetParent = asFirstChild ? dropNode : parentByKey.value.get(dropKey);
  if (!isValidParent(dragNode.type, targetParent)) {
    if (dragNode.type === 'module') message.warning('Modules can only be reordered at the top level.');
    else if (dragNode.type === 'subgroup') message.warning('Groups can only be placed directly under a module.');
    else message.warning('Items must stay inside a module or group.');
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

/* ---------------------------------------------- hide / rename / remove */

function toggleHidden(node) {
  node.hidden = !node.hidden;
  dirty.value = true;
}

function setLocation(node, value) {
  node.location = value;
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
    message.warning('Label is required.');
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

function resetAddForm() {
  newItem.value = { label: '', icon: 'bar-chart', route: '', external: false };
  addTarget.value = null;
}

function confirmAddItem() {
  const label = newItem.value.label.trim();
  const route = newItem.value.route.trim();
  if (!label || !route) {
    message.warning('Label and route/URL are required.');
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
  if (!target) entry.icon = newItem.value.icon;
  if (newItem.value.external) entry.href = route;
  else entry.to = route;

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
    if (n.type === 'module') row.location = n.location || 'sidebar';
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
    await http.put('sidebar_menu_order', { order });
    const positions = flattenPositions(treeData.value);
    await http.post('menu-positions', { positions });
    menuPositions.value = positions;
    // Live-update the sidebar for this session; other users get it at next boot.
    if (auth.user) auth.user.sidebar_menu_order = order;
    dirty.value = false;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

function confirmReset() {
  Modal.confirm({
    title: t('Reset_to_Default'),
    content: 'Restore the original menu structure for all users? Custom items, renames and hidden items at every level are cleared.',
    okText: t('Reset_to_Default'),
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
    // factory layout -- both must go for a true reset.
    await http.delete('menu-positions');
    menuPositions.value = null;
    treeData.value = buildTree(null, null);
    dirty.value = false;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.message || t('InvalidData'));
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
  max-width: 680px;
}
.smm-tree :deep(.ant-tree-treenode) {
  padding-bottom: 2px;
}
.smm-node {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  line-height: 24px;
}
.smm-icon {
  flex: none;
  opacity: 0.75;
}
.smm-module > .smm-label {
  font-weight: 600;
}
.smm-subgroup > .smm-label {
  font-weight: 500;
}
.smm-tag {
  font-size: 10px;
  line-height: 16px;
  margin-inline-start: 2px;
  opacity: 0.7;
}
.smm-hidden-label {
  opacity: 0.5;
  text-decoration: line-through;
}
.smm-row-actions {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-inline-start: auto;
  padding-inline-start: 8px;
}
.smm-location {
  width: 96px;
}
</style>
