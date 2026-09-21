<template>
  <div class="page">
    <PageHeader
      :title="$t('Manufacturing_Reports')"
      :subtitle="$t('What_it_cost_how_the_floor_performed_where_material_went_and_how_quality_held')"
      :breadcrumb="[$t('Manufacturing'), $t('Reports')]"
    >
      <template #actions>
        <a-button :loading="exporting" @click="download('excel')">
          <template #icon><FileExcelOutlined /></template>
          {{ $t('EXCEL') }}
        </a-button>
        <a-button :loading="exporting" @click="download('pdf')">
          <template #icon><FilePdfOutlined /></template>
          {{ $t('PDF') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-segmented v-model:value="report" :options="reportOptions" @change="load" />
        <a-range-picker v-model:value="range" class="tb-range" value-format="YYYY-MM-DD" @change="load" />
        <a-select
          v-if="report === 'cost' || report === 'material'"
          v-model:value="warehouseId" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('All_Warehouses')"
          :options="warehouseOptions" @change="load"
        />
      </div>
    </a-card>

    <a-spin :spinning="loading">
      <!-- ------------------------------------------------- cost -->
      <template v-if="report === 'cost'">
        <div class="kpis">
          <div class="kpi">
            <span class="kpi-label">{{ $t('Orders_Completed') }}</span>
            <span class="kpi-value">{{ totals.orders || 0 }}</span>
          </div>
          <div class="kpi">
            <span class="kpi-label">{{ $t('Units_Produced') }}</span>
            <span class="kpi-value">{{ number(totals.produced || 0, 0) }}</span>
            <span class="kpi-sub">{{ number(totals.scrapped || 0, 0) }} {{ $t('scrapped') }}</span>
          </div>
          <div class="kpi kpi--accent">
            <span class="kpi-label">{{ $t('Total_Cost') }}</span>
            <span class="kpi-value">{{ money(totals.total || 0) }}</span>
            <span class="kpi-sub">
              {{ money(totals.material || 0) }} {{ $t('material') }} · {{ money(totals.labour || 0) }} {{ $t('labour') }}
            </span>
          </div>
          <div class="kpi">
            <span class="kpi-label">{{ $t('Against_Plan') }}</span>
            <span class="kpi-value" :class="(totals.variance || 0) > 0 ? 'kpi-value--bad' : 'kpi-value--good'">
              {{ money(totals.variance || 0) }}
            </span>
            <span class="kpi-sub">{{ $t('positive_over_budget') }}</span>
          </div>
        </div>
        <a-card size="small">
          <a-table
            size="small" :columns="costColumns" :data-source="rows" row-key="id"
            :pagination="{ pageSize: 25, showSizeChanger: true }" :scroll="{ x: 1300 }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'reference'">
                <a class="link" @click="$router.push(`/mrp/production-orders/${record.id}`)">
                  {{ record.reference }}
                </a>
                <div class="sub">{{ record.product_name }}</div>
              </template>
              <template v-else-if="moneyKeys.includes(column.key)">{{ money(record[column.key]) }}</template>
              <template v-else-if="column.key === 'yield_pct'">
                <a-tag v-if="record.yield_pct !== null" :color="yieldColor(record.yield_pct)">
                  {{ record.yield_pct }}%
                </a-tag>
                <span v-else class="muted">—</span>
              </template>
              <template v-else-if="column.key === 'cost_variance_pct'">
                <a-tag v-if="record.cost_variance_pct !== null" :color="varianceColor(record.cost_variance_pct)">
                  {{ signedPct(record.cost_variance_pct) }}
                </a-tag>
                <span v-else class="muted">—</span>
              </template>
            </template>
          </a-table>
        </a-card>
      </template>

      <!-- ------------------------------------------------- efficiency -->
      <template v-else-if="report === 'efficiency'">
        <div class="kpis">
          <div class="kpi">
            <span class="kpi-label">{{ $t('Operations') }}</span>
            <span class="kpi-value">{{ totals.operations || 0 }}</span>
          </div>
          <div class="kpi">
            <span class="kpi-label">{{ $t('Hours_Booked') }}</span>
            <span class="kpi-value">{{ number(totals.actual_hours || 0, 1) }}</span>
            <span class="kpi-sub">{{ $t('of') }} {{ number(totals.planned_hours || 0, 1) }} {{ $t('allowed') }}</span>
          </div>
          <div class="kpi kpi--accent">
            <span class="kpi-label">{{ $t('Labour_Cost') }}</span>
            <span class="kpi-value">{{ money(totals.labour_cost || 0) }}</span>
          </div>
        </div>
        <a-row :gutter="16">
          <a-col :xs="24" :xl="14">
            <a-card size="small">
              <a-table
                size="small" :columns="efficiencyColumns" :data-source="rows" row-key="id"
                :pagination="{ pageSize: 25 }" :scroll="{ x: 900 }"
              >
                <template #bodyCell="{ column, record }">
                  <template v-if="column.key === 'efficiency_pct'">
                    <a-tag v-if="record.efficiency_pct !== null" :color="efficiencyColor(record.efficiency_pct)">
                      {{ record.efficiency_pct }}%
                    </a-tag>
                    <span v-else class="muted">—</span>
                  </template>
                  <template v-else-if="column.key === 'reject_rate'">
                    <a-tag v-if="record.reject_rate !== null" :color="record.reject_rate > 5 ? 'error' : 'default'">
                      {{ record.reject_rate }}%
                    </a-tag>
                    <span v-else class="muted">—</span>
                  </template>
                  <template v-else-if="['labour_cost', 'overhead_cost'].includes(column.key)">
                    {{ money(record[column.key]) }}
                  </template>
                </template>
              </a-table>
            </a-card>
          </a-col>
          <a-col :xs="24" :xl="10">
            <ReportChart
              :data="chartRows"
              :fields="[
                { key: 'planned_hours', label: $t('Allowed') },
                { key: 'actual_hours', label: $t('Booked') },
              ]"
              :title="$t('Hours_by_work_centre')"
              type="bar"
              x-key="name"
              :height="320"
            />
          </a-col>
        </a-row>
      </template>

      <!-- ------------------------------------------------- material -->
      <template v-else-if="report === 'material'">
        <div class="kpis">
          <div class="kpi">
            <span class="kpi-label">{{ $t('Required') }}</span>
            <span class="kpi-value">{{ number(totals.required || 0, 0) }}</span>
          </div>
          <div class="kpi">
            <span class="kpi-label">{{ $t('Consumed') }}</span>
            <span class="kpi-value">{{ number(totals.consumed || 0, 0) }}</span>
          </div>
          <div class="kpi kpi--accent">
            <span class="kpi-label">{{ $t('Material_Cost') }}</span>
            <span class="kpi-value">{{ money(totals.cost || 0) }}</span>
          </div>
        </div>
        <a-card size="small">
          <a-table
            size="small" :columns="materialColumns" :data-source="rows" row-key="id"
            :pagination="{ pageSize: 25, showSizeChanger: true }" :scroll="{ x: 900 }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'name'">
                {{ record.name }}
                <div class="sub">{{ record.code }}</div>
              </template>
              <template v-else-if="column.key === 'variance_pct'">
                <a-tag v-if="record.variance_pct !== null" :color="varianceColor(record.variance_pct)">
                  {{ signedPct(record.variance_pct) }}
                </a-tag>
                <span v-else class="muted">—</span>
              </template>
              <template v-else-if="column.key === 'cost'">{{ money(record.cost) }}</template>
            </template>
          </a-table>
        </a-card>
      </template>

      <!-- ------------------------------------------------- quality -->
      <template v-else>
        <div class="kpis">
          <div class="kpi">
            <span class="kpi-label">{{ $t('Inspections') }}</span>
            <span class="kpi-value">{{ totals.checks || 0 }}</span>
          </div>
          <div class="kpi">
            <span class="kpi-label">{{ $t('Units_Inspected') }}</span>
            <span class="kpi-value">{{ number(totals.inspected || 0, 0) }}</span>
          </div>
          <div class="kpi">
            <span class="kpi-label">{{ $t('Rejected') }}</span>
            <span class="kpi-value kpi-value--bad">{{ number(totals.rejected || 0, 0) }}</span>
          </div>
          <div class="kpi kpi--accent">
            <span class="kpi-label">{{ $t('Pass_Rate') }}</span>
            <span class="kpi-value">
              {{ totals.pass_rate !== null && totals.pass_rate !== undefined ? totals.pass_rate + '%' : '—' }}
            </span>
          </div>
        </div>
        <a-card size="small">
          <a-table
            size="small" :columns="qualityColumns" :data-source="rows" row-key="id"
            :pagination="{ pageSize: 25 }" :scroll="{ x: 820 }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'name'">
                {{ record.name }}
                <div class="sub">{{ record.code }}</div>
              </template>
              <template v-else-if="column.key === 'pass_rate'">
                <a-tag v-if="record.pass_rate !== null" :color="passRateColor(record.pass_rate)">
                  {{ record.pass_rate }}%
                </a-tag>
                <span v-else class="muted">—</span>
              </template>
              <template v-else-if="column.key === 'failed_checks'">
                <a-tag v-if="record.failed_checks" color="error">{{ record.failed_checks }}</a-tag>
                <span v-else class="muted">—</span>
              </template>
            </template>
          </a-table>
        </a-card>
      </template>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * The four questions a plant is judged on: what it cost, how the floor
 * performed, where material went, and whether quality held.
 *
 * Each tab is its own endpoint — they join differently, and forcing them into
 * one payload would make all four slower than any one needs to be.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { FileExcelOutlined, FilePdfOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useFormat } from '../../composables/useFormat';
import { varianceColor, efficiencyColor, passRateColor, signedPct } from './mrpOptions';
import { exportExcel, exportPdf } from '../../lib/exporters';
import { t } from '../../i18n';
import http from '../../lib/http';

const { money, number } = useFormat();

const report = ref('cost');
const reportOptions = [
  { value: 'cost', label: t('Cost_and_variance', 'Cost & variance') },
  { value: 'efficiency', label: t('Work_centre_efficiency', 'Work centre efficiency') },
  { value: 'material', label: t('Material_usage', 'Material usage') },
  { value: 'quality', label: t('Quality', 'Quality') },
];

const loading = ref(false);
const exporting = ref(false);
const rows = ref([]);
const totals = ref({});
const range = ref(null);
const warehouseId = ref(undefined);

const warehouses = ref([]);
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));

