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

      <!-- Specialist clinic (2026-10-26): book online -->
      <div v-if="clinic && patients.length" class="card mb-4">
        <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-calendar-plus me-1"></i>{{ $t('hosp_book_title') }}</h3></div>
        <div class="card-body">
          <div class="row g-2">
            <div v-if="patients.length > 1" class="col-md-3">
              <label class="form-label">{{ $t('hosp_book_patient') }}</label>
              <select v-model="book.patient_id" class="form-select"><option v-for="p in patients" :key="p.id" :value="p.id">{{ p.name }}</option></select>
            </div>
            <div class="col-md-5">
              <label class="form-label">{{ $t('hosp_book_doctor') }}</label>
              <select v-model="book.doctor_id" class="form-select" @change="loadSlots">
                <option :value="null">—</option>
                <option v-for="d in doctors" :key="d.id" :value="d.id">{{ d.specialty ? `${d.specialty} · ${d.name}` : d.name }}</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">{{ $t('hosp_book_date') }}</label>
              <input v-model="book.date" type="date" class="form-control" :min="today" @change="loadSlots" />
            </div>
          </div>
          <div v-if="book.doctor_id && book.date" class="mt-3">
            <label class="form-label">{{ $t('hosp_book_slots') }}</label>
            <div v-if="!slots.length" class="text-secondary">{{ $t('hosp_book_no_slots') }}</div>
            <div class="d-flex flex-wrap gap-2">
              <button v-for="t in slots" :key="t" type="button" class="btn btn-sm" :class="book.time === t ? 'btn-primary' : 'btn-outline-primary'" @click="book.time = t">{{ t }}</button>
            </div>
          </div>
          <div v-if="book.time" class="mt-3">
            <label class="form-label">{{ $t('hosp_book_reason') }}</label>
            <input v-model="book.reason" class="form-control" maxlength="255" />
            <button type="button" class="btn btn-primary mt-2" :disabled="saving" @click="doBook">{{ $t('hosp_book_button') }}</button>
          </div>
          <div v-if="notice" class="alert alert-success mt-3 mb-0">{{ notice }}</div>
          <div v-if="error" class="alert alert-danger mt-3 mb-0">{{ error }}</div>
        </div>
      </div>

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
              <template v-if="clinic">
                <span v-if="a.pre_registered_at" :class="badge('success')"><i class="ti ti-check me-1"></i>{{ $t('hosp_prereg_done') }}</span>
                <button v-else type="button" class="btn btn-sm btn-outline-primary" @click="openPrereg(a)">{{ $t('hosp_prereg') }}</button>
              </template>
            </div>
          </div>
        </div>

        <!-- Pre-registration form (from home) -->
        <div v-if="prereg && prereg.patient === p.id" class="card mb-3 border-primary">
          <div class="card-header"><h3 class="card-title mb-0">{{ $t('hosp_prereg') }} · {{ formatDateTime(prereg.scheduled_at) }}</h3></div>
          <div class="card-body">
            <p class="text-secondary">{{ $t('hosp_prereg_help') }}</p>
            <div class="mb-2"><label class="form-label">{{ $t('hosp_prereg_reason') }}</label><textarea v-model="prereg.reason" class="form-control" rows="2"></textarea></div>
            <div class="row g-2">
              <div class="col-md-4"><label class="form-label">{{ $t('hosp_prereg_since') }}</label><input v-model="prereg.symptoms_since" class="form-control" /></div>
              <div class="col-md-8"><label class="form-label">{{ $t('hosp_prereg_allergies') }}</label><input v-model="prereg.allergies" class="form-control" /></div>
            </div>
            <div class="my-2"><label class="form-label">{{ $t('hosp_prereg_medication') }}</label><textarea v-model="prereg.current_medication" class="form-control" rows="2"></textarea></div>
            <div class="mb-2"><label class="form-label">{{ $t('hosp_prereg_history') }}</label><textarea v-model="prereg.history" class="form-control" rows="2"></textarea></div>
            <button type="button" class="btn btn-primary" :disabled="saving" @click="sendPrereg">{{ $t('hosp_prereg_send') }}</button>
            <button type="button" class="btn btn-link" @click="prereg = null">{{ $t('hosp_cancel') }}</button>
          </div>
        </div>

        <!-- Surveys pending -->
        <div v-if="clinic && p.surveys && p.surveys.length" class="card mb-3">
          <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-mood-smile me-1"></i>{{ $t('hosp_surveys') }}</h3></div>
          <div class="list-group list-group-flush">
            <a v-for="sv in p.surveys" :key="sv.token" :href="sv.url" target="_blank" class="list-group-item list-group-item-action">
              {{ sv.doctor }} · {{ formatDate(sv.created_at) }} — <b>{{ $t('hosp_survey_answer') }}</b>
            </a>
          </div>
        </div>

        <!-- Prescriptions -->
        <div v-if="clinic" class="card mb-3">
          <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-prescription me-1"></i>{{ $t('hosp_rx_title') }}</h3></div>
          <div v-if="!p.prescriptions || !p.prescriptions.length" class="card-body text-secondary">{{ $t('hosp_rx_none') }}</div>
          <div v-else class="list-group list-group-flush">
            <div v-for="rx in p.prescriptions" :key="rx.reference" class="list-group-item d-flex align-items-center gap-3">
              <div class="flex-fill">
                <div class="fw-medium">{{ formatDate(rx.prescribed_on) }} · {{ rx.doctor }}<span v-if="rx.specialty" class="text-secondary"> · {{ rx.specialty }}</span></div>
                <div class="text-secondary small">{{ rx.items.join(', ') }}</div>
              </div>
              <a :href="`/api/portal/hospital/prescriptions/${rx.reference}`" target="_blank" class="btn btn-sm btn-outline-secondary">{{ $t('hosp_rx_print') }}</a>
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
  data() {
    return {
      patients: [], visitHours: '', loading: true,
      // Specialist clinic (2026-10-26)
      clinic: false, doctors: [], slots: [], saving: false, notice: '', error: '', prereg: null,
      book: { patient_id: null, doctor_id: null, date: '', time: '', reason: '' },
    };
  },
  computed: {
    today() { return new Date().toISOString().slice(0, 10); },
  },
  mounted() { this.fetch(); },
  methods: {
    badge,
    async loadSlots() {
      this.book.time = '';
      this.slots = [];
      if (!this.book.doctor_id || !this.book.date) return;
      try {
        const { data } = await http.get('/portal/hospital/slots', { params: { doctor_id: this.book.doctor_id, date: this.book.date } });
        this.slots = data.slots || [];
      } catch (_) {}
    },
    async doBook() {
      this.saving = true;
      this.notice = '';
      this.error = '';
      try {
        await http.post('/portal/hospital/book', { ...this.book, patient_id: this.book.patient_id || this.patients[0].id });
        this.notice = this.$t('hosp_book_done');
        this.book = { patient_id: this.book.patient_id, doctor_id: null, date: '', time: '', reason: '' };
        this.slots = [];
        this.fetch(true);
      } catch (e) {
        this.error = e?.response?.data?.message || this.$t('hosp_slot_taken');
      }
      this.saving = false;
    },
    openPrereg(a) {
      const p = this.patients.find(x => (x.appointments || []).includes(a));
      this.prereg = { reference: a.reference, scheduled_at: a.scheduled_at, patient: p ? p.id : null, reason: '', symptoms_since: '', current_medication: '', allergies: '', history: '' };
    },
    async sendPrereg() {
      this.saving = true;
      try {
        const { reference, scheduled_at, patient, ...body } = this.prereg;
        await http.post(`/portal/hospital/appointments/${reference}/pre-registration`, body);
        this.prereg = null;
        this.notice = this.$t('hosp_prereg_saved');
        this.fetch(true);
      } catch (_) {}
      this.saving = false;
    },
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
    async fetch(quiet = false) {
      if (!quiet) this.loading = true;
      try {
        const { data } = await http.get('/portal/hospital');
        this.patients = data.patients || [];
        this.visitHours = data.visit_hours || '';
        this.clinic = !!data.clinic;
        this.doctors = data.doctors || [];
        if (!this.book.patient_id && this.patients.length) this.book.patient_id = this.patients[0].id;
      } catch (_) {}
      this.loading = false;
    },
  },
};
</script>

<style scoped>
.pre { white-space: pre-line; }
</style>
