<template>
  <a-card
    :body-style="{ padding: 0 }" :style="cardVars"
    :class="{ 'dt-th-bg': !!thBg, 'dt-th-color': !!dt.headerColor }"
  >
    <div v-if="toolbarVisible" class="datatable-toolbar">
      <a-input-search
        v-if="searchable && dt.showSearch"
        :value="crud.search.value"
        :placeholder="$t('Search_this_table')"
        allow-clear
        style="max-width: 280px"
        @update:value="v => (crud.search.value = v)"
        @search="crud.reload()"
      />
      <span v-else></span>
      <a-space>
        <a-button
          v-if="selectionEnabled && crud.selectedIds.value.length"
          danger
          @click="crud.removeSelected()"
        >
          <template #icon><DeleteOutlined /></template>
          {{ $t('Del') }} ({{ crud.selectedIds.value.length }})
        </a-button>
        <!-- Safe bulk actions for the selected rows (bulkSelect pages). -->
        <template v-if="bulkSelect && crud.selectedIds.value.length">
          <span class="dt-bulk-count">{{ crud.selectedIds.value.length }} selected</span>
          <slot name="bulk" :ids="crud.selectedIds.value" />
        </template>
        <slot name="toolbar" />

        <!-- Column visibility -->
        <a-popover v-if="dt.showColumns" trigger="click" placement="bottomRight" overlay-class-name="dt-cols-pop">
          <template #content>
            <div class="col-toggle-hint">{{ $t('Columns_Remember_Hint') }}</div>
            <div class="col-toggle-list">
              <a-checkbox
                v-for="c in toggleableColumns"
                :key="colKey(c)"
                :checked="!hiddenKeys.includes(colKey(c))"
                @change="toggleColumn(colKey(c))"
              >
                {{ c.title }}
              </a-checkbox>
            </div>
            <a-button type="link" size="small" class="col-toggle-reset" @click="resetHiddenColumns">
              {{ $t('Reset') }}
            </a-button>
          </template>
          <a-tooltip :title="$t('Columns') || 'Columns'" placement="left">
            <a-button>
              <template #icon><SettingOutlined /></template>
            </a-button>
          </a-tooltip>
        </a-popover>

        <!-- Customize columns (admins/SuperAdmin): rename, width, show/hide,
             reorder -- for everyone in the company, per table. -->
        <a-tooltip v-if="auth.canCustomizeColumns" :title="$t('Customize_Columns') === 'Customize_Columns' ? 'Customize columns' : $t('Customize_Columns')">
          <a-button @click="openCustomize">
            <template #icon><ColumnWidthOutlined /></template>
          </a-button>
        </a-tooltip>

        <!-- Fit to width: no horizontal scroll bar -- the columns share the
             screen and long text wraps. Remembered per table, per user. -->
        <a-tooltip v-if="!isPhone" :title="fitWidth ? 'Fit to width: on (click for full-width columns)' : 'Fit to width (no horizontal scroll bar)'">
          <a-button :type="fitWidth ? 'primary' : 'default'" @click="toggleFitWidth">
            <template #icon><ShrinkOutlined /></template>
          </a-button>
        </a-tooltip>

        <!-- Refresh -->
        <a-tooltip v-if="dt.showRefresh" :title="$t('Refresh')">
          <a-button @click="crud.fetchRows()">
            <template #icon><ReloadOutlined :spin="crud.loading.value" /></template>
          </a-button>
        </a-tooltip>
      </a-space>
    </div>

    <a-table
      class="dt-managed"
      :class="{ 'dt-fit': fitWidth }"
      :columns="fitWidth ? fitColumns : (isPhone ? unpinnedColumns : visibleColumns)"
      :data-source="crud.rows.value"
      :loading="crud.loading.value"
      :pagination="crud.pagination.value"
      :row-selection="checkboxes ? crud.rowSelection.value : undefined"
      :row-key="rowKey"
      :scroll="fitWidth ? undefined : { x: 'max-content' }"
      :size="dt.density"
      :bordered="dt.bordered"
      :show-sorter-tooltip="false"
      :row-class-name="dt.striped ? stripedClass : undefined"
      :row-expandable="$slots.expandedRowRender ? rowExpandable : undefined"
      @change="crud.onChange"
    >
      <!-- Expandable rows only when the page supplies the content. -->
      <template v-if="$slots.expandedRowRender" #expandedRowRender="slotProps">
        <slot name="expandedRowRender" v-bind="slotProps" />
      </template>
      <!-- Forward cell rendering to the page. -->
      <template #bodyCell="slotProps">
        <slot name="bodyCell" v-bind="slotProps" />
      </template>
      <!-- Custom header cells (e.g. a help tooltip) only when the page asks. -->
      <template v-if="$slots.headerCell" #headerCell="slotProps">
        <slot name="headerCell" v-bind="slotProps" />
      </template>
      <template #emptyText>
        <a-empty :description="$t('NodataAvailable')" style="padding: 32px 0" />
      </template>
      <!-- Totals row (legacy rendered this as a bottom "group header").
           Pages either supply a custom #summary slot, or simply flag columns
           with `sum: true` (plain number) / `sum: 'money'` (currency) and get
           this auto-generated page-total footer. -->
      <template v-if="$slots.summary || hasAutoSummary" #summary>
        <slot name="summary">
          <a-table-summary-row>
            <a-table-summary-cell v-if="checkboxes" :index="0" />
            <a-table-summary-cell
              v-for="(col, i) in visibleColumns"
              :key="colKey(col) || i"
              :index="i + (checkboxes ? 1 : 0)"
              :align="col.align"
            >
              <b v-if="col.sum">{{ sumFor(col) }}</b>
              <b v-else-if="i === totalLabelIndex">{{ $t('Total') }}</b>
            </a-table-summary-cell>
          </a-table-summary-row>
        </slot>
      </template>
    </a-table>

    <!-- Customize columns: applies to every user of this company on this table. -->
    <a-modal
      v-model:open="customizeOpen"
      :title="$t('Customize_Columns') === 'Customize_Columns' ? 'Customize columns' : $t('Customize_Columns')"
      :width="640"
      :confirm-loading="customizeSaving"
      @ok="saveCustomize"
    >
      <div class="cc-hint">Rename, resize, hide or reorder this table's columns for everyone in the company. Leave a name or width empty to keep the default.</div>
      <div class="cc-head">
        <span class="cc-move"></span>
        <span class="cc-vis">Show</span>
        <span class="cc-title">Column name</span>
        <span class="cc-width">Width (px)</span>
      </div>
      <div v-for="(row, i) in customizeRows" :key="row.key" class="cc-row">
        <span class="cc-move">
          <a-button size="small" type="text" :disabled="i === 0" @click="moveRow(i, -1)"><template #icon><ArrowUpOutlined /></template></a-button>
          <a-button size="small" type="text" :disabled="i === customizeRows.length - 1" @click="moveRow(i, 1)"><template #icon><ArrowDownOutlined /></template></a-button>
        </span>
        <span class="cc-vis"><a-checkbox v-model:checked="row.visible" /></span>
        <span class="cc-title"><a-input v-model:value="row.title" size="small" :placeholder="row.original" allow-clear /></span>
        <span class="cc-width"><a-input-number v-model:value="row.width" size="small" :min="40" :max="800" placeholder="auto" style="width: 100%" /></span>
      </div>
      <template #footer>
        <a-button danger :loading="customizeSaving" @click="resetCustomize">{{ $t('Reset') }}</a-button>
        <a-button @click="customizeOpen = false">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" :loading="customizeSaving" @click="saveCustomize">{{ $t('Save') }}</a-button>
      </template>
    </a-modal>
  </a-card>
