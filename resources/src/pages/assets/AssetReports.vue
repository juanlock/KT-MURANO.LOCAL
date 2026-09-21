<template>
  <div class="page">
    <PageHeader
      :title="$t('Asset_Reports')"
      :subtitle="$t('Register_value_upkeep_spend_and_how_reliably_kit_comes_back')"
      :breadcrumb="[$t('Asset_Management'), $t('Reports')]"
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
        <a-range-picker
          v-if="report !== 'register'"
          v-model:value="range" class="tb-range" value-format="YYYY-MM-DD" @change="load"
        />
        <a-select
          v-if="report !== 'custody'"
          v-model:value="categoryId" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('All_Categories')"
          :options="categoryOptions" @change="load"
        />
      </div>
    </a-card>

    <a-spin :spinning="loading">
      <!-- ------------------------------------------------ register -->
      <template v-if="report === 'register'">
        <div class="kpis">
          <div class="kpi">
            <span class="kpi-label">{{ $t('Assets') }}</span>
            <span class="kpi-value">{{ totalRows }}</span>
          </div>
          <div class="kpi">
            <span class="kpi-label">{{ $t('Purchase_cost') }}</span>
            <span class="kpi-value">{{ money(totals.purchase_cost || 0) }}</span>
          </div>
          <div class="kpi kpi--accent">
            <span class="kpi-label">{{ $t('Book_Value') }}</span>
            <span class="kpi-value">{{ money(totals.book_value || 0) }}</span>
          </div>
        </div>
        <a-card size="small">
          <a-table
            size="small" :columns="registerColumns" :data-source="rows" row-key="id"
            :pagination="{ pageSize: 25, showSizeChanger: true }" :scroll="{ x: 1100 }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'name'">
                <a class="link" @click="$router.push(`/assets/${record.id}`)">{{ record.name }}</a>
                <div class="sub">{{ record.tag }}</div>
              </template>
              <template v-else-if="column.key === 'status'">
                <a-tag :color="optionOf(ASSET_STATUSES, record.status).color">
                  {{ labelOf(ASSET_STATUSES, record.status) }}
                </a-tag>
              </template>
              <template v-else-if="moneyKeys.includes(column.key)">{{ money(record[column.key]) }}</template>
            </template>
          </a-table>
        </a-card>
      </template>

      <!-- ------------------------------------------------ maintenance -->
      <template v-else-if="report === 'maintenance'">
        <div class="kpis">
          <div class="kpi">
            <span class="kpi-label">{{ $t('Jobs') }}</span>
            <span class="kpi-value">{{ totals.jobs || 0 }}</span>
          </div>
          <div class="kpi">
            <span class="kpi-label">{{ $t('Still_open') }}</span>
            <span class="kpi-value">{{ totals.open_jobs || 0 }}</span>
          </div>
          <div class="kpi kpi--accent">
            <span class="kpi-label">{{ $t('Completed_spend') }}</span>
            <span class="kpi-value">{{ money(totals.spend || 0) }}</span>
          </div>
        </div>
        <a-row :gutter="16">
          <a-col :xs="24" :xl="14">
            <a-card size="small">
              <a-table
                size="small" :columns="maintenanceColumns" :data-source="rows" row-key="id"
                :pagination="{ pageSize: 25, showSizeChanger: true }" :scroll="{ x: 900 }"
              >
                <template #bodyCell="{ column, record }">
                  <template v-if="column.key === 'name'">
                    <a class="link" @click="$router.push(`/assets/${record.id}`)">{{ record.name }}</a>
                    <div class="sub">{{ record.tag }}</div>
                  </template>
                  <template v-else-if="column.key === 'spend' || column.key === 'purchase_cost'">
                    {{ money(record[column.key]) }}
                  </template>
                  <template v-else-if="column.key === 'spend_ratio'">
                    <span v-if="record.spend_ratio === null" class="muted">—</span>
                    <a-tag v-else :color="ratioColor(record.spend_ratio)">{{ record.spend_ratio }}%</a-tag>
                  </template>
                </template>
              </a-table>
            </a-card>
          </a-col>
          <a-col :xs="24" :xl="10">
            <ReportChart
              :data="topSpenders"
              :fields="[{ key: 'spend', label: $t('Spend'), money: true }]"
              :title="$t('Costliest_assets_to_keep')"
              type="bar"
              x-key="name"
              :height="320"
              :format="money"
            />
          </a-col>
        </a-row>
      </template>

      <!-- ------------------------------------------------ custody -->
      <template v-else>
        <div class="kpis">
          <div class="kpi">
            <span class="kpi-label">{{ $t('Out_right_now') }}</span>
            <span class="kpi-value">{{ totals.holding || 0 }}</span>
          </div>
          <div class="kpi">
            <span class="kpi-label">{{ $t('Overdue') }}</span>
            <span class="kpi-value kpi-value--bad">{{ totals.overdue || 0 }}</span>
          </div>
          <div class="kpi">
            <span class="kpi-label">{{ $t('Returned') }}</span>
            <span class="kpi-value">{{ totals.returned || 0 }}</span>
          </div>
        </div>
        <a-card size="small">
          <a-table
            size="small" :columns="custodyColumns" :data-source="rows" row-key="id"
            :pagination="{ pageSize: 25, showSizeChanger: true }" :scroll="{ x: 800 }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'overdue'">
                <a-tag v-if="record.overdue" color="error">{{ record.overdue }}</a-tag>
                <span v-else class="muted">0</span>
              </template>
              <template v-else-if="column.key === 'on_time_rate'">
                <span v-if="record.on_time_rate === null" class="muted">—</span>
                <a-tag v-else :color="rateColor(record.on_time_rate)">{{ record.on_time_rate }}%</a-tag>
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
 * Three questions the register cannot answer on its own: what is it all worth,
 * what is it costing to keep, and who never brings anything back.
 *
 * Each tab is a separate endpoint rather than one payload with everything in
 * it — the custody report joins differently from the value report, and forcing
 * them together would make both slower than either needs to be.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { FileExcelOutlined, FilePdfOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useFormat } from '../../composables/useFormat';