const moneyKeys = ['material_cost', 'labour_cost', 'overhead_cost', 'total_cost', 'unit_cost', 'planned_cost', 'cost_variance'];

const chartRows = computed(() => rows.value.slice(0, 10));

/** Yield reads the opposite way to cost: more is better. */
function yieldColor(pct) {
  if (pct === null || pct === undefined) return 'default';
  if (pct >= 98) return 'success';
  if (pct >= 90) return 'warning';
  return 'error';
}

const costColumns = [
  { title: t('Order', 'Order'), key: 'reference', dataIndex: 'reference', width: 200, fixed: 'left' },
  { title: t('Completed', 'Completed'), dataIndex: 'completed_at', key: 'completed_at', width: 115 },
  { title: t('Planned', 'Planned'), dataIndex: 'qty_planned', key: 'qty_planned', width: 90, align: 'right' },
  { title: t('Produced', 'Produced'), dataIndex: 'qty_produced', key: 'qty_produced', width: 95, align: 'right' },
  { title: t('Scrapped', 'Scrapped'), dataIndex: 'qty_scrapped', key: 'qty_scrapped', width: 95, align: 'right' },
  { title: t('Yield', 'Yield'), key: 'yield_pct', dataIndex: 'yield_pct', width: 90, align: 'center' },
  { title: t('Material', 'Material'), key: 'material_cost', dataIndex: 'material_cost', width: 110, align: 'right' },
  { title: t('Labour', 'Labour'), key: 'labour_cost', dataIndex: 'labour_cost', width: 110, align: 'right' },
  { title: t('Overhead', 'Overhead'), key: 'overhead_cost', dataIndex: 'overhead_cost', width: 110, align: 'right' },
  { title: t('Total', 'Total'), key: 'total_cost', dataIndex: 'total_cost', width: 120, align: 'right' },
  { title: t('Per_unit', 'Per unit'), key: 'unit_cost', dataIndex: 'unit_cost', width: 110, align: 'right' },
  { title: t('vs_plan', 'vs plan'), key: 'cost_variance_pct', dataIndex: 'cost_variance_pct', width: 100, align: 'right' },
];