</template>

<script setup>
/**
 * Thin wrapper binding an a-table to a useCrudTable() instance: server-side
 * pagination, sorting, search and bulk delete wired once. Pages supply columns
 * + a bodyCell slot and nothing else.
 *
 * Display preferences (font, size, density, borders, stripes and which toolbar
 * components show) come from the datatable store — customized under
 * System Settings > DataTable and persisted per device.
 */
import { ref, computed } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import {
  DeleteOutlined, SettingOutlined, ReloadOutlined, ColumnWidthOutlined, ArrowUpOutlined, ArrowDownOutlined, ShrinkOutlined,
} from '@ant-design/icons-vue';
import { useDatatableStore, headerBgCss } from '../stores/datatable';
import { useAuthStore } from '../stores/auth';
import { useFormat } from '../composables/useFormat';
import http from '../lib/http';
import { getUserPref, setUserPref } from '../lib/userPrefs';

const props = defineProps({
  crud: { type: Object, required: true },
  columns: { type: Array, required: true },
  rowKey: { type: String, default: 'id' },
  // With an #expandedRowRender slot: which rows get the expand arrow (default all).
  rowExpandable: { type: Function, default: undefined },
  searchable: { type: Boolean, default: true },
  // Row checkboxes + bulk delete (endpoint must support /delete/by_selection).
  // Disabled system-wide (2026-09-23) -- a real server bug let a blocked bulk
  // delete (e.g. one selected row already had a return) silently report
  // success while leaving every row untouched (SalesController/
  // PurchasesController delete_by_selection() swallowed the block inside a
  // DB::transaction() closure). Even with that fixed, deleting several
  // records in one click is too easy to trigger by accident to leave
  // enabled across every list in the app. The prop/wiring stays in place
  // (harmless, ignored) rather than ripped out of every page that passes it.
  selectable: { type: Boolean, default: false },
  // The exception: row checkboxes for SAFE bulk actions only (the page's
  // #bulk slot, e.g. Email Office "set type"), never the bulk delete above.
  bulkSelect: { type: Boolean, default: false },
  // Identifies this table for admin column customization. Defaults to the
  // route + the crud endpoint, which keeps pages with several tables apart.
  tableKey: { type: String, default: '' },
});

