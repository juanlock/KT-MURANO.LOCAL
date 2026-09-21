<template>
  <div>
    <div v-if="data" class="row row-deck row-cards mb-3">
      <div class="col-sm-6 col-lg-4"><StatCard :label="$t('account')" :value="(data.client && data.client.name) || '—'" icon="user" tone="blue" /></div>
      <div class="col-sm-6 col-lg-4"><StatCard :label="$t('opening_balance')" :value="money(data.current_opening_balance != null ? data.current_opening_balance : data.opening_balance)" icon="wallet" tone="teal" /></div>
      <div class="col-sm-6 col-lg-4"><StatCard :label="$t('closing_balance')" :value="money(data.closing_balance)" icon="scale" :tone="Number(data.closing_balance) > 0 ? 'red' : 'green'" /></div>
    </div>

    <div class="card">
      <div class="card-header d-block">
        <div class="d-flex flex-wrap align-items-center gap-2">
          <h3 class="card-title mb-0">{{ $t('account_statement') }}</h3>
          <form class="ms-auto d-flex flex-wrap align-items-center gap-2 d-print-none" @submit.prevent="fetch">
            <input v-model="fromDate" type="date" class="form-control w-auto" :aria-label="$t('from')" :title="$t('from')" />
            <input v-model="toDate" type="date" class="form-control w-auto" :aria-label="$t('to')" :title="$t('to')" />
            <button type="submit" class="btn btn-outline-secondary" :disabled="loading">
              <span v-if="loading" class="spinner-border spinner-border-sm me-1" role="status"></span>
              {{ $t('apply') }}
            </button>
            <button v-if="fromDate || toDate" type="button" class="btn btn-ghost-secondary" @click="fromDate = ''; toDate = ''; fetch()">{{ $t('reset_filters') }}</button>
          </form>
        </div>
      </div>

      <template v-if="data">
        <EmptyState v-if="!(data.entries && data.entries.length)" icon="report-money" :title="$t('no_entries')" />
        <div v-else class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead>
              <tr>
                <th>{{ $t('date') }}</th>
                <th>{{ $t('type') }}</th>
                <th>{{ $t('ref') }}</th>
                <th>{{ $t('description') }}</th>
                <th class="text-end">{{ $t('debit') }}</th>
                <th class="text-end">{{ $t('credit') }}</th>
                <th class="text-end">{{ $t('balance') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(e, i) in data.entries" :key="i">
                <td class="text-secondary text-nowrap">{{ e.date }}</td>
                <td><span class="badge bg-orange-lt">{{ enumLabel('type_', e.type) }}</span></td>
                <td class="font-monospace">{{ e.ref }}</td>
                <td class="text-secondary">{{ e.description }}</td>
                <td class="text-end font-monospace text-danger">{{ e.debit ? money(e.debit) : '—' }}</td>
                <td class="text-end font-monospace text-success">{{ e.credit ? money(e.credit) : '—' }}</td>
                <td class="text-end font-monospace fw-bold">{{ money(e.balance) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
      <EmptyState v-else icon="report-money" :title="$t('statement_hint')" />
    </div>
  </div>
</template>

<script>
import http from '../lib/http';
import { money } from '../lib/ui';
import StatCard from '../components/StatCard.vue';
import EmptyState from '../components/EmptyState.vue';

export default {
  components: { StatCard, EmptyState },
  data() { return { data: null, fromDate: '', toDate: '', loading: false }; },
  mounted() { this.fetch(); },
  methods: {
    money,
    pageMeta() { return { title: this.$t('account_statement'), pretitle: this.$t('nav_billing'), crumbs: [] }; },
    async fetch() {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/statement', { params: { from_date: this.fromDate || undefined, to_date: this.toDate || undefined } });
        this.data = data;
      } catch (_) {
        this.data = null;
      }
      this.loading = false;
    },
  },
};
</script>
