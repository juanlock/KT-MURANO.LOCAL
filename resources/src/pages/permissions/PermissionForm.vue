<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit_Permission') : $t('Create_Permission')"
      :breadcrumb="[$t('Users'), $t('GroupPermissions'), isEdit ? $t('Edit') : $t('Add')]"
    >
      <template #extra>
        <a-space>
          <a-button @click="$router.push('/settings/sidebar-menu')">
            <template #icon><MenuOutlined /></template>
            {{ tf('Manage_Menus', 'Manage Menus') }}
          </a-button>
          <a-button @click="$router.push('/permissions')">{{ $t('Cancel') }}</a-button>
          <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-card size="small" style="margin-bottom: 16px">
        <a-form ref="formRef" :model="role" :rules="rules" layout="vertical">
          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('RoleName') + ' *'" name="name">
                <a-input v-model:value="role.name" :placeholder="$t('Enter_Role_Name')" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('RoleDescription')" style="margin-bottom: 0">
                <a-input v-model:value="role.description" :placeholder="$t('Enter_Role_Description')" />
              </a-form-item>
            </a-col>
          </a-row>
          <!-- SuperAdmin editing another company's role: say whose it is. -->
          <a-alert
            v-if="isSuperAdmin && role.company_name" type="info" show-icon style="margin-top: 12px"
            :message="`Role of company: ${role.company_name}`"
          />
        </a-form>
      </a-card>

      <!-- Summary + legend -->
      <a-card size="small" style="margin-bottom: 16px">
        <div class="summary">
          <div class="stat">
            <div class="stat__icon stat__icon--menu"><AppstoreOutlined /></div>
            <div>
              <div class="stat__label">Menu items visible</div>
              <div class="stat__value">{{ menuStats.on }} <small>/ {{ menuStats.total }}</small></div>
            </div>
          </div>
          <div class="stat">
            <div class="stat__icon stat__icon--form"><SafetyCertificateOutlined /></div>
            <div>
              <div class="stat__label">Form permissions granted</div>
              <div class="stat__value">{{ formStats.on }} <small>/ {{ formStats.total }}</small></div>
            </div>
          </div>
          <div class="stat stat--grow">
            <a-progress :percent="totalCount ? Math.round((countOf(offeredPerms) / totalCount) * 100) : 0" size="small" />
            <div class="stat__label">{{ countOf(offeredPerms) }} of {{ totalCount }} permissions in this role</div>
          </div>
          <a-space wrap>
            <a-input-search v-model:value="search" :placeholder="$t('Search')" allow-clear style="width: 220px" />
            <a-button size="small" @click="selectAll(true)">{{ tf('Select_All', 'Select all') }}</a-button>
            <a-button size="small" @click="selectAll(false)">{{ tf('Deselect_All', 'Deselect all') }}</a-button>
          </a-space>
        </div>
        <div class="legend">
          <span class="legend__title">How to read it:</span>
          <span><a-switch size="small" :checked="true" /> <b>Menu access</b> -- shows the item in the sidebar</span>
          <span class="legend__pill legend__pill--view"><EyeOutlined /> View</span>
          <span class="legend__pill legend__pill--add"><PlusOutlined /> Add</span>
          <span class="legend__pill legend__pill--edit"><EditOutlined /> Edit</span>
          <span class="legend__pill legend__pill--delete"><DeleteOutlined /> Delete</span>
          <span class="legend__pill legend__pill--other"><KeyOutlined /> Special</span>
          <span><b>Form permissions</b> -- filled = granted, outlined = not granted</span>
          <span><LinkOutlined style="color: #1677ff" /> shared with other menu items</span>
        </div>
      </a-card>

      <div class="panes">
        <!-- Left: the company's menu sections, in its own order -->
        <a-card size="small" class="nav" :body-style="{ padding: '6px' }">
          <div class="nav__hint">Menu order of this company</div>
          <template v-for="(sec, i) in navSections" :key="sec.id">
            <div v-if="sec.off && !(navSections[i - 1] || {}).off" class="nav__divider">Modules turned off</div>
            <div v-if="sec.id === '__other'" class="nav__divider">Not in the menu</div>
            <button
              type="button"
              class="nav__item"
              :class="{ 'nav__item--active': selectedId === sec.id, 'nav__item--off': sec.off }"
              @click="selectedId = sec.id"
            >
              <component :is="sectionIcon(sec)" class="nav__icon" />
              <span class="nav__label">{{ nodeLabel(sec) }}</span>
              <span class="nav__count" :class="countClass(sec)">{{ countOf(sec.allPerms) }}/{{ sec.allPerms.length }}</span>
              <span class="nav__bar"><span :style="{ width: pct(sec) + '%' }" :class="countClass(sec)" /></span>
            </button>
          </template>
          <a-empty v-if="!navSections.length" :description="$t('NodataAvailable')" style="padding: 24px 0" />
        </a-card>

        <!-- Right: the selected section (or every match while searching) -->
        <div class="detail">
          <a-card v-for="sec in detailSections" :key="sec.id" size="small" class="detail__card">
            <div class="detail__head">
              <div class="detail__title">
                <span class="detail__icon"><component :is="sectionIcon(sec)" /></span>
                <div>
                  <div class="detail__name">
                    {{ nodeLabel(sec) }}
                    <a-tag v-if="sec.off" style="margin-left: 6px">module off</a-tag>
                    <a-tag v-if="sec.hidden" style="margin-left: 6px">hidden in menu</a-tag>
                  </div>
                  <div class="detail__sub">{{ countOf(sec.allPerms) }} of {{ sec.allPerms.length }} permissions granted</div>
                </div>
              </div>
              <a-space wrap>
                <a-button v-if="sectionMenuPerms(sec).length" size="small" @click="showWholeSection(sec)">
                  <template #icon><EyeOutlined /></template>
                  Show all in menu
                </a-button>
                <a-button size="small" type="primary" ghost @click="toggleMany(sec.allPerms, true)">
                  <template #icon><CheckOutlined /></template>
                  Grant all
                </a-button>
                <a-button size="small" danger @click="toggleMany(sec.allPerms, false)">
                  <template #icon><CloseOutlined /></template>
                  Clear
                </a-button>
              </a-space>
            </div>
            <a-progress :percent="pct(sec)" :show-info="false" size="small" :stroke-color="pctColor(sec)" style="margin: 4px 0 12px" />
            <PermissionMatrix
              :section="sec" :permissions="permissions" :share-count="layout.shareCount"
              :label-of="nodeLabel" :item-label-of="itemLabel" :group-label-of="groupLabel"
              :can-force-visibility="canForceVisibility" :busy-key="visibilityBusy"
              @toggle="togglePermission" @toggle-many="toggleMany" @menu="toggleMenu"
              @force-visibility="forceVisibility"
            />
          </a-card>
          <a-empty v-if="!detailSections.length" :description="$t('NodataAvailable')" style="padding: 48px 0" />
        </div>
      </div>

      <div style="display: flex; justify-content: flex-end; margin-top: 16px">
        <a-button type="primary" size="large" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
      </div>
    </template>
  </div>