const dt = useDatatableStore();
const auth = useAuthStore();

// Hard override, not just a lower default: pages still pass :selectable="...",
// this makes it a no-op everywhere instead of requiring every call site to be
// edited individually. See the `selectable` prop comment above for why.
const selectionEnabled = false;
// Checkboxes shown: only for a page that opted into safe bulk actions (bulkSelect).
const checkboxes = computed(() => selectionEnabled || props.bulkSelect);

const toolbarVisible = computed(() =>
  (props.searchable && dt.showSearch) || checkboxes.value || dt.showColumns || dt.showRefresh
);

// tbody/thead styling via CSS vars, so 'default' cleanly inherits Ant's stack.
// Header background: the theme tint by default, a custom colour, or none
// ('plain' = Ant's own header) -- headerBgCss(). Header text colour only when set.
const thBg = computed(() => headerBgCss(dt.headerBg));
const cardVars = computed(() => ({
  '--dt-font': dt.fontStack || 'inherit',
  '--dt-font-size': `${dt.fontSize}px`,
  '--dt-th-weight': dt.headerWeight || '600',
  '--dt-th-size': `${dt.headerFontSize || 14}px`,
  '--dt-th-transform': dt.headerUppercase ? 'uppercase' : 'none',
  ...(thBg.value ? { '--dt-th-bg': thBg.value } : {}),
  ...(dt.headerColor ? { '--dt-th-color': dt.headerColor } : {}),
}));

const stripedClass = (_record, index) => (index % 2 === 1 ? 'dt-row-striped' : '');

/* ------------------------------------------------ column visibility */
const colKey = c => c.key ?? c.dataIndex;

// Persisted per page (route name/path) on the user's account (server, see
// lib/userPrefs.js) so hide/unhide choices follow the user to any PC/browser
// and survive logout; localStorage is only a fallback/offline copy.
// A page with several tables (e.g. the customer details tabs) passes a
// tableKey so each table remembers its own hidden columns.
const route = useRoute();
const hiddenColsStorageKey = `dt_hidden_columns_${String(route.name || route.path)}${props.tableKey ? `|${props.tableKey}` : ''}`;

function readLocalHiddenKeys() {
  try {
    const raw = window.localStorage.getItem(hiddenColsStorageKey);
    if (raw !== null) {
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed)) return parsed;
    }
  } catch (e) { /* storage unavailable / corrupt value */ }
  return null;
}

function readStoredHiddenKeys() {
  const server = getUserPref(hiddenColsStorageKey);
  if (Array.isArray(server)) return server;
  // A choice made before this was saved on the account: keep it and move it
  // to the account, so it isn't lost on the next PC/browser.
  const local = readLocalHiddenKeys();
  if (local) {
    setUserPref(hiddenColsStorageKey, local);
    return local;
  }
  // Columns flagged `defaultHidden: true` start unchecked in the picker.
  return props.columns.filter(c => c.defaultHidden).map(colKey);
}

