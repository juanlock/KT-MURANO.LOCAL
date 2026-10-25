<template>
  <!--
    My hospital (hospital phase 4, 2026-10-25): for each patient record of this
    customer -- the stay today (where, day N, the status the nurses publish for
    the family, diet, visit hours), the discharge instructions (prescriptions,
    home care, videos, follow-up) and upcoming appointments. No diagnoses,
    results or medication of the stay.
  -->
  <div>
    <div v-if="loading" class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading') }}</div>
    </div>

    <template v-else>
      <div v-if="!patients.length" class="card"><div class="card-body text-secondary">{{ $t('hosp_none') }}</div></div>

      <div v-for="p in patients" :key="p.mrn" class="mb-4">
        <h2 v-if="patients.length > 1" class="h3 mb-2">{{ p.name }} <span class="text-secondary fs-5 font-monospace">{{ p.mrn }}</span></h2>

        <!-- The stay today -->
        <div v-if="p.stay" class="card mb-3">
          <div class="card-header">
            <div>
              <h3 class="card-title mb-0"><i class="ti ti-bed me-1"></i>{{ p.stay.discharged ? $t('hosp_discharged_today') : $t('hosp_in_hospital') }}</h3>
              <p class="card-subtitle mb-0">{{ $t('hosp_day_n', { n: p.stay.day }) }} · {{ $t('hosp_since') }} {{ formatDate(p.stay.admitted_at) }}</p>
            </div>
            <div v-if="p.stay.status" class="card-actions">
              <span :class="badge(tone(p.stay.status))" class="fs-4 px-3 py-2">{{ $t('hosp_st_' + p.stay.status) }}</span>
            </div>
          </div>
          <div class="card-body">
            <div v-if="p.stay.note" class="alert alert-info mb-3">
              <i class="ti ti-message-heart me-1"></i>{{ p.stay.note }}
              <div v-if="p.stay.status_at" class="text-secondary small mt-1">{{ $t('hosp_updated') }} {{ formatDateTime(p.stay.status_at) }}</div>
            </div>
            <div class="datagrid">
              <div v-if="p.stay.floor" class="datagrid-item"><div class="datagrid-title">{{ $t('hosp_floor') }}</div><div class="datagrid-content">{{ p.stay.floor }}</div></div>
              <div class="datagrid-item"><div class="datagrid-title">{{ $t('hosp_ward') }}</div><div class="datagrid-content">{{ p.stay.ward || '—' }}</div></div>
              <div v-if="p.stay.room && p.stay.room !== p.stay.ward" class="datagrid-item"><div class="datagrid-title">{{ $t('hosp_room') }}</div><div class="datagrid-content">{{ p.stay.room }}</div></div>
              <div class="datagrid-item"><div class="datagrid-title">{{ $t('hosp_bed') }}</div><div class="datagrid-content">{{ p.stay.bed || '—' }}</div></div>
              <div v-if="p.stay.doctor" class="datagrid-item"><div class="datagrid-title">{{ $t('hosp_doctor') }}</div><div class="datagrid-content">{{ p.stay.doctor }}</div></div>
              <div v-if="p.stay.planned_discharge && !p.stay.discharged" class="datagrid-item"><div class="datagrid-title">{{ $t('hosp_planned_discharge') }}</div><div class="datagrid-content">{{ formatDate(p.stay.planned_discharge) }}</div></div>
            </div>
            <div v-if="p.stay.diet.length" class="mt-3">
              <div class="datagrid-title">{{ $t('hosp_diet') }}</div>
              <div v-for="(d, i) in p.stay.diet" :key="i"><i class="ti ti-tools-kitchen-2 me-1 text-secondary"></i>{{ d }}</div>
            </div>
          </div>
        </div>

        <!-- Going home -->
        <div v-if="p.discharge" class="card mb-3">
          <div class="card-header">
            <div>
              <h3 class="card-title mb-0"><i class="ti ti-home-heart me-1"></i>{{ $t('hosp_home_title') }}</h3>
              <p class="card-subtitle mb-0">{{ $t('hosp_discharged_on') }} {{ formatDate(p.discharge.discharged_at) }}</p>
            </div>
          </div>
          <div class="card-body">
            <div v-if="p.discharge.follow_up_at" class="alert alert-warning">
              <i class="ti ti-calendar-event me-1"></i>{{ $t('hosp_follow_up') }}: <b>{{ formatDateTime(p.discharge.follow_up_at) }}</b>
            </div>
            <div v-if="p.discharge.prescriptions" class="mb-3">
              <h4 class="mb-1">{{ $t('hosp_prescriptions') }}</h4>
              <div class="p-3 rounded border rst-surface-2 pre">{{ p.discharge.prescriptions }}</div>
            </div>
            <div v-if="p.discharge.home_care" class="mb-3">
              <h4 class="mb-1">{{ $t('hosp_home_care') }}</h4>
              <div class="p-3 rounded border rst-surface-2 pre">{{ p.discharge.home_care }}</div>
            </div>
            <div v-if="p.discharge.videos.length">
              <h4 class="mb-1">{{ $t('hosp_videos') }}</h4>
              <div class="list-group list-group-flush border rounded">
                <a v-for="(v, i) in p.discharge.videos" :key="i" :href="v.url" target="_blank" rel="noopener noreferrer" class="list-group-item list-group-item-action d-flex align-items-center gap-3">
                  <i class="ti ti-brand-youtube fs-2 text-danger"></i>
                  <span class="flex-fill">{{ v.title }}<span v-if="v.topic" class="text-secondary"> · {{ v.topic }}</span></span>
                  <i class="ti ti-external-link text-secondary"></i>
                </a>
              </div>
            </div>
          </div>
        </div>

        <!-- Appointments -->
        <div class="card mb-3">
          <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-calendar-event me-1"></i>{{ $t('hosp_appointments') }}</h3></div>
          <div v-if="!p.appointments.length" class="card-body text-secondary">{{ $t('hosp_no_appointments') }}</div>
          <div v-else class="list-group list-group-flush">
            <div v-for="a in p.appointments" :key="a.reference" class="list-group-item d-flex align-items-center gap-3">
              <span class="avatar bg-primary-lt"><i class="ti ti-stethoscope"></i></span>
              <div class="flex-fill">
                <div class="fw-medium">{{ formatDateTime(a.scheduled_at) }}</div>
                <div class="text-secondary small">{{ [a.doctor, a.department].filter(Boolean).join(' · ') || a.reference }}</div>
              </div>
              <span :class="badge(a.status === 'confirmed' ? 'success' : 'info')">{{ $t('hosp_ap_' + a.status) }}</span>
            </div>
          </div>
        </div>
      </div>

      <div v-if="visitHours" class="card">
        <div class="card-body"><i class="ti ti-clock me-1"></i><b>{{ $t('hosp_visit_hours') }}:</b> {{ visitHours }}</div>
      </div>
    </template>
  </div>
