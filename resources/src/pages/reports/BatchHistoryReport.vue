<template>
  <div class="page">
    <PageHeader
      :title="`${tf('Batch_History', 'Batch History')}: ${batch.batch_no || '—'}`"
      :breadcrumb="[$t('Reports'), tf('Batch_History', 'Batch History')]"
    >
      <template #actions>
        <!-- Multi-Currency: view amounts converted (display-only) -->
        <ViewCurrencySelect />
        <a-button @click="$router.push('/reports/batch-register')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ tf('back', 'Back') }}
        </a-button>
        <a-button @click="print">
          <template #icon><PrinterOutlined /></template>
          {{ $t('print') }}
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <!-- Batch summary -->
      <a-card size="small" style="margin-bottom: 16px">
        <a-row :gutter="24">
          <a-col :xs="24" :md="12">
            <a-descriptions :column="1" size="small">
              <a-descriptions-item :label="$t('Product')">
                {{ batch.product_name }}
                <span v-if="batch.product_code" class="muted">[{{ batch.product_code }}]</span>
                <div v-if="batch.generic_name" class="muted">
                  {{ batch.generic_name }}
                  <span v-if="batch.strength"> · {{ batch.strength }}</span>
                  <span v-if="batch.dosage_form"> · {{ batch.dosage_form }}</span>
                </div>
                <a-tag v-if="batch.variant_name" color="purple">{{ batch.variant_name }}</a-tag>
              </a-descriptions-item>
              <a-descriptions-item :label="$t('Warehouse')">{{ batch.warehouse_name }}</a-descriptions-item>
              <a-descriptions-item :label="$t('Status')">
                <a-tag :color="STATUS_COLOR[batch.status] || 'default'">
                  {{ tf(`Batch_Status_${batch.status}`, batch.status) }}
                </a-tag>
              </a-descriptions-item>
            </a-descriptions>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-descriptions :column="1" size="small">
              <a-descriptions-item :label="$t('Mfg_Date')">{{ batch.mfg_date || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="$t('Expiry_Date')">
                <template v-if="batch.expiry_date">
                  <a-tag :color="EXPIRY_COLOR[batch.expiry_bucket] || 'default'">{{ batch.expiry_date }}</a-tag>
                  <span v-if="batch.expiry_bucket === 'expired'" style="color: #ff4d4f">
                    {{ $t('Expired') }} ({{ Math.abs(batch.days_to_expiry) }}d)
                  </span>
                  <span v-else-if="batch.expiry_bucket === 'near'" style="color: #faad14">
                    {{ $t('Expires_in') }} {{ batch.days_to_expiry }}d
                  </span>
                </template>
                <span v-else class="muted">—</span>
              </a-descriptions-item>
              <a-descriptions-item :label="tf('Current_Quantity', 'Current Qty')">
                <strong style="font-size: 18px; color: #6d28d9">{{ num(batch.qty) }}</strong>
              </a-descriptions-item>
            </a-descriptions>
            <div v-if="batch.notes" class="muted">{{ batch.notes }}</div>
          </a-col>
        </a-row>
      </a-card>

      <!-- Movements -->
      <a-card size="small">
        <template #title>
          {{ tf('Movements', 'Movements') }} <span class="muted">({{ filtered.length }})</span>
        </template>
        <template #extra>
          <a-select v-model:value="typeFilter" style="width: 200px" :options="filterOptions" />
        </template>

        <a-table
          :columns="columns" :data-source="filtered" :pagination="false"
          size="small" :row-key="(r, i) => i" :scroll="{ x: 'max-content' }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'type'">
              <a-tag :color="TYPE_COLOR[record.type] || 'default'">{{ typeLabel(record.type) }}</a-tag>
            </template>
            <template v-else-if="column.key === 'ref'">
              <router-link v-if="sourceLink(record)" :to="sourceLink(record)">{{ record.ref }}</router-link>
              <span v-else>{{ record.ref }}</span>
            </template>
            <template v-else-if="column.key === 'party_name'">
              <div>{{ record.party_name || '—' }}</div>
              <div class="muted">{{ record.party_label }}</div>
            </template>
            <template v-else-if="column.key === 'qty_in'">
              <span v-if="record.qty_in" style="color: #52c41a; font-weight: 600">+{{ num(record.qty_in) }}</span>
              <!-- A quotation only RESERVES stock, so legacy shows it in
                   parentheses and does not count it as an inbound movement. -->
              <span
                v-else-if="record.type === 'quotation' && record.reserved_qty" class="muted"
                :title="tf('Quotation_Reserved', 'Quotation reserved (not yet sold)')"
              >({{ num(record.reserved_qty) }})</span>
              <span v-else class="muted">—</span>
            </template>
            <template v-else-if="column.key === 'qty_out'">
              <span v-if="record.qty_out" style="color: #ff4d4f; font-weight: 600">-{{ num(record.qty_out) }}</span>
              <span v-else class="muted">—</span>
            </template>
            <template v-else-if="column.key === 'unit_value'">
              <span v-if="record.unit_value !== null">{{ money(record.unit_value) }}</span>
              <span v-else class="muted">—</span>
            </template>
            <template v-else-if="column.key === 'running_balance'">
              <strong>{{ num(record.running_balance) }}</strong>
            </template>
          </template>
          <template v-if="filtered.length" #summary>
            <a-table-summary-row>
              <a-table-summary-cell :index="0"><b>{{ $t('Total') }}</b></a-table-summary-cell>
              <a-table-summary-cell :index="1" />
              <a-table-summary-cell :index="2" />
              <a-table-summary-cell :index="3" />
              <a-table-summary-cell :index="4" align="right">
                <b style="color: #52c41a">+{{ num(filteredTotals.in) }}</b>
              </a-table-summary-cell>
              <a-table-summary-cell :index="5" align="right">
                <b style="color: #ff4d4f">-{{ num(filteredTotals.out) }}</b>
              </a-table-summary-cell>
              <a-table-summary-cell :index="6" />
              <a-table-summary-cell :index="7" />
            </a-table-summary-row>
          </template>
          <template #emptyText>
            <a-empty :description="tf('No_movements_recorded', 'No movements recorded for this batch yet.')" />
          </template>
        </a-table>

        <!-- Reconciliation -->
        <div class="recon">
          <div class="recon-title">{{ tf('Reconciliation', 'Reconciliation') }}</div>
          <a-row :gutter="16">
            <a-col :xs="12" :md="6">
              <a-statistic :title="tf('Total_In', 'Total In')" :value="`+${num(totals.in)}`" :value-style="{ color: '#52c41a' }" />
            </a-col>
            <a-col :xs="12" :md="6">
              <a-statistic :title="tf('Total_Out', 'Total Out')" :value="`-${num(totals.out)}`" :value-style="{ color: '#ff4d4f' }" />
            </a-col>
            <a-col :xs="12" :md="6">
              <a-statistic :title="tf('Computed_Qty', 'Computed Qty')" :value="num(totals.computed_qty)" />
            </a-col>
            <a-col :xs="12" :md="6">
              <a-statistic :title="tf('Actual_Qty', 'Actual Qty')" :value="num(totals.actual_qty)" />
            </a-col>
          </a-row>
          <a-alert
            style="margin-top: 12px"
            :type="hasDrift ? 'error' : 'success'"
            show-icon
            :message="hasDrift
              ? `${tf('Drift_Warning', 'Ledger drift detected')}: ${num(totals.drift)}`
              : tf('Ledger_Balanced', 'Ledger balanced — actual qty matches sum of transactions.')"
          />
        </div>
      </a-card>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Batch history — legacy Batch_History_Report.vue.
 * GET report/batches/{id}/history → {batch, transactions, totals}.
 *
 * Everything is client-side: the whole ledger arrives in one response, the type
 * filter never touches the API, and there is no pagination. The filter is
 * asymmetric on purpose — `in`/`out` match the row's `direction`, every other
 * value matches its `type` — and it drives the movement count and the print
 * output as well as the table.
 *
 * Quantities deliberately use the plain number formatter, not the money one:
 * legacy formatted them without thousands separators, and only Unit Value
 * carries a currency symbol.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ArrowLeftOutlined, PrinterOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';
import { useFormat } from '../../composables/useFormat';
import { t as tf } from '../../i18n';
import { printRows } from '../../lib/exporters';
import http from '../../lib/http';

const { t } = useI18n();
const { money } = useFormat();
const route = useRoute();

const loading = ref(true);
const batch = ref({});
const transactions = ref([]);
const totals = ref({ in: 0, out: 0, computed_qty: 0, actual_qty: 0, drift: 0 });
const typeFilter = ref('all');

const STATUS_COLOR = {
  active: 'success', quarantined: 'warning', expired: 'error', written_off: 'default',
};
const EXPIRY_COLOR = { expired: 'error', near: 'warning', valid: 'success' };
const TYPE_COLOR = {
  purchase: 'success', sale_return: 'processing', sale: 'error', purchase_return: 'warning',
  adjustment: 'blue', transfer_in: 'processing', transfer_out: 'default', damage: 'default',
  quotation: 'default',
};
const TYPE_LABEL = {
  purchase: ['Purchase', 'Purchase'],
  sale: ['Sale', 'Sale'],
  sale_return: ['Sales_Return', 'Sales Return'],
  purchase_return: ['Purchase_Return', 'Purchase Return'],
  adjustment: ['Adjustment', 'Adjustment'],
  transfer_in: ['Transfer_In', 'Transfer In'],
  transfer_out: ['Transfer_Out', 'Transfer Out'],
  damage: ['Damage', 'Damage'],
  quotation: ['Quotation', 'Quotation'],
};

function typeLabel(type) {
  const hit = TYPE_LABEL[type];
  return hit ? tf(hit[0], hit[1]) : type;
}

/** Legacy formatNumber: integers bare, non-integers to 2dp, no separators. */
function num(v) {
  if (v === null || v === undefined || v === '') return '0';
  const n = Number(v);
  if (Number.isNaN(n)) return '0';
  return Number.isInteger(n) ? String(n) : n.toFixed(2);
}

const filtered = computed(() => {
  if (typeFilter.value === 'all') return transactions.value;
  if (typeFilter.value === 'in') return transactions.value.filter(x => x.direction === 'in');
  if (typeFilter.value === 'out') return transactions.value.filter(x => x.direction === 'out');
  return transactions.value.filter(x => x.type === typeFilter.value);
});

const filteredTotals = computed(() => ({
  in: filtered.value.reduce((s, r) => s + (Number(r.qty_in) || 0), 0),
  out: filtered.value.reduce((s, r) => s + (Number(r.qty_out) || 0), 0),
}));

// Epsilon comparison, verbatim from legacy — not a !== 0 test.
const hasDrift = computed(() => Math.abs(Number(totals.value.drift) || 0) > 0.0001);

const filterOptions = computed(() => [
  { value: 'all', label: t('All') },
  { value: 'in', label: `↑ ${tf('In', 'In')}` },
  { value: 'out', label: `↓ ${tf('Out', 'Out')}` },
  ...Object.keys(TYPE_LABEL).map(k => ({ value: k, label: typeLabel(k) })),
]);

const columns = computed(() => [
  { title: tf('Type', 'Type'), dataIndex: 'type', key: 'type', exportValue: r => typeLabel(r.type) },
  { title: t('Date'), dataIndex: 'date', key: 'date', exportValue: r => r.date || '—' },
  { title: t('Reference'), dataIndex: 'ref', key: 'ref' },
  { title: tf('Party', 'Party'), dataIndex: 'party_name', key: 'party_name' },
  { title: tf('In', 'In'), dataIndex: 'qty_in', key: 'qty_in', align: 'right', exportValue: r => (r.qty_in ? `+${num(r.qty_in)}` : '—') },
  { title: tf('Out', 'Out'), dataIndex: 'qty_out', key: 'qty_out', align: 'right', exportValue: r => (r.qty_out ? `-${num(r.qty_out)}` : '—') },
  { title: tf('Unit_Value', 'Unit Value'), dataIndex: 'unit_value', key: 'unit_value', align: 'right', exportValue: r => (r.unit_value !== null ? money(r.unit_value) : '—') },
  { title: tf('Balance', 'Balance'), dataIndex: 'running_balance', key: 'running_balance', align: 'right', exportValue: r => num(r.running_balance) },
]);

/** Where a movement's reference points, by document type. */
function sourceLink(row) {
  if (!row?.ref_id) return null;
  const map = {
    purchase: `/purchases/${row.ref_id}`,
    sale: `/sales/${row.ref_id}`,
    sale_return: `/sale-returns/${row.ref_id}`,
    purchase_return: `/purchase-returns/${row.ref_id}`,
    adjustment: `/adjustments/${row.ref_id}`,
    transfer_in: `/transfers/${row.ref_id}`,
    transfer_out: `/transfers/${row.ref_id}`,
    damage: `/damages/${row.ref_id}/edit`,
    quotation: `/quotations/${row.ref_id}`,
  };
  return map[row.type] || null;
}

function print() {
  printRows(
    `${t('Reports')} / ${tf('Batch_History', 'Batch History')} — ${batch.value.batch_no || ''}`,
    columns.value,
    filtered.value,
    {
      subtitle: [
        `${t('Product')}: ${batch.value.product_name || ''}${batch.value.product_code ? ` [${batch.value.product_code}]` : ''}`,
        `${t('Warehouse')}: ${batch.value.warehouse_name || ''}`,
        `${t('Mfg_Date')}: ${batch.value.mfg_date || '—'}`,
        `${t('Expiry_Date')}: ${batch.value.expiry_date || '—'}`,
        `${tf('Current_Quantity', 'Current Qty')}: ${num(batch.value.qty)}`,
      ],
    }
  );
}

async function load() {
  const id = route.params.id;
  if (!id) return;
  loading.value = true;
  try {
    const data = await http.get(`report/batches/${id}/history`) || {};
    batch.value = data.batch || {};
    transactions.value = data.transactions || [];
    if (data.totals) totals.value = data.totals;
  } catch (e) { /* renders empty, as legacy did */ } finally {
    loading.value = false;
  }
}

watch(() => route.params.id, load);
onMounted(load);
</script>

<style scoped>
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
.recon {
  margin-top: 16px;
  padding: 16px;
  background: rgba(109, 40, 217, 0.04);
  border: 1px solid rgba(109, 40, 217, 0.15);
  border-radius: 8px;
}
.recon-title {
  font-weight: 600;
  margin-bottom: 12px;
}
</style>