</template>

<script setup>
/**
 * Role create/edit — replaces legacy Create_permission.vue and
 * Edit_permission.vue (~4,100 lines each of hand-written checkboxes) with the
 * same catalogue driven by config/permissions.js, which was EXTRACTED from
 * that template so the original 239 permissions and their 29 groups stayed
 * identical. Modules added since (promotions, document archive...) append their
 * group there — this page needs no edit to show them.
 * Layout follows the ACTING company's menu (menu_positions via menuTree.js):
 * left pane = that company's top-level menu sections in its own order (with
 * progress per section); right pane = the selected section as a table
 * (PermissionMatrix.vue) with two separate columns -- Menu access (sidebar
 * switch) and Form permissions (color-coded view/add/edit/delete pills).
 * Assignment of permissions to menu items: lib/permissionTree.js.
 * Load (edit): GET roles/{id}/edit → {role, permissions[]}.
 * Save: POST roles / PUT roles/{id} with {role, permissions} — legacy payload.
 * After saving, auth permissions are refreshed (legacy dispatched
 * refreshUserPermissions) so the sidebar reflects a self-edit immediately.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  MenuOutlined, AppstoreOutlined, SafetyCertificateOutlined, EyeOutlined, PlusOutlined,
  EditOutlined, DeleteOutlined, KeyOutlined, LinkOutlined, CheckOutlined, CloseOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import PermissionMatrix from './PermissionMatrix.vue';
import { MENU_ICONS, fallbackIcon } from '../../config/menuIcons';
import { PERMISSION_GROUPS } from '../../config/permissions';
import { buildTopLevelMenuForEditor } from '../../lib/menuTree';
import { buildPermissionTree, filterPermissionNode, menuHideKey, MENU_HIDE_PREFIX } from '../../lib/permissionTree';
import { isMenuEntryAllowed } from '../../lib/menuVisibility';
import { useAuthStore } from '../../stores/auth';
import { useMenuStore } from '../../stores/menu';
import { useCompanyStore } from '../../stores/company';
import { t as tf } from '../../i18n';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const id = route.params.id || null;
const isEdit = !!id;
// Duplicate (roles list): /permissions/create?copy=<role id> pre-fills a NEW
// role with that role's description and permissions.
const copyFrom = !isEdit && /^\d+$/.test(String(route.query.copy || '')) ? String(route.query.copy) : null;

const isLoading = ref(isEdit || !!copyFrom);
const submitting = ref(false);
const formRef = ref();
const search = ref('');
const role = ref({ name: '', description: '' });
const permissions = ref([]);

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
}));

// Install-level tooling that only a SuperAdmin can use (router superAdminOnly
// pages + server abort_unless checks): not offered to company Admins in the
// role editor. Already-assigned values stay in the payload untouched.
const SUPERADMIN_ONLY = ['zatca_settings', 'business_modules', 'update_settings', 'backup', 'login_device_management'];
const isSuperAdmin = computed(() => auth.user?.type === 'SuperAdmin');
const GROUPS = computed(() => (isSuperAdmin.value
  ? PERMISSION_GROUPS
  : PERMISSION_GROUPS
    .map(g => ({ ...g, items: g.items.filter(i => !SUPERADMIN_ONLY.includes(i.v)) }))
    .filter(g => g.items.length)));

/** Label helpers — `l` is an i18n key, `f` the legacy fallback text. */
function itemLabel(item) {
  const first = tf(item.l, item.f);
  if (!item.l2) return first;
  return `${first}${item.sep || ' '}${tf(item.l2, item.f2)}`;
}
function groupLabel(group) {
  return group.raw ? group.label : tf(group.label, group.label);
}
function nodeLabel(node) {
  return node.raw ? node.label : tf(node.label, node.label);
}

