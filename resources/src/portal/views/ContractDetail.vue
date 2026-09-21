<template>
  <div>
    <PageActions>
      <router-link to="/contracts" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>{{ $t('back') }}</router-link>
    </PageActions>

    <div v-if="loading" class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading') }}</div>
    </div>

    <div v-else class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title mb-0">{{ contract.subject || contract.contract_number }}</h3>
          <p class="card-subtitle mb-0 font-monospace">{{ contract.contract_number }}</p>
        </div>
        <div class="card-actions d-flex align-items-center gap-3">
          <span :class="badge(contractTone(contract.status))">{{ statusLabel(contract.status) || '—' }}</span>
          <span v-if="contract.value" class="h2 mb-0 font-monospace">{{ money(contract.value) }}</span>
        </div>
      </div>
      <div class="card-body">
        <div class="datagrid">
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('type') }}</div><div class="datagrid-content">{{ contract.type || '—' }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('start_date') }}</div><div class="datagrid-content">{{ contract.start_date || '—' }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('end_date') }}</div><div class="datagrid-content">{{ contract.end_date || '—' }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('signed') }}</div><div class="datagrid-content">{{ contract.signed_at ? formatDate(contract.signed_at) : $t('not_signed') }}</div></div>
          <div v-if="contract.signer_name" class="datagrid-item"><div class="datagrid-title">{{ $t('signer') }}</div><div class="datagrid-content">{{ contract.signer_name }}</div></div>
        </div>
        <div v-if="contract.description" class="mt-4">
          <h3 class="card-title mb-2">{{ $t('description') }}</h3>
          <div class="p-3 rounded border rst-surface-2 pc-html markdown" v-html="contract.description"></div>
        </div>
        <div v-if="contract.attachments && contract.attachments.length" class="mt-4">
          <h3 class="card-title mb-2">{{ $t('attachments') }}</h3>
          <div class="list-group list-group-flush border rounded">
            <div v-for="a in contract.attachments" :key="a.id" class="list-group-item d-flex align-items-center gap-3">
              <i class="ti ti-paperclip fs-2 text-secondary"></i>
              <span class="flex-fill text-break">{{ a.file_name }}</span>
              <a :href="downloadUrl(a.id)" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="ti ti-download me-1"></i>{{ $t('download') }}</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import { money, badge, contractTone } from '../lib/ui';

export default {
  components: { PageActions },
  data() { return { contract: {}, loading: true }; },
  mounted() { this.fetch(); },
  methods: {
    money, badge, contractTone,
    pageMeta() {
      return { title: this.contract.contract_number || this.$t('contract'), pretitle: this.$t('contract'), crumbs: [{ label: this.$t('contracts'), to: '/contracts' }] };
    },
    async fetch() {
      this.loading = true;
      try {
        const { data } = await http.get(`/portal/contracts/${this.$route.params.id}`);
        this.contract = data || {};
      } catch (_) {}
      this.loading = false;
      this.applyPage();
    },
    downloadUrl(attachmentId) { return `/api/portal/contracts/${this.$route.params.id}/attachments/${attachmentId}/download`; },
    formatDate(iso) {
      if (!iso) return '—';
      try { return new Date(iso).toLocaleString(); } catch (_) { return iso; }
    },
  },
};
</script>