const efficiencyColumns = [
  { title: t('Work_centre', 'Work centre'), dataIndex: 'name', key: 'name' },
  { title: t('Operations', 'Operations'), dataIndex: 'operations', key: 'operations', width: 100, align: 'right' },
  { title: t('Allowed', 'Allowed'), dataIndex: 'planned_hours', key: 'planned_hours', width: 100, align: 'right' },
  { title: t('Booked', 'Booked'), dataIndex: 'actual_hours', key: 'actual_hours', width: 100, align: 'right' },
  { title: t('Efficiency', 'Efficiency'), key: 'efficiency_pct', dataIndex: 'efficiency_pct', width: 110, align: 'center' },
  { title: t('Labour', 'Labour'), key: 'labour_cost', dataIndex: 'labour_cost', width: 110, align: 'right' },
  { title: t('Rejects', 'Rejects'), key: 'reject_rate', dataIndex: 'reject_rate', width: 100, align: 'center' },
];

const materialColumns = [
  { title: t('Component', 'Component'), key: 'name', dataIndex: 'name' },
  { title: t('Orders', 'Orders'), dataIndex: 'orders', key: 'orders', width: 90, align: 'right' },
  { title: t('Required', 'Required'), dataIndex: 'required', key: 'required', width: 110, align: 'right' },
  { title: t('Consumed', 'Consumed'), dataIndex: 'consumed', key: 'consumed', width: 110, align: 'right' },
  { title: t('Variance', 'Variance'), dataIndex: 'variance', key: 'variance', width: 110, align: 'right' },
  { title: t('vs_BOM', 'vs BOM'), key: 'variance_pct', dataIndex: 'variance_pct', width: 100, align: 'right' },
  { title: t('Cost', 'Cost'), key: 'cost', dataIndex: 'cost', width: 130, align: 'right' },
];

