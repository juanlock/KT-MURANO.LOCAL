<template>
  <div>
    <PageActions>
      <router-link to="/appointments/new" class="btn btn-primary">
        <i class="ti ti-plus me-1"></i>{{ $t('book_appointment') }}
      </router-link>
    </PageActions>

    <DataTable
      ref="table"
      :title="$t('appointments')"
      :columns="columns"
      :rows="appointments"
      :total-rows="totalRows"
      :loading="loading"
      default-sort="scheduled_date"
      default-dir="desc"
      :search-placeholder="$t('search_appointments')"
      :empty-text="$t('no_appointments_yet')"
      empty-icon="calendar-event"
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

      <template #cell-Ref="{ row }"><router-link :to="`/appointments/${row.id}`" class="fw-medium font-monospace">{{ row.Ref }}</router-link></template>
      <template #cell-job_type="{ value }">{{ enumLabel('job_', value) || '—' }}</template>
      <template #cell-scheduled_date="{ value }"><span class="text-secondary">{{ value || '—' }}</span></template>
      <template #cell-status="{ value }"><span :class="badge(appointmentTone(value))">{{ statusLabel(value) }}</span></template>
      <template #cell-actions="{ row }">
        <div class="rst-row-actions justify-content-end">
          <router-link :to="`/appointments/${row.id}`" :title="$t('view')" class="text-secondary"><i class="ti ti-eye"></i></router-link>
        </div>
      </template>
    </DataTable>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import { badge, appointmentTone } from '../lib/ui';
import DataTable from '../components/DataTable.vue';

export default {
  components: { PageActions, DataTable },
  data() {
    return { appointments: [], statuses: [], totalRows: 0, loading: false, filters: { status: '', date_from: '', date_to: '' } };
  },
  computed: {
    columns() {
      return [
        { key: 'Ref', label: this.$t('ref'), sortable: true },
        { key: 'service_item', label: this.$t('service'), sortable: true },
        { key: 'job_type', label: this.$t('type') },
        { key: 'scheduled_date', label: this.$t('when'), sortable: true },
        { key: 'status', label: this.$t('status'), sortable: true },
        { key: 'actions', label: '', class: 'w-1 text-end' },
      ];
    },
  },
  methods: {
    badge, appointmentTone,
    pageMeta() { return { title: this.$t('appointments'), pretitle: this.$t('nav_services'), crumbs: [] }; },
    async fetch(q) {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/appointments', {
          params: { ...q, status: this.filters.status || undefined, date_from: this.filters.date_from || undefined, date_to: this.filters.date_to || undefined },
        });
        this.appointments = data.appointments || [];
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
