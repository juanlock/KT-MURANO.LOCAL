<template>
  <DataTable :crud="crud" :columns="columns" :row-key="rowKey">
    <template #toolbar>
      <a-button size="small" @click="print">
        <template #icon><PrinterOutlined /></template>
        {{ $t('print') }}
      </a-button>
      <a-button size="small" @click="pdf">
        <template #icon><FilePdfOutlined /></template>
        PDF
      </a-button>
      <a-button size="small" @click="excel">
        <template #icon><FileExcelOutlined /></template>
        EXCEL
      </a-button>
    </template>

    <template #bodyCell="slotProps">
      <slot name="bodyCell" v-bind="slotProps" />
    </template>

    <!-- Page-scoped totals, like legacy's bottom group-header row. -->
    <template v-if="sums.length" #summary>
      <SummaryRow>
        <SummaryCell v-for="(col, i) in columns" :key="col.key || i" :index="i">
          <strong v-if="sumFor(col)">{{ sumFor(col) }}</strong>
        </SummaryCell>
      </SummaryRow>
    </template>
  </DataTable>
</template>

<script setup>
/**
 * One tab of a drill-down report: a server-paginated table over a
 * `report/*` endpoint plus the print / PDF / Excel actions the legacy pages
 * carried, and an optional totals row.
 *
 * The legacy pages built this per tab by hand (seven near-identical copies in
 * detail_user_report alone) and wrapped rows in a fake single-group
 * `[{children: rows}]` purely to get vue-good-table to render a footer; here
 * the totals are an a-table summary row instead.
 *
 * `sums` lists the columns to total, e.g.
 *   :sums="[{ key: 'GrandTotal', money: true }, { key: 'items' }]"
 * Totals cover the CURRENT PAGE only — same as legacy, whose headerField
 * summed the loaded children.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { PrinterOutlined, FilePdfOutlined, FileExcelOutlined } from '@ant-design/icons-vue';
import { Table } from 'ant-design-vue';
import DataTable from './DataTable.vue';
import { useCrudTable } from '../composables/useCrudTable';
import { useFormat } from '../composables/useFormat';
import { exportExcel, exportPdf, printRows } from '../lib/exporters';

// Imported rather than relying on the global registration for these two
// nested sub-components.
const SummaryRow = Table.Summary.Row;
const SummaryCell = Table.Summary.Cell;

const props = defineProps({
  endpoint: { type: String, required: true },
  rowsKey: { type: String, required: true },
  columns: { type: Array, required: true },
  rowKey: { type: String, default: 'id' },
  // Extra query params merged into every request (typically the :id).
  extraParams: { type: Object, default: () => ({}) },
  sums: { type: Array, default: () => [] },
  // Heading used by print and PDF.
  title: { type: String, required: true },
});

const { t } = useI18n();
const { money } = useFormat();

// Legacy sent only page/limit/search/id — no SortField/SortType, and these
// report endpoints have no sort handling, so sorting stays off.
const crud = useCrudTable(props.endpoint, {
  rowsKey: props.rowsKey,
  params: () => ({ ...props.extraParams }),
});

const sums = computed(() => props.sums);

function sumFor(col) {
  const spec = props.sums.find(s => s.key === (col.dataIndex || col.key));
  if (!spec) return '';
  const total = crud.rows.value.reduce((acc, r) => acc + (Number(r[spec.key]) || 0), 0);
  return spec.money ? money(total) : total.toLocaleString();
}

function print() {
  printRows(props.title, props.columns, crud.rows.value);
}
function pdf() {
  exportPdf(props.title, props.columns, crud.rows.value);
}
function excel() {
  exportExcel(props.title.replace(/[^\w]+/g, '_'), props.columns, crud.rows.value);
}

crud.fetchRows();
defineExpose({ crud });
</script>
