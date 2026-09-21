<template>
  <div>
    <DataTable
      ref="table"
      :title="$t('payments')"
      :columns="columns"
      :rows="payments"
      :row-key="rowKey"
      :total-rows="totalRows"
      :loading="loading"
      default-sort="date"
      default-dir="desc"
      :search-placeholder="$t('search_payments')"
      :empty-text="$t('no_payments_found')"
      empty-icon="credit-card-off"
      :filters="filters"
      @query="fetch"
      @reset="resetFilters"
    >
      <template #filters>
        <select v-model="filters.method" class="form-select w-auto" :aria-label="$t('method')">
          <option value="">{{ $t('all_methods') }}</option>
          <option v-for="m in methods" :key="m" :value="m">{{ m }}</option>
        </select>
        <input v-model="filters.date_from" type="date" class="form-control w-auto" :aria-label="$t('from')" :title="$t('from')" />
        <input v-model="filters.date_to" type="date" class="form-control w-auto" :aria-label="$t('to')" :title="$t('to')" />
      </template>

      <template #cell-date="{ value }"><span class="text-secondary">{{ value }}</span></template>
      <template #cell-Ref="{ value }"><span class="fw-medium font-monospace">{{ value }}</span></template>
      <template #cell-Sale_Ref="{ value }"><span class="font-monospace">{{ value || '—' }}</span></template>
      <template #cell-payment_method="{ value }"><span v-if="value" class="badge bg-azure-lt">{{ value }}</span><span v-else>—</span></template>
      <template #cell-montant="{ value }"><span class="text-success fw-semibold">{{ money(value) }}</span></template>
    </DataTable>
  </div>
</template>

<script>
import http from '../lib/http';
import { money } from '../lib/ui';
import DataTable from '../components/DataTable.vue';

export default {
  components: { DataTable },
  data() {
    return { payments: [], methods: [], totalRows: 0, loading: false, filters: { method: '', date_from: '', date_to: '' } };
  },
  computed: {
    columns() {
      return [
        { key: 'date', label: this.$t('date'), sortable: true },
        { key: 'Ref', label: this.$t('ref'), sortable: true },
        { key: 'Sale_Ref', label: this.$t('invoice'), sortable: true },
        { key: 'payment_method', label: this.$t('method'), sortable: true },
        { key: 'montant', label: this.$t('amount'), sortable: true, numeric: true },
      ];
    },
  },
  methods: {
    money,
    pageMeta() { return { title: this.$t('payments'), pretitle: this.$t('nav_billing'), crumbs: [] }; },
    rowKey(row, i) { return `${row.payment_type || 'p'}-${row.id != null ? row.id : i}`; },
    async fetch(q) {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/payments', {
          params: { ...q, method: this.filters.method || undefined, date_from: this.filters.date_from || undefined, date_to: this.filters.date_to || undefined },
        });
        this.payments = data.payments || [];
        this.totalRows = data.totalRows || 0;
        if (Array.isArray(data.methods)) this.methods = data.methods;
      } catch (_) {}
      this.loading = false;
    },
    resetFilters() {
      this.filters = { method: '', date_from: '', date_to: '' };
      if (this.$refs.table) this.$refs.table.clearSearch();
    },
  },
};
</script>
