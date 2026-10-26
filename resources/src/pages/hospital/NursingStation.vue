<template>
  <div class="page">
    <PageHeader :title="s('Nursing station')" :breadcrumb="[s('Clinic'), s('Nursing station')]">
      <template #actions>
        <a-button @click="load"><ReloadOutlined /> {{ s('Refresh') }}</a-button>
      </template>
    </PageHeader>

    <a-row :gutter="12">
      <a-col :xs="24" :lg="9">
        <a-card size="small" :title="`${s('Waiting for vital signs')} (${waiting.length})`">
          <a-empty v-if="!waiting.length" :description="s('Nobody waiting')" />
          <div v-for="a in waiting" :key="a.id" class="row" :class="{ on: sel && sel.id === a.id }" @click="pick(a)">
            <div><b>{{ a.patient }}</b> <span class="muted">{{ a.age != null ? `${a.age} ${s('y')}` : '' }}</span></div>
            <div class="muted">{{ doctorOf(a) }} · {{ s('checked in') }} {{ since(a.checked_in_at) }}</div>
            <div v-if="a.allergies" class="allergy">{{ s('Allergies') }}: {{ a.allergies }}</div>
          </div>
        </a-card>
        <a-card size="small" :title="`${s('Ready to go in')} (${ready.length})`" style="margin-top: 12px">
          <div v-for="a in ready" :key="a.id" class="row ready" @click="pick(a)">
            <div><b>{{ a.patient }}</b> <a-tag color="green">{{ s('Ready') }}</a-tag></div>
            <div class="muted">{{ doctorOf(a) }} · {{ since(a.ready_at) }}</div>
          </div>
        </a-card>
      </a-col>
      <a-col :xs="24" :lg="15">
        <a-card v-if="sel" size="small" :title="`${s('Vital signs')} · ${sel.patient}`">
          <div class="muted" style="margin-bottom: 8px">{{ sel.mrn }} · {{ s(GENDER[sel.gender] || '') }} · {{ sel.reason || '' }}
            <template v-if="sel.last_weight"> · {{ s('last weight') }} {{ sel.last_weight }} kg</template>
          </div>
          <a-form layout="vertical">
            <a-row :gutter="10">
              <a-col :xs="12" :md="6"><a-form-item :label="s('Weight (kg)')"><a-input-number v-model:value="f.weight" :min="0" :max="600" :step="0.1" style="width: 100%" /></a-form-item></a-col>
              <a-col :xs="12" :md="6"><a-form-item :label="s('Height (cm)')"><a-input-number v-model:value="f.height" :min="0" :max="300" :step="0.5" style="width: 100%" /></a-form-item></a-col>
              <a-col :xs="12" :md="6"><a-form-item :label="s('Temperature °C')"><a-input-number v-model:value="f.temperature" :min="25" :max="45" :step="0.1" style="width: 100%" /></a-form-item></a-col>
              <a-col :xs="12" :md="6"><a-form-item :label="s('Pulse (bpm)')"><a-input-number v-model:value="f.pulse" :min="0" :max="300" style="width: 100%" /></a-form-item></a-col>
              <a-col :xs="12" :md="6"><a-form-item :label="s('BP systolic')"><a-input-number v-model:value="f.bp_systolic" :min="0" :max="300" style="width: 100%" /></a-form-item></a-col>
              <a-col :xs="12" :md="6"><a-form-item :label="s('BP diastolic')"><a-input-number v-model:value="f.bp_diastolic" :min="0" :max="250" style="width: 100%" /></a-form-item></a-col>
              <a-col :xs="12" :md="6"><a-form-item :label="s('Respiratory rate')"><a-input-number v-model:value="f.respiratory_rate" :min="0" :max="80" style="width: 100%" /></a-form-item></a-col>
              <a-col :xs="12" :md="6"><a-form-item label="SpO₂ %"><a-input-number v-model:value="f.spo2" :min="0" :max="100" style="width: 100%" /></a-form-item></a-col>
              <a-col v-if="sel.age != null && sel.age < 6" :xs="12" :md="6"><a-form-item :label="s('Head circumference (cm)')"><a-input-number v-model:value="f.head_circumference" :min="0" :max="80" :step="0.1" style="width: 100%" /></a-form-item></a-col>
            </a-row>
            <div class="bmi" v-if="bmiText">{{ s('BMI') }}: <b>{{ bmiText }}</b></div>
            <div v-if="alerts.length" class="alerts"><WarningOutlined /> {{ alerts.join(' · ') }}</div>
            <a-form-item :label="s('Note for the doctor')"><a-textarea v-model:value="f.notes" :rows="2" /></a-form-item>
            <a-button type="primary" size="large" :loading="saving" @click="save">{{ s('Save and send to the doctor') }}</a-button>
          </a-form>
        </a-card>
        <a-empty v-else :description="s('Pick a patient from the list')" />
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Nursing station (specialist clinic, 2026-10-26): the patients checked in at
 * reception wait here for their vital signs (weight, height, BP, temperature...);
 * saving them puts the patient "ready to go in" and tells the doctor (bell).
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { ReloadOutlined, WarningOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { bmi } from './specialtyTemplates';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/ClinicFlow.es.js';

const { s } = useHospitalText(ES);
const GENDER = { male: 'Male', female: 'Female', other: 'Other' };
const queue = ref([]);
const doctors = ref([]);
const sel = ref(null);
const saving = ref(false);
const f = reactive({});

const waiting = computed(() => queue.value.filter(a => a.flow === 'checked_in'));
const ready = computed(() => queue.value.filter(a => a.flow === 'ready'));
const since = iso => (iso ? `${dayjs().diff(dayjs(iso), 'minute')} min` : '');
const doctorOf = a => (doctors.value.find(d => d.id === a.doctor_id) || {}).name || '';
const bmiText = computed(() => bmi(f));
const alerts = computed(() => {
  const out = [];
  if (f.temperature >= 38) out.push(s('Fever'));
  if (f.bp_systolic >= 140 || f.bp_diastolic >= 90) out.push(s('High blood pressure'));
  if (f.spo2 && f.spo2 < 92) out.push(s('Low oxygen saturation'));
  if (f.pulse && (f.pulse > 120 || f.pulse < 50)) out.push(s('Abnormal pulse'));
  return out;
});

function pick(a) {
  sel.value = a;
  const v = a.vitals || {};
  Object.keys(f).forEach(k => delete f[k]);
  Object.assign(f, { weight: v.weight ?? null, height: v.height ?? a.last_height ?? null, temperature: v.temperature ?? null, pulse: v.pulse ?? null,
    bp_systolic: v.bp_systolic ?? null, bp_diastolic: v.bp_diastolic ?? null, respiratory_rate: v.respiratory_rate ?? null, spo2: v.spo2 ?? null,
    head_circumference: null, notes: '' });
}
async function load() {
  try {
    queue.value = (await http.get('hospital/clinic/nursing'))?.queue || [];
    if (!doctors.value.length) doctors.value = (await http.get('hospital/clinic/agenda', { date: dayjs().format('YYYY-MM-DD') }))?.doctors || [];
    if (sel.value) sel.value = queue.value.find(a => a.id === sel.value.id) || null;
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not load'));
  }
}
async function save() {
  saving.value = true;
  try {
    await http.post(`hospital/clinic/appointments/${sel.value.id}/vitals`, { ...f });
    message.success(s('Saved: the doctor sees the patient ready.'));
    sel.value = null;
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  } finally {
    saving.value = false;
  }
}

let timer = null;
onMounted(() => { load(); timer = setInterval(load, 30000); });
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.row { padding: 8px; border-radius: 8px; cursor: pointer; border: 1px solid transparent; margin-bottom: 4px; }
.row:hover, .row.on { border-color: #2563eb; background: rgba(37, 99, 235, 0.06); }
.row.ready { opacity: 0.85; }
.muted { opacity: 0.6; font-size: 12px; }
.allergy { color: #dc2626; font-size: 12px; }
.bmi { margin-bottom: 8px; }
.alerts { color: #dc2626; margin-bottom: 8px; font-weight: 600; }
</style>
