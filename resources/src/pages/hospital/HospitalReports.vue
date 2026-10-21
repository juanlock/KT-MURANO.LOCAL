<template>
  <div class="page">
    <PageHeader :title="s('Hospital reports')" :breadcrumb="[s('Hospital'), s('Reports')]">
      <template #actions>
        <a-button :loading="exporting === 'excel'" @click="doExport('excel')">
          <template #icon><FileExcelOutlined /></template>
          {{ s('Export') }} Excel
        </a-button>
        <a-button :loading="exporting === 'pdf'" @click="doExport('pdf')">
          <template #icon><FilePdfOutlined /></template>
          {{ s('Export') }} PDF
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-tabs v-model:activeKey="report" @change="onReportChange">
        <a-tab-pane v-for="r in REPORTS" :key="r.key" :tab="r.title" />
      </a-tabs>

      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="current.searchHint"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <PresetRangeFilter v-model:value="range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
        <a-select
          v-if="current.departmentFilter"
          v-model:value="departmentId" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="s('All departments')"
          :options="departmentOptions" @change="crud.reload"
        />
      </div>

      <div v-if="totalTiles.length" class="totals">
        <div v-for="tile in totalTiles" :key="tile.label" class="total">
          <span class="total-label">{{ tile.label }}</span>
          <span class="total-value">{{ tile.value }}</span>
        </div>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.money">{{ money(record[column.dataIndex] || 0) }}</template>
        <template v-else-if="column.percent">
          <span v-if="record[column.dataIndex] === null || record[column.dataIndex] === undefined" class="muted">—</span>
          <a-tag v-else :color="percentColor(column.dataIndex, record[column.dataIndex])">
            {{ record[column.dataIndex] }}%
          </a-tag>
        </template>
        <template v-else-if="column.decimal">
          {{ record[column.dataIndex] === null || record[column.dataIndex] === undefined
            ? '—' : number(record[column.dataIndex], 1) }}
        </template>
        <template v-else-if="column.key === 'last_visit'">
          {{ record.last_visit ? date(record.last_visit) : '—' }}
        </template>
        <template v-else-if="column.key === 'patient_name'">
          <button type="button" class="link-cell" @click="$router.push(`/hospital/patients/${record.id}`)">
            <div class="cell-name">{{ record.patient_name }}</div>
            <div class="cell-mrn">{{ record.mrn }}</div>
          </button>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
