<template>
  <div>
    <PageActions>
      <router-link to="/quotations/new" class="btn btn-primary">
        <i class="ti ti-plus me-1"></i>{{ $t('request_quotation') }}
      </router-link>
    </PageActions>

    <DataTable
      ref="table"
      :title="$t('quotations')"
      :columns="columns"
      :rows="quotations"
      :total-rows="totalRows"
      :loading="loading"
      default-sort="date"
      default-dir="desc"
      :search-placeholder="$t('search_quotations')"
      :empty-text="$t('no_quotations_yet')"
      empty-icon="file-description"
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

      <template #cell-Ref="{ row }"><router-link :to="`/quotations/${row.id}`" class="fw-medium font-monospace">{{ row.Ref }}</router-link></template>
      <template #cell-date="{ value }"><span class="text-secondary">{{ value }}</span></template>
      <template #cell-GrandTotal="{ value }">{{ money(value) }}</template>
      <template #cell-statut="{ value }"><span :class="badge(quotationTone(value))">{{ statusLabel(value) }}</span></template>
      <template #cell-actions="{ row }">
        <div class="rst-row-actions justify-content-end">
          <router-link :to="`/quotations/${row.id}`" :title="$t('view')" class="text-secondary"><i class="ti ti-eye"></i></router-link>
        </div>
      </template>
    </DataTable>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import { money, badge, quotationTone } from '../lib/ui';
import DataTable from '../components/DataTable.vue';

export default {
  components: { PageActions, DataTable },
  data() {
    return { quotations: [], statuses: [], totalRows: 0, loading: false, filters: { status: '', date_from: '', date_to: '' } };
  },
  computed: {
    columns() {
      return [
        { key: 'Ref', label: this.$t('ref'), sortable: true },
        { key: 'date', label: this.$t('date'), sortable: true },
        { key: 'GrandTotal', label: this.$t('total'), sortable: true, numeric: true },
        { key: 'statut', label: this.$t('status'), sortable: true },
        { key: 'actions', label: '', class: 'w-1 text-end' },
      ];
    },
  },
  methods: {
    money, badge, quotationTone,
    pageMeta() { return { title: this.$t('quotations'), pretitle: this.$t('nav_services'), crumbs: [] }; },
    async fetch(q) {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/quotations', {
          params: { ...q, status: this.filters.status || undefined, date_from: this.filters.date_from || undefined, date_to: this.filters.date_to || undefined },
        });
        this.quotations = data.quotations || [];
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
