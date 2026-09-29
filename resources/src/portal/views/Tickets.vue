<template>
  <div>
    <PageActions>
      <router-link to="/tickets/new" class="btn btn-primary">
        <i class="ti ti-plus me-1"></i>{{ $t('new_ticket') }}
      </router-link>
    </PageActions>

    <DataTable
      ref="table"
      :title="$t('tickets')"
      :columns="columns"
      :rows="tickets"
      :total-rows="totalRows"
      :loading="loading"
      default-sort="last_message_at"
      default-dir="desc"
      :search-placeholder="$t('search_tickets')"
      :empty-text="$t('no_tickets_yet')"
      empty-icon="lifebuoy"
      :filters="filters"
      @query="fetch"
      @reset="resetFilters"
    >
      <template #filters>
        <select v-model="filters.status" class="form-select w-auto" :aria-label="$t('status')">
          <option value="">{{ $t('all_statuses') }}</option>
          <option v-for="s in statusOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
      </template>

      <template #cell-number="{ row }"><router-link :to="`/tickets/${row.id}`" class="fw-medium font-monospace">{{ row.number }}</router-link></template>
      <template #cell-subject="{ row }">
        <div class="text-break">{{ row.subject }}</div>
        <div v-if="row.category" class="small text-secondary">{{ row.category }}</div>
      </template>
      <template #cell-status="{ value }"><span :class="badge(ticketTone(value))">{{ enumLabel('ticket_status_', value) }}</span></template>
      <template #cell-last_message_at="{ value }"><span class="text-secondary">{{ value || '—' }}</span></template>
      <template #cell-actions="{ row }">
        <div class="rst-row-actions justify-content-end">
          <router-link :to="`/tickets/${row.id}`" :title="$t('view')" class="text-secondary"><i class="ti ti-eye"></i></router-link>
        </div>
      </template>
    </DataTable>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import DataTable from '../components/DataTable.vue';
import { badge, ticketTone } from '../lib/ui';

export default {
  components: { PageActions, DataTable },
  data() {
    return { tickets: [], statuses: [], totalRows: 0, loading: false, filters: { status: '' } };
  },
  computed: {
    columns() {
      return [
        { key: 'number', label: this.$t('ticket_number'), sortable: true },
        { key: 'subject', label: this.$t('subject'), sortable: true },
        { key: 'status', label: this.$t('status'), sortable: true },
        { key: 'last_message_at', label: this.$t('ticket_last_activity'), sortable: true },
        { key: 'actions', label: '', class: 'w-1 text-end' },
      ];
    },
    // Status filter options, alphabetical by their displayed label.
    statusOptions() {
      return this.statuses
        .map((s) => ({ value: s, label: this.enumLabel('ticket_status_', s) }))
        .sort((a, b) => a.label.localeCompare(b.label));
    },
  },
  methods: {
    badge, ticketTone,
    pageMeta() { return { title: this.$t('tickets'), pretitle: this.$t('nav_support'), crumbs: [] }; },
    async fetch(q) {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/tickets', { params: { ...q, status: this.filters.status || undefined } });
        this.tickets = data.tickets || [];
        this.totalRows = data.totalRows || 0;
        if (Array.isArray(data.statuses)) this.statuses = data.statuses;
      } catch (_) {}
      this.loading = false;
    },
    resetFilters() {
      this.filters = { status: '' };
      if (this.$refs.table) this.$refs.table.clearSearch();
    },
  },
};
</script>
