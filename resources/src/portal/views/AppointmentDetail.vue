<template>
  <div>
    <PageActions>
      <router-link to="/appointments" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>{{ $t('back') }}</router-link>
    </PageActions>

    <div v-if="loading" class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading') }}</div>
    </div>

    <div v-else-if="error" class="card">
      <EmptyState icon="alert-circle" :title="error.title" :subtitle="error.message">
        <button type="button" class="btn btn-primary" @click="fetch">{{ $t('try_again') }}</button>
      </EmptyState>
    </div>

    <div v-else-if="!appointment.id" class="card">
      <EmptyState icon="calendar-off" :title="$t('appointment_not_found')" :subtitle="$t('appointment_not_found_text')" />
    </div>

    <div v-else class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title mb-0">{{ appointment.service_item || appointment.Ref }}</h3>
          <p v-if="appointment.scheduled_date" class="card-subtitle mb-0">{{ $t('scheduled_on', { date: appointment.scheduled_date }) }}</p>
        </div>
        <div class="card-actions d-flex align-items-center gap-3">
          <span :class="badge(appointmentTone(appointment.status))">{{ statusLabel(appointment.status || 'pending') }}</span>
          <span v-if="appointment.total_amount" class="h2 mb-0 font-monospace">{{ money(appointment.total_amount) }}</span>
        </div>
      </div>
      <div class="card-body">
        <div class="datagrid">
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('reference') }}</div><div class="datagrid-content font-monospace">{{ appointment.Ref || '—' }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('service') }}</div><div class="datagrid-content">{{ appointment.service_item || '—' }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('type') }}</div><div class="datagrid-content">{{ enumLabel('job_', appointment.job_type) || '—' }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('scheduled') }}</div><div class="datagrid-content">{{ appointment.scheduled_date || '—' }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('booked') }}</div><div class="datagrid-content">{{ appointment.created_at || '—' }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('started') }}</div><div class="datagrid-content">{{ appointment.started_at || '—' }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('completed') }}</div><div class="datagrid-content">{{ appointment.completed_at || '—' }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('technician') }}</div><div class="datagrid-content">{{ appointment.technician_name || '—' }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('device') }}</div><div class="datagrid-content">{{ deviceLabel || '—' }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('quote') }}</div><div class="datagrid-content">{{ appointment.quote_amount ? money(appointment.quote_amount) : '—' }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('paid') }}</div><div class="datagrid-content">{{ appointment.paid_amount != null ? money(appointment.paid_amount) : '—' }}</div></div>
          <div class="datagrid-item"><div class="datagrid-title">{{ $t('payment_status') }}</div><div class="datagrid-content">{{ statusLabel(appointment.payment_status) || '—' }}</div></div>
        </div>
        <div v-for="block in blocks" :key="block.key" class="mt-4">
          <h3 class="card-title mb-2">{{ block.label }}</h3>
          <div class="p-3 rounded border rst-surface-2" style="white-space: pre-wrap">{{ block.text }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import { money, badge, appointmentTone } from '../lib/ui';
import EmptyState from '../components/EmptyState.vue';

export default {
  components: { PageActions, EmptyState },
  data() { return { appointment: {}, loading: true, error: null }; },
  computed: {
    deviceLabel() {
      const a = this.appointment;
      return [a.device_brand, a.device_model, a.device_serial].filter(Boolean).join(' / ');
    },
    blocks() {
      const a = this.appointment;
      return [
        { key: 'issue', label: this.$t('reported_issue'), text: a.reported_issue },
        { key: 'diagnosis', label: this.$t('diagnosis'), text: a.diagnosis },
        { key: 'notes', label: this.$t('notes'), text: a.notes },
      ].filter((b) => b.text);
    },
  },
  mounted() { this.fetch(); },
  methods: {
    money, badge, appointmentTone,
    pageMeta() {
      return { title: this.appointment.Ref || this.$t('appointment'), pretitle: this.$t('appointment'), crumbs: [{ label: this.$t('appointments'), to: '/appointments' }] };
    },
    async fetch() {
      this.loading = true;
      this.error = null;
      try {
        const { data } = await http.get(`/portal/appointments/${this.$route.params.id}`);
        this.appointment = data || {};
      } catch (e) {
        this.appointment = {};
        const status = e && e.response && e.response.status;
        const serverMessage = e && e.response && e.response.data && e.response.data.message;
        if (status === 404) this.error = { title: this.$t('appointment_not_found'), message: serverMessage || this.$t('appointment_not_found_account') };
        else if (status === 403) this.error = { title: this.$t('access_denied'), message: serverMessage || this.$t('access_denied_text') };
        else if (status === 401) this.error = { title: this.$t('session_expired'), message: this.$t('session_expired_text') };
        else this.error = { title: this.$t('appointment_load_failed'), message: serverMessage || this.$t('appointment_load_failed_text') };
      }
      this.loading = false;
      this.applyPage();
    },
  },
};
</script>
