<template>
  <a-drawer
    :open="open"
    :width="600"
    :title="null"
    :closable="false"
    :body-style="{ padding: '20px 20px 28px' }"
    root-class-name="ts-drawer"
    @close="$emit('close')"
  >
    <!-- ============ Head ============ -->
    <div class="ts-head">
      <div>
        <div class="ts-eyebrow">{{ $t('Day_Ledger') }}</div>
        <div class="ts-title">{{ $t('Todays_Summary') }}</div>
        <div class="ts-date">{{ date(summary.date || new Date()) }}</div>
      </div>
      <a-space :size="2">
        <a-tooltip :title="$t('Refresh')">
          <a-button type="text" @click="load">
            <template #icon><SyncOutlined :spin="loading" /></template>
          </a-button>
        </a-tooltip>
        <a-button type="text" @click="$emit('close')">
          <template #icon><CloseOutlined /></template>
        </a-button>
      </a-space>
    </div>

    <div v-if="loading && !loaded" class="ts-loading"><a-spin size="large" /></div>

    <div v-else class="ts-body" :class="{ 'ts-body--in': loaded }">
      <!-- ============ Hero: the day's headline ============ -->
      <div class="ts-hero">
        <div class="ts-hero-top">
          <div>
            <div class="ts-hero-label">{{ $t('Net_Sales') }}</div>
            <div class="ts-hero-value">{{ money(sales.net || 0) }}</div>
          </div>
          <div class="ts-hero-chips">
            <span class="ts-chip">{{ sales.transactions || 0 }} {{ $t('Sales') }}</span>
            <span class="ts-chip">{{ num(sales.items).toFixed(0) }} {{ $t('Items') }}</span>
          </div>
        </div>

        <!-- Money flow: received vs given, one proportional bar. -->
        <div class="ts-flow">
          <div class="ts-flow-bar">
            <span class="ts-flow-in" :style="{ width: flowInPct + '%' }"></span>
            <span class="ts-flow-out" :style="{ width: (100 - flowInPct) + '%' }"></span>
          </div>
          <div class="ts-flow-legend">
            <span><i class="ts-dot ts-dot--in"></i>{{ $t('In') }} {{ money(pay.received_total || 0) }}</span>
            <span><i class="ts-dot ts-dot--out"></i>{{ $t('Out') }} {{ money(pay.given_total || 0) }}</span>
          </div>
        </div>
      </div>

      <!-- ============ Twin ledgers ============ -->
      <div class="ts-grid">
        <section class="ts-ledger ts-ledger--sales">
          <header class="ts-ledger-head">
            <span class="ts-ledger-icon"><ShoppingCartOutlined /></span>
            {{ $t('Sales') }}
          </header>
          <div v-for="r in salesRows" :key="r.label" class="ts-line">
            <span>{{ r.label }}</span><b>{{ r.value }}</b>
          </div>
          <div class="ts-net">
            <span>{{ $t('Net_Sales') }}</span><b>{{ money(num(sales.net)) }}</b>
          </div>
          <div class="ts-line ts-line--dim">
            <span>{{ $t('Returns') }}</span><b>{{ money(num(sales.returns)) }}</b>
          </div>
          <div class="ts-line ts-line--dim">
            <span>{{ $t('Average_Sale') }}</span><b>{{ money(num(sales.average)) }}</b>
          </div>
        </section>

        <section class="ts-ledger ts-ledger--purchases">
          <header class="ts-ledger-head">
            <span class="ts-ledger-icon"><ShoppingOutlined /></span>
            {{ $t('Purchases') }}
          </header>
          <div v-for="r in purchaseRows" :key="r.label" class="ts-line">
            <span>{{ r.label }}</span><b>{{ r.value }}</b>
          </div>
          <div class="ts-net">
            <span>{{ $t('Net_Purchase') }}</span><b>{{ money(num(purchases.net)) }}</b>
          </div>
          <div class="ts-line ts-line--dim">
            <span>{{ $t('Returns') }}</span><b>{{ money(num(purchases.returns)) }}</b>
          </div>
          <div class="ts-line ts-line--dim">
            <span>{{ $t('Average_Purchase') }}</span><b>{{ money(num(purchases.average)) }}</b>
          </div>
        </section>
      </div>

      <!-- ============ Profit ============ -->
      <section class="ts-ledger ts-ledger--profit">
        <header class="ts-ledger-head">
          <span class="ts-ledger-icon"><RiseOutlined /></span>
          {{ $t('Profit') }}
        </header>
        <div class="ts-line">
          <span>{{ $t('Cost_Of_Goods_Sold') }}</span><b>{{ money(num(profit.cogs)) }}</b>
        </div>
        <div class="ts-margin">
          <div class="ts-margin-top">
            <span>{{ $t('Gross_Profit') }}</span><b>{{ money(num(profit.gross)) }}</b>
          </div>
          <div class="ts-margin-track">
            <span class="ts-margin-fill" :style="{ width: clampPct(profit.gross_pct) + '%' }"></span>
          </div>
          <div class="ts-margin-pct">{{ num(profit.gross_pct) }}% {{ $t('Of_Sales') }}</div>
        </div>
        <div class="ts-margin">
          <div class="ts-margin-top">
            <span>{{ $t('Net_Profit') }}</span><b>{{ money(num(profit.net)) }}</b>
          </div>
          <div class="ts-margin-track">
            <span class="ts-margin-fill" :style="{ width: clampPct(profit.net_pct) + '%' }"></span>
          </div>
          <div class="ts-margin-pct">{{ num(profit.net_pct) }}% {{ $t('Of_Sales') }}</div>
        </div>
        <div class="ts-foot">
          <span>{{ $t('Tax_Collected') }} <b>{{ money(num(profit.tax_collected)) }}</b></span>
          <span>{{ $t('Tax_Paid') }} <b>{{ money(num(profit.tax_paid)) }}</b></span>
          <span>{{ $t('Expenses') }} <b>{{ money(num(profit.expenses)) }}</b></span>
        </div>
      </section>

      <!-- ============ Payments by method ============ -->
      <section class="ts-ledger ts-ledger--payments">
        <header class="ts-ledger-head">
          <span class="ts-ledger-icon"><WalletOutlined /></span>
          {{ $t('PaymentsByMethod') }}
        </header>
        <div v-if="payMethods.length" class="ts-paytable-wrap">
          <table class="ts-paytable">
            <thead>
              <tr>
                <th></th>
                <th v-for="m in payMethods" :key="m">{{ m }}</th>
                <th>{{ $t('Total') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><i class="ts-dot ts-dot--in"></i>{{ $t('Received') }}</td>
                <td v-for="m in payMethods" :key="m">{{ money(pay.received?.[m] || 0) }}</td>
                <td class="ts-strong">{{ money(pay.received_total || 0) }}</td>
              </tr>
              <tr>
                <td><i class="ts-dot ts-dot--out"></i>{{ $t('Given') }}</td>
                <td v-for="m in payMethods" :key="m">{{ money(pay.given?.[m] || 0) }}</td>
                <td class="ts-strong">{{ money(pay.given_total || 0) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="ts-empty">{{ $t('No_Payments_Today') }}</div>
      </section>

      <!-- ============ Stock ============ -->
      <section class="ts-ledger ts-ledger--stock">
        <header class="ts-ledger-head">
          <span class="ts-ledger-icon"><InboxOutlined /></span>
          {{ $t('Stock') }}
        </header>
        <div class="ts-line"><span>{{ $t('On_Hand_At_Cost') }}</span><b>{{ money(num(stock.at_cost)) }}</b></div>
        <div class="ts-line"><span>{{ $t('On_Hand_At_Retail') }}</span><b>{{ money(num(stock.at_retail)) }}</b></div>
        <div class="ts-line"><span>{{ $t('Potential_Margin') }}</span><b>{{ money(num(stock.margin)) }}</b></div>
        <div class="ts-line ts-line--dim"><span>{{ $t('Units_In_Stock') }}</span><b>{{ num(stock.units).toLocaleString() }}</b></div>
        <div class="ts-line ts-line--dim"><span>{{ $t('OutOfStock') }}</span><b>{{ num(stock.out_of_stock) }}</b></div>
      </section>

      <!-- ============ Pulse tiles ============ -->
      <div class="ts-tiles">
        <div class="ts-tile">
          <span class="ts-tile-icon" style="background: rgba(109, 40, 217, 0.1); color: #6d28d9"><UserAddOutlined /></span>
          <div>
            <div class="ts-tile-value">{{ tiles.new_customers || 0 }}</div>
            <div class="ts-tile-label">{{ $t('New_Customers') }}</div>
          </div>
        </div>
        <div class="ts-tile">
          <span class="ts-tile-icon" style="background: rgba(250, 173, 20, 0.14); color: #d48806"><WarningOutlined /></span>
          <div>
            <div class="ts-tile-value">{{ tiles.low_stock || 0 }}</div>
            <div class="ts-tile-label">{{ $t('Low_Stock') }}</div>
          </div>
        </div>
        <div class="ts-tile">
          <span class="ts-tile-icon" style="background: rgba(19, 194, 194, 0.12); color: #13c2c2"><TeamOutlined /></span>
          <div>
            <div class="ts-tile-value">{{ tiles.on_shift || 0 }}</div>
            <div class="ts-tile-label">{{ $t('On_Shift') }}</div>
          </div>
        </div>
      </div>
    </div>
  </a-drawer>
</template>

<script setup>
/**
 * "Today's summary" drawer — GET today_summary, opened from the topbar icon.
 * Designed as a day ledger: hero (net sales + money in/out flow bar), twin
 * sales/purchase ledgers with a receipt-style net row, profit with margin
 * bars, payments by method, stock, and three pulse tiles.
 */
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  SyncOutlined, CloseOutlined, ShoppingCartOutlined, ShoppingOutlined,
  RiseOutlined, WalletOutlined, InboxOutlined, UserAddOutlined,
  WarningOutlined, TeamOutlined,
} from '@ant-design/icons-vue';
import { useFormat } from '../composables/useFormat';
import http from '../lib/http';

const props = defineProps({ open: { type: Boolean, default: false } });
defineEmits(['close']);

const { money, date } = useFormat();
const { t } = useI18n();

const loading = ref(false);
const loaded = ref(false);
const summary = ref({});

const num = v => Number(v) || 0;
const clampPct = v => Math.max(0, Math.min(100, Number(v) || 0));

const sales = computed(() => summary.value.sales || {});
const purchases = computed(() => summary.value.purchases || {});
const stock = computed(() => summary.value.stock || {});
const pay = computed(() => summary.value.payments || {});
const payMethods = computed(() => pay.value.methods || []);
const profit = computed(() => summary.value.profit || {});
const tiles = computed(() => summary.value.tiles || {});

// Money-in share of the flow bar; 50/50 when nothing moved yet.
const flowInPct = computed(() => {
  const inflow = num(pay.value.received_total);
  const outflow = num(pay.value.given_total);
  const total = inflow + outflow;
  return total > 0 ? Math.round((inflow / total) * 100) : 50;
});

// The rows above the receipt-style net line.
const docRows = d => [
  { label: t('Gross'), value: money(num(d.gross)) },
  { label: t('Discount'), value: money(num(d.discounts)) },
  { label: t('Taxable'), value: money(num(d.taxable)) },
  { label: t('Tax'), value: money(num(d.tax)) },
];
const salesRows = computed(() => docRows(sales.value));
const purchaseRows = computed(() => docRows(purchases.value));

async function load() {
  loading.value = true;
  try {
    summary.value = await http.get('today_summary');
    loaded.value = true;
  } catch (e) { /* keep last data */ } finally {
    loading.value = false;
  }
}

watch(() => props.open, v => { if (v && !loaded.value) load(); });
</script>

<style scoped>
/* Palette: iris #6d28d9 (sales) · sky #1677ff (purchases) · teal #13c2c2
   (stock) · emerald #10b981 (in/profit) · rose #f43f5e (out) · mist #f7f7f9. */

.ts-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 16px;
}
.ts-eyebrow {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #6d28d9;
}
.ts-title {
  font-size: 20px;
  font-weight: 700;
  letter-spacing: -0.01em;
  line-height: 1.25;
}
.ts-date {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}
.ts-loading {
  display: flex;
  justify-content: center;
  padding: 96px 0;
}

/* Every figure in the ledger sits in tabular mono — digits align, reads like a till roll. */
.ts-body b,
.ts-hero-value,
.ts-paytable td,
.ts-tile-value {
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  font-variant-numeric: tabular-nums;
}

/* ---------------- Hero ---------------- */
.ts-hero {
  background: linear-gradient(135deg, rgba(109, 40, 217, 0.09), rgba(109, 40, 217, 0.02) 65%);
  border: 1px solid rgba(109, 40, 217, 0.18);
  border-radius: 14px;
  padding: 16px 18px;
  margin-bottom: 14px;
}
.ts-hero-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.ts-hero-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.5);
}
.ts-hero-value {
  font-size: 30px;
  font-weight: 700;
  letter-spacing: -0.02em;
  line-height: 1.15;
  color: #3b1a78;
}
.ts-hero-chips {
  display: flex;
  gap: 6px;
}
.ts-chip {
  font-size: 12px;
  padding: 3px 10px;
  border-radius: 999px;
  background: #fff;
  border: 1px solid rgba(109, 40, 217, 0.25);
  color: #6d28d9;
  white-space: nowrap;
}
.ts-flow { margin-top: 14px; }
.ts-flow-bar {
  display: flex;
  height: 8px;
  border-radius: 4px;
  overflow: hidden;
  background: rgba(0, 0, 0, 0.06);
}
.ts-flow-in {
  background: #10b981;
  transition: width 0.6s cubic-bezier(0.22, 1, 0.36, 1);
}
.ts-flow-out {
  background: #f43f5e;
  transition: width 0.6s cubic-bezier(0.22, 1, 0.36, 1);
}
.ts-flow-legend {
  display: flex;
  justify-content: space-between;
  margin-top: 6px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.6);
}
.ts-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  margin-right: 6px;
  vertical-align: 1px;
}
.ts-dot--in { background: #10b981; }
.ts-dot--out { background: #f43f5e; }

/* ---------------- Ledger sections ---------------- */
.ts-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
@media (max-width: 520px) {
  .ts-grid { grid-template-columns: 1fr; }
}
.ts-ledger {
  position: relative;
  border: 1px solid #ececee;
  border-radius: 12px;
  padding: 12px 14px 10px;
  background: #fff;
  overflow: hidden;
  margin-top: 12px;
}
.ts-grid .ts-ledger { margin-top: 0; }
/* Color rail: each section keeps its hue from the app's chart palette. */
.ts-ledger::before {
  content: '';
  position: absolute;
  inset: 0 auto 0 0;
  width: 3px;
}
.ts-ledger--sales::before { background: #6d28d9; }
.ts-ledger--purchases::before { background: #1677ff; }
.ts-ledger--profit::before { background: #10b981; }
.ts-ledger--payments::before { background: #f59e0b; }
.ts-ledger--stock::before { background: #13c2c2; }

.ts-ledger-head {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: rgba(0, 0, 0, 0.55);
  padding-bottom: 8px;
  border-bottom: 1px solid #f0f0f2;
  margin-bottom: 2px;
}
.ts-ledger-icon {
  display: inline-flex;
  font-size: 14px;
  color: inherit;
}

.ts-line {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  padding: 7px 0;
  font-size: 13px;
}
.ts-line span { color: rgba(0, 0, 0, 0.6); }
.ts-line b { font-weight: 500; }
.ts-line--dim span,
.ts-line--dim b { color: rgba(0, 0, 0, 0.4); font-weight: 400; }

/* Receipt-style total: dashed rule above, heavy figures. */
.ts-net {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 0;
  border-top: 1px dashed rgba(0, 0, 0, 0.25);
  font-size: 13px;
}
.ts-net span { font-weight: 600; }
.ts-net b { font-weight: 700; }

/* Profit margin bars */
.ts-margin { padding: 7px 0 2px; }
.ts-margin-top {
  display: flex;
  justify-content: space-between;
  font-size: 13px;
}
.ts-margin-top span { font-weight: 600; color: #0f766e; }
.ts-margin-top b { font-weight: 700; color: #10b981; }
.ts-margin-track {
  height: 5px;
  border-radius: 3px;
  background: rgba(16, 185, 129, 0.12);
  margin-top: 6px;
  overflow: hidden;
}
.ts-margin-fill {
  display: block;
  height: 100%;
  border-radius: 3px;
  background: #10b981;
  transition: width 0.6s cubic-bezier(0.22, 1, 0.36, 1);
}
.ts-margin-pct {
  font-size: 11px;
  color: rgba(0, 0, 0, 0.4);
  margin-top: 3px;
}
.ts-foot {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
  padding-top: 9px;
  margin-top: 6px;
  border-top: 1px solid #f0f0f2;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.5);
}
.ts-foot b { font-weight: 600; color: rgba(0, 0, 0, 0.75); }

/* Payments table */
.ts-paytable-wrap { overflow-x: auto; }
.ts-paytable {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.ts-paytable th {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: rgba(0, 0, 0, 0.4);
  text-align: right;
  padding: 8px 6px 6px;
}
.ts-paytable td {
  text-align: right;
  padding: 7px 6px;
  border-top: 1px solid #f5f5f7;
}
.ts-paytable td:first-child,
.ts-paytable th:first-child {
  text-align: left;
  font-family: inherit;
}
.ts-strong { font-weight: 700; }
.ts-empty {
  padding: 14px 0 8px;
  font-size: 13px;
  color: rgba(0, 0, 0, 0.4);
}

/* Pulse tiles */
.ts-tiles {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  margin-top: 12px;
}
@media (max-width: 520px) {
  .ts-tiles { grid-template-columns: 1fr; }
}
.ts-tile {
  display: flex;
  align-items: center;
  gap: 10px;
  border: 1px solid #ececee;
  border-radius: 12px;
  padding: 10px 12px;
  background: #fff;
}
.ts-tile-icon {
  width: 34px;
  height: 34px;
  border-radius: 9px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  flex: none;
}
.ts-tile-value {
  font-size: 17px;
  font-weight: 700;
  line-height: 1.1;
}
.ts-tile-label {
  font-size: 11px;
  color: rgba(0, 0, 0, 0.5);
}

/* ---------------- Entrance ---------------- */
.ts-body > * {
  opacity: 0;
  transform: translateY(6px);
  transition: opacity 0.35s ease, transform 0.35s ease;
}
.ts-body--in > * {
  opacity: 1;
  transform: none;
}
.ts-body--in > *:nth-child(1) { transition-delay: 0.02s; }
.ts-body--in > *:nth-child(2) { transition-delay: 0.08s; }
.ts-body--in > *:nth-child(3) { transition-delay: 0.14s; }
.ts-body--in > *:nth-child(4) { transition-delay: 0.2s; }
.ts-body--in > *:nth-child(5) { transition-delay: 0.26s; }
.ts-body--in > *:nth-child(6) { transition-delay: 0.32s; }

@media (prefers-reduced-motion: reduce) {
  .ts-body > *,
  .ts-flow-in,
  .ts-flow-out,
  .ts-margin-fill {
    transition: none !important;
    opacity: 1;
    transform: none;
  }
}

/* ---------------- Mobile ---------------- */
@media (max-width: 640px) {
  .ts-title { font-size: 18px; }
  .ts-hero { padding: 14px; }
  .ts-hero-value { font-size: 24px; }
  .ts-flow-legend {
    flex-direction: column;
    gap: 2px;
  }
  .ts-tiles { grid-template-columns: 1fr; }
  .ts-foot { gap: 8px; flex-direction: column; }
}
</style>

<!-- Drawer chrome lives outside this component's DOM (teleported to body),
     so the width override must be global — scoped by root-class-name. -->
<style>
@media (max-width: 640px) {
  .ts-drawer .ant-drawer-content-wrapper {
    width: 100% !important;
    max-width: 100vw;
  }
  .ts-drawer .ant-drawer-body {
    padding: 14px 12px 22px !important;
  }
}
</style>