const hiddenKeys = ref(readStoredHiddenKeys());

/* ------------------------------------------------ fit to width */
// On (the default -- no horizontal scroll bar): ellipsis columns wrap
// instead (else they'd cut to "...") and fixed columns stop sticking. The
// button turns it off for that table (full-width columns + scroll bar);
// only the "off" choice is saved, on the user's account per table.
const fitStorageKey = hiddenColsStorageKey.replace('dt_hidden_columns_', 'dt_fit_width_');
const fitPref = ref(getUserPref(fitStorageKey) !== false);
// Phones (2026-10-07): never fit -- squeezing every column into 390 px made the
// rows unreadable and left no horizontal scroll. There the table always scrolls
// sideways (swipe), and the Fit button is hidden.
const phoneMq = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(max-width: 767px)') : null;
const isPhone = ref(!!phoneMq?.matches);
phoneMq?.addEventListener?.('change', e => { isPhone.value = e.matches; });
const fitWidth = computed({
  get: () => fitPref.value && !isPhone.value,
  set: v => { fitPref.value = v; },
});
// Phones: no pinned (fixed) columns -- a pinned Action column covered the
// others while swiping; the whole row scrolls together instead.
const unpinnedColumns = computed(() => visibleColumns.value.map(c => (c.fixed ? { ...c, fixed: undefined } : c)));
function toggleFitWidth() {
  fitWidth.value = !fitWidth.value;
  setUserPref(fitStorageKey, fitWidth.value ? null : false);
}

/* ------------------------------------------------ admin column customization
 * Company-wide, per table (settings.datatable_columns via get_user_auth):
 * [{key, title, width, hidden}] in display order. Applied before the per-user
 * show/hide picker; admin-hidden columns are removed for everyone and don't
 * appear in the picker. Columns added to a page later (not in the saved
 * list) keep showing, after the saved ones. The actions column never moves. */
const tableKeyResolved = computed(() =>
  props.tableKey || `${String(route.name || route.path)}|${props.crud.endpoint || ''}`);
const adminCfg = computed(() => {
  const cfg = auth.datatableColumns?.[tableKeyResolved.value];
  return Array.isArray(cfg) && cfg.length ? cfg : null;
});

const customizedColumns = computed(() => {
  const cfg = adminCfg.value;
  if (!cfg) return props.columns;
  const actionsIdx = props.columns.findIndex(c => colKey(c) === 'actions');
  const data = props.columns.filter(c => colKey(c) !== 'actions');
  const byKey = new Map(data.map(c => [colKey(c), c]));
  const out = [];
  cfg.forEach(entry => {
    const col = byKey.get(entry.key);
    if (!col) return;
    byKey.delete(entry.key);
    if (entry.hidden) return;
    out.push({
      ...col,
      ...(entry.title ? { title: entry.title } : {}),
      ...(entry.width ? { width: entry.width } : {}),
    });
  });
  data.forEach(c => { if (byKey.has(colKey(c))) out.push(c); });
  if (actionsIdx >= 0) {
    const actions = props.columns[actionsIdx];
    if (actionsIdx === 0) out.unshift(actions); else out.push(actions);
  }
  return out;
});

const customizeOpen = ref(false);
const customizeSaving = ref(false);
const customizeRows = ref([]);

function openCustomize() {
  const cfg = adminCfg.value || [];
  const cfgByKey = new Map(cfg.map(e => [e.key, e]));
  const data = props.columns.filter(c => colKey(c) !== 'actions');
  const ordered = [
    ...cfg.map(e => data.find(c => colKey(c) === e.key)).filter(Boolean),
    ...data.filter(c => !cfgByKey.has(colKey(c))),
  ];
  customizeRows.value = ordered.map(c => {
    const e = cfgByKey.get(colKey(c)) || {};
    return {
      key: colKey(c),
      original: typeof c.title === 'string' ? c.title : String(colKey(c)),
      title: e.title || '',
      width: e.width || null,
      visible: !e.hidden,
    };
  });
  customizeOpen.value = true;
}

function moveRow(i, dir) {
  const rows = customizeRows.value;
  const j = i + dir;
  if (j < 0 || j >= rows.length) return;
  [rows[i], rows[j]] = [rows[j], rows[i]];
}