// ---------------- layout: permissions along this company's menu ----------------
// The acting company's own menu (order, nesting, hidden flags) -- a role
// always belongs to the acting company (the edit endpoint enforces it), so
// that's the menu this role's users will actually see.
const menuStore = useMenuStore();
const company = useCompanyStore();
const menuTree = computed(() => buildTopLevelMenuForEditor(menuStore.positions || []));

const layout = computed(() => buildPermissionTree(menuTree.value, GROUPS.value, {
  allowNode: entry => isMenuEntryAllowed(entry, {
    can: () => true, // show every item regardless of the EDITOR's own permissions
    actingCompanyId: company.actingCompanyId,
    isSuperAdmin: isSuperAdmin.value,
  }),
  allowPerm: v => isSuperAdmin.value || !SUPERADMIN_ONLY.includes(v),
  moduleOn: entry => auth.moduleEnabled(entry.key),
}));

// Every permission the editor offers (menu + special + other), for the counter
// and Select all.
const offeredPerms = computed(() => [...new Set([
  ...layout.value.sections.flatMap(s => s.allPerms),
  ...layout.value.offSections.flatMap(s => s.allPerms),
  ...layout.value.other.flatMap(o => o.items.map(i => i.v)),
])]);
const totalCount = computed(() => offeredPerms.value.length);

