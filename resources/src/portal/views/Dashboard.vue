<template>
  <div>
    <div v-if="loading" class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading_dashboard') }}</div>
    </div>

    <template v-else>
      <div class="mb-3">
        <div class="h3 mb-0">{{ greeting }}, {{ firstName || d.client.name }}</div>
        <div class="text-secondary">{{ today }}</div>
      </div>

      <!-- ══ Headline figures ═══════════════════════════════════════ -->
      <div class="row row-deck row-cards mb-3">
        <div class="col-sm-6 col-xl-3">
          <StatCard :label="$t('amount_due')" :value="money(d.total_due)" icon="currency-dollar" :tone="Number(d.total_due) > 0 ? 'red' : 'primary'"
            :sub="dueSub" />
        </div>
        <div class="col-sm-6 col-xl-3">
          <StatCard :label="tr('invoiced_this_month', 'Invoiced this month')" :value="money(d.month.invoiced)" icon="receipt" tone="blue" :change="d.month.change_invoiced" :sub="d.month.label" />
        </div>
        <div class="col-sm-6 col-xl-3">
          <StatCard :label="tr('paid_this_month', 'Paid this month')" :value="money(d.month.paid)" icon="chart-bar" tone="teal" :change="d.month.change_paid" :sub="d.month.label" />
        </div>
        <div class="col-sm-6 col-xl-3">
          <StatCard :label="$t('total_invoices')" :value="number(d.total_invoices)" icon="file-invoice" tone="green"
            :sub="tr('average_invoice_sub', 'average {amount}', { amount: money(d.average_invoice) })" />
        </div>
      </div>

      <!-- ══ Needs attention ═════════════════════════════════════════ -->
      <div v-if="d.alerts.length" class="row row-cards mb-3">
        <div v-for="(alert, i) in d.alerts" :key="i" class="col-md-6 col-xl-4">
          <div class="card h-100" :class="`border-${alert.tone}`">
            <div class="card-body">
              <div class="d-flex align-items-start gap-2 mb-2">
                <i :class="`ti ti-${alert.icon} fs-2 text-${alert.tone}`"></i>
                <div class="fw-semibold">{{ alert.title }}</div>
              </div>
              <p class="text-secondary small mb-2">{{ alert.detail }}</p>
              <router-link :to="alert.url" class="btn btn-sm" :class="`btn-outline-${alert.tone}`">{{ alert.action }}</router-link>
            </div>
          </div>
        </div>
      </div>

      <div class="row row-cards mb-3">
        <!-- ══ The trend ══════════════════════════════════════════════ -->
        <div class="col-xl-8">
          <div class="card h-100">
            <div class="card-header">
              <div>
                <h3 class="card-title">{{ tr('invoiced_last_months', 'Invoiced, last {months} months', { months: d.by_month.length }) }}</h3>
                <p class="card-subtitle">{{ tr('invoiced_vs_paid', 'Invoiced against what was paid') }}</p>
              </div>
              <div class="card-actions text-end">
                <div class="h2 mb-0">{{ money(trendTotal) }}</div>
                <div class="text-secondary small">{{ tr('a_month_on_average', '{amount} a month on average', { amount: money(trendAverage) }) }}</div>
              </div>
            </div>
            <div class="card-body">
              <EmptyState v-if="trendTotal <= 0" icon="chart-line" :title="tr('no_invoices_in_window', 'No invoices in this window yet')" :subtitle="tr('trend_fills_in', 'The trend fills in as invoices are issued.')" />
              <RstChart v-else :height="300" :labels="d.by_month.map(m => m.label)" :series="[
                { label: tr('invoiced', 'Invoiced'), data: d.by_month.map(m => m.gross), color: 'accent' },
                { label: $t('paid'), data: d.by_month.map(m => m.paid), color: 'slate', fill: false, dashed: true, width: 2 },
              ]" :aria-label="tr('invoiced_last_months', 'Invoiced, last {months} months', { months: d.by_month.length })" />
            </div>
            <div class="card-footer d-flex flex-wrap gap-4 text-secondary small">
              <span>{{ tr('busiest_month', 'Busiest month') }} <strong class="text-reset">{{ busiestMonth }}</strong></span>
              <span>{{ $t('invoices') }} <strong class="text-reset">{{ number(d.total_invoices) }}</strong></span>
              <span>{{ $t('total_paid') }} <strong class="text-reset">{{ money(d.total_paid) }}</strong></span>
            </div>
          </div>
        </div>

        <!-- ══ Right now ══════════════════════════════════════════════ -->
        <div class="col-xl-4">
          <div class="card h-100">
            <div class="card-header"><h3 class="card-title">{{ tr('right_now', 'Right now') }}</h3></div>
            <div class="list-group list-group-flush">
              <router-link v-for="row in liveRows" :key="row.to" :to="row.to" class="list-group-item list-group-item-action d-flex align-items-center gap-3">
                <i :class="`ti ti-${row.icon} fs-2 text-secondary`"></i>
                <span class="flex-fill">{{ row.label }}</span>
                <span class="h3 mb-0">{{ row.value }}</span>
              </router-link>
            </div>
            <div class="card-footer">
              <div class="row g-3 text-center">
                <div class="col-6">
                  <div class="text-secondary small">{{ $t('opening_balance') }}</div>
                  <div class="h3 mb-0">{{ money(d.opening_balance) }}</div>
                </div>
                <div class="col-6">
                  <div class="text-secondary small">{{ tr('last_payment', 'Last payment') }}</div>
                  <div class="h3 mb-0">{{ d.last_payment ? money(d.last_payment.amount) : '—' }}</div>
                  <div v-if="d.last_payment" class="text-secondary small">{{ d.last_payment.date }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row row-cards mb-3">
        <!-- ══ Latest invoices ═══════════════════════════════════════ -->
        <div class="col-xl-8">
          <div class="card h-100">
            <div class="card-header">
              <h3 class="card-title">{{ $t('recent_invoices') }}</h3>
              <div class="card-actions">
                <router-link to="/invoices" class="btn btn-sm btn-ghost-secondary">{{ $t('view_all') }}</router-link>
              </div>
            </div>
            <div v-if="!d.recent_invoices.length" class="card-body">
              <EmptyState icon="receipt-off" :title="$t('no_invoices_yet')" />
            </div>
            <div v-else class="table-responsive">
              <table class="table table-vcenter card-table">
                <thead>
                  <tr>
                    <th>{{ $t('invoice') }}</th>
                    <th>{{ $t('date') }}</th>
                    <th>{{ $t('status') }}</th>
                    <th class="text-end">{{ $t('total') }}</th>
                    <th class="text-end">{{ $t('due') }}</th>
                    <th class="w-1"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="inv in d.recent_invoices" :key="inv.id">
                    <td>
                      <router-link :to="`/invoices/${inv.id}`" class="fw-semibold text-reset text-decoration-none font-monospace">{{ inv.Ref }}</router-link>
                    </td>
                    <td class="text-secondary">{{ inv.date }}</td>
                    <td><span :class="badge(invoiceTone(inv.payment_status))">{{ statusLabel(inv.payment_status) }}</span></td>
                    <td class="text-end font-monospace">{{ money(inv.GrandTotal) }}</td>
                    <td class="text-end font-monospace" :class="Number(inv.due) > 0 ? 'text-danger' : 'text-secondary'">{{ money(inv.due) }}</td>
                    <td class="text-end">
                      <div class="rst-row-actions justify-content-end">
                        <router-link :to="`/invoices/${inv.id}`" :title="$t('view')" class="text-secondary"><i class="ti ti-eye"></i></router-link>
                        <a :href="`/api/portal/invoices/${inv.id}/pdf`" target="_blank" rel="noopener" :title="$t('pdf')" class="text-primary"><i class="ti ti-download"></i></a>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- ══ Payment mix ═══════════════════════════════════════════ -->
        <div class="col-xl-4">
          <div class="card h-100">
            <div class="card-header">
              <div>
                <h3 class="card-title">{{ tr('payment_mix', 'Payment mix') }}</h3>
                <p class="card-subtitle">{{ tr('all_time', 'All time') }}</p>
              </div>
            </div>
            <div class="card-body">
              <EmptyState v-if="!d.by_method.length" icon="credit-card-off" :title="$t('no_payments_yet')" />
              <template v-else>
                <div class="rst-donut mb-3">
                  <RstChart type="doughnut" :height="180" :legend="false" :total="methodTotal"
                    :labels="d.by_method.map(m => m.label)"
                    :series="[{ data: d.by_method.map(m => m.total), colors: slots.slice(0, d.by_method.length) }]"
                    :aria-label="tr('payment_mix', 'Payment mix')" />
                  <div class="rst-donut-centre">
                    <div class="text-secondary small">{{ $t('paid') }}</div>
                    <div class="h3 mb-0">{{ money(methodTotal) }}</div>
                  </div>
                </div>
                <ul class="rst-legend">
                  <li v-for="(row, i) in d.by_method" :key="row.label">
                    <span class="rst-swatch" :style="{ background: `var(--rst-${slots[i]})` }"></span>
                    <span class="rst-legend-label">{{ row.label }}</span>
                    <span class="rst-legend-value">{{ money(row.total) }}</span>
                    <span class="badge bg-secondary-lt">{{ row.share }}%</span>
                  </li>
                </ul>
              </template>
            </div>
          </div>
        </div>
      </div>

      <div class="row row-cards">
        <!-- ══ What you buy most ═════════════════════════════════════ -->
        <div class="col-xl-7">
          <div class="card h-100">
            <div class="card-header">
              <div>
                <h3 class="card-title mb-0">{{ tr('top_purchases', 'What you buy most') }}</h3>
                <p class="card-subtitle mb-0">{{ tr('by_revenue', 'By amount') }}</p>
              </div>
            </div>
            <div v-if="!d.top_products.length" class="card-body">
              <EmptyState icon="shopping-bag" :title="tr('nothing_purchased_yet', 'Nothing purchased yet')" />
            </div>
            <div v-else class="table-responsive">
              <table class="table table-vcenter card-table">
                <thead>
                  <tr>
                    <th>{{ $t('product') }}</th>
                    <th class="text-end">{{ $t('qty') }}</th>
                    <th class="text-end">{{ $t('amount') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in d.top_products" :key="item.name">
                    <td>
                      {{ item.name }}
                      <div class="rst-meter"><span :style="{ width: item.share + '%' }"></span></div>
                    </td>
                    <td class="text-end font-monospace">{{ number(item.qty) }}</td>
                    <td class="text-end font-monospace">{{ money(item.revenue) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- ══ Account summary ═══════════════════════════════════════ -->
        <div class="col-xl-5">
          <div class="card h-100">
            <div class="card-header">
              <div>
                <h3 class="card-title">{{ tr('account_summary', 'Account summary') }}</h3>
                <p class="card-subtitle">{{ tr('all_time', 'All time') }}</p>
              </div>
              <div class="card-actions">
                <router-link to="/statement" class="btn btn-sm btn-ghost-secondary">{{ $t('nav_statement') }}</router-link>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table table-sm table-vcenter card-table">
                <tbody>
                  <tr v-for="line in summaryLines" :key="line.label">
                    <td class="text-secondary">{{ line.label }}</td>
                    <td class="text-end font-monospace" :class="{ 'fw-bold': line.strong, 'text-danger': line.danger }">{{ line.value }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script>
import http from '../lib/http';
import { money, number, badge, invoiceTone } from '../lib/ui';
import { i18n } from '../i18n';
import StatCard from '../components/StatCard.vue';
import EmptyState from '../components/EmptyState.vue';
import RstChart from '../components/RstChart.vue';

const EMPTY = {
  client: { name: '' }, total_invoices: 0, total_amount: 0, total_paid: 0, sales_due: 0, opening_balance: 0, total_due: 0,
  unpaid_count: 0, average_invoice: 0, last_payment: null,
  month: { invoices: 0, invoiced: 0, paid: 0, due: 0, average: 0, change_invoiced: null, change_paid: null, label: '' },
  by_month: [], by_method: [], top_products: [], live: { open_invoices: 0, pending_quotations: 0, upcoming_appointments: 0, active_contracts: 0 },
  alerts: [], recent_invoices: [],
};

export default {
  components: { StatCard, EmptyState, RstChart },
  data() {
    return { loading: true, d: { ...EMPTY }, slots: ['s1', 's2', 's3', 's4', 's5', 's6'] };
  },
  computed: {
    greeting() {
      const h = new Date().getHours();
      if (h < 12) return this.$t('good_morning');
      if (h < 18) return this.$t('good_afternoon');
      return this.$t('good_evening');
    },
    today() {
      try {
        return new Date().toLocaleDateString(i18n.global.locale.value, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
      } catch (_) { return new Date().toDateString(); }
    },
    firstName() { return this.d.client && this.d.client.name ? this.d.client.name.split(/\s+/)[0] : ''; },
    dueSub() {
      const d = this.d;
      if (!(Number(d.total_due) > 0)) return this.tr('nothing_outstanding', 'Nothing outstanding');
      if (Number(d.opening_balance) > 0) return this.$t('due_breakdown', { sales: money(d.sales_due), opening: money(d.opening_balance) });
      return this.tr('unpaid_invoices_count', `${d.unpaid_count} unpaid invoice(s)`, { count: d.unpaid_count });
    },
    trendTotal() { return this.d.by_month.reduce((s, m) => s + (Number(m.gross) || 0), 0); },
    trendAverage() { return this.d.by_month.length ? this.trendTotal / this.d.by_month.length : 0; },
    busiestMonth() {
      const best = [...this.d.by_month].sort((a, b) => b.gross - a.gross)[0];
      return best && best.gross > 0 ? best.label : '—';
    },
    methodTotal() { return this.d.by_method.reduce((s, m) => s + (Number(m.total) || 0), 0); },
    liveRows() {
      const l = this.d.live;
      return [
        { label: this.tr('open_invoices', 'Unpaid invoices'), value: number(l.open_invoices), icon: 'file-invoice', to: '/invoices' },
        { label: this.tr('open_quotations', 'Quotations awaiting'), value: number(l.pending_quotations), icon: 'file-description', to: '/quotations' },
        { label: this.tr('upcoming_appointments', 'Upcoming appointments'), value: number(l.upcoming_appointments), icon: 'calendar-event', to: '/appointments' },
        { label: this.tr('active_contracts', 'Active contracts'), value: number(l.active_contracts), icon: 'file-certificate', to: '/contracts' },
      ];
    },
    summaryLines() {
      const d = this.d;
      return [
        { label: this.tr('lifetime_invoiced', 'Invoiced'), value: money(d.total_amount), strong: true },
        { label: this.$t('total_paid'), value: money(d.total_paid) },
        { label: this.$t('opening_balance'), value: money(d.opening_balance) },
        { label: this.$t('amount_due'), value: money(d.total_due), strong: true, danger: Number(d.total_due) > 0 },
        { label: this.$t('total_invoices'), value: number(d.total_invoices) },
        { label: this.tr('average_invoice', 'Average invoice'), value: money(d.average_invoice) },
        { label: this.tr('invoiced_this_month', 'Invoiced this month'), value: money(d.month.invoiced) },
        { label: this.tr('paid_this_month', 'Paid this month'), value: money(d.month.paid) },
      ];
    },
  },
  mounted() { this.fetch(); },
  methods: {
    money, number, badge, invoiceTone,
    pageMeta() {
      return { title: this.$t('nav_home'), pretitle: this.$t('client_portal'), crumbs: [] };
    },
    async fetch() {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/dashboard');
        this.d = { ...EMPTY, ...data, month: { ...EMPTY.month, ...(data.month || {}) }, live: { ...EMPTY.live, ...(data.live || {}) } };
      } catch (_) {
        this.d = { ...EMPTY };
      }
      this.loading = false;
    },
  },
};
</script>
