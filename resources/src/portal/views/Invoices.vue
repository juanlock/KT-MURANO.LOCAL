<template>
  <div>
    <DataTable
      ref="table"
      :title="$t('invoices')"
      :columns="columns"
      :rows="invoices"
      :total-rows="totalRows"
      :loading="loading"
      default-sort="date"
      default-dir="desc"
      :search-placeholder="$t('search_invoices')"
      :empty-text="$t('no_invoices_found')"
      empty-icon="file-invoice"
      :filters="filters"
      @query="fetch"
      @reset="resetFilters"
    >
      <template #filters>
        <select v-model="filters.status" class="form-select w-auto" :aria-label="$t('status')">
          <option value="">{{ $t('all_statuses') }}</option>
          <option v-for="s in statuses" :key="s" :value="s">{{ statusLabel(s) }}</option>
        </select>
        <input v-model="filters.date_from" type="date" class="form-control w-auto" :aria-label="$t('from')" :title="$t('from')" />
        <input v-model="filters.date_to" type="date" class="form-control w-auto" :aria-label="$t('to')" :title="$t('to')" />
      </template>

      <template #cell-Ref="{ row }">
        <router-link :to="`/invoices/${row.id}`" class="fw-medium font-monospace">{{ row.Ref }}</router-link>
      </template>
      <template #cell-date="{ value }"><span class="text-secondary">{{ value }}</span></template>
      <template #cell-GrandTotal="{ value }">{{ money(value) }}</template>
      <template #cell-paid_amount="{ value }">{{ money(value) }}</template>
      <template #cell-due="{ value }"><span :class="Number(value) > 0 ? 'text-danger fw-semibold' : 'text-secondary'">{{ money(value) }}</span></template>
      <template #cell-payment_status="{ value }"><span :class="badge(invoiceTone(value))">{{ statusLabel(value) }}</span></template>
      <template #cell-actions="{ row }">
        <div class="rst-row-actions justify-content-end">
          <router-link :to="`/invoices/${row.id}`" :title="$t('view')" class="text-secondary"><i class="ti ti-eye"></i></router-link>
          <a :href="`/api/portal/invoices/${row.id}/pdf`" target="_blank" rel="noopener" :title="$t('pdf')" class="text-primary"><i class="ti ti-download"></i></a>
        </div>
      </template>
    </DataTable>
  </div>
</template>

<script>
import http from '../lib/http';
import { money, badge, invoiceTone } from '../lib/ui';
import DataTable from '../components/DataTable.vue';

export default {
  components: { DataTable },
  data() {
    return {
      invoices: [], statuses: [], totalRows: 0, loading: false,
      filters: { status: '', date_from: '', date_to: '' },
    };
  },
  computed: {
    columns() {
      return [
        { key: 'Ref', label: this.$t('ref'), sortable: true },
        { key: 'date', label: this.$t('date'), sortable: true },
        { key: 'GrandTotal', label: this.$t('total'), sortable: true, numeric: true },
        { key: 'paid_amount', label: this.$t('paid'), sortable: true, numeric: true },
        { key: 'due', label: this.$t('due'), numeric: true },
        { key: 'payment_status', label: this.$t('status'), sortable: true },
        { key: 'actions', label: '', class: 'w-1 text-end' },
      ];
    },
  },
  mounted() {
    // Topbar search lands here with ?q=
    const q = this.$route.query.q;
    if (q && this.$refs.table) this.$refs.table.setSearch(String(q));
  },
  methods: {
    money, badge, invoiceTone,
    pageMeta() { return { title: this.$t('invoices'), pretitle: this.$t('nav_billing'), crumbs: [] }; },
    async fetch(q) {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/invoices', {
          params: { ...q, status: this.filters.status || undefined, date_from: this.filters.date_from || undefined, date_to: this.filters.date_to || undefined },
        });
        this.invoices = data.invoices || [];
        this.totalRows = data.totalRows || 0;
        if (Array.isArray(data.statuses)) this.statuses = data.statuses;
      } catch (_) {}
      this.loading = false;
    },
    resetFilters() {
      this.filters = { status: '', date_from: '', date_to: '' };
      if (this.$refs.table) this.$refs.table.clearSearch();
    },
  },
};
</script>