// ---------------- search ----------------
const q = computed(() => search.value.trim().toLowerCase());
const matchText = text => String(text || '').toLowerCase().includes(q.value);
function filterSections(list) {
  if (!q.value) return list;
  return list
    .map(sec => filterPermissionNode(sec, matchText, nodeLabel, itemLabel))
    .filter(Boolean);
}
const visibleSections = computed(() => filterSections(layout.value.sections));
const visibleOffSections = computed(() => filterSections(layout.value.offSections));
const visibleOther = computed(() => {
  if (!q.value) return layout.value.other;
  return layout.value.other
    .map(o => (matchText(groupLabel(o.group))
      ? o
      : { ...o, items: o.items.filter(i => matchText(i.v) || matchText(itemLabel(i))) }))
    .filter(o => o.items.length);
});

// "Other permissions" shaped like a menu section, so the same table renders
// it: one plain row per catalogue group no menu item uses.
const otherSection = computed(() => {
  if (!visibleOther.value.length) return null;
  const children = visibleOther.value.map((o, i) => ({
    id: `__other-${i}`, label: groupLabel(o.group), raw: true, isContainer: false, plain: true,
    menuPerms: [], special: [{ group: o.group, items: o.items }], children: [],
    allPerms: o.items.map(it => it.v),
  }));
  return {
    id: '__other', label: 'Other permissions', raw: true, isContainer: true, off: false,
    menuPerms: [], special: [], children, allPerms: children.flatMap(c => c.allPerms),
  };
});

// ---------------- two-pane navigation ----------------
// Left: every section in the company's menu order (then "not in the menu",
// then modules turned off). Right: the selected one -- or, while searching,
// every section with a hit, stacked.
const navSections = computed(() => [
  ...visibleSections.value,
  ...(otherSection.value ? [otherSection.value] : []),
  ...visibleOffSections.value,
]);
const selectedId = ref(null);
const detailSections = computed(() => {
  if (q.value) return navSections.value;
  const sel = navSections.value.find(s => s.id === selectedId.value) || navSections.value[0];
  return sel ? [sel] : [];
});
watch(navSections, list => {
  if (!list.some(s => s.id === selectedId.value)) selectedId.value = list[0]?.id ?? null;
}, { immediate: true });

function sectionIcon(sec) {
  if (sec.id === '__other') return KeyOutlined;
  return MENU_ICONS[sec.entry?.icon] || fallbackIcon(sec.entry?.key || sec.label);
}
const pct = sec => (sec.allPerms.length ? Math.round((countOf(sec.allPerms) / sec.allPerms.length) * 100) : 0);
const countClass = sec => {
  const n = countOf(sec.allPerms);
  if (!n) return 'lvl-none';
  return n === sec.allPerms.length ? 'lvl-full' : 'lvl-part';
};
const pctColor = sec => ({ 'lvl-none': '#d9d9d9', 'lvl-part': '#faad14', 'lvl-full': '#52c41a' }[countClass(sec)]);

// All menu items of a section (any depth) -- for "Show all in menu" and the stats.
function sectionLeaves(sec) {
  const out = [];
  const walk = n => (n.isContainer ? n.children.forEach(walk) : n.menuPerms.length && out.push(n));
  walk(sec);
  return out;
}
const sectionMenuPerms = sec => sectionLeaves(sec).map(n => n.menuPerms[0]);
function showWholeSection(sec) {
  sectionLeaves(sec).forEach(n => toggleMenu(n, true));
}

// Summary strip: menu visibility vs form permissions, counted separately.
const allSections = computed(() => [...layout.value.sections, ...layout.value.offSections]);
const menuStats = computed(() => {
  const leaves = allSections.value.flatMap(sectionLeaves);
  return { total: leaves.length, on: leaves.filter(menuVisible).length };
});
const formStats = computed(() => {
  const menuSet = new Set(allSections.value.flatMap(sectionLeaves).flatMap(n => n.menuPerms));
  const form = offeredPerms.value.filter(v => !menuSet.has(v));
  return { total: form.length, on: countOf(form) };
});

// ---------------- toggles ----------------
function countOf(list) {
  return list.filter(v => permissions.value.includes(v)).length;
}
function togglePermission(v, on) {
  const i = permissions.value.indexOf(v);
  if (on && i === -1) permissions.value.push(v);
  else if (!on && i !== -1) permissions.value.splice(i, 1);
}
function toggleMany(list, on) {
  list.forEach(v => togglePermission(v, on));
}
const menuVisible = node => node.menuPerms.some(p => permissions.value.includes(p))
  && !permissions.value.includes(menuHideKey(node));
