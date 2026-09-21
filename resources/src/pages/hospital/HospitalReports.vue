<template>
  <div class="page">
    <PageHeader :title="$t('Hospital Reports')" :breadcrumb="[$t('Hospital'), $t('Reports')]">
      <template #actions>
        <a-button :loading="exporting === 'excel'" @click="doExport('excel')">
          <template #icon><FileExcelOutlined /></template>
          {{ $t('Export') }} {{ $t('EXCEL') }}
        </a-button>
        <a-button :loading="exporting === 'pdf'" @click="doExport('pdf')">
          <template #icon><FilePdfOutlined /></template>
          {{ $t('Export') }} {{ $t('PDF') }}
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
        <a-range-picker v-model:value="range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
        <a-select
          v-if="current.departmentFilter"
          v-model:value="departmentId" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('All_Departments')"
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
import { t } from '../../i18n';

const { money, number, date } = useFormat();

const REPORTS = [
  {
    key: 'doctors', title: t('Doctor_Workload', 'Doctor workload'), endpoint: 'hospital/reports/doctors',
    sort: 'visits', searchHint: t('Search_Doctor', 'Search doctor…'), departmentFilter: true,
  },
  {
    key: 'revenue', title: t('Revenue_By_Department', 'Revenue by department'), endpoint: 'hospital/reports/revenue',
    sort: 'billed', searchHint: t('Search_Department', 'Search department…'), departmentFilter: true,
  },
  {
    key: 'occupancy', title: t('Ward_Occupancy', 'Ward occupancy'), endpoint: 'hospital/reports/occupancy',
    sort: 'occupancy_rate', searchHint: t('Search_Ward', 'Search ward…'), departmentFilter: true,
  },
  {
    key: 'patients', title: t('Patient_Activity', 'Patient activity'), endpoint: 'hospital/reports/patients',
    sort: 'visits', searchHint: t('Search_Patient_Or_MRN', 'Search patient or MRN…'), departmentFilter: false,
  },
  {
    key: 'collections', title: t('Collections', 'Collections'), endpoint: 'hospital/reports/collections',
    sort: 'amount', searchHint: t('Search', 'Search…'), departmentFilter: false,
  },
];

const report = ref('doctors');
const current = computed(() => REPORTS.find(r => r.key === report.value) || REPORTS[0]);

const range = ref(null);
const departmentId = ref(undefined);
const endpoint = ref(REPORTS[0].endpoint);

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

