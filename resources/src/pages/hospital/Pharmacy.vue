<template>
  <div class="page">
    <PageHeader :title="s('Pharmacy')" :breadcrumb="[s('Hospital'), s('Pharmacy')]">
      <template #actions>
        <PermLink to="/products/batches" permission="view_batches">
          <a-button>{{ s('Batches') }}</a-button>
        </PermLink>
        <PermLink to="/products" permission="products_view">
          <a-button>{{ s('Products') }}</a-button>
        </PermLink>
      </template>
    </PageHeader>

    <div class="kpis">
      <button v-for="k in kpiCards" :key="k.key" type="button" class="kpi" :class="{ on: filter === k.filter }" @click="setFilter(k.filter)">
        <div class="kpi-v" :style="{ color: k.color }">{{ k.value }}</div>
        <div class="kpi-l">{{ s(k.label) }}</div>
      </button>
    </div>

    <a-card size="small" style="margin-bottom: 12px">
      <div class="toolbar">
        <a-input-search v-model:value="search" :placeholder="s('Search medicine, generic name, code or manufacturer…')" allow-clear class="tb-search" @search="load" />
        <a-select v-model:value="categoryId" class="tb-item" allow-clear show-search option-filter-prop="label" :placeholder="s('All categories')" :options="categoryOptions" @change="load" />
        <a-checkbox v-model:checked="rxOnly" @change="load">{{ s('Prescription only') }}</a-checkbox>
      </div>
    </a-card>

    <a-table
      :data-source="rows" :columns="columns" row-key="id" size="small" :loading="loading" :scroll="{ x: 1000 }"
      :pagination="{ pageSize: 25, showSizeChanger: true, pageSizeOptions: ['25', '50', '100'] }"
      :expanded-row-keys="expanded" @expand="onExpand"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <PermLink :to="`/products/${record.id}`" permission="products_view"><b>{{ record.name }}</b></PermLink>
          <div v-if="details_(record)" class="cell-sub">{{ details_(record) }}</div>
          <div class="cell-sub mono">{{ record.code }}<template v-if="record.manufacturer"> · {{ record.manufacturer }}</template></div>
        </template>
        <template v-else-if="column.key === 'rx'">
          <a-tag v-if="record.prescription_required" color="red">Rx</a-tag>
          <span v-else class="muted">{{ s('OTC') }}</span>
        </template>
        <template v-else-if="column.key === 'stock'">
          <b :class="{ out: record.out_of_stock, low: record.low_stock }">{{ number(record.stock) }}</b>
          <span class="muted"> {{ record.unit || '' }}</span>
          <div v-if="record.out_of_stock" class="cell-sub out">{{ s('Out of stock') }}</div>
          <div v-else-if="record.low_stock" class="cell-sub low">{{ s('Low stock') }} (≤ {{ number(record.stock_alert) }})</div>
        </template>
        <template v-else-if="column.key === 'expiry'">
          <template v-if="record.next_expiry">
            <span :class="{ out: record.expired, low: record.expiring }">{{ date(record.next_expiry) }}</span>
            <div v-if="record.expired" class="cell-sub out">{{ s('Expired') }}</div>
            <div v-else-if="record.expiring" class="cell-sub low">{{ s('Expires soon') }}</div>
          </template>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'price'">{{ money(record.price) }}</template>
      </template>
      <template #expandedRowRender="{ record }">
        <a-spin :spinning="!details[record.id]">
          <div v-if="details[record.id]" class="detail">
            <div>
              <div class="d-title">{{ s('Stock by warehouse') }}</div>
              <div v-if="!details[record.id].stock.length" class="muted">{{ s('No stock') }}</div>
              <div v-for="w in details[record.id].stock" :key="w.warehouse" class="d-row">
                <span>{{ w.warehouse }}</span><b>{{ number(w.qty) }}</b>
              </div>
            </div>
            <div>
              <div class="d-title">{{ s('Batches') }}</div>
              <div v-if="!details[record.id].batches.length" class="muted">{{ s('No batches recorded') }}</div>
              <div v-for="(b, i) in details[record.id].batches" :key="i" class="d-row">
                <span>{{ b.batch_no }} · {{ b.warehouse }}</span>
                <span>{{ b.expiry_date ? date(b.expiry_date) : '—' }}</span>
                <b>{{ number(b.qty) }}</b>
              </div>
            </div>
          </div>
        </a-spin>
      </template>
    </a-table>
  </div>
</template>