async function persistCustomize(columns) {
  customizeSaving.value = true;
  try {
    const res = await http.put('datatable_columns', { table_key: tableKeyResolved.value, columns });
    if (auth.user) auth.user.datatable_columns = res?.datatable_columns || {};
    customizeOpen.value = false;
    message.success('Saved');
  } catch (e) {
    message.error(e?.data?.message || 'Could not save');
  } finally {
    customizeSaving.value = false;
  }
}

function saveCustomize() {
  persistCustomize(customizeRows.value.map(r => ({
    key: String(r.key),
    title: (r.title || '').trim() || null,
    width: r.width ? Number(r.width) : null,
    hidden: !r.visible,
  })));
}

function resetCustomize() {
  persistCustomize(null);
}

function persistHiddenKeys() {
  setUserPref(hiddenColsStorageKey, [...hiddenKeys.value]);
  try {
    window.localStorage.setItem(hiddenColsStorageKey, JSON.stringify(hiddenKeys.value));
  } catch (e) { /* storage unavailable (private mode, quota) -- the account copy still holds it */ }
}

// The actions column stays out of the picker — hiding it strands the row menu.
const toggleableColumns = computed(() => customizedColumns.value.filter(c => colKey(c) !== 'actions'));

/* ------------------------------------------------ sorting
 * Global "Sortable columns" pref (System Settings > DataTable):
 * - off: strip every sorter so no column shows sort arrows;
 * - on:  columns that declared `sorter: true` keep server-side sorting, and
 *   every other data column gets a client-side comparator over the loaded
 *   page. Client-side because most of those fields (joined names, computed
 *   quantities…) are not real DB columns — sending them as SortField would
 *   make the backend's orderBy() throw. useCrudTable recognizes function
 *   sorters and keeps them out of the server params. */
function localSorter(key) {
  return (a, b) => {
    const av = a?.[key], bv = b?.[key];
    const an = Number(av), bn = Number(bv);
    if (av !== '' && bv !== '' && Number.isFinite(an) && Number.isFinite(bn)) return an - bn;
    // numeric collation so "12.00 pcs" > "9.00 pcs" and dates stay chronological
    return String(av ?? '').localeCompare(String(bv ?? ''), undefined, { numeric: true, sensitivity: 'base' });
  };
}

const sortedColumns = computed(() => {
  if (!dt.autoSort) return customizedColumns.value.map(c => (c.sorter ? { ...c, sorter: undefined } : c));
  return customizedColumns.value.map(c => {
    if (c.sorter || c.sorter === false || !c.dataIndex || colKey(c) === 'actions') return c;
    return { ...c, sorter: localSorter(c.dataIndex) };
  });
});

// A column with a width (its own, or set in Customize columns) keeps it: the
// table lays out at max-content, where one long unwrapped text (e.g. an
// email preview) would otherwise stretch its column and ignore the width.
const withWidth = c => {
  const w = Number(c.width);
  if (!w || c.customCell) return c;
  const style = { maxWidth: `${w}px`, whiteSpace: 'normal', overflowWrap: 'anywhere' };
  return { ...c, customCell: () => ({ style }) };
};
const visibleColumns = computed(() => sortedColumns.value.filter(c => !hiddenKeys.value.includes(colKey(c))).map(withWidth));
// Fit to width: same columns, wrapping instead of ellipsis, not pinned.
const fitColumns = computed(() => visibleColumns.value.map(c => ({ ...c, ellipsis: false, fixed: undefined })));

// The auto-summary "Total" label goes on the first data column, in case a page
// leads with its actions column.
const totalLabelIndex = computed(() => visibleColumns.value.findIndex(c => colKey(c) !== 'actions'));

function toggleColumn(key) {
  const i = hiddenKeys.value.indexOf(key);
  if (i >= 0) hiddenKeys.value.splice(i, 1);
  else hiddenKeys.value.push(key);
  persistHiddenKeys();
}

// Clears this table's saved choices back to the columns' own defaultHidden
// flags, and forgets the persisted override entirely (not just this session).
function resetHiddenColumns() {
  hiddenKeys.value = props.columns.filter(c => c.defaultHidden).map(colKey);
  setUserPref(hiddenColsStorageKey, null);
  try {
    window.localStorage.removeItem(hiddenColsStorageKey);
  } catch (e) { /* storage unavailable */ }
}

/* ------------------------------------------------ auto totals footer */
const { money, number } = useFormat();