// Menu switch ON clears the item's hide mark and, if needed, grants its first
// gate permission (its primary one -- granting every alternative could also
// reveal unrelated items that share them). OFF hides just this item: a
// permission other still-visible items depend on is kept (the item gets a
// menu_hide:<route> mark instead); a permission nothing visible uses any more
// is removed, along with the hide marks it no longer needs.
function toggleMenu(node, on) {
  const hide = menuHideKey(node);
  if (on) {
    if (hide) togglePermission(hide, false);
    if (!node.menuPerms.some(p => permissions.value.includes(p))) {
      // Granting a shared permission fresh would also reveal the other items
      // behind it, which were off -- keep those hidden.
      const p = node.menuPerms[0];
      (layout.value.leavesByPerm?.[p] || []).forEach(n => {
        if (n !== node && !menuVisible(n)) togglePermission(menuHideKey(n), true);
      });
      togglePermission(p, true);
    }
    return;
  }
  if (hide) togglePermission(hide, true);
  node.menuPerms.forEach(p => {
    const sharers = layout.value.leavesByPerm?.[p] || [node];
    const stillUsed = sharers.some(n => n !== node && menuVisible(n));
    if (stillUsed && hide) return;
    togglePermission(p, false);
    sharers.forEach(n => {
      if (!n.menuPerms.some(q => permissions.value.includes(q))) togglePermission(menuHideKey(n), false);
    });
  });
}
// Items with no permission gate: only a SuperAdmin working as AdminSystem
// (999) may force them shown / hidden -- for every company at once.
const canForceVisibility = computed(() => isSuperAdmin.value && Number(company.actingCompanyId) === 999);
const visibilityBusy = ref('');
async function forceVisibility(node, hidden) {
  const key = node.entry && (node.entry.to || node.entry.key);
  if (!key) return;
  visibilityBusy.value = key;
  try {
    const res = await http.post('menu-positions/visibility', { key, hidden });
    await menuStore.loadPositions();
    message.success(`${nodeLabel(node)}: ${hidden ? 'hidden for everyone' : 'shown to everyone'} (${res?.companies ?? 0} companies)`);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    visibilityBusy.value = '';
  }
}

function selectAll(on) {
  const offered = new Set(offeredPerms.value);
  // Values the editor doesn't offer (SuperAdmin-only for an Admin, legacy
  // leftovers) are kept untouched either way.
  // Per-item menu hide marks go either way (all on = everything visible).
  const untouched = permissions.value.filter(v => !offered.has(v) && !String(v).startsWith(MENU_HIDE_PREFIX));
  permissions.value = on ? [...new Set([...untouched, ...offered])] : untouched;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  submitting.value = true;
  const payload = { role: { name: role.value.name, description: role.value.description }, permissions: permissions.value };
  try {
    if (isEdit) {
      await http.put(`roles/${id}`, payload);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('roles', payload);
      message.success(t('Successfully_Created'));
    }
    // legacy dispatched refreshUserPermissions after saving — the store's
    // load() short-circuits once loaded, so clear the flag to force a refetch.
    try {
      auth.loaded = false;
      await auth.load();
    } catch (e) { /* non-fatal */ }
    router.push('/permissions');
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  // Always re-read the company's saved menu layout, so a reorder made in
  // Manage Menus (by anyone) is reflected here right away.
  const menuReady = menuStore.loadPositions();
  if (!isEdit && copyFrom) {
    try {
      const [data] = await Promise.all([http.get(`roles/${copyFrom}/edit`), menuReady]);
      const src = data.role || {};
      role.value = { name: `${src.name || ''} (copy)`.trim(), description: src.description || '' };
      permissions.value = Array.isArray(data.permissions) ? [...data.permissions] : [];
      message.info('Copy of the role loaded -- rename it and adjust permissions, then Submit to create it.', 6);
    } catch (e) {
      message.error(e?.data?.message || t('InvalidData'));
    } finally {
      isLoading.value = false;
    }
    return;
  }
  if (!isEdit) {
    await menuReady;
    return;
  }
  try {
    const [data] = await Promise.all([http.get(`roles/${id}/edit`), menuReady]);
    role.value = data.role || { name: '', description: '' };
    permissions.value = Array.isArray(data.permissions) ? data.permissions : [];
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    isLoading.value = false;
  }
});
</script>