<script setup>
/**
 * Hospital Pharmacy (2026-10-21): the medicines and supplies on hand -- the
 * company's products with their pharmacy details, stock over the warehouses
 * the user may see, the nearest batch expiry and alerts. Read only; stock
 * moves through Purchases / Adjustments / Sales.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';
import { useSystemContext } from '../../lib/systemContext';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/Pharmacy.es.js';

const { s } = useHospitalText(ES);
const { money, number, date } = useFormat();
const { isSystem } = useSystemContext();

const loading = ref(false);
const rows = ref([]);
const kpis = ref({});
const categories = ref([]);
const search = ref('');
const categoryId = ref(undefined);
const rxOnly = ref(false);
const filter = ref('');
const expanded = ref([]);
const details = reactive({});

const kpiCards = computed(() => [
  { key: 'items', filter: '', label: 'Items', value: kpis.value.items ?? 0, color: '' },
  { key: 'in_stock', filter: 'in_stock', label: 'In stock', value: kpis.value.in_stock ?? 0, color: '#16a34a' },
  { key: 'low', filter: 'low', label: 'Low stock', value: kpis.value.low_stock ?? 0, color: '#ca8a04' },
  { key: 'out', filter: 'out', label: 'Out of stock', value: kpis.value.out_of_stock ?? 0, color: '#dc2626' },
  { key: 'expiring', filter: 'expiring', label: 'Expiring / expired', value: (kpis.value.expiring ?? 0) + (kpis.value.expired ?? 0), color: '#ea580c' },
  { key: 'value', filter: null, label: 'Stock value (cost)', value: money(kpis.value.stock_value ?? 0), color: '' },
]);

const categoryOptions = computed(() => sortByLabel(categories.value.map(c => ({ value: c.id, label: c.name }))));

const columns = computed(() => [
  { title: s('Medicine'), key: 'name' },
  ...(isSystem.value ? [{ title: s('Company'), dataIndex: 'company_name', width: 150 }] : []),
  { title: s('Category'), dataIndex: 'category', width: 150 },
  { title: s('Prescription'), key: 'rx', width: 100 },
  { title: s('Stock'), key: 'stock', width: 140 },
  { title: s('Next expiry'), key: 'expiry', width: 130 },
  { title: s('Price'), key: 'price', width: 110, align: 'right' },
]);

/** "generic · strength · form · pack", skipping the empty ones. */
const details_ = r => [r.generic_name, r.strength, r.dosage_form, r.pack_size].filter(Boolean).join(' · ');

function setFilter(f) {
  if (f === null) return;
  filter.value = filter.value === f ? '' : f;
  load();
}

async function load() {
  loading.value = true;
  try {
    const d = await http.get('hospital/pharmacy', {
      search: search.value || '', category_id: categoryId.value || '', rx_only: rxOnly.value ? 1 : '', filter: filter.value,
    });
    rows.value = d?.medicines || [];
    kpis.value = d?.kpis || {};
    categories.value = d?.categories || [];
  } catch (e) {
    message.error(e?.data?.message || s('Could not load'));
  } finally {
    loading.value = false;
  }
}

async function onExpand(open, record) {
  expanded.value = open ? [...expanded.value, record.id] : expanded.value.filter(id => id !== record.id);
  if (open && !details[record.id]) {
    try {
      const d = await http.get(`hospital/pharmacy/${record.id}`);
      details[record.id] = { stock: d?.stock || [], batches: d?.batches || [] };
    } catch (e) {
      details[record.id] = { stock: [], batches: [] };
    }
  }
}

onMounted(load);
</script>

<style scoped>
.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 10px;
  margin-bottom: 12px;
}
.kpi {
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(128, 128, 128, 0.08);
  border: 2px solid transparent;
  text-align: left;
  cursor: pointer;
  color: inherit;
  font: inherit;
}
.kpi.on {
  border-color: #6d28d9;
}
.kpi-v {
  font-size: 22px;
  font-weight: 700;
}
.kpi-l {
  font-size: 12px;
  opacity: 0.65;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}
.tb-search {
  flex: 1 1 260px;
  min-width: 180px;
}
.tb-item {
  width: 200px;
}
.cell-sub {
  font-size: 11.5px;
  opacity: 0.6;
}
.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.muted {
  opacity: 0.55;
}
.out {
  color: #dc2626;
  opacity: 1;
}
.low {
  color: #ca8a04;
  opacity: 1;
}
.detail {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 20px;
}
.d-title {
  font-weight: 600;
  margin-bottom: 6px;
}
.d-row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: 13px;
  padding: 2px 0;
  border-bottom: 1px dashed rgba(128, 128, 128, 0.2);
}
</style>
