<template>
  <div>
    <PageActions>
      <router-link to="/appointments" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>{{ $t('back_to_appointments') }}</router-link>
    </PageActions>

    <form class="card" @submit.prevent="submit">
      <div class="card-header">
        <div>
          <h3 class="card-title mb-0">{{ $t('book_an_appointment') }}</h3>
          <p class="card-subtitle mb-0">{{ $t('book_subtitle') }}</p>
        </div>
      </div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label required">{{ $t('service_item') }}</label>
          <input v-model="form.service_item" type="text" class="form-control" required maxlength="190" :placeholder="$t('service_item_placeholder')" />
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">{{ $t('type') }}</label>
            <select v-model="form.job_type" class="form-select">
              <option value="service">{{ $t('job_service') }}</option>
              <option value="repair">{{ $t('job_repair') }}</option>
              <option value="installation">{{ $t('job_installation') }}</option>
              <option value="consultation">{{ $t('job_consultation') }}</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label required">{{ $t('preferred_datetime') }}</label>
            <input v-model="form.scheduled_date" type="datetime-local" class="form-control" required />
          </div>
          <div class="col-md-6">
            <label class="form-label">{{ $t('device_brand') }} <span class="form-label-description">{{ $t('optional') }}</span></label>
            <input v-model="form.device_brand" type="text" class="form-control" maxlength="120" />
          </div>
          <div class="col-md-6">
            <label class="form-label">{{ $t('device_model') }} <span class="form-label-description">{{ $t('optional') }}</span></label>
            <input v-model="form.device_model" type="text" class="form-control" maxlength="120" />
          </div>
          <div class="col-12">
            <label class="form-label">{{ $t('serial_imei') }} <span class="form-label-description">{{ $t('optional') }}</span></label>
            <input v-model="form.device_serial" type="text" class="form-control" maxlength="120" />
          </div>
          <div class="col-12">
            <label class="form-label">{{ $t('issue_label') }}</label>
            <textarea v-model="form.reported_issue" class="form-control" rows="4" maxlength="5000" :placeholder="$t('issue_placeholder')"></textarea>
          </div>
        </div>
        <div v-if="error" class="alert alert-danger mb-0 mt-3">{{ error }}</div>
      </div>
      <div class="card-footer d-flex justify-content-end gap-2">
        <router-link to="/appointments" class="btn btn-ghost-secondary">{{ $t('cancel') }}</router-link>
        <button type="submit" class="btn btn-primary" :disabled="submitting">
          <span v-if="submitting" class="spinner-border spinner-border-sm me-2" role="status"></span>
          <i v-else class="ti ti-calendar-plus me-1"></i>
          {{ submitting ? $t('submitting') : $t('book_appointment') }}
        </button>
      </div>
    </form>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import { apiError } from '../lib/ui';

export default {
  components: { PageActions },
  data() {
    return {
      form: { service_item: '', job_type: 'service', scheduled_date: '', device_brand: '', device_model: '', device_serial: '', reported_issue: '' },
      submitting: false,
      error: '',
    };
  },
  methods: {
    pageMeta() { return { title: this.$t('book_an_appointment'), pretitle: this.$t('appointments'), crumbs: [{ label: this.$t('appointments'), to: '/appointments' }] }; },
    async submit() {
      if (!this.form.service_item || !this.form.scheduled_date) { this.error = this.$t('fill_required'); return; }
      this.error = '';
      this.submitting = true;
      try {
        await http.post('/portal/appointments', {
          service_item: this.form.service_item,
          job_type: this.form.job_type || undefined,
          scheduled_date: this.form.scheduled_date,
          reported_issue: this.form.reported_issue || undefined,
          device_brand: this.form.device_brand || undefined,
          device_model: this.form.device_model || undefined,
          device_serial: this.form.device_serial || undefined,
        });
        this.$router.push('/appointments');
      } catch (e) {
        this.error = apiError(e, this.$t('book_failed'));
      }
      this.submitting = false;
    },
  },
};
</script>