// Only for columns still visible — totals follow the column picker.
const hasAutoSummary = computed(
  () => visibleColumns.value.some(c => c.sum) && props.crud.rows.value.length > 0
);

// Page-scoped totals (the loaded rows), like the legacy bottom group-header.
function sumFor(col) {
  const key = col.dataIndex ?? col.key;
  const total = props.crud.rows.value.reduce((acc, r) => acc + (Number(r?.[key]) || 0), 0);
  return col.sum === 'money' ? money(total) : number(total, Number.isInteger(total) ? 0 : 2);
}
</script>

<style scoped>
.dt-bulk-count {
  font-weight: 600;
  color: #6d28d9;
}
.datatable-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 16px;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
.col-toggle-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  /* Viewport-aware: a fixed cap silently clipped the tail of longer column sets
     (products has 12 toggleable). The reserved gutter keeps the width stable so
     the popover doesn't re-measure and flash while it animates open — that was
     the reason the bar used to be hidden outright. */
  max-height: min(60vh, 460px);
  overflow-y: auto;
  scrollbar-gutter: stable;
  scrollbar-width: thin;
  min-width: 180px;
}
.col-toggle-list::-webkit-scrollbar {
  width: 6px;
}
.col-toggle-list::-webkit-scrollbar-thumb {
  background: rgba(128, 128, 128, 0.35);
  border-radius: 3px;
}
.col-toggle-list :deep(.ant-checkbox-wrapper) {
  margin-inline-start: 0;
}
.col-toggle-hint {
  font-size: 11px;
  opacity: 0.55;
  margin-bottom: 8px;
  max-width: 220px;
}
.col-toggle-reset {
  padding: 0;
  height: auto;
  margin-top: 4px;
}
.cc-hint { font-size: 12px; opacity: 0.65; margin-bottom: 12px; }
.cc-head, .cc-row { display: flex; align-items: center; gap: 8px; }
.cc-head { font-size: 12px; font-weight: 600; opacity: 0.7; margin-bottom: 6px; }
.cc-row { padding: 4px 0; border-top: 1px solid rgba(128, 128, 128, 0.12); }
.cc-move { width: 64px; flex: none; display: flex; }
.cc-vis { width: 44px; flex: none; text-align: center; }
.cc-title { flex: 1; min-width: 0; }
.cc-width { width: 110px; flex: none; }

/* Body cells follow the user's DataTable preferences (font + size). */
:deep(.ant-table-tbody > tr > td) {
  font-family: var(--dt-font, inherit);
  font-size: var(--dt-font-size, 13px);
}
:deep(.dt-row-striped > td) {
  background: rgba(128, 128, 128, 0.045);
}

/* Header cells: weight/size/case always follow the prefs (defaults match
   Ant's); colors apply only when the user picked one (classes above). */
:deep(.ant-table-thead > tr > th) {
  font-weight: var(--dt-th-weight, 600);
  font-size: var(--dt-th-size, 14px);
  text-transform: var(--dt-th-transform, none);
}
.dt-th-bg :deep(.ant-table-thead > tr > th) {
  background: var(--dt-th-bg);
}
.dt-th-color :deep(.ant-table-thead > tr > th) {
  color: var(--dt-th-color);
}
</style>

<!-- The column-visibility popover is teleported to <body>, outside this
     component's scoped styles — its chrome must be clamped globally so no
     transient scrollbar flashes on .ant-popover-content while it animates.
     Scrolling happens ONLY inside .col-toggle-list. -->
<style>
/* Fit to width: text wraps between words instead of widening the table past
   the screen. Headers never split a word; a cell splits one only when it
   can't fit on its own (long emails, references). */
.dt-fit .ant-table-thead > tr > th,
.dt-fit .ant-table-tbody > tr > td {
  white-space: normal !important;
}
.dt-fit .ant-table-thead > tr > th {
  overflow-wrap: normal;
  word-break: normal;
}
.dt-fit .ant-table-tbody > tr > td {
  overflow-wrap: break-word;
}
.dt-fit .ant-table-content > table {
  width: 100% !important;
  min-width: 0 !important;
}

.dt-cols-pop .ant-popover-content,
.dt-cols-pop .ant-popover-inner,
.dt-cols-pop .ant-popover-inner-content {
  overflow: hidden !important;
}
</style>
