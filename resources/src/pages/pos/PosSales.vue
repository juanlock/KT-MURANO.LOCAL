<template>
  <div class="page">
    <PageHeader :title="$t('POS_Sales')" :breadcrumb="['POS', $t('POS_Sales')]">
      <template #actions>
        <a-space wrap :size="12">
          <DateRangeFields :value="rangeFields" :disabled="searching" @change="onRangeEdit" />
          <DatePresetButtons v-model:value="datePreset" @change="applyPreset(datePreset)" />
          <a-button :loading="exporting === 'pdf'" @click="exportList('pdf')"><template #icon><FilePdfOutlined /></template>PDF</a-button>
          <a-button :loading="exporting === 'xlsx'" @click="exportList('xlsx')"><template #icon><FileExcelOutlined /></template>EXCEL</a-button>
          <a-button v-if="auth.can('Pos_view')" type="primary" @click="$router.push('/pos')">
            <template #icon><ShopOutlined /></template> POS
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <!-- Totals for the filtered set (all pages): sales, total, then money per payment method. -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="tile in statTiles" :key="tile.label" :xs="12" :sm="8" :md="6" :lg="4">
        <a-card size="small" class="stat-card">
          <div class="stat-label">{{ tile.label }}</div>
          <div class="stat-value" :style="{ color: tile.color }">
            <a-spin v-if="crud.loading.value" size="small" />
            <template v-else>{{ tile.value }}</template>
          </div>
          <div v-if="tile.sub" class="stat-sub">{{ tile.sub }}</div>
        </a-card>
      </a-col>
    </a-row>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[12, 12]">
        <a-col :xs="24" :sm="12" :md="6">
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select v-model:value="filters.warehouse_id" style="width: 100%" allow-clear show-search option-filter-prop="label"
            :placeholder="$t('All')" :options="warehouseOptions" @change="crud.reload()" />
        </a-col>
        <a-col :xs="24" :sm="12" :md="6">
          <div class="filter-label">{{ $t('Cashier') }}</div>
          <a-select v-model:value="filters.cashier_id" style="width: 100%" allow-clear show-search option-filter-prop="label"
            :placeholder="$t('All')" :options="cashierOptions" @change="crud.reload()" />
        </a-col>
        <a-col :xs="24" :sm="12" :md="6">
          <div class="filter-label">{{ $t('Payment_Method') || 'Payment method' }}</div>
          <a-select v-model:value="filters.payment_method_id" style="width: 100%" allow-clear show-search option-filter-prop="label"
            :placeholder="$t('All')" :options="methodOptions" @change="crud.reload()" />
        </a-col>
        <a-col :xs="24" :sm="12" :md="6">
          <div class="filter-label">{{ $t('Customer') }}</div>
          <a-select v-model:value="filters.client_id" style="width: 100%" allow-clear show-search option-filter-prop="label"
            :placeholder="$t('All')" :options="customerOptions" @change="crud.reload()" />
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'actions'">
          <a-space :size="4">
            <a-tooltip :title="$t('SaleDetail') || 'Details'">
              <a-button type="text" size="small" @click="$router.push(`/sales/${record.id}`)"><EyeOutlined /></a-button>
            </a-tooltip>
            <a-tooltip :title="$t('tx_Reprint_receipt_6ebf2')">
              <a-button type="text" size="small" @click="reprint(record)"><PrinterOutlined /></a-button>
            </a-tooltip>
            <a-tooltip title="PDF">
              <a-button type="text" size="small" @click="http.download(`sale_pdf/${record.id}`, `Sale-${record.Ref}.pdf`)"><FilePdfOutlined /></a-button>
            </a-tooltip>
          </a-space>
        </template>
        <template v-else-if="column.key === 'date'">{{ dateTime(record.date) }}</template>
        <template v-else-if="column.key === 'Ref'">
          <a @click="$router.push(`/sales/${record.id}`)">{{ record.Ref }}</a>
        </template>
        <template v-else-if="column.key === 'payment_methods'">
          <a-tag v-for="m in String(record.payment_methods || '').split(', ').filter(Boolean)" :key="m">{{ looseTranslation(m) || m }}</a-tag>
        </template>
        <template v-else-if="column.key === 'GrandTotal'"><b>{{ money(record.GrandTotal) }}</b></template>
        <template v-else-if="column.key === 'paid_amount'">{{ money(record.paid_amount) }}</template>
        <template v-else-if="column.key === 'due'">
          <span :style="{ color: Number(record.due) > 0 ? '#ff4d4f' : undefined }">{{ money(record.due) }}</span>
        </template>
        <template v-else-if="column.key === 'payment_status'">
          <a-tag :color="payStatusColor(record.payment_status)">{{ $t(record.payment_status) }}</a-tag>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
