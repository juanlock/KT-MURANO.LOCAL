<template>
  <a-card
    :body-style="{ padding: 0 }" :style="cardVars"
    :class="{ 'dt-th-bg': !!dt.headerBg, 'dt-th-color': !!dt.headerColor }"
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
          v-if="selectable && crud.selectedIds.value.length"
          danger
          @click="crud.removeSelected()"
        >
          <template #icon><DeleteOutlined /></template>
          {{ $t('Del') }} ({{ crud.selectedIds.value.length }})
        </a-button>
        <slot name="toolbar" />

        <!-- Column visibility -->
        <a-popover v-if="dt.showColumns" trigger="click" placement="bottomRight" overlay-class-name="dt-cols-pop">
          <template #content>
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
          </template>
          <a-tooltip :title="$t('Columns') || 'Columns'" placement="left">
            <a-button>
              <template #icon><SettingOutlined /></template>
            </a-button>
          </a-tooltip>
        </a-popover>

        <!-- Refresh -->
        <a-tooltip v-if="dt.showRefresh" :title="$t('Refresh')">
          <a-button @click="crud.fetchRows()">
            <template #icon><ReloadOutlined :spin="crud.loading.value" /></template>
          </a-button>
        </a-tooltip>
      </a-space>
    </div>

    <a-table
      :columns="visibleColumns"
      :data-source="crud.rows.value"
      :loading="crud.loading.value"
      :pagination="crud.pagination.value"
      :row-selection="selectable ? crud.rowSelection.value : undefined"
      :row-key="rowKey"
      :scroll="{ x: 'max-content' }"
      :size="dt.density"
      :bordered="dt.bordered"
      :show-sorter-tooltip="false"
      :row-class-name="dt.striped ? stripedClass : undefined"
      @change="crud.onChange"
    >
      <!-- Forward cell rendering to the page. -->
      <template #bodyCell="slotProps">
        <slot name="bodyCell" v-bind="slotProps" />
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
            <a-table-summary-cell v-if="selectable" :index="0" />
            <a-table-summary-cell
              v-for="(col, i) in visibleColumns"
              :key="colKey(col) || i"
              :index="i + (selectable ? 1 : 0)"
              :align="col.align"
            >
              <b v-if="col.sum">{{ sumFor(col) }}</b>
              <b v-else-if="i === totalLabelIndex">{{ $t('Total') }}</b>
            </a-table-summary-cell>
          </a-table-summary-row>
        </slot>
      </template>
    </a-table>
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
import { DeleteOutlined, SettingOutlined, ReloadOutlined } from '@ant-design/icons-vue';
import { useDatatableStore } from '../stores/datatable';
import { useFormat } from '../composables/useFormat';

const props = defineProps({
  crud: { type: Object, required: true },
  columns: { type: Array, required: true },
  rowKey: { type: String, default: 'id' },
  searchable: { type: Boolean, default: true },
  // Row checkboxes + bulk delete (endpoint must support /delete/by_selection).
  selectable: { type: Boolean, default: false },
});

const dt = useDatatableStore();

const toolbarVisible = computed(() =>
  (props.searchable && dt.showSearch) || props.selectable || dt.showColumns || dt.showRefresh
);

// tbody/thead styling via CSS vars, so 'default' cleanly inherits Ant's stack.
// Header colors are only emitted when set — the theme's own header colors
// (light AND dark) stay untouched otherwise.
const cardVars = computed(() => ({
  '--dt-font': dt.fontStack || 'inherit',
  '--dt-font-size': `${dt.fontSize}px`,
  '--dt-th-weight': dt.headerWeight || '600',
  '--dt-th-size': `${dt.headerFontSize || 14}px`,
  '--dt-th-transform': dt.headerUppercase ? 'uppercase' : 'none',
  ...(dt.headerBg ? { '--dt-th-bg': dt.headerBg } : {}),
  ...(dt.headerColor ? { '--dt-th-color': dt.headerColor } : {}),
}));

const stripedClass = (_record, index) => (index % 2 === 1 ? 'dt-row-striped' : '');

/* ------------------------------------------------ column visibility */
const colKey = c => c.key ?? c.dataIndex;

// Columns flagged `defaultHidden: true` start unchecked in the picker; the
// user can turn them on from the columns dropdown for the session.
const hiddenKeys = ref(props.columns.filter(c => c.defaultHidden).map(colKey));

// The actions column stays out of the picker — hiding it strands the row menu.
const toggleableColumns = computed(() => props.columns.filter(c => colKey(c) !== 'actions'));

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
  if (!dt.autoSort) return props.columns.map(c => (c.sorter ? { ...c, sorter: undefined } : c));
  return props.columns.map(c => {
    if (c.sorter || c.sorter === false || !c.dataIndex || colKey(c) === 'actions') return c;
    return { ...c, sorter: localSorter(c.dataIndex) };
  });
});

const visibleColumns = computed(() => sortedColumns.value.filter(c => !hiddenKeys.value.includes(colKey(c))));

// The auto-summary "Total" label goes on the first data column, in case a page
// leads with its actions column.
const totalLabelIndex = computed(() => visibleColumns.value.findIndex(c => colKey(c) !== 'actions'));

function toggleColumn(key) {
  const i = hiddenKeys.value.indexOf(key);
  if (i >= 0) hiddenKeys.value.splice(i, 1);
  else hiddenKeys.value.push(key);
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
.dt-cols-pop .ant-popover-content,
.dt-cols-pop .ant-popover-inner,
.dt-cols-pop .ant-popover-inner-content {
  overflow: hidden !important;
}
</style>
