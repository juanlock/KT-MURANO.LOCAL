<template>
  <div class="pm">
    <div class="pm__head">
      <div class="pm__col-item">{{ $t('tx_Menu_item_eeaab') }}</div>
      <div class="pm__col-menu">
        {{ $t('tx_Menu_access_b9c14') }}
        <a-tooltip :title="$t('tx_Shows_or_hides_this_item_in_the_role_s_sidebar_menu_d296c')">
          <QuestionCircleOutlined class="pm__help" />
        </a-tooltip>
      </div>
      <div class="pm__col-perms">
        {{ $t('tx_Form_permissions_f0387') }}
        <a-tooltip :title="$t('tx_What_the_role_may_do_inside_the_page_view_add_edit_delete_an_481d0')">
          <QuestionCircleOutlined class="pm__help" />
        </a-tooltip>
      </div>
    </div>

    <div
      v-for="{ node, depth } in rows" :key="node.id"
      class="pm__row"
      :class="{ 'pm__row--group': node.isContainer, 'pm__row--dim': rowDim(node) }"
    >
      <!-- Menu item (indented by level) -->
      <div class="pm__col-item" :style="{ paddingLeft: `${8 + depth * 22}px` }">
        <span v-if="depth > 0" class="pm__branch">└</span>
        <FolderOpenOutlined v-if="node.isContainer" class="pm__icon pm__icon--group" />
        <FileTextOutlined v-else class="pm__icon" />
        <span class="pm__label">{{ labelOf(node) }}</span>
        <a-tag v-if="node.hidden" color="default" class="pm__tag"><EyeInvisibleOutlined /> hidden in menu</a-tag>
        <a-tag v-if="node.location && node.location !== 'sidebar'" class="pm__tag">{{ node.location }}</a-tag>
      </div>

      <!-- Menu access -->
      <div class="pm__col-menu">
        <template v-if="node.isContainer">
          <a-tooltip :title="$t('tx_Select_clear_everything_inside_this_group_menu_access_form_p_4393d')">
            <span class="pm__group-all">
              <a-checkbox
                :checked="groupCount(node) === node.allPerms.length && node.allPerms.length > 0"
                :indeterminate="groupCount(node) > 0 && groupCount(node) < node.allPerms.length"
                :disabled="!node.allPerms.length"
                @change="e => $emit('toggle-many', node.allPerms, e.target.checked)"
              />
              <span class="pm__muted">all ({{ groupCount(node) }}/{{ node.allPerms.length }})</span>
            </span>
          </a-tooltip>
        </template>
        <template v-else-if="node.menuPerms.length">
          <a-tooltip :title="menuTip(node)" v-bind="TIP">
            <span class="pm__switch">
              <a-switch
                size="small"
                :checked="menuOn(node)"
                @change="on => $emit('menu', node, on)"
              />
              <span :class="menuOn(node) ? 'pm__on' : 'pm__off'">
                <EyeOutlined v-if="menuOn(node)" /><EyeInvisibleOutlined v-else />
                {{ menuOn(node) ? 'Visible' : $t('Hidden') }}
              </span>
              <LinkOutlined v-if="shared(node) > 0" class="pm__shared" />
            </span>
          </a-tooltip>
        </template>
        <!-- No permission gates this item: only a SuperAdmin working as
             AdminSystem decides, for EVERY company, whether it's shown. -->
        <template v-else-if="!node.plain && canForceVisibility">
          <a-tooltip :title="$t('tx_This_item_needs_no_permission_As_AdminSystem_you_decide_for__f6cc4')" v-bind="TIP">
            <span class="pm__switch">
              <a-switch
                size="small"
                :checked="!node.hidden"
                :loading="busyKey === nodeKey(node)"
                @change="on => $emit('force-visibility', node, !on)"
              />
              <span :class="!node.hidden ? 'pm__forced' : 'pm__off'">
                {{ !node.hidden ? $t('tx_Everyone_f9ae5') : $t('tx_Hidden_for_all_d849b') }}
              </span>
            </span>
          </a-tooltip>
        </template>
        <span v-else-if="!node.plain" class="pm__muted">—</span>
      </div>

      <!-- Form permissions -->
      <div class="pm__col-perms">
        <template v-for="s in node.special" :key="s.group.label">
          <span v-if="node.special.length > 1" class="pm__group-name">{{ groupLabelOf(s.group) }}</span>
          <a-tooltip v-for="item in s.items" :key="item.v" :title="item.v" v-bind="TIP">
            <button
              type="button"
              class="pill"
              :class="[`pill--${kind(item)}`, { 'pill--on': permissions.includes(item.v) }]"
              @click="$emit('toggle', item.v, !permissions.includes(item.v))"
            >
              <component :is="KIND_ICON[kind(item)]" class="pill__icon" />
              {{ itemLabelOf(item) }}
            </button>
          </a-tooltip>
        </template>
        <span v-if="!node.special.length && !node.isContainer" class="pm__muted">—</span>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * One menu section of the role editor as a table: every menu item (any
 * level, indented) on its own row with two separate columns -- Menu access
 * (sidebar visibility switch) and Form permissions (color-coded pills per
 * action: view / add / edit / delete / other). Data from lib/permissionTree.js.
 */
import { computed } from 'vue';
import {
  QuestionCircleOutlined, FolderOpenOutlined, FileTextOutlined, EyeOutlined,
  EyeInvisibleOutlined, LinkOutlined, PlusOutlined,
  EditOutlined, DeleteOutlined, KeyOutlined,
} from '@ant-design/icons-vue';
import { flattenNodes, permKind, menuHideKey } from '../../lib/permissionTree';