import { looseTranslation } from '../../i18n';
import { t as tEs } from '../../i18n/index.js';
import DatePresetButtons from '../../components/DatePresetButtons.vue';
import DateRangeFields from '../../components/DateRangeFields.vue';
import { startPreset } from '../../lib/datePresetDefault';
/**
 * POS Management -> POS Sales: the Sales list narrowed to sales rung up at
 * the POS (GET sales?is_pos=1). Same company / location / record-view
 * scoping as Sales; adds cashier + payment-method filters, per-method
 * totals, and a receipt reprint. Default range: Today.
 */
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSystemContext } from '../../lib/systemContext';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { presetOf, presetRange } from '../../lib/datePresets';
import {
  FilePdfOutlined, FileExcelOutlined, ShopOutlined, EyeOutlined, PrinterOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { payStatusColor } from '../../lib/statusColors';
import { exportExcel, exportPdf } from '../../lib/exporters';
import { sortByLabel } from '../../lib/filterOptions';
import { printPosReceipt } from '../../lib/printPosReceipt';
import http from '../../lib/http';

const { t } = useI18n();
const { isSystem } = useSystemContext();
const auth = useAuthStore();
const { money, number, dateTime } = useFormat();

const filters = ref({ date_from: '', date_to: '', warehouse_id: undefined, cashier_id: undefined, payment_method_id: undefined, client_id: undefined });

const filterParams = () => ({
  is_pos: 1,
  Ref: '', date: '', statut: '', payment_statut: '', shipping_status: '',
  date_from: String(crud?.search?.value || '').trim() ? '' : (filters.value.date_from || ''),
  date_to: String(crud?.search?.value || '').trim() ? '' : (filters.value.date_to || ''),
  warehouse_id: filters.value.warehouse_id || '',
  cashier_id: filters.value.cashier_id || '',
  payment_method_id: filters.value.payment_method_id || '',
  client_id: filters.value.client_id || '',
});

// eslint-disable-next-line prefer-const
let crud;
crud = useCrudTable('sales', { rowsKey: 'sales', params: filterParams, limit: 20, showQuickJumper: true });

// ---- date presets (default: the user's default date, else Today), same set as the Sales list ----
// DATE_PRESETS / presetRange: shared list (lib/datePresets.js).
const datePreset = ref(startPreset('today'));
function applyPreset(p) {
  const [from, to] = presetRange(p);
  filters.value.date_from = from ? from.format('YYYY-MM-DD') : '';
  filters.value.date_to = to ? to.format('YYYY-MM-DD') : '';
  crud.reload();
}
applyPreset(datePreset.value);

// Start date / to: the active range (empty for All / while searching);
// editing a date makes it a custom range.
const searching = computed(() => !!String(crud.search.value || '').trim());
const rangeFields = computed(() => (!searching.value && (filters.value.date_from || filters.value.date_to)
  ? [filters.value.date_from, filters.value.date_to]
  : null));
function onRangeEdit(range) {
  filters.value.date_from = range ? range[0] : '';
  filters.value.date_to = range ? range[1] : '';
  datePreset.value = presetOf(filters.value.date_from, filters.value.date_to);
  crud.reload();
}

// ---- totals ----
const statTiles = computed(() => {
  const s = crud.payload.value?.stats || {};
  const n = v => Number(v) || 0;
  const tiles = [
    { label: t('Sales'), value: n(s.count), color: '#6d28d9' },
    { label: t('Total'), value: money(n(s.total)), color: '#1677ff' },
  ];
  if (n(s.due) > 0) tiles.push({ label: t('Due'), value: money(n(s.due)), color: '#ff4d4f' });
  (s.by_method || []).forEach(m => tiles.push({ label: m.method, value: money(n(m.amount)), sub: `${m.count} payment(s)`, color: '#1f1f2c' }));
  return tiles;
});

// ---- filter options ----
const payload = computed(() => crud.payload.value || {});
const warehouseOptions = computed(() => sortByLabel((payload.value.warehouses || []).map(w => ({ value: w.id, label: w.name }))));
const cashierOptions = computed(() => sortByLabel((payload.value.cashiers || []).map(u => ({
  value: u.id, label: [u.firstname, u.lastname].filter(Boolean).join(' ') || u.username,
}))));
const methodOptions = computed(() => sortByLabel((payload.value.payment_methods || []).map(m => ({ value: m.id, label: looseTranslation(m.name) || m.name }))));
const customerOptions = computed(() => sortByLabel((payload.value.customers || []).map(c => ({ value: c.id, label: c.name }))));

// ---- table ----
const columns = computed(() => [
  { title: t('Action'), key: 'actions', width: 120, align: 'center', fixed: 'left' },
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => dateTime(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  // superadmin/AdminSystem: every company (2026-10-21) -- whose record it is.
  ...(isSystem.value ? [{ title: t('Company'), key: 'company_name', dataIndex: 'company_name', exportValue: r => r.company_name || '' }] : []),
  { title: tEs('Cashier', 'Cashier'), dataIndex: 'created_by', key: 'created_by' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name', sorter: true },
  { title: t('Name'), dataIndex: 'product_names', key: 'product_names', width: 240, ellipsis: { showTitle: true } },
  { title: t('Payment_Method') || 'Payment method', dataIndex: 'payment_methods', key: 'payment_methods', exportValue: r => r.payment_methods || '' },
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', sorter: true, align: 'right', exportValue: r => money(r.GrandTotal) },
  { title: t('Paid'), dataIndex: 'paid_amount', key: 'paid_amount', align: 'right', exportValue: r => money(r.paid_amount) },
  { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right', exportValue: r => money(r.due) },
  { title: t('PaymentStatus'), dataIndex: 'payment_status', key: 'payment_status', exportValue: r => r.payment_status },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name', sorter: true },
]);

function reprint(record) {
  printPosReceipt(record.id, { t, number, onError: () => message.error(t('InvalidData')) });
}

// ---- exports ----
const exporting = ref(null);
async function exportList(kind) {
  exporting.value = kind;
  try {
    const data = await http.get('sales', {
      page: 1, SortField: crud.sortField.value, SortType: crud.sortType.value,
      search: crud.search.value, limit: -1, ...filterParams(),
    });
    const rows = data.sales || [];
    const cols = columns.value.filter(c => c.key !== 'actions');
    if (kind === 'xlsx') await exportExcel('pos_sales', cols, rows);
    else await exportPdf(t('POS_Sales'), cols, rows);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    exporting.value = null;
  }
}
</script>

<style scoped>
.range-card :deep(.ant-card-body) { padding: 4px 12px; }
.range-row { display: flex; gap: 8px; font-size: 12px; line-height: 18px; }
.range-label { opacity: 0.6; min-width: 60px; }
.stat-card { height: 100%; }
.stat-label { font-size: 12px; opacity: 0.65; }
.stat-value { font-size: 20px; font-weight: 700; }
.stat-sub { font-size: 11px; opacity: 0.55; }
.filter-label { font-size: 12px; opacity: 0.7; margin-bottom: 4px; }
</style>
