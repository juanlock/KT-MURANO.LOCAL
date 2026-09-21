<template>
  <div class="page">
    <PageHeader :title="title" :breadcrumb="breadcrumb">
      <template #actions>
        <a-space wrap>
          <slot name="actions" />
          <a-button v-if="$slots.filters" @click="filterOpen = true">
            <template #icon><FilterOutlined /></template>
            {{ $t('Filter') }}
          </a-button>
          <a-button :loading="exporting === 'excel'" @click="doExport('excel')">
            <template #icon><FileExcelOutlined /></template>
            {{ $t('Export') }} Excel
          </a-button>
          <a-button :loading="exporting === 'pdf'" @click="doExport('pdf')">
            <template #icon><FilePdfOutlined /></template>
            {{ $t('Export') }} PDF
          </a-button>
          <a-button :loading="exporting === 'print'" @click="doPrint">
            <template #icon><PrinterOutlined /></template>
            {{ $t('print') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <!-- Optional trend chart for reports that return a timeseries -->
    <slot name="chart" />

    <DataTable :crud="crud" :columns="columns" :row-key="rowKey">
      <template #bodyCell="slotProps">
        <slot name="bodyCell" v-bind="slotProps" />
      </template>
      <!-- Custom totals row; columns flagged with `sum` get one automatically. -->
      <template v-if="$slots.summary" #summary>
        <slot name="summary" />
      </template>
    </DataTable>

    <!-- Filters (date range, warehouse, …) supplied per report — opened from the
         Filter button, shown in a right drawer like the legacy reports. Controls
         are forced full-width in the drawer regardless of their inline widths. -->
    <a-drawer
      v-if="$slots.filters"
      v-model:open="filterOpen"
      :title="$t('Filter')"
      placement="right"
      :width="340"
    >
      <div class="report-filters">
        <slot name="filters" />
      </div>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * Shell for report pages: header + export buttons + filter bar + table.
 * A report page supplies columns, filters and a bodyCell slot — nothing else.
 *
 * Export always covers the FULL result set, not just the page on screen: it
 * refetches with limit=-1 (the legacy "All" convention) so a 10-row view still
 * exports every matching row.
 */
import { ref } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { FileExcelOutlined, FilePdfOutlined, PrinterOutlined, FilterOutlined } from '@ant-design/icons-vue';
import PageHeader from './PageHeader.vue';
import DataTable from './DataTable.vue';
import { exportExcel, exportPdf, printRows, totalsRow, exportPrefs } from '../lib/exporters';
import { useFormat } from '../composables/useFormat';
import http from '../lib/http';

const props = defineProps({
  title: { type: String, required: true },
  breadcrumb: { type: Array, default: () => [] },
  crud: { type: Object, required: true },
  columns: { type: Array, required: true },
  rowKey: { type: String, default: 'id' },
  // Endpoint + params for the "export everything" refetch; defaults to the
  // rows already loaded when omitted.
  exportEndpoint: { type: String, default: '' },
  exportParams: { type: Function, default: null },
  exportRowsKey: { type: String, default: '' },
  // For nested payloads (mirrors useCrudTable's `select`), e.g.
  //   :export-select="p => p?.data?.rows || []"
  exportSelect: { type: Function, default: null },
});

const { t } = useI18n();
const { money, number } = useFormat();
const exporting = ref('');
const filterOpen = ref(false);

// Totals footer over the full exported set — same `sum` flags and formatting
// as DataTable's on-screen footer. Null when no column is flagged or the
// admin turned totals off (Settings → Export).
const footFor = rows => (exportPrefs().totals ? totalsRow(
  props.columns,
  rows,
  (col, total) => (col.sum === 'money' ? money(total) : number(total, Number.isInteger(total) ? 0 : 2)),
  t('Total'),
) : null);

async function allRows() {
  // Admin default (Settings → Export): 'page' exports only the rows on
  // screen, skipping the full refetch; 'all' (default) exports everything.
  if (exportPrefs().scope === 'page') return props.crud.rows.value;
  if (!props.exportEndpoint) return props.crud.rows.value;
  const data = await http.get(props.exportEndpoint, {
    page: 1,
    limit: -1, // legacy convention for "all rows"
    SortField: props.crud.sortField.value,
    SortType: props.crud.sortType.value,
    search: props.crud.search.value,
    ...(props.exportParams ? props.exportParams() : {}),
  });
  if (props.exportSelect) return props.exportSelect(data) || [];
  const key = props.exportRowsKey;
  if (key && Array.isArray(data?.[key])) return data[key];
  // Last resort. Ambiguous when a payload carries several arrays (warehouses,
  // customers…), so pages with those should pass exportRowsKey/exportSelect.
  return Object.values(data || {}).find(v => Array.isArray(v)) || [];
}

async function doExport(kind) {
  exporting.value = kind;
  try {
    const rows = await allRows();
    if (!rows.length) {
      message.warning(t('NodataAvailable'));
      return;
    }
    const foot = footFor(rows);
    if (kind === 'excel') await exportExcel(props.title, props.columns, rows, { foot });
    else await exportPdf(props.title, props.columns, rows, { foot });
  } catch (e) {
    console.error('[report-export]', e);
    message.error(t('InvalidData'));
  } finally {
    exporting.value = '';
  }
}

/**
 * Print the FULL result set (like export), reproducing the legacy reports'
 * printTableOnly. The window is opened synchronously on click — before the
 * row fetch — so pop-up blockers don't kill it, then filled once rows arrive.
 */
async function doPrint() {
  const win = window.open('', '_blank');
  if (!win) {
    message.warning('Please allow popups to print');
    return;
  }
  win.document.write('<p style="font-family:sans-serif;padding:16px;color:#666">…</p>');
  exporting.value = 'print';
  try {
    const rows = await allRows();
    if (!rows.length) {
      win.close();
      message.warning(t('NodataAvailable'));
      return;
    }
    printRows(props.title, props.columns, rows, { win, foot: footFor(rows) });
  } catch (e) {
    console.error('[report-print]', e);
    win.close();
    message.error(t('InvalidData'));
  } finally {
    exporting.value = '';
  }
}
</script>

<style scoped>
/* Filter drawer: stack the per-report filter controls vertically and force them
   full-width (they carry inline fixed widths meant for the old inline bar;
   !important beats the inline style). */
.report-filters {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.report-filters :deep(.ant-select),
.report-filters :deep(.ant-picker),
.report-filters :deep(.ant-input),
.report-filters :deep(.ant-input-number),
.report-filters :deep(.ant-input-affix-wrapper),
.report-filters :deep(.ant-input-group-wrapper) {
  width: 100% !important;
}
</style>