const props = defineProps({
  section: { type: Object, required: true },
  permissions: { type: Array, required: true },
  shareCount: { type: Object, default: () => ({}) },
  labelOf: { type: Function, required: true },
  itemLabelOf: { type: Function, required: true },
  groupLabelOf: { type: Function, required: true },
  // SuperAdmin acting as AdminSystem: may force permission-less items on/off for everyone.
  canForceVisibility: { type: Boolean, default: false },
  busyKey: { type: String, default: '' },
});
defineEmits(['toggle', 'toggle-many', 'menu', 'force-visibility']);
const nodeKey = node => (node.entry && (node.entry.to || node.entry.key)) || '';

const KIND_ICON = {
  view: EyeOutlined, add: PlusOutlined, edit: EditOutlined, delete: DeleteOutlined, other: KeyOutlined,
};
const kind = permKind;

// Hover hints sit up and to the right, appear only after a pause, and
// never catch the mouse -- they used to cover the neighbouring pills and
// swallow the click meant for them.
const TIP = {
  placement: 'topRight',
  mouseEnterDelay: 0.8,
  arrow: false,
  overlayStyle: { pointerEvents: 'none' },
  align: { offset: [24, -10] },
};

// A top-level single item (Dashboard) is its own only row; a container shows
// its children, plus a "General" row for permissions homed on the container.
const rows = computed(() => {
  const sec = props.section;
  if (!sec.isContainer) return [{ node: { ...sec, depth: 0 }, depth: 0 }];
  const out = flattenNodes(sec.children);
  if (sec.special.length) {
    out.unshift({
      node: { ...sec, id: `${sec.id}-own`, children: [], isContainer: false, menuPerms: [], plain: true, label: 'General', raw: true },
      depth: 0,
    });
  }
  return out;
});

// Visible = the role has the item's permission and hasn't hidden this one item
// (menu_hide:<route>, used when the permission is shared with other items).
const menuOn = node => node.menuPerms.some(p => props.permissions.includes(p))
  && !props.permissions.includes(menuHideKey(node));
const shared = node => Math.max(0, ...node.menuPerms.map(p => (props.shareCount[p] || 1) - 1));
const groupCount = node => node.allPerms.filter(v => props.permissions.includes(v)).length;
const rowDim = node => !node.isContainer && node.menuPerms.length > 0 && !menuOn(node);

function menuTip(node) {
  const n = shared(node);
  const base = `Permission: ${node.menuPerms.join(', ')}`;
  return n > 0 ? `${base} -- shared with ${n} other menu item(s); this switch shows/hides only this item` : base;
}
</script>

<style scoped>
.pm {
  border: 1px solid var(--pm-border, rgba(128, 128, 128, 0.2));
  border-radius: 8px;
  overflow: hidden;
}
.pm__head,
.pm__row {
  display: grid;
  grid-template-columns: minmax(200px, 1.1fr) 190px minmax(240px, 2fr);
  align-items: center;
}
.pm__head {
  background: rgba(128, 128, 128, 0.1);
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  opacity: 0.85;
}
.pm__head > div {
  padding: 8px;
}
.pm__row {
  border-top: 1px solid rgba(128, 128, 128, 0.15);
  min-height: 42px;
}
.pm__row:hover {
  background: rgba(22, 119, 255, 0.04);
}
.pm__row--group {
  background: rgba(128, 128, 128, 0.06);
  font-weight: 600;
}
.pm__row > div {
  padding: 6px 8px;
}
.pm__row--dim .pm__label {
  opacity: 0.5;
}
.pm__col-item {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
  flex-wrap: wrap;
}
.pm__col-perms {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}
.pm__branch {
  opacity: 0.3;
}
.pm__icon {
  opacity: 0.55;
}
.pm__icon--group {
  color: #d48806;
  opacity: 1;
}
.pm__tag {
  font-size: 11px;
  line-height: 16px;
  margin: 0;
  font-weight: 400;
}
.pm__help {
  margin-left: 4px;
  opacity: 0.55;
  cursor: help;
}
.pm__switch,
.pm__group-all {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
}
.pm__on {
  color: #389e0d;
  font-weight: 500;
}
.pm__off {
  opacity: 0.45;
}
.pm__shared {
  color: #1677ff;
  opacity: 0.7;
}
.pm__forced {
  color: #722ed1;
  font-weight: 500;
}
.pm__muted {
  font-size: 12px;
  opacity: 0.5;
  font-weight: 400;
}
.pm__group-name {
  font-size: 11px;
  opacity: 0.6;
  margin-left: 4px;
}

/* Permission pills: outlined when off, filled with the action's color when on. */
.pill {
  --c: #722ed1;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 1px 10px;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--c) 45%, transparent);
  background: transparent;
  color: var(--c);
  font-size: 12px;
  line-height: 20px;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
}
.pill:hover {
  background: color-mix(in srgb, var(--c) 10%, transparent);
}
.pill--on {
  background: var(--c);
  border-color: var(--c);
  color: #fff;
}
.pill--on:hover {
  background: color-mix(in srgb, var(--c) 85%, #000);
}
.pill__icon {
  font-size: 11px;
}
.pill--view { --c: #1677ff; }
.pill--add { --c: #389e0d; }
.pill--edit { --c: #d46b08; }
.pill--delete { --c: #cf1322; }
.pill--other { --c: #722ed1; }

@media (max-width: 900px) {
  .pm__head { display: none; }
  .pm__row { grid-template-columns: 1fr; }
  .pm__col-menu, .pm__col-perms { padding-left: 32px !important; }
}
</style>