<style scoped>
/* ---- summary strip ---- */
.summary {
  display: flex;
  align-items: center;
  gap: 16px 28px;
  flex-wrap: wrap;
}
.stat {
  display: flex;
  align-items: center;
  gap: 10px;
}
.stat--grow {
  flex: 1 1 200px;
  flex-direction: column;
  align-items: stretch;
  gap: 0;
}
.stat__icon {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
}
.stat__icon--menu { background: rgba(22, 119, 255, 0.12); color: #1677ff; }
.stat__icon--form { background: rgba(114, 46, 209, 0.12); color: #722ed1; }
.stat__label {
  font-size: 12px;
  opacity: 0.65;
}
.stat__value {
  font-size: 20px;
  font-weight: 700;
  line-height: 1.2;
}
.stat__value small {
  font-size: 13px;
  font-weight: 400;
  opacity: 0.6;
}

/* ---- legend ---- */
.legend {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 14px;
  margin-top: 12px;
  padding-top: 10px;
  border-top: 1px dashed rgba(128, 128, 128, 0.25);
  font-size: 12px;
}
.legend > span {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  pointer-events: none;
}
.legend__title {
  font-weight: 600;
}
.legend__pill {
  --c: #722ed1;
  padding: 0 9px;
  border-radius: 999px;
  background: var(--c);
  color: #fff;
  line-height: 20px;
}
.legend__pill--view { --c: #1677ff; }
.legend__pill--add { --c: #389e0d; }
.legend__pill--edit { --c: #d46b08; }
.legend__pill--delete { --c: #cf1322; }
.legend__pill--other { --c: #722ed1; }

/* ---- two panes ---- */
.panes {
  display: grid;
  grid-template-columns: 270px minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}
.nav {
  position: sticky;
  top: 12px;
  max-height: calc(100vh - 24px);
  overflow-y: auto;
}
.nav__hint,
.nav__divider {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  opacity: 0.55;
  padding: 6px 8px 4px;
}
.nav__divider {
  margin-top: 6px;
  border-top: 1px solid rgba(128, 128, 128, 0.2);
  padding-top: 10px;
}
.nav__item {
  position: relative;
  display: grid;
  grid-template-columns: 20px minmax(0, 1fr) auto;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 7px 8px 9px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: inherit;
  text-align: left;
  cursor: pointer;
}
.nav__item:hover {
  background: rgba(128, 128, 128, 0.1);
}
.nav__item--active {
  background: rgba(22, 119, 255, 0.12);
  color: #1677ff;
  font-weight: 600;
}
.nav__item--off {
  opacity: 0.55;
}
.nav__icon {
  width: 16px;
  height: 16px;
}
.nav__label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.nav__count {
  font-size: 11px;
  padding: 0 6px;
  border-radius: 999px;
  font-weight: 500;
}
.nav__bar {
  position: absolute;
  left: 36px;
  right: 8px;
  bottom: 3px;
  height: 3px;
  border-radius: 2px;
  background: rgba(128, 128, 128, 0.15);
  overflow: hidden;
}
.nav__bar > span {
  display: block;
  height: 100%;
}
.nav__count.lvl-none { background: rgba(128, 128, 128, 0.15); }
.nav__count.lvl-part { background: rgba(250, 173, 20, 0.2); color: #d48806; }
.nav__count.lvl-full { background: rgba(82, 196, 26, 0.18); color: #389e0d; }
.nav__bar > .lvl-part { background: #faad14; }
.nav__bar > .lvl-full { background: #52c41a; }

.detail {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}
.detail__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.detail__title {
  display: flex;
  align-items: center;
  gap: 12px;
}
.detail__icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(22, 119, 255, 0.12);
  color: #1677ff;
}
.detail__name {
  font-size: 17px;
  font-weight: 700;
}
.detail__sub {
  font-size: 12px;
  opacity: 0.6;
}

@media (max-width: 900px) {
  .panes {
    grid-template-columns: 1fr;
  }
  .nav {
    position: static;
    max-height: 280px;
  }
}
</style>
