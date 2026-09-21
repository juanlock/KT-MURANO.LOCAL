<template>
  <div>
    <PageActions>
      <router-link to="/quotations" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>{{ $t('back_to_quotations') }}</router-link>
    </PageActions>

    <div v-if="loading" class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading') }}</div>
    </div>

    <div v-else class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title mb-0">{{ quotation.Ref }}</h3>
          <p v-if="quotation.date" class="card-subtitle mb-0">{{ $t('created_on', { date: quotation.date }) }}</p>
        </div>
        <div class="card-actions d-flex align-items-center gap-3">
          <span :class="badge(quotationTone(quotation.statut))">{{ statusLabel(quotation.statut) }}</span>
          <span class="h2 mb-0 font-monospace">{{ money(quotation.GrandTotal) }}</span>
        </div>
      </div>
      <div class="card-body">
        <div class="datagrid">
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('warehouse') }}</div><div class="datagrid-content">{{ quotation.warehouse_name || '—' }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('discount') }}</div><div class="datagrid-content">{{ money(quotation.discount) }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('shipping') }}</div><div class="datagrid-content">{{ money(quotation.shipping) }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('tax') }}</div><div class="datagrid-content">{{ money(quotation.TaxNet) }} ({{ quotation.tax_rate || 0 }}%)</div></div>
        </div>
        <div v-if="quotation.notes" class="mt-4">
          <h3 class="card-title mb-2">{{ $t('notes') }}</h3>
          <div class="p-3 rounded border rst-surface-2" style="white-space: pre-wrap">{{ quotation.notes }}</div>
        </div>
      </div>
      <template v-if="quotation.details && quotation.details.length">
        <div class="card-header"><h3 class="card-title">{{ $t('items') }}</h3></div>
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead><tr><th>{{ $t('product') }}</th><th class="text-end">{{ $t('qty') }}</th><th class="text-end">{{ $t('price') }}</th><th class="text-end">{{ $t('total') }}</th></tr></thead>
            <tbody>
              <tr v-for="(d, i) in quotation.details" :key="i">
                <td>{{ d.product_name || '—' }}</td>
                <td class="text-end font-monospace">{{ d.quantity }}</td>
                <td class="text-end font-monospace">{{ money(d.price) }}</td>
                <td class="text-end font-monospace fw-bold">{{ money(d.total) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import { money, badge, quotationTone } from '../lib/ui';

export default {
  components: { PageActions },
  data() { return { quotation: {}, loading: true }; },
  mounted() { this.fetch(); },
  methods: {
    money, badge, quotationTone,
    pageMeta() {
      return { title: this.quotation.Ref || this.$t('quotation'), pretitle: this.$t('quotation'), crumbs: [{ label: this.$t('quotations'), to: '/quotations' }] };
    },
    async fetch() {
      this.loading = true;
      try {
        const { data } = await http.get(`/portal/quotations/${this.$route.params.id}`);
        this.quotation = data || {};
      } catch (_) {}
      this.loading = false;
      this.applyPage();
    },
  },
};
</script>