/**
 * Hospital reports — five views over one shell, because they share every filter
 * and differ only in columns and endpoint. Switching tabs swaps the endpoint on
 * the SAME crud instance so the date range and search survive the switch.
 *
 * Totals come from the server for the whole filtered set, never summed from the
 * page on screen — a 10-row page would otherwise report a 10-row total.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { FileExcelOutlined, FilePdfOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { exportExcel, exportPdf } from '../../lib/exporters';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/HospitalReports.es.js';

const { s } = useHospitalText(ES);

const { money, number, date } = useFormat();

const REPORTS = computed(() => [
  {
    key: 'doctors', title: s('Doctor workload'), endpoint: 'hospital/reports/doctors',
    sort: 'visits', searchHint: s('Search doctor…'), departmentFilter: true,
  },
  {
    key: 'revenue', title: s('Revenue by department'), endpoint: 'hospital/reports/revenue',
    sort: 'billed', searchHint: s('Search department…'), departmentFilter: true,
  },
  {
    key: 'occupancy', title: s('Ward occupancy'), endpoint: 'hospital/reports/occupancy',
    sort: 'occupancy_rate', searchHint: s('Search ward…'), departmentFilter: true,
  },
  {
    key: 'patients', title: s('Patient activity'), endpoint: 'hospital/reports/patients',
    sort: 'visits', searchHint: s('Search patient or MRN…'), departmentFilter: false,
  },
  {
    key: 'collections', title: s('Collections'), endpoint: 'hospital/reports/collections',
    sort: 'amount', searchHint: s('Search…'), departmentFilter: false,
  },
]);

const report = ref('doctors');
const current = computed(() => REPORTS.value.find(r => r.key === report.value) || REPORTS.value[0]);

const range = ref(null);
const departmentId = ref(undefined);
const endpoint = ref(REPORTS.value[0].endpoint);

const crud = useCrudTable(() => endpoint.value, {
  sortField: 'visits',
  select: p => ({ rows: p?.rows || [], total: p?.totalRows || 0 }),
  params: () => ({
    start_date: range.value?.[0] || '',
    end_date: range.value?.[1] || '',
    department_id: departmentId.value || '',
  }),
});

function onReportChange() {
  endpoint.value = current.value.endpoint;
  crud.sortField.value = current.value.sort;
  crud.sortType.value = 'desc';
  crud.reload();
}

const COLUMNS = computed(() => ({
  doctors: [
    { title: s('Doctor'), dataIndex: 'doctor_name', key: 'doctor_name', sorter: true },
    { title: s('Specialty'), dataIndex: 'specialty', key: 'specialty', width: 150 },
    { title: s('Department'), dataIndex: 'department_name', key: 'department_name', width: 150 },
    { title: s('Appointments'), dataIndex: 'appointments', key: 'appointments', sorter: true, width: 140 },
    { title: s('No-shows'), dataIndex: 'no_shows', key: 'no_shows', sorter: true, width: 120 },
    { title: s('No-show rate'), dataIndex: 'no_show_rate', key: 'no_show_rate', sorter: true, percent: true, width: 140 },
    { title: s('Consultations'), dataIndex: 'visits', key: 'visits', sorter: true, width: 140 },
    { title: s('Patients'), dataIndex: 'patients', key: 'patients', sorter: true, width: 110 },
    { title: s('Fees'), dataIndex: 'consultation_revenue', key: 'consultation_revenue', sorter: true, money: true, width: 130 },
  ],
  revenue: [
    { title: s('Department'), dataIndex: 'department_name', key: 'department_name', sorter: true },
    { title: s('Consultations'), dataIndex: 'visits', key: 'visits', sorter: true, width: 140 },
    { title: s('Consultation fees'), dataIndex: 'consultation_fees', key: 'consultation_fees', sorter: true, money: true, width: 160 },
    { title: s('Invoices'), dataIndex: 'invoices', key: 'invoices', sorter: true, width: 110 },
    { title: s('Billed'), dataIndex: 'billed', key: 'billed', sorter: true, money: true, width: 140 },
    { title: s('Collected'), dataIndex: 'collected', key: 'collected', sorter: true, money: true, width: 140 },
    { title: s('Outstanding'), dataIndex: 'outstanding', key: 'outstanding', sorter: true, money: true, width: 140 },
    { title: s('Collection rate'), dataIndex: 'collection_rate', key: 'collection_rate', sorter: true, percent: true, width: 150 },
  ],
  occupancy: [
    { title: s('Ward'), dataIndex: 'ward_name', key: 'ward_name', sorter: true },
    { title: s('Type'), dataIndex: 'type', key: 'type', width: 130 },
    { title: s('Department'), dataIndex: 'department_name', key: 'department_name', width: 150 },
    { title: s('Beds'), dataIndex: 'beds', key: 'beds', sorter: true, width: 100 },
    { title: s('Occupied'), dataIndex: 'occupied', key: 'occupied', sorter: true, width: 110 },
    { title: s('Occupancy'), dataIndex: 'occupancy_rate', key: 'occupancy_rate', sorter: true, percent: true, width: 130 },
    { title: s('Admissions'), dataIndex: 'admissions', key: 'admissions', sorter: true, width: 130 },
    { title: s('Discharges'), dataIndex: 'discharges', key: 'discharges', sorter: true, width: 130 },
    { title: s('Avg stay'), dataIndex: 'avg_stay', key: 'avg_stay', sorter: true, decimal: true, width: 110 },
    { title: s('Bed revenue'), dataIndex: 'bed_revenue', key: 'bed_revenue', sorter: true, money: true, width: 140 },
  ],
  patients: [
    { title: s('Patient'), dataIndex: 'patient_name', key: 'patient_name', sorter: true },
    { title: s('Gender'), dataIndex: 'gender', key: 'gender', width: 110 },
    { title: s('Age'), dataIndex: 'age', key: 'age', sorter: true, width: 90 },
    { title: s('Consultations'), dataIndex: 'visits', key: 'visits', sorter: true, width: 140 },
    { title: s('Admissions'), dataIndex: 'admissions', key: 'admissions', sorter: true, width: 130 },
    { title: s('Lab orders'), dataIndex: 'lab_orders', key: 'lab_orders', sorter: true, width: 130 },
    { title: s('Billed'), dataIndex: 'billed', key: 'billed', sorter: true, money: true, width: 130 },
    { title: s('Outstanding'), dataIndex: 'outstanding', key: 'outstanding', sorter: true, money: true, width: 140 },
    { title: s('Last visit'), dataIndex: 'last_visit', key: 'last_visit', width: 140 },
  ],
  collections: [
    { title: s('Method'), dataIndex: 'method', key: 'method', sorter: true },
    { title: s('Payments'), dataIndex: 'payments', key: 'payments', sorter: true, width: 130 },
    { title: s('Amount'), dataIndex: 'amount', key: 'amount', sorter: true, money: true, width: 160 },
    { title: s('Share'), dataIndex: 'share', key: 'share', sorter: true, percent: true, width: 120 },
  ],
}));

const columns = computed(() => COLUMNS.value[report.value] || COLUMNS.value.doctors);

/** Higher is better for most rates, worse for no-shows and occupancy pressure. */
function percentColor(field, value) {
  if (field === 'no_show_rate') return value >= 20 ? 'error' : value >= 10 ? 'warning' : 'success';
  if (field === 'occupancy_rate') return value >= 90 ? 'error' : value >= 70 ? 'warning' : 'success';
  if (field === 'collection_rate') return value >= 90 ? 'success' : value >= 60 ? 'warning' : 'error';
  return 'default';
}