const COLUMNS = {
  doctors: [
    { title: t('Doctor', 'Doctor'), dataIndex: 'doctor_name', key: 'doctor_name', sorter: true },
    { title: t('Specialty', 'Specialty'), dataIndex: 'specialty', key: 'specialty', width: 150 },
    { title: t('Department', 'Department'), dataIndex: 'department_name', key: 'department_name', width: 150 },
    { title: t('Appointments', 'Appointments'), dataIndex: 'appointments', key: 'appointments', sorter: true, width: 140 },
    { title: t('No_Shows', 'No-shows'), dataIndex: 'no_shows', key: 'no_shows', sorter: true, width: 120 },
    { title: t('No_Show_Rate', 'No-show rate'), dataIndex: 'no_show_rate', key: 'no_show_rate', sorter: true, percent: true, width: 140 },
    { title: t('Consultations', 'Consultations'), dataIndex: 'visits', key: 'visits', sorter: true, width: 140 },
    { title: t('Patients', 'Patients'), dataIndex: 'patients', key: 'patients', sorter: true, width: 110 },
    { title: t('Fees', 'Fees'), dataIndex: 'consultation_revenue', key: 'consultation_revenue', sorter: true, money: true, width: 130 },
  ],
  revenue: [
    { title: t('Department', 'Department'), dataIndex: 'department_name', key: 'department_name', sorter: true },
    { title: t('Consultations', 'Consultations'), dataIndex: 'visits', key: 'visits', sorter: true, width: 140 },
    { title: t('Consultation_Fees', 'Consultation fees'), dataIndex: 'consultation_fees', key: 'consultation_fees', sorter: true, money: true, width: 160 },
    { title: t('Invoices', 'Invoices'), dataIndex: 'invoices', key: 'invoices', sorter: true, width: 110 },
    { title: t('Billed', 'Billed'), dataIndex: 'billed', key: 'billed', sorter: true, money: true, width: 140 },
    { title: t('Collected', 'Collected'), dataIndex: 'collected', key: 'collected', sorter: true, money: true, width: 140 },
    { title: t('Outstanding', 'Outstanding'), dataIndex: 'outstanding', key: 'outstanding', sorter: true, money: true, width: 140 },
    { title: t('Collection_Rate', 'Collection rate'), dataIndex: 'collection_rate', key: 'collection_rate', sorter: true, percent: true, width: 150 },
  ],
  occupancy: [
    { title: t('Ward', 'Ward'), dataIndex: 'ward_name', key: 'ward_name', sorter: true },
    { title: t('Type', 'Type'), dataIndex: 'type', key: 'type', width: 130 },
    { title: t('Department', 'Department'), dataIndex: 'department_name', key: 'department_name', width: 150 },
    { title: t('Beds', 'Beds'), dataIndex: 'beds', key: 'beds', sorter: true, width: 100 },
    { title: t('Occupied', 'Occupied'), dataIndex: 'occupied', key: 'occupied', sorter: true, width: 110 },
    { title: t('Occupancy', 'Occupancy'), dataIndex: 'occupancy_rate', key: 'occupancy_rate', sorter: true, percent: true, width: 130 },
    { title: t('Admissions', 'Admissions'), dataIndex: 'admissions', key: 'admissions', sorter: true, width: 130 },
    { title: t('Discharges', 'Discharges'), dataIndex: 'discharges', key: 'discharges', sorter: true, width: 130 },
    { title: t('Avg_Stay', 'Avg stay'), dataIndex: 'avg_stay', key: 'avg_stay', sorter: true, decimal: true, width: 110 },
    { title: t('Bed_Revenue', 'Bed revenue'), dataIndex: 'bed_revenue', key: 'bed_revenue', sorter: true, money: true, width: 140 },
  ],
  patients: [
    { title: t('Patient', 'Patient'), dataIndex: 'patient_name', key: 'patient_name', sorter: true },
    { title: t('Gender', 'Gender'), dataIndex: 'gender', key: 'gender', width: 110 },
    { title: t('Age', 'Age'), dataIndex: 'age', key: 'age', sorter: true, width: 90 },
    { title: t('Consultations', 'Consultations'), dataIndex: 'visits', key: 'visits', sorter: true, width: 140 },
    { title: t('Admissions', 'Admissions'), dataIndex: 'admissions', key: 'admissions', sorter: true, width: 130 },
    { title: t('Lab Orders', 'Lab orders'), dataIndex: 'lab_orders', key: 'lab_orders', sorter: true, width: 130 },
    { title: t('Billed', 'Billed'), dataIndex: 'billed', key: 'billed', sorter: true, money: true, width: 130 },
    { title: t('Outstanding', 'Outstanding'), dataIndex: 'outstanding', key: 'outstanding', sorter: true, money: true, width: 140 },
    { title: t('Last_Visit', 'Last visit'), dataIndex: 'last_visit', key: 'last_visit', width: 140 },
  ],
  collections: [
    { title: t('Method', 'Method'), dataIndex: 'method', key: 'method', sorter: true },
    { title: t('Payments', 'Payments'), dataIndex: 'payments', key: 'payments', sorter: true, width: 130 },
    { title: t('Amount', 'Amount'), dataIndex: 'amount', key: 'amount', sorter: true, money: true, width: 160 },
    { title: t('Share', 'Share'), dataIndex: 'share', key: 'share', sorter: true, percent: true, width: 120 },
  ],
};

const columns = computed(() => COLUMNS[report.value] || COLUMNS.doctors);

/** Higher is better for most rates, worse for no-shows and occupancy pressure. */
function percentColor(field, value) {
  if (field === 'no_show_rate') return value >= 20 ? 'error' : value >= 10 ? 'warning' : 'success';
  if (field === 'occupancy_rate') return value >= 90 ? 'error' : value >= 70 ? 'warning' : 'success';
  if (field === 'collection_rate') return value >= 90 ? 'success' : value >= 60 ? 'warning' : 'error';
  return 'default';
}

const TOTAL_LABELS = {
  appointments: t('Appointments', 'Appointments'),
  visits: t('Consultations', 'Consultations'),
  consultation_revenue: t('Consultation_Fees', 'Consultation fees'),
  billed: t('Billed', 'Billed'),
  collected: t('Collected', 'Collected'),
  outstanding: t('Outstanding', 'Outstanding'),
  beds: t('Beds', 'Beds'),
  occupied: t('Occupied', 'Occupied'),
  admissions: t('Admissions', 'Admissions'),
  bed_revenue: t('Bed_Revenue', 'Bed revenue'),
  payments: t('Payments', 'Payments'),
  amount: t('Collected', 'Collected'),
};
const MONEY_TOTALS = ['consultation_revenue', 'billed', 'collected', 'outstanding', 'bed_revenue', 'amount'];

const totalTiles = computed(() => {
  const totals = crud.payload.value?.totals || {};
  return Object.entries(totals).map(([key, value]) => ({
    label: TOTAL_LABELS[key] || key,
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
      message.warning(t('NodataAvailable', 'Nothing to export'));
      return;
    }
    const title = `${t('Hospital', 'Hospital')} — ${current.value.title}`;
    if (kind === 'excel') await exportExcel(title, columns.value, rows);
    else await exportPdf(title, columns.value, rows);
  } catch (e) {
    message.error(t('InvalidData', 'Could not export this report'));
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