const qualityColumns = [
  { title: t('Product', 'Product'), key: 'name', dataIndex: 'name' },
  { title: t('Inspections', 'Inspections'), dataIndex: 'checks', key: 'checks', width: 110, align: 'right' },
  { title: t('Failed', 'Failed'), key: 'failed_checks', dataIndex: 'failed_checks', width: 100, align: 'center' },
  { title: t('Inspected', 'Inspected'), dataIndex: 'inspected', key: 'inspected', width: 110, align: 'right' },
  { title: t('Passed', 'Passed'), dataIndex: 'passed', key: 'passed', width: 110, align: 'right' },
  { title: t('Rejected', 'Rejected'), dataIndex: 'rejected', key: 'rejected', width: 110, align: 'right' },
  { title: t('Pass_Rate', 'Pass rate'), key: 'pass_rate', dataIndex: 'pass_rate', width: 110, align: 'center' },
];

const columnsFor = computed(() => ({
  cost: costColumns,
  efficiency: efficiencyColumns,
  material: materialColumns,
  quality: qualityColumns,
}[report.value]));

async function load() {
  loading.value = true;
  try {
    const data = await http.get(`mrp/reports/${report.value}`, {
      from: range.value?.[0] || '',
      to: range.value?.[1] || '',
      warehouse_id: warehouseId.value || '',
    });
    rows.value = data?.rows || [];
    totals.value = data?.totals || {};
  } catch (e) {
    rows.value = [];
    totals.value = {};
  } finally {
    loading.value = false;
  }
}

async function download(kind) {
  if (!rows.value.length) {
    message.info(t('NodataAvailable', 'Nothing to export'));
    return;
  }
  exporting.value = true;
  try {
    const title = reportOptions.find(o => o.value === report.value).label;
    const cols = columnsFor.value.map(c => ({ title: c.title, dataIndex: c.dataIndex || c.key }));
    if (kind === 'excel') await exportExcel(title, cols, rows.value);
    else await exportPdf(title, cols, rows.value);
  } catch (e) {
    message.error(t('InvalidData', 'Could not build the export'));
  } finally {
    exporting.value = false;
  }
}

onMounted(async () => {
  load();
  try {
    const meta = await http.get('mrp/meta');
    warehouses.value = meta?.warehouses || [];
  } catch (e) { /* the warehouse select stays empty */ }
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.link {
  color: #6d28d9;
  cursor: pointer;
  font-weight: 500;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-item {
  width: 180px;
}
.tb-range {
  width: 240px;
}
.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.kpi {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 13px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
}
.kpi--accent {
  border-color: rgba(109, 40, 217, 0.45);
  background: rgba(109, 40, 217, 0.05);
}
.kpi-label {
  font-size: 12.5px;
  opacity: 0.65;
}
.kpi-value {
  font-size: 20px;
  font-weight: 600;
}
.kpi-value--bad {
  color: #dc2626;
}
.kpi-value--good {
  color: #16a34a;
}
.kpi-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-range {
    width: 100%;
  }
}
</style>
