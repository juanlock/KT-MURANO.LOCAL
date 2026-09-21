<template>
  <div class="page">
    <PageHeader :title="$t('Customer_Ledger')" :breadcrumb="[$t('Customers'), $t('Customer_Ledger')]">
      <template #actions>
        <a-button @click="$router.push('/customers')">
          <template #icon><LeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <a-button type="primary" :loading="exporting" @click="exportPdf">
          <template #icon><FilePdfOutlined /></template>
          {{ exporting ? $t('Generating') : $t('Download_PDF') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- Customer profile banner -->
      <a-card :bordered="false" class="ledger-hero" style="margin-bottom: 16px">
        <div class="hero-main">
          <a-avatar :size="60" class="hero-avatar">{{ initials }}</a-avatar>
          <div class="hero-info">
            <div class="hero-name-row">
              <span class="hero-name">{{ client.name || '-' }}</span>
              <a-tag v-if="client.code" color="blue" class="hero-code">#{{ client.code }}</a-tag>
            </div>
            <div class="hero-meta">
              <span v-if="client.email" class="meta-chip"><MailOutlined /> {{ client.email }}</span>
              <span v-if="client.phone" class="meta-chip"><PhoneOutlined /> {{ client.phone }}</span>
              <span v-if="cityCountry" class="meta-chip"><EnvironmentOutlined /> {{ cityCountry }}</span>
              <span v-if="client.tax_number" class="meta-chip"><IdcardOutlined /> {{ client.tax_number }}</span>
            </div>
          </div>
        </div>
      </a-card>

      <!-- KPI tiles -->
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="kpi in kpis" :key="kpi.label" :xs="12" :sm="8" :md="6" :xl="6">
          <a-card :bordered="false" class="kpi-card">
            <div class="kpi-inner">
              <div class="kpi-icon" :style="{ background: kpi.tint, color: kpi.color }">
                <component :is="kpi.icon" />
              </div>
              <div class="kpi-text">
                <div class="kpi-label">{{ $t(kpi.label) }}</div>
                <div class="kpi-value" :style="kpi.style">{{ kpi.text }}</div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-tabs v-model:activeKey="tab">
        <a-tab-pane v-for="pane in panes" :key="pane.key" :tab="$t(pane.label)">
          <a-card size="small">
            <div class="toolbar">
              <a-input-search
                v-model:value="pane.state.search"
                :placeholder="$t(pane.placeholder)"
                allow-clear style="max-width: 300px"
                @search="() => reload(pane)"
              />
              <a-space>
                <span class="muted">{{ $t('Per_page') }}</span>
                <a-select
                  v-model:value="pane.state.limit" style="width: 90px"
                  :options="[10, 25, 50, 100].map(v => ({ value: v, label: String(v) }))"
                  @change="() => reload(pane)"
                />
                <a-button size="small" @click="() => resetPane(pane)">{{ $t('Reset') }}</a-button>
              </a-space>
            </div>

            <a-table
              :columns="pane.columns" :data-source="pane.state.rows" :loading="pane.state.loading"
              :pagination="{ current: pane.state.page, pageSize: pane.state.limit, total: pane.state.total, showSizeChanger: false }"
              size="small" :row-key="r => r.id ?? r.Ref" :scroll="{ x: 'max-content' }"
              @change="p => { pane.state.page = p.current; fetchPane(pane); }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
                <template v-else-if="column.key === 'statut'">
                  <a-tag :color="docStatusColor(record.statut)">{{ record.statut }}</a-tag>
                </template>
                <template v-else-if="column.key === 'payment_status'">
                  <a-tag :color="paymentStatusColor(record.payment_status)">{{ record.payment_status }}</a-tag>
                </template>
                <template v-else-if="column.key === 'payment_type'">
                  <a-tag :color="record.payment_type === 'opening_balance' ? 'processing' : 'success'">
                    {{ record.payment_type === 'opening_balance' ? $t('Opening_Balance') : $t('Sale') }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'Sale_Ref'">
                  <span v-if="record.Sale_Ref">{{ record.Sale_Ref }}</span>
                  <span v-else class="muted">-</span>
                </template>
              </template>
              <template #emptyText>
                <a-empty :description="$t('NodataAvailable')" style="padding: 24px 0" />
              </template>
            </a-table>

            <div class="page-totals">{{ pageTotalsText(pane) }}</div>
          </a-card>
        </a-tab-pane>
      </a-tabs>
    </template>
  </div>
</template>

<script setup>
/**
 * Customer ledger — legacy CustomerLedger.vue. Header + eight KPI cards from
 * GET clients/{id}/brief, four independently paginated tabs (sales / payments /
 * quotations / returns), and a server-rendered PDF export.
 *
 * The KPI figures are all server-computed; netBalance is
 * opening_balance + sale_due - return_due. The per-tab totals line under each
 * table is deliberately page-scoped — legacy labels it "page totals" and that
 * is what it means.
 *
 * BUG FIXED: the Returns tab read `returns_customer` from a payload whose key
 * is `returns`, so it has always rendered empty while its pager showed real
 * page counts. It reads `returns` now (falling back to the old key in case a
 * deployment differs). Search also resets to page 1 — legacy kept the current
 * page, so searching from page 3 showed nothing.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  LeftOutlined, FilePdfOutlined, MailOutlined, PhoneOutlined, EnvironmentOutlined, IdcardOutlined,
  ShoppingOutlined, CheckCircleOutlined, CalculatorOutlined, ExclamationCircleOutlined,
  WalletOutlined, RollbackOutlined, DollarOutlined, CreditCardOutlined,
  PayCircleOutlined, StarOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { money } = useFormat();
const route = useRoute();
const id = route.params.id;

const loading = ref(true);
const exporting = ref(false);
const tab = ref('sales');
const client = ref({});

const MONEY_KEYS = ['GrandTotal', 'paid_amount', 'due', 'montant'];

const RED = { color: '#ff4d4f' };
const GREEN = { color: '#52c41a' };

// Accent colours + matching soft tints for the KPI icon chips.
const C = {
  blue: ['#1677ff', 'rgba(22, 119, 255, 0.12)'],
  green: ['#52c41a', 'rgba(82, 196, 26, 0.12)'],
  purple: ['#722ed1', 'rgba(114, 46, 209, 0.12)'],
  red: ['#ff4d4f', 'rgba(255, 77, 79, 0.12)'],
  indigo: ['#2f54eb', 'rgba(47, 84, 235, 0.12)'],
  amber: ['#faad14', 'rgba(250, 173, 20, 0.14)'],
  teal: ['#13c2c2', 'rgba(19, 194, 194, 0.12)'],
};

const initials = computed(() =>
  (client.value.name || '?')
    .split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?'
);
const cityCountry = computed(() =>
  [client.value.city, client.value.country].filter(Boolean).join(', ')
);

const kpis = computed(() => {
  const c = client.value;
  const n = k => Number(c[k]) || 0;
  const sign = v => (v > 0 ? RED : GREEN);
  const tile = (label, text, color, extra = {}) => ({ label, text, icon: extra.icon, color: color[0], tint: color[1], style: extra.style });
  const tiles = [
    tile('Sales_Grand', money(n('salesGrand')), C.blue, { icon: ShoppingOutlined }),
    tile('Sales_Paid', money(n('salesPaid')), C.green, { icon: CheckCircleOutlined }),
    tile('Opening_Balance', money(n('opening_balance')), C.purple, { icon: CalculatorOutlined, style: sign(n('opening_balance')) }),
    tile('Sales_Due', money(n('sale_due')), C.red, { icon: ExclamationCircleOutlined, style: sign(n('sale_due')) }),
    tile('Total_Due', money(n('netBalance')), C.indigo, { icon: WalletOutlined, style: sign(n('netBalance')) }),
    tile('Returns_Due', money(n('return_due')), C.amber, { icon: RollbackOutlined, style: { color: '#faad14' } }),
    tile('Payments_Total', money(n('paymentsTotal')), C.teal, { icon: DollarOutlined }),
    tile('Credit_Limit', n('credit_limit') > 0 ? money(n('credit_limit')) : t('No_limit'), C.blue, { icon: CreditCardOutlined, style: { color: '#1677ff' } }),
  ];
  if (c.wallet_enabled) {
    tiles.push(tile('WalletBalance', money(n('wallet_balance')), C.green, { icon: PayCircleOutlined, style: { color: n('wallet_balance') < 0 ? '#ff4d4f' : '#52c41a' } }));
  }
  tiles.push(tile('Loyalty_Points', String(n('points')), C.amber, { icon: StarOutlined, style: { color: '#faad14' } }));
  return tiles;
});

function paymentStatusColor(status) {
  const s = String(status || '').toLowerCase().trim();
  // 'unpaid' contains 'paid', so it must be tested first.
  if (s.includes('unpaid')) return 'error';
  if (s.includes('partial')) return 'warning';
  if (s.includes('paid')) return 'success';
  return 'default';
}
function docStatusColor(st) {
  const s = String(st || '').toLowerCase();
  if (s.includes('completed') || s.includes('approved')) return 'success';
  if (s.includes('pending')) return 'warning';
  if (s.includes('sent')) return 'processing';
  if (s.includes('canceled') || s.includes('cancelled') || s.includes('rejected')) return 'error';
  return 'default';
}

// ---------------- tabs ----------------

const makeState = () => reactive({ rows: [], total: 0, page: 1, limit: 10, search: '', loading: false });
const states = {
  sales: makeState(), payments: makeState(), quotations: makeState(), returns: makeState(),
};

const col = (label, key, opts = {}) => ({ title: t(label), dataIndex: key, key, ...opts });

const panes = computed(() => [
  {
    key: 'sales', label: 'Sales', placeholder: 'Search_sales_ph',
    endpoint: 'sales_client', rowsKeys: ['sales'], state: states.sales,
    totals: ['GrandTotal', 'paid_amount', 'due'],
    columns: [
      col('Date', 'date'), col('Sale_Ref', 'Ref'), col('Warehouse', 'warehouse_name'),
      col('Status', 'statut'),
      col('Grand_Total', 'GrandTotal', { align: 'right' }),
      col('Paid', 'paid_amount', { align: 'right' }),
      col('Due', 'due', { align: 'right' }),
      col('Payment_Status', 'payment_status'),
      col('Shipping_Status', 'shipping_status'),
    ],
  },
  {
    key: 'payments', label: 'Payments', placeholder: 'Search_payments_ph',
    endpoint: 'payments_client', rowsKeys: ['payments'], state: states.payments,
    totals: ['montant'],
    columns: [
      col('Date', 'date'), col('Payment_Ref', 'Ref'), col('Type', 'payment_type'),
      col('Sale_Ref', 'Sale_Ref'), col('Method', 'payment_method'),
      col('Amount', 'montant', { align: 'right' }),
    ],
  },
  {
    key: 'quotations', label: 'Quotations', placeholder: 'Search_quotations_ph',
    endpoint: 'quotations_client', rowsKeys: ['quotations'], state: states.quotations,
    totals: ['GrandTotal'],
    columns: [
      col('Date', 'date'), col('Quotation_Ref', 'Ref'), col('Warehouse', 'warehouse_name'),
      col('Status', 'statut'), col('Grand_Total', 'GrandTotal', { align: 'right' }),
    ],
  },
  {
    key: 'returns', label: 'Returns', placeholder: 'Search_returns_ph',
    // The controller answers `returns`; legacy read `returns_customer`.
    endpoint: 'returns_client', rowsKeys: ['returns', 'returns_customer'], state: states.returns,
    totals: ['GrandTotal', 'paid_amount', 'due'],
    columns: [
      col('Return_Ref', 'Ref'), col('Status', 'statut'), col('Customer', 'client_name'),
      col('Sale_Ref', 'sale_ref'), col('Warehouse', 'warehouse_name'),
      col('Grand_Total', 'GrandTotal', { align: 'right' }),
      col('Paid', 'paid_amount', { align: 'right' }),
      col('Due', 'due', { align: 'right' }),
      col('Payment_Status', 'payment_status'),
    ],
  },
]);

function pageTotalsText(pane) {
  const sum = k => pane.state.rows.reduce((acc, r) => acc + (Number(r[k]) || 0), 0);
  if (pane.totals.length === 1) {
    const label = pane.key === 'payments' ? t('Payments') : t('Grand');
    return `${t('Page_total')} — ${label}: ${money(sum(pane.totals[0]))}`;
  }
  return `${t('Page_totals')} — ${t('Grand')}: ${money(sum('GrandTotal'))}, `
    + `${t('Paid')}: ${money(sum('paid_amount'))}, ${t('Due')}: ${money(sum('due'))}`;
}

async function fetchPane(pane) {
  const s = pane.state;
  s.loading = true;
  try {
    const data = await http.get(pane.endpoint, {
      id, page: s.page, limit: s.limit, search: s.search,
    });
    const key = pane.rowsKeys.find(k => Array.isArray(data?.[k]));
    s.rows = key ? data[key] : [];
    s.total = Number(data?.totalRows) || 0;
  } catch (e) {
    s.rows = [];
  } finally {
    s.loading = false;
  }
}
function reload(pane) {
  pane.state.page = 1;
  fetchPane(pane);
}
function resetPane(pane) {
  pane.state.search = '';
  pane.state.page = 1;
  fetchPane(pane);
}

async function exportPdf() {
  exporting.value = true;
  try {
    await http.download(`client_ledger_pdf?id=${id}`, `customer_ledger_${id}.pdf`);
  } catch (e) {
    message.error(t('Failed_to_export'));
  } finally {
    exporting.value = false;
  }
}

onMounted(async () => {
  try {
    const brief = await http.get(`clients/${id}/brief`);
    client.value = brief || {};
  } catch (e) { /* header renders with dashes, as legacy did */ }
  panes.value.forEach(fetchPane);
  loading.value = false;
});
</script>

