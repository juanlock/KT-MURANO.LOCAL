<template>
  <div>
    <PageActions>
      <router-link to="/tickets" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>{{ $t('back_to_tickets') }}</router-link>
    </PageActions>

    <form class="card" @submit.prevent="submit">
      <div class="card-header">
        <div>
          <h3 class="card-title mb-0">{{ $t('open_a_ticket') }}</h3>
          <p class="card-subtitle mb-0">{{ $t('ticket_subtitle') }}</p>
        </div>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label required">{{ $t('subject') }}</label>
            <input v-model="form.subject" type="text" class="form-control" maxlength="250" required />
          </div>
          <div class="col-md-4">
            <label class="form-label">{{ $t('ticket_category') }} <span class="form-label-description">{{ $t('optional') }}</span></label>
            <select v-model="form.category" class="form-select">
              <option value="">—</option>
              <!-- Admin-defined category order (Helpdesk settings), not alphabetical on purpose. -->
              <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label required">{{ $t('ticket_message') }}</label>
            <textarea v-model="form.body" class="form-control" rows="7" maxlength="60000" required></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">{{ $t('ticket_files') }} <span class="form-label-description">{{ $t('optional') }}</span></label>
            <input ref="files" type="file" class="form-control" multiple @change="onFiles" />
            <div class="form-hint">{{ $t('ticket_files_hint') }}</div>
          </div>
        </div>
        <div v-if="error" class="alert alert-danger mb-0 mt-3">{{ error }}</div>
      </div>
      <div class="card-footer d-flex justify-content-end gap-2">
        <router-link to="/tickets" class="btn btn-ghost-secondary">{{ $t('cancel') }}</router-link>
        <button type="submit" class="btn btn-primary" :disabled="submitting">
          <span v-if="submitting" class="spinner-border spinner-border-sm me-2" role="status"></span>
          <i v-else class="ti ti-send me-1"></i>
          {{ submitting ? $t('submitting') : $t('send_ticket') }}
        </button>
      </div>
    </form>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import { validationMessage } from '../lib/ui';

export default {
  components: { PageActions },
  data() {
    return { form: { subject: '', category: '', body: '' }, files: [], categories: [], submitting: false, error: '' };
  },
  mounted() { this.loadCategories(); },
  methods: {
    pageMeta() { return { title: this.$t('new_ticket'), pretitle: this.$t('tickets'), crumbs: [{ label: this.$t('tickets'), to: '/tickets' }] }; },
    async loadCategories() {
      try {
        const { data } = await http.get('/portal/tickets', { params: { limit: 1 } });
        this.categories = Array.isArray(data.categories) ? data.categories : [];
      } catch (_) {}
    },
    onFiles(ev) { this.files = Array.from(ev.target.files || []); },
    async submit() {
      if (!this.form.subject.trim() || !this.form.body.trim()) { this.error = this.$t('ticket_body_required'); return; }
      this.error = '';
      this.submitting = true;
      try {
        const fd = new FormData();
        fd.append('subject', this.form.subject);
        fd.append('body', this.form.body);
        if (this.form.category) fd.append('category', this.form.category);
        this.files.forEach((f) => fd.append('files[]', f));
        const { data } = await http.post('/portal/tickets', fd);
        this.$router.push(`/tickets/${data.id}`);
      } catch (e) {
        this.error = validationMessage(e, this.$t('ticket_submit_failed'));
      }
      this.submitting = false;
    },
  },
};
</script>
