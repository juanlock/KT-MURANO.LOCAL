<template>
  <a-card>
    <a-alert
      type="info"
      show-icon
      message="Drag and drop to rearrange the sidebar"
      description="Reorder modules, reorder items inside a module, or drag an item into a different module. Modules always stay at the top level and items cannot become modules. The saved arrangement applies to every user."
      style="margin-bottom: 16px"
    />

    <div class="smm-toolbar">
      <a-space>
        <a-button size="small" @click="expandAll">Expand all</a-button>
        <a-button size="small" @click="expandedKeys = []">Collapse all</a-button>
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
          <span class="smm-label">{{ labelOf(dataRef.entry) }}</span>
          <a-tag v-if="dataRef.type === 'subgroup'" class="smm-tag" :bordered="false">group</a-tag>
        </span>
      </template>
    </a-tree>
  </a-card>
</template>

<script setup>
/**
 * Sidebar Menu Manager — the "sidebar" section of System Settings.
 *
 * Shows the FULL default menu (permission gates ignored: the admin arranges the
 * structure for everyone) as a draggable tree and persists the arrangement as
 * an id-tree via PUT/DELETE sidebar_menu_order (see lib/menuOrder.js for the id
 * scheme and the defensive merge the sidebars apply).
 *
 * Nesting rules, enforced both as drag feedback (allowDrop) and on drop:
 * - modules (top level) can only be reordered, never nested;
 * - items can live directly under any module, or inside a sub-group;
 * - sub-groups (Payments, Real Estate, Leave request) move as one unit and only
 *   sit directly under a module — no group-inside-group.
 *
 * After a successful save the new order is written back onto
 * auth.user.sidebar_menu_order, so the live sidebar re-arranges immediately
 * without a reload.
 */
import { ref, computed } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { SaveOutlined, UndoOutlined } from '@ant-design/icons-vue';
import { MENU_ICONS } from '../../config/menuIcons';
import { MENU_ITEM_ICONS } from '../../config/menuItemIcons';
import { nodeId, applyMenuOrder } from '../../lib/menuOrder';
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

/* ------------------------------------------------------------- tree data */

function labelOf(entry) {
  if (entry.raw) return entry.label;
  if (entry.labelKeys) return entry.labelKeys.map(k => t(k)).join(' ');
  return t(entry.label);
}

/** Menu entry → tree node. Containers keep a children array even when empty. */
function toNode(entry, depth) {
  const isTop = depth === 0;
  const type = isTop ? 'module' : (entry.children ? 'subgroup' : 'item');
  const node = {
    key: nodeId(entry, isTop),
    type,
    entry,
    // Only containers (had children in the default MENU) may receive drops.
    isContainer: Array.isArray(entry.children),
    iconCmp: isTop ? MENU_ICONS[entry.icon] : MENU_ITEM_ICONS[entry.to],
  };
  if (node.isContainer) node.children = entry.children.map(c => toNode(c, depth + 1));
  return node;
}

function buildTree(order) {
  const arranged = (Array.isArray(order) && order.length) ? applyMenuOrder(order) : MENU;
  return arranged.map(g => toNode(g, 0));
}

const treeData = ref(buildTree(auth.user && auth.user.sidebar_menu_order));

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

/* ------------------------------------------------------------ save/reset */

function serialize(list) {
  return list.map(n => (n.isContainer
    ? { id: n.key, children: serialize(n.children || []) }
    : { id: n.key }));
}

async function save() {
  saving.value = true;
  try {
    const order = serialize(treeData.value);
    await http.put('sidebar_menu_order', { order });
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
    content: 'Restore the original sidebar structure for all users?',
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
    treeData.value = buildTree(null);
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
</style>