<style scoped>
/* Customer profile banner ---------------------------------------------- */
.ledger-hero {
  border: 1px solid rgba(22, 119, 255, 0.18);
  background: linear-gradient(120deg, rgba(22, 119, 255, 0.08), rgba(114, 46, 209, 0.05));
}
.hero-main {
  display: flex;
  align-items: center;
  gap: 18px;
}
.hero-avatar {
  flex: 0 0 auto;
  background: #1677ff;
  color: #fff;
  font-size: 22px;
  font-weight: 600;
}
.hero-info { min-width: 0; }
.hero-name-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.hero-name {
  font-size: 22px;
  font-weight: 700;
  line-height: 1.2;
}
.hero-code { margin: 0; }
.hero-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 18px;
  margin-top: 8px;
}
.meta-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: rgba(0, 0, 0, 0.6);
}
.meta-chip :deep(svg) { color: rgba(0, 0, 0, 0.35); }

/* KPI tiles ------------------------------------------------------------- */
.kpi-card {
  border: 1px solid #f0f0f0;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
  height: 100%;
}
.kpi-inner {
  display: flex;
  align-items: center;
  gap: 12px;
}
.kpi-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex: 0 0 auto;
}
.kpi-text {
  min-width: 0;
  flex: 1 1 auto;
}
.kpi-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  line-height: 1.3;
  margin-bottom: 3px;
  /* allow up to two lines rather than clipping the label */
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.kpi-value {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

@media (max-width: 575px) {
  .hero-main { gap: 12px; }
  .hero-name { font-size: 18px; }
  .kpi-value { font-size: 16px; }
}

.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
.page-totals {
  margin-top: 12px;
  text-align: right;
  font-size: 13px;
}
</style>
