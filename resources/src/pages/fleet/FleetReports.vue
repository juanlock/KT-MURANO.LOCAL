<template>
  <div class="page">
    <PageHeader :title="$t('Fleet Reports')" :breadcrumb="[$t('Fleet Management'), $t('Reports')]">
      <template #actions>
        <a-button :loading="exporting === 'excel'" @click="doExport('excel')">
          <template #icon><FileExcelOutlined /></template>
          {{ $t('Export_Excel') }}
        </a-button>
        <a-button :loading="exporting === 'pdf'" @click="doExport('pdf')">
          <template #icon><FilePdfOutlined /></template>
          {{ $t('Export_PDF') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-tabs v-model:activeKey="report" @change="onReportChange">
        <a-tab-pane v-for="r in REPORTS" :key="r.key" :tab="r.title" />
      </a-tabs>

      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="$t('Search_vehicle')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
        <template v-if="report !== 'drivers'">
          <a-select
            v-model:value="filters.warehouse_id" class="tb-item" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Warehouse')"
            :options="warehouseOptions" @change="crud.reload"
          />
          <a-select
            v-model:value="filters.type" class="tb-item" allow-clear
            :placeholder="$t('All_types')" :options="VEHICLE_TYPES" @change="crud.reload"
          />
          <a-select
            v-model:value="filters.status" class="tb-item" allow-clear
            :placeholder="$t('All_Status')" :options="VEHICLE_STATUSES" @change="crud.reload"
          />
        </template>
      </div>

      <!-- Totals for the CURRENT filters, not just the visible page -->
      <div v-if="totalTiles.length" class="totals">
        <div v-for="tile in totalTiles" :key="tile.label" class="total">
          <span class="total-label">{{ tile.label }}</span>
          <span class="total-value">{{ tile.value }}</span>
        </div>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'vehicle_name'">
          <a class="link" @click="$router.push(`/fleet/vehicles/${record.id}`)">{{ record.vehicle_name }}</a>
          <div class="plate">{{ record.plate_number }}</div>
        </template>
        <template v-else-if="column.key === 'type'">{{ labelOf(VEHICLE_TYPES, record.type) }}</template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="optionOf(VEHICLE_STATUSES, record.status).color">
            {{ labelOf(VEHICLE_STATUSES, record.status) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'fuel_type'">{{ labelOf(FUEL_TYPES, record.fuel_type) }}</template>
        <template v-else-if="column.money">{{ money(record[column.dataIndex] || 0) }}</template>
        <template v-else-if="column.distance">{{ number(record[column.dataIndex] || 0, 0) }}</template>
        <template v-else-if="column.decimal">
          {{ record[column.dataIndex] === null || record[column.dataIndex] === undefined
            ? '—' : number(record[column.dataIndex], 2) }}
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Fleet reports — four views over one shell, because they share every filter
 * and only differ in columns and endpoint. Switching tabs swaps the endpoint on
 * the SAME crud instance so the date range and search survive the switch.
 *
 * Totals come from the server for the whole filtered set, never summed from the
 * page on screen — a 10-row page would otherwise report a 10-row total.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { FileExcelOutlined, FilePdfOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { exportExcel, exportPdf } from '../../lib/exporters';
import { VEHICLE_TYPES, VEHICLE_STATUSES, FUEL_TYPES, labelOf, optionOf } from './fleetOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const { money, number } = useFormat();

const REPORTS = [
  { key: 'costs', title: t('Running_costs', 'Running costs'), endpoint: 'fleet/reports/costs', sort: 'total_cost' },
  { key: 'fuel', title: t('Fuel_And_Efficiency', 'Fuel & efficiency'), endpoint: 'fleet/reports/fuel', sort: 'fuel_cost' },
  { key: 'maintenance', title: t('Maintenance', 'Maintenance'), endpoint: 'fleet/reports/maintenance', sort: 'total_cost' },
  { key: 'drivers', title: t('Drivers', 'Drivers'), endpoint: 'fleet/reports/drivers', sort: 'distance' },
];

const report = ref('costs');
const current = computed(() => REPORTS.find(r => r.key === report.value) || REPORTS[0]);

const filters = reactive({ range: null, warehouse_id: undefined, type: undefined, status: undefined });

// The endpoint is a ref read at request time, so one crud instance serves all
// four tabs — swapping it keeps the filters, page size and search in place.
const endpoint = ref(REPORTS[0].endpoint);

const crud = useCrudTable(() => endpoint.value, {
  sortField: 'total_cost',
  select: p => ({ rows: p?.rows || [], total: p?.totalRows || 0 }),
  params: () => ({
    start_date: filters.range?.[0] || '',
    end_date: filters.range?.[1] || '',
    warehouse_id: filters.warehouse_id || '',
    type: filters.type || '',
    status: filters.status || '',
  }),
});

function onReportChange() {
  endpoint.value = current.value.endpoint;
  crud.sortField.value = current.value.sort;
  crud.sortType.value = 'desc';
  crud.reload();
}

// ---------------- columns per report ----------------

const COLUMNS = {
  costs: [
    { title: t('Vehicle', 'Vehicle'), key: 'vehicle_name', dataIndex: 'vehicle_name', sorter: true },
    { title: t('Type', 'Type'), key: 'type', dataIndex: 'type', width: 110 },
    { title: t('Status', 'Status'), key: 'status', dataIndex: 'status', width: 130 },
    { title: t('Branch', 'Branch'), dataIndex: 'warehouse_name', key: 'warehouse_name', width: 140 },
    { title: t('Fuel', 'Fuel'), dataIndex: 'fuel_cost', key: 'fuel_cost', sorter: true, money: true, width: 120 },
    { title: t('Maintenance', 'Maintenance'), dataIndex: 'maintenance_cost', key: 'maintenance_cost', sorter: true, money: true, width: 140 },
    { title: t('Total', 'Total'), dataIndex: 'total_cost', key: 'total_cost', sorter: true, money: true, width: 130 },
    { title: t('Distance', 'Distance'), dataIndex: 'distance', key: 'distance', sorter: true, distance: true, width: 110 },
    { title: t('Cost_per_distance', 'Cost / distance'), dataIndex: 'cost_per_distance', key: 'cost_per_distance', sorter: true, decimal: true, width: 140 },
  ],
  fuel: [
    { title: t('Vehicle', 'Vehicle'), key: 'vehicle_name', dataIndex: 'vehicle_name', sorter: true },
    { title: t('Fuel_type', 'Fuel type'), key: 'fuel_type', dataIndex: 'fuel_type', width: 120 },
    { title: t('Fill_ups', 'Fill-ups'), dataIndex: 'fill_ups', key: 'fill_ups', sorter: true, width: 100 },
    { title: t('Quantity', 'Quantity'), dataIndex: 'quantity', key: 'quantity', sorter: true, decimal: true, width: 120 },
    { title: t('Spend', 'Spend'), dataIndex: 'fuel_cost', key: 'fuel_cost', sorter: true, money: true, width: 130 },
    { title: t('Avg_unit_price', 'Avg unit price'), dataIndex: 'avg_unit_price', key: 'avg_unit_price', sorter: true, money: true, width: 140 },
    { title: t('Distance', 'Distance'), dataIndex: 'distance', key: 'distance', sorter: true, distance: true, width: 110 },
    { title: t('Efficiency', 'Efficiency'), dataIndex: 'efficiency', key: 'efficiency', sorter: true, decimal: true, width: 120 },
    { title: t('Cost_per_distance', 'Cost / distance'), dataIndex: 'cost_per_distance', key: 'cost_per_distance', sorter: true, decimal: true, width: 140 },
  ],
  maintenance: [
    { title: t('Vehicle', 'Vehicle'), key: 'vehicle_name', dataIndex: 'vehicle_name', sorter: true },
    { title: t('Entries', 'Entries'), dataIndex: 'entries', key: 'entries', sorter: true, width: 100 },
    { title: t('Service', 'Service'), dataIndex: 'service_cost', key: 'service_cost', sorter: true, money: true, width: 130 },
    { title: t('Repairs', 'Repairs'), dataIndex: 'repair_cost', key: 'repair_cost', sorter: true, money: true, width: 130 },
    { title: t('Tyres', 'Tyres'), dataIndex: 'tyres_cost', key: 'tyres_cost', sorter: true, money: true, width: 130 },
    { title: t('Other', 'Other'), dataIndex: 'other_cost', key: 'other_cost', sorter: true, money: true, width: 130 },
    { title: t('Total', 'Total'), dataIndex: 'total_cost', key: 'total_cost', sorter: true, money: true, width: 140 },
  ],
  drivers: [
    { title: t('Driver', 'Driver'), dataIndex: 'driver_name', key: 'driver_name', sorter: true },
    { title: t('Trips', 'Trips'), dataIndex: 'trips', key: 'trips', sorter: true, width: 100 },
    { title: t('Open_trips', 'Open trips'), dataIndex: 'open_trips', key: 'open_trips', sorter: true, width: 120 },
    { title: t('Distance', 'Distance'), dataIndex: 'distance', key: 'distance', sorter: true, distance: true, width: 130 },
    { title: t('Fuel_quantity', 'Fuel quantity'), dataIndex: 'fuel_quantity', key: 'fuel_quantity', sorter: true, decimal: true, width: 140 },
    { title: t('Fuel_spend', 'Fuel spend'), dataIndex: 'fuel_cost', key: 'fuel_cost', sorter: true, money: true, width: 140 },
  ],
};

const columns = computed(() => COLUMNS[report.value] || COLUMNS.costs);

// ---------------- totals ----------------

const TOTAL_LABELS = {
  fuel_cost: t('Fuel_spend', 'Fuel spend'),
  maintenance_cost: t('Maintenance', 'Maintenance'),
  total_cost: t('Total_cost', 'Total cost'),
  distance: t('Distance', 'Distance'),
  quantity: t('Fuel_quantity', 'Fuel quantity'),
  service_cost: t('Service', 'Service'),
  repair_cost: t('Repairs', 'Repairs'),
  tyres_cost: t('Tyres', 'Tyres'),
  other_cost: t('Other', 'Other'),
  trips: t('Trips', 'Trips'),
};
const MONEY_TOTALS = ['fuel_cost', 'maintenance_cost', 'total_cost', 'service_cost', 'repair_cost', 'tyres_cost', 'other_cost'];

const totalTiles = computed(() => {
  const totals = crud.payload.value?.totals || {};
  return Object.entries(totals).map(([key, value]) => ({
    label: TOTAL_LABELS[key] || key,
    value: MONEY_TOTALS.includes(key) ? money(value || 0) : number(value || 0, 0),
  }));
});

// ---------------- export ----------------

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
      start_date: filters.range?.[0] || '',
      end_date: filters.range?.[1] || '',
      warehouse_id: filters.warehouse_id || '',
      type: filters.type || '',
      status: filters.status || '',
    });
    const rows = data?.rows || [];
    if (!rows.length) {
      message.warning(t('NodataAvailable', 'Nothing to export'));
      return;
    }
    const title = `${t('Fleet', 'Fleet')} — ${current.value.title}`;
    if (kind === 'excel') await exportExcel(title, columns.value, rows);
    else await exportPdf(title, columns.value, rows);
  } catch (e) {
    message.error(t('InvalidData', 'Could not export this report'));
  } finally {
    exporting.value = '';
  }
}

// ---------------- filters data ----------------

const warehouses = ref([]);
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));

onMounted(async () => {
  crud.fetchRows();
  try {
    const data = await http.get('fleet/meta');
    warehouses.value = data?.warehouses || [];
  } catch (e) { /* filter stays empty */ }
});
</script>

<style scoped>
.link {
  color: #6d28d9;
  cursor: pointer;
}
.plate {
  font-size: 11px;
  opacity: 0.55;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 220px;
  min-width: 180px;
}
.tb-item {
  width: 160px;
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
@media (max-width: 767px) {
  .tb-item,
  .tb-range {
    width: 100%;
  }
}
</style>