</template>

<script>
import http from '../lib/http';
import { badge } from '../lib/ui';

export default {
  data() { return { patients: [], visitHours: '', loading: true }; },
  mounted() { this.fetch(); },
  methods: {
    badge,
    pageMeta() { return { title: this.$t('nav_hospital'), pretitle: this.$t('nav_services'), crumbs: [] }; },
    tone(status) {
      return { stable: 'success', improving: 'success', observation: 'warning', resting: 'info', in_procedure: 'info', preparing_discharge: 'primary' }[status] || 'secondary';
    },
    formatDate(v) {
      if (!v) return '';
      const d = new Date(String(v).replace(' ', 'T'));
      return isNaN(d) ? v : d.toLocaleDateString(this.$i18n.locale, { dateStyle: 'medium' });
    },
    formatDateTime(v) {
      if (!v) return '';
      const d = new Date(String(v).replace(' ', 'T'));
      return isNaN(d) ? v : d.toLocaleString(this.$i18n.locale, { dateStyle: 'medium', timeStyle: 'short' });
    },
    async fetch() {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/hospital');
        this.patients = data.patients || [];
        this.visitHours = data.visit_hours || '';
      } catch (_) {}
      this.loading = false;
    },
  },
};
</script>

<style scoped>
.pre { white-space: pre-line; }
</style>
