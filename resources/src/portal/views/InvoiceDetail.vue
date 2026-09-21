<template>
  <div>
    <PageActions>
      <router-link to="/invoices" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>{{ $t('back_to_invoices') }}</router-link>
      <a v-if="invoice" :href="`/api/portal/invoices/${invoice.id}/pdf`" target="_blank" rel="noopener" class="btn btn-primary">
        <i class="ti ti-download me-1"></i>{{ $t('download_pdf') }}
      </a>
    </PageActions>

    <div v-if="loading" class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading_invoice') }}</div>
    </div>

    <template v-else-if="invoice">
      <div class="row row-deck row-cards mb-3">
        <div class="col-sm-6 col-lg-3"><StatCard :label="$t('total')" :value="money(invoice.GrandTotal)" icon="receipt" tone="blue" :sub="invoice.date" /></div>
        <div class="col-sm-6 col-lg-3"><StatCard :label="$t('paid')" :value="money(invoice.paid_amount)" icon="check" tone="green" /></div>
        <div class="col-sm-6 col-lg-3"><StatCard :label="$t('due')" :value="money(invoice.due)" icon="alert-circle" :tone="Number(invoice.due) > 0 ? 'red' : 'teal'" /></div>
        <div class="col-sm-6 col-lg-3"><StatCard :label="$t('status')" :value="statusLabel(invoice.payment_status)" icon="tag" :tone="invoiceTone(invoice.payment_status)" :sub="invoice.warehouse_name || ''" /></div>
      </div>

      <div class="card">
        <div class="card-header">
          <div>
            <h3 class="card-title">{{ $t('items') }}</h3>
            <p class="card-subtitle">{{ $t('items_count', (invoice.details || []).length) }}</p>
          </div>
          <div class="card-actions"><span :class="badge(invoiceTone(invoice.payment_status))">{{ statusLabel(invoice.payment_status) }}</span></div>
        </div>
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead>
              <tr>
                <th>{{ $t('product') }}</th>
                <th class="text-end">{{ $t('qty') }}</th>
                <th class="text-end">{{ $t('price') }}</th>
                <th class="text-end">{{ $t('discount') }}</th>
                <th class="text-end">{{ $t('tax') }}</th>
                <th class="text-end">{{ $t('total') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(line, i) in invoice.details" :key="i">
                <td class="fw-medium">{{ line.product_name }}</td>
                <td class="text-end font-monospace">{{ line.quantity }}</td>
                <td class="text-end font-monospace">{{ money(line.price) }}</td>
                <td class="text-end font-monospace">{{ money(line.DiscountNet) }}</td>
                <td class="text-end font-monospace">{{ money(line.taxe) }}</td>
                <td class="text-end font-monospace fw-bold">{{ money(line.total) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="card-footer">
          <div class="row justify-content-end">
            <div class="col-md-5 col-lg-4">
              <table class="table table-sm table-borderless mb-0">
                <tbody>
                  <tr><td class="text-secondary">{{ $t('subtotal') }}</td><td class="text-end font-monospace">{{ money(invoice.subtotal != null ? invoice.subtotal : invoice.GrandTotal) }}</td></tr>
                  <tr v-if="Number(invoice.TaxNet) > 0"><td class="text-secondary">{{ $t('order_tax') }}</td><td class="text-end font-monospace">{{ money(invoice.TaxNet) }}</td></tr>
                  <tr v-if="Number(invoice.discount) > 0">
                    <td class="text-secondary">{{ $t('discount') }}</td>
                    <td class="text-end font-monospace text-danger">
                      <template v-if="String(invoice.discount_Method || '2') === '1'">− {{ Number(invoice.discount).toFixed(2) }}%</template>
                      <template v-else>− {{ money(invoice.discount) }}</template>
                    </td>
                  </tr>
                  <tr v-if="Number(invoice.discount_from_points) > 0"><td class="text-secondary">{{ $t('discount_from_points') }}</td><td class="text-end font-monospace text-danger">− {{ money(invoice.discount_from_points) }}</td></tr>
                  <tr v-if="Number(invoice.shipping) > 0"><td class="text-secondary">{{ $t('shipping') }}</td><td class="text-end font-monospace">{{ money(invoice.shipping) }}</td></tr>
                  <tr class="border-top"><td class="fw-bold">{{ $t('total') }}</td><td class="text-end font-monospace fw-bold">{{ money(invoice.GrandTotal) }}</td></tr>
                  <tr><td class="text-secondary">{{ $t('paid') }}</td><td class="text-end font-monospace text-success">{{ money(invoice.paid_amount) }}</td></tr>
                  <tr class="border-top"><td class="fw-bold">{{ $t('amount_due') }}</td><td class="text-end font-monospace fw-bold fs-3" :class="Number(invoice.due) > 0 ? 'text-danger' : ''">{{ money(invoice.due) }}</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </template>

    <div v-else class="card">
      <EmptyState icon="file-off" :title="$t('invoice_not_found')">
        <router-link to="/invoices" class="btn btn-primary">{{ $t('back_to_invoices') }}</router-link>
      </EmptyState>
    </div>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import { money, badge, invoiceTone } from '../lib/ui';
import StatCard from '../components/StatCard.vue';
import EmptyState from '../components/EmptyState.vue';

export default {
  components: { PageActions, StatCard, EmptyState },
  data() { return { invoice: null, loading: true }; },
  mounted() { this.fetch(); },
  methods: {
    money, badge, invoiceTone,
    pageMeta() {
      return {
        title: this.invoice ? this.invoice.Ref : this.$t('invoice'),
        pretitle: this.$t('invoice'),
        crumbs: [{ label: this.$t('invoices'), to: '/invoices' }],
      };
    },
    async fetch() {
      try {
        const { data } = await http.get(`/portal/invoices/${this.$route.params.id}`);
        this.invoice = data;
      } catch (_) {
        this.invoice = null;
      }
      this.loading = false;
      this.applyPage();
    },
  },
};
</script>
