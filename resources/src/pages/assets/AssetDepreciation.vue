<template>
  <div class="page">
    <PageHeader
      title="Depreciation"
      subtitle="What the register is worth on the books, asset by asset."
      :breadcrumb="['Asset Management', 'Depreciation']"
    >
      <template #actions>
        <a-button :loading="exporting" @click="download('excel')">
          <template #icon><FileExcelOutlined /></template>
          Excel
        </a-button>
        <a-button :loading="exporting" @click="download('pdf')">
          <template #icon><FilePdfOutlined /></template>
          PDF
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="search" placeholder="Search asset…" allow-clear
          class="tb-search" @search="reload"
        />
        <a-date-picker
          v-model:value="filters.as_of" class="tb-item" value-format="YYYY-MM-DD"
          placeholder="Valued as of" :allow-clear="false" @change="reload"
        />
        <a-select
          v-model:value="filters.category_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="All categories"
          :options="categoryOptions" @change="reload"
        />
        <a-select
          v-model:value="filters.method" class="tb-item" allow-clear
          placeholder="All methods" :options="methodOptions" @change="reload"
        />
        <a-checkbox v-model:checked="filters.include_disposed" class="tb-check" @change="reload">
          Include disposed
        </a-checkbox>
      </div>
    </a-card>

    <div class="kpis">
      <div class="kpi">
        <span class="kpi-label">Purchase cost</span>
        <span class="kpi-value">{{ money(totals.purchase_cost || 0) }}</span>
      </div>
      <div class="kpi">
        <span class="kpi-label">Accumulated depreciation</span>
        <span class="kpi-value kpi-value--down">−{{ money(totals.accumulated_depreciation || 0) }}</span>
      </div>
      <div class="kpi kpi--accent">
        <span class="kpi-label">Book value as of {{ date(asOf) }}</span>
        <span class="kpi-value">{{ money(totals.book_value || 0) }}</span>
      </div>
    </div>

    <a-card size="small">
      <a-table
        size="small"
        :loading="loading"
        :columns="columns"
        :data-source="rows"
        row-key="id"
        :scroll="{ x: 1200 }"
        :pagination="{
          current: page,
          pageSize: limit,
          total: totalRows,
          showSizeChanger: true,
          pageSizeOptions: PAGE_SIZE_OPTIONS,
          buildOptionText: buildPageSizeOptionText,
        }"
        @change="onTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'">
            <a class="link" @click="$router.push(`/assets/${record.id}`)">{{ record.name }}</a>
            <div class="sub">{{ record.tag }}</div>
          </template>
          <template v-else-if="column.key === 'method'">
            <a-tag :color="record.method === 'none' ? 'default' : 'blue'">
              {{ labelOf(DEPRECIATION_METHODS, record.method || 'none') }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'life'">
            <span v-if="!record.useful_life_months" class="muted">—</span>
            <span v-else>
              {{ record.months_depreciated }} / {{ record.useful_life_months }} mo
              <a-progress
                :percent="lifePct(record)" :show-info="false" size="small"
                :stroke-color="lifePct(record) >= 100 ? '#dc2626' : '#6d28d9'"
              />
            </span>
          </template>
          <template v-else-if="column.key === 'purchase_cost'">{{ money(record.purchase_cost) }}</template>
          <template v-else-if="column.key === 'salvage_value'">{{ money(record.salvage_value) }}</template>
          <template v-else-if="column.key === 'accumulated_depreciation'">
            <span class="down">−{{ money(record.accumulated_depreciation) }}</span>
          </template>
          <template v-else-if="column.key === 'book_value'">
            <strong>{{ money(record.book_value) }}</strong>
          </template>
          <template v-else-if="column.key === 'disposal'">
            <span v-if="!record.disposal_date" class="muted">—</span>
            <a-tooltip v-else :title="`Disposed ${record.disposal_date}`">
              <a-tag :color="record.disposal_gain >= 0 ? 'success' : 'error'">
                {{ record.disposal_gain >= 0 ? '+' : '' }}{{ money(record.disposal_gain) }}
              </a-tag>
            </a-tooltip>
          </template>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup>
/**
 * The depreciation register.
 *
 * The "valued as of" date is the point of this page: it re-runs every asset's
 * own schedule at whatever date you pick, so you can pull a book value for a
 * year end rather than only for today. Totals describe the whole filtered set,
 * not the page on screen — a per-page total on a money report is a trap.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { FileExcelOutlined, FilePdfOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { DEPRECIATION_METHODS, labelOf } from './assetOptions';
import { exportExcel, exportPdf } from '../../lib/exporters';
import http from '../../lib/http';
import { PAGE_SIZE_OPTIONS, buildPageSizeOptionText } from '../../composables/useCrudTable';

const { money, date } = useFormat();

const loading = ref(false);
const exporting = ref(false);
const rows = ref([]);
const totals = ref({});
const totalRows = ref(0);
const asOf = ref(new Date().toISOString().slice(0, 10));
const page = ref(1);
const limit = ref(25);
const search = ref('');

const filters = reactive({
  as_of: new Date().toISOString().slice(0, 10),
  category_id: undefined,
  method: undefined,
  include_disposed: false,
});

const meta = ref({ categories: [] });
const categoryOptions = computed(() => (meta.value.categories || []).map(c => ({ value: c.id, label: c.name })));
const methodOptions = DEPRECIATION_METHODS.map(m => ({ value: m.value, label: m.label }));

const columns = computed(() => [
  { title: 'Asset', key: 'name', dataIndex: 'name', width: 220, fixed: 'left' },
  { title: 'Category', dataIndex: 'category_name', key: 'category_name', width: 140 },
  { title: 'Purchased', dataIndex: 'purchase_date', key: 'purchase_date', width: 110 },
  { title: 'Method', key: 'method', dataIndex: 'method', width: 150 },
  { title: 'Life used', key: 'life', width: 150 },
  { title: 'Cost', key: 'purchase_cost', dataIndex: 'purchase_cost', width: 120, align: 'right' },
  { title: 'Salvage', key: 'salvage_value', dataIndex: 'salvage_value', width: 110, align: 'right' },
  { title: 'Accumulated', key: 'accumulated_depreciation', dataIndex: 'accumulated_depreciation', width: 140, align: 'right' },
  { title: 'Book value', key: 'book_value', dataIndex: 'book_value', width: 130, align: 'right' },
  { title: 'Disposal gain', key: 'disposal', dataIndex: 'disposal_gain', width: 130, align: 'right' },
]);

function lifePct(record) {
  if (!record.useful_life_months) return 0;
  return Math.min(100, Math.round((record.months_depreciated / record.useful_life_months) * 100));
}

function params(overrides = {}) {
  return {
    page: page.value,
    limit: limit.value,
    search: search.value || '',
    as_of: filters.as_of || '',
    category_id: filters.category_id || '',
    method: filters.method || '',
    include_disposed: filters.include_disposed ? 1 : 0,
    ...overrides,
  };
}

async function load() {
  loading.value = true;
  try {
    const data = await http.get('assets/workspace/report/register', params());
    rows.value = data?.rows || [];
    totals.value = data?.totals || {};
    totalRows.value = data?.totalRows || 0;
    asOf.value = data?.as_of || filters.as_of;
  } catch (e) {
    rows.value = [];
    totals.value = {};
    totalRows.value = 0;
  } finally {
    loading.value = false;
  }
}

function reload() {
  page.value = 1;
  load();
}

function onTableChange(pagination) {
  page.value = pagination.current;
  limit.value = pagination.pageSize;
  load();
}

/** Export the whole filtered register, not just the rows on screen. */
async function download(kind) {
  exporting.value = true;
  try {
    const data = await http.get('assets/workspace/report/register', params({ limit: -1, page: 1 }));
    const all = data?.rows || [];
    if (!all.length) {
      message.info('Nothing to export');
      return;
    }

    const cols = [
      { title: 'Tag', dataIndex: 'tag' },
      { title: 'Asset', dataIndex: 'name' },
      { title: 'Category', dataIndex: 'category_name' },
      { title: 'Warehouse', dataIndex: 'warehouse_name' },
      { title: 'Purchased', dataIndex: 'purchase_date' },
      { title: 'Method', dataIndex: 'method', exportValue: r => labelOf(DEPRECIATION_METHODS, r.method || 'none') },
      { title: 'Life (months)', dataIndex: 'useful_life_months' },
      { title: 'Months used', dataIndex: 'months_depreciated' },
      { title: 'Cost', dataIndex: 'purchase_cost' },
      { title: 'Salvage', dataIndex: 'salvage_value' },
      { title: 'Accumulated', dataIndex: 'accumulated_depreciation' },
      { title: 'Book value', dataIndex: 'book_value' },
      { title: 'Disposal gain', dataIndex: 'disposal_gain' },
    ];
    const title = `Depreciation as of ${asOf.value}`;

    if (kind === 'excel') await exportExcel(title, cols, all);
    else await exportPdf(title, cols, all);
  } catch (e) {
    message.error('Could not build the export');
  } finally {
    exporting.value = false;
  }
}

onMounted(async () => {
  load();
  try {
    meta.value = await http.get('assets/workspace/meta');
  } catch (e) { /* the selects stay empty */ }
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
.down {
  color: #dc2626;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 200px;
  min-width: 170px;
}
.tb-item {
  width: 170px;
}
.tb-check {
  white-space: nowrap;
}
.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.kpi {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 14px 16px;
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
.kpi-value--down {
  color: #dc2626;
}
@media (max-width: 767px) {
  .tb-item {
    width: 100%;
  }
}
</style>
