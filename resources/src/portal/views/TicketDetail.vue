<template>
  <div>
    <PageActions>
      <router-link to="/tickets" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>{{ $t('back_to_tickets') }}</router-link>
    </PageActions>

    <div v-if="loading" class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading') }}</div>
    </div>

    <div v-else-if="notFound" class="card">
      <EmptyState icon="lifebuoy" :title="$t('no_matching_records')" />
    </div>

    <template v-else>
      <div class="card mb-3">
        <div class="card-header">
          <div class="min-w-0">
            <h3 class="card-title mb-0 text-break">{{ ticket.subject }}</h3>
            <p class="card-subtitle mb-0 font-monospace">{{ ticket.number }}</p>
          </div>
          <div class="card-actions">
            <span :class="badge(ticketTone(ticket.status))">{{ enumLabel('ticket_status_', ticket.status) }}</span>
          </div>
        </div>
        <div class="card-body">
          <div class="datagrid">
            <div class="datagrid-item"><div class="datagrid-title">{{ $t('ticket_opened') }}</div><div class="datagrid-content">{{ formatDate(ticket.created_at) }}</div></div>
            <div class="datagrid-item"><div class="datagrid-title">{{ $t('ticket_last_activity') }}</div><div class="datagrid-content">{{ formatDate(ticket.last_message_at) }}</div></div>
            <div v-if="ticket.category" class="datagrid-item"><div class="datagrid-title">{{ $t('ticket_category') }}</div><div class="datagrid-content">{{ ticket.category }}</div></div>
          </div>
        </div>
      </div>

      <div v-for="m in messages" :key="m.id" class="card mb-3" :class="m.author_type === 'agent' ? 'border-primary' : ''">
        <div class="card-body">
          <div class="d-flex flex-wrap justify-content-between gap-2 mb-2 small">
            <span class="fw-semibold">
              <i :class="m.author_type === 'agent' ? 'ti ti-headset' : 'ti ti-user'" class="me-1"></i>{{ m.author_type === 'agent' ? (m.author_name || $t('ticket_support_team')) : $t('ticket_you') }}
            </span>
            <span class="text-secondary">{{ formatDate(m.created_at) }}</span>
          </div>
          <!-- Plain text: rendered through text interpolation (escaped), line breaks kept by CSS. -->
          <div class="text-break" style="white-space: pre-wrap">{{ m.body }}</div>
          <div v-if="m.attachments && m.attachments.length" class="mt-3 d-flex flex-wrap gap-2">
            <a v-for="a in m.attachments" :key="a.index" :href="downloadUrl(m.id, a.index)" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">
              <i class="ti ti-paperclip me-1"></i>{{ a.name }}
            </a>
          </div>
        </div>
      </div>

      <div class="card">
        <div v-if="ticket.status === 'closed'" class="card-body text-secondary">
          <i class="ti ti-lock me-1"></i>{{ $t('ticket_closed_notice') }}
        </div>
        <form v-else @submit.prevent="reply">
          <div class="card-body">
            <label class="form-label required">{{ $t('ticket_reply') }}</label>
            <textarea v-model="body" class="form-control" rows="5" maxlength="60000" required></textarea>
            <div class="mt-3">
              <label class="form-label">{{ $t('ticket_files') }} <span class="form-label-description">{{ $t('optional') }}</span></label>
              <input ref="files" type="file" class="form-control" multiple @change="onFiles" />
              <div class="form-hint">{{ $t('ticket_files_hint') }}</div>
            </div>
            <div v-if="error" class="alert alert-danger mb-0 mt-3">{{ error }}</div>
            <div v-if="sent" class="alert alert-success mb-0 mt-3">{{ $t('ticket_reply_sent') }}</div>
          </div>
          <div class="card-footer d-flex justify-content-end">
            <button type="submit" class="btn btn-primary" :disabled="submitting">
              <span v-if="submitting" class="spinner-border spinner-border-sm me-2" role="status"></span>
              <i v-else class="ti ti-send me-1"></i>
              {{ submitting ? $t('submitting') : $t('send_reply') }}
            </button>
          </div>
        </form>
      </div>
    </template>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import EmptyState from '../components/EmptyState.vue';
import { badge, ticketTone, validationMessage } from '../lib/ui';

export default {
  components: { PageActions, EmptyState },
  data() {
    return { ticket: {}, messages: [], loading: true, notFound: false, body: '', files: [], submitting: false, error: '', sent: false };
  },
  mounted() { this.fetch(); },
  methods: {
    badge, ticketTone,
    pageMeta() {
      return { title: this.ticket.number || this.$t('ticket'), pretitle: this.$t('ticket'), crumbs: [{ label: this.$t('tickets'), to: '/tickets' }] };
    },
    apply(data) {
      this.ticket = (data && data.ticket) || {};
      this.messages = (data && data.messages) || [];
    },
    async fetch() {
      this.loading = true;
      try {
        const { data } = await http.get(`/portal/tickets/${this.$route.params.id}`);
        this.apply(data);
      } catch (_) {
        this.notFound = true;
      }
      this.loading = false;
      this.applyPage();
    },
    onFiles(ev) { this.files = Array.from(ev.target.files || []); },
    async reply() {
      if (!this.body.trim()) { this.error = this.$t('ticket_body_required'); return; }
      this.error = '';
      this.sent = false;
      this.submitting = true;
      try {
        const fd = new FormData();
        fd.append('body', this.body);
        this.files.forEach((f) => fd.append('files[]', f));
        const { data } = await http.post(`/portal/tickets/${this.ticket.id}/reply`, fd);
        this.apply(data);
        this.body = '';
        this.files = [];
        if (this.$refs.files) this.$refs.files.value = '';
        this.sent = true;
      } catch (e) {
        this.error = validationMessage(e, this.$t('ticket_reply_failed'));
      }
      this.submitting = false;
    },
    downloadUrl(messageId, index) { return `/api/portal/tickets/messages/${messageId}/attachments/${index}`; },
    formatDate(v) {
      if (!v) return '—';
      try { return new Date(String(v).replace(' ', 'T')).toLocaleString(); } catch (_) { return v; }
    },
  },
};
</script>