const TOTAL_LABELS = {
  appointments: 'Appointments',
  visits: 'Consultations',
  consultation_revenue: 'Consultation fees',
  billed: 'Billed',
  collected: 'Collected',
  outstanding: 'Outstanding',
  beds: 'Beds',
  occupied: 'Occupied',
  admissions: 'Admissions',
  bed_revenue: 'Bed revenue',
  payments: 'Payments',
  amount: 'Collected',
};
const MONEY_TOTALS = ['consultation_revenue', 'billed', 'collected', 'outstanding', 'bed_revenue', 'amount'];

const totalTiles = computed(() => {
  const totals = crud.payload.value?.totals || {};
  return Object.entries(totals).map(([key, value]) => ({
    label: TOTAL_LABELS[key] ? s(TOTAL_LABELS[key]) : key,
    value: MONEY_TOTALS.includes(key) ? money(value || 0) : number(value || 0, 0),
  }));
});

const departments = ref([]);
const departmentOptions = computed(() => departments.value.map(d => ({ value: d.id, label: d.name })));

const exporting = ref('');

async function doExport(kind) {
  exporting.value = kind;
  try {
    // limit=-1 is the legacy "all rows" convention, so the export covers the
    // whole filtered set rather than the visible page.
    const data = await http.get(endpoint.value, {
      page: 1,
      limit: -1,
      SortField: crud.sortField.value,
      SortType: crud.sortType.value,
      search: crud.search.value,
      start_date: range.value?.[0] || '',
      end_date: range.value?.[1] || '',
      department_id: departmentId.value || '',
    });
    const rows = data?.rows || [];
    if (!rows.length) {
      message.warning(s('Nothing to export'));
      return;
    }
    const title = `${s('Hospital')} — ${current.value.title}`;
    if (kind === 'excel') await exportExcel(title, columns.value, rows);
    else await exportPdf(title, columns.value, rows);
  } catch (e) {
    message.error(s('Could not export this report'));
  } finally {
    exporting.value = '';
  }
}

onMounted(async () => {
  crud.fetchRows();
  try {
    const meta = await http.get('hospital/meta');
    departments.value = meta?.departments || [];
  } catch (e) { /* filter stays empty */ }
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.tb-search {
  flex: 1 1 220px;
  min-width: 180px;
}
.tb-item {
  width: 180px;
}
.tb-range {
  width: 240px;
}
.totals {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid rgba(128, 128, 128, 0.18);
}
.total {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.total-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  opacity: 0.55;
}
.total-value {
  font-size: 17px;
  font-weight: 600;
}
.link-cell {
  border: 0;
  background: none;
  padding: 0;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
}
.cell-name {
  font-weight: 500;
}
.link-cell:hover .cell-name {
  color: #6d28d9;
}
.cell-mrn {
  font-size: 11.5px;
  opacity: 0.55;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
</style>
