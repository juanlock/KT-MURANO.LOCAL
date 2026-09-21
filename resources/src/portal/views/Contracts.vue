<template>
  <div>
    <DataTable
      ref="table"
      :title="$t('contracts')"
      :columns="columns"
      :rows="contracts"
      :total-rows="totalRows"
      :loading="loading"
      default-sort="start_date"
      default-dir="desc"
      :search-placeholder="$t('search_contracts')"
      :empty-text="$t('no_contracts')"
      empty-icon="file-certificate"
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

      <template #cell-contract_number="{ row }"><router-link :to="`/contracts/${row.id}`" class="fw-medium font-monospace">{{ row.contract_number }}</router-link></template>
      <template #cell-period="{ row }"><span class="text-secondary">{{ row.start_date || '—' }} → {{ row.end_date || '—' }}</span></template>
      <template #cell-value="{ value }">{{ money(value, true) }}</template>
      <template #cell-status="{ value }"><span :class="badge(contractTone(value))">{{ statusLabel(value) || '—' }}</span></template>
      <template #cell-actions="{ row }">
        <div class="rst-row-actions justify-content-end">
          <router-link :to="`/contracts/${row.id}`" :title="$t('view')" class="text-secondary"><i class="ti ti-eye"></i></router-link>
        </div>
      </template>
    </DataTable>
  </div>
</template>

<script>
import http from '../lib/http';
import { money, badge, contractTone } from '../lib/ui';
import DataTable from '../components/DataTable.vue';

export default {
  components: { DataTable },
  data() {
    return { contracts: [], statuses: [], totalRows: 0, loading: false, filters: { status: '', date_from: '', date_to: '' } };
  },
  computed: {
    columns() {
      return [
        { key: 'contract_number', label: this.$t('number'), sortable: true },
        { key: 'subject', label: this.$t('subject'), sortable: true },
        { key: 'type', label: this.$t('type') },
        { key: 'period', label: this.$t('period') },
        { key: 'value', label: this.$t('value'), sortable: true, numeric: true },
        { key: 'status', label: this.$t('status'), sortable: true },
        { key: 'actions', label: '', class: 'w-1 text-end' },
      ];
    },
  },
  methods: {
    money, badge, contractTone,
    pageMeta() { return { title: this.$t('contracts'), pretitle: this.$t('nav_services'), crumbs: [] }; },
    async fetch(q) {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/contracts', {
          params: { ...q, status: this.filters.status || undefined, date_from: this.filters.date_from || undefined, date_to: this.filters.date_to || undefined },
        });
        this.contracts = data.contracts || [];
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