import { ASSET_STATUSES, labelOf, optionOf } from './assetOptions';
import { exportExcel, exportPdf } from '../../lib/exporters';
import { t } from '../../i18n';
import http from '../../lib/http';

const { money } = useFormat();

const report = ref('register');
const reportOptions = [
  { value: 'register', label: t('Register_and_value', 'Register & value') },
  { value: 'maintenance', label: t('Maintenance_cost', 'Maintenance cost') },
  { value: 'custody', label: t('Custody', 'Custody') },
];

const loading = ref(false);
const exporting = ref(false);
const rows = ref([]);
const totals = ref({});
const totalRows = ref(0);
const range = ref(null);
const categoryId = ref(undefined);

const meta = ref({ categories: [] });
const categoryOptions = computed(() => (meta.value.categories || []).map(c => ({ value: c.id, label: c.name })));

const moneyKeys = ['purchase_cost', 'accumulated_depreciation', 'book_value', 'maintenance_cost', 'total_cost_of_ownership'];

const registerColumns = [
  { title: t('Asset', 'Asset'), key: 'name', dataIndex: 'name', width: 200, fixed: 'left' },
  { title: t('Category', 'Category'), dataIndex: 'category_name', key: 'category_name', width: 140 },
  { title: t('Warehouse', 'Warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name', width: 140 },
  { title: t('Status', 'Status'), key: 'status', dataIndex: 'status', width: 120 },
  { title: t('Cost', 'Cost'), key: 'purchase_cost', dataIndex: 'purchase_cost', width: 120, align: 'right' },
  { title: t('Depreciated', 'Depreciated'), key: 'accumulated_depreciation', dataIndex: 'accumulated_depreciation', width: 130, align: 'right' },
  { title: t('Book_Value', 'Book value'), key: 'book_value', dataIndex: 'book_value', width: 130, align: 'right' },
  { title: t('Upkeep', 'Upkeep'), key: 'maintenance_cost', dataIndex: 'maintenance_cost', width: 120, align: 'right' },
  { title: t('Total_owned_cost', 'Total owned cost'), key: 'total_cost_of_ownership', dataIndex: 'total_cost_of_ownership', width: 150, align: 'right' },
];

const maintenanceColumns = [
  { title: t('Asset', 'Asset'), key: 'name', dataIndex: 'name', width: 200, fixed: 'left' },
  { title: t('Category', 'Category'), dataIndex: 'category_name', key: 'category_name', width: 130 },
  { title: t('Jobs', 'Jobs'), dataIndex: 'jobs', key: 'jobs', width: 80, align: 'right' },
  { title: t('Open', 'Open'), dataIndex: 'open_jobs', key: 'open_jobs', width: 80, align: 'right' },
  { title: t('Purchase_cost', 'Purchase cost'), key: 'purchase_cost', dataIndex: 'purchase_cost', width: 130, align: 'right' },
  { title: t('Spend', 'Spend'), key: 'spend', dataIndex: 'spend', width: 120, align: 'right' },
  { title: t('Of_cost', 'Of cost'), key: 'spend_ratio', dataIndex: 'spend_ratio', width: 100, align: 'right' },
];

const custodyColumns = [
  { title: t('Person', 'Person'), dataIndex: 'user_name', key: 'user_name' },
  { title: t('Assignments', 'Assignments'), dataIndex: 'total', key: 'total', width: 120, align: 'right' },
  { title: t('Holding_now', 'Holding now'), dataIndex: 'holding', key: 'holding', width: 120, align: 'right' },
  { title: t('Overdue', 'Overdue'), key: 'overdue', dataIndex: 'overdue', width: 100, align: 'right' },
  { title: t('Returned', 'Returned'), dataIndex: 'returned', key: 'returned', width: 100, align: 'right' },
  { title: t('Returned_late', 'Returned late'), dataIndex: 'returned_late', key: 'returned_late', width: 120, align: 'right' },
  { title: t('On_time', 'On time'), key: 'on_time_rate', dataIndex: 'on_time_rate', width: 100, align: 'right' },
];

const columnsFor = computed(() => {
  if (report.value === 'register') return registerColumns;
  if (report.value === 'maintenance') return maintenanceColumns;
  return custodyColumns;
});

const topSpenders = computed(() => rows.value.slice(0, 8).map(r => ({ name: r.tag || r.name, spend: r.spend })));

/** Upkeep past half the purchase price is where replacing starts to win. */
function ratioColor(pct) {
  if (pct >= 50) return 'error';
  if (pct >= 25) return 'warning';
  return 'success';
}

function rateColor(pct) {
  if (pct >= 90) return 'success';
  if (pct >= 70) return 'warning';
  return 'error';
}

function endpointAndParams(limit) {
  const base = {
    register: 'assets/workspace/report/register',
    maintenance: 'assets/workspace/report/maintenance',
    custody: 'assets/workspace/report/custody',
  }[report.value];

  const params = {
    from: range.value?.[0] || '',
    to: range.value?.[1] || '',
    category_id: report.value === 'custody' ? '' : (categoryId.value || ''),
  };
  // Only the register report paginates; the other two are already grouped down
  // to one row per asset or per person.
  if (report.value === 'register') params.limit = limit ?? -1;

  return [base, params];
}

async function load() {
  loading.value = true;
  try {
    const [url, params] = endpointAndParams();
    const data = await http.get(url, params);
    rows.value = data?.rows || [];
    totals.value = data?.totals || {};
    totalRows.value = data?.totalRows ?? rows.value.length;
  } catch (e) {
    rows.value = [];
    totals.value = {};
    totalRows.value = 0;
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
    meta.value = await http.get('assets/workspace/meta');
  } catch (e) { /* the select stays empty */ }
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
@media (max-width: 767px) {
  .tb-item,
  .tb-range {
    width: 100%;
  }
}
</style>
