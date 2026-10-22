<template>
  <div class="page">
    <PageHeader :title="s('My patients')" :breadcrumb="[s('Hospital'), s('My patients')]">
      <template #actions>
        <a-checkbox v-if="data.can_all" v-model:checked="all" @change="load">{{ s('Everyone in the hospital') }}</a-checkbox>
        <a-button @click="load"><template #icon><ReloadOutlined /></template>{{ s('Refresh') }}</a-button>
      </template>
    </PageHeader>

    <div class="shifts">
      <template v-if="data.shifts.length">
        <a-tag v-for="sh in data.shifts" :key="sh.id" color="blue">{{ s(SHIFT_LABEL[sh.shift]) }} · {{ s(POS_LABEL[sh.position]) }} · {{ sh.date }}</a-tag>
      </template>
      <span v-else class="muted">{{ s('You are not on a shift now.') }}</span>
    </div>

    <a-spin :spinning="loading">
      <a-empty v-if="!data.patients.length" :description="s('No patients assigned to you in this shift.')" />
      <div class="cards">
        <div v-for="p in data.patients" :key="p.patient_id" class="card" :class="{ visited: p.visited_at }">
          <div class="c-top">
            <span v-if="p.triage_level" class="triage" :style="{ background: TRIAGE_COLOR[p.triage_level] }">{{ p.triage_level }}</span>
            <div class="c-who">
              <div class="c-name">{{ p.name }}</div>
              <div class="muted">{{ p.mrn }} · {{ s(AREA_LABEL[p.area] || p.area) }}<template v-if="p.where"> · {{ p.where }}</template></div>
            </div>
            <a-button v-if="p.assignment_id" size="small" :type="p.visited_at ? 'primary' : 'default'" @click="toggleVisited(p)">
              <CheckOutlined /> {{ p.visited_at ? s('Visited') : s('Mark visited') }}
            </a-button>
          </div>
          <div v-if="p.allergies" class="allergy"><WarningOutlined /> {{ s('Allergies') }}: {{ p.allergies }}</div>
          <div class="doses">
            <div class="d-title">{{ s('Doses due (next 2 h)') }}</div>
            <div v-if="!p.doses.length" class="muted">{{ s('None') }}</div>
            <div v-for="d in p.doses" :key="d.id" class="dose" :class="{ late: d.overdue }">
              <span class="d-time">{{ time(d.scheduled_at) }}</span>
              <span class="d-med">{{ d.medicine }} · {{ d.dose }} · {{ s(ROUTE_LABEL[d.route] || d.route || '') }}</span>
              <a-tag v-if="!d.dispensed" color="orange">{{ s('Not dispensed') }}</a-tag>
              <a-button size="small" type="primary" @click="give(d, 'given')">{{ s('Given') }}</a-button>
              <a-button size="small" @click="give(d, 'omitted')">{{ s('Omitted') }}</a-button>
            </div>
          </div>
          <div class="c-actions">
            <PermLink v-if="p.treatment_id" :to="`/hospital/treatments?id=${p.treatment_id}`" :permission="['hms_treatments', 'hms_treatments_administer']">{{ s('Open treatment') }}</PermLink>
            <span v-else class="muted">{{ s('No active treatment') }}</span>
            <PermLink :to="`/hospital/patients/${p.patient_id}`" permission="hms_patients_view">{{ s('Patient record') }}</PermLink>
          </div>
        </div>
      </div>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * My patients (2026-10-21): the logged-in doctor's / nurse's patients for the
 * shift running now (Staff shifts), where each one is, allergies, the doses
 * due in the next 2 hours (overdue in red) with Given / Omitted, and "visited".
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { ReloadOutlined, CheckOutlined, WarningOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/MyPatients.es.js';

const { s } = useHospitalText(ES);
const SHIFT_LABEL = { er24: 'Emergency guard (24 h)', morning: 'Morning', afternoon: 'Afternoon', night: 'Night' };
const POS_LABEL = { er_doctor: 'Emergency doctor', specialist: 'Specialist', nurse_supervisor: 'Nurse supervisor', nurse: 'Nurse' };
const AREA_LABEL = { ward: 'Ward', er_bed: 'ER bed', waiting_room: 'Waiting room', gone: 'Discharged' };
const ROUTE_LABEL = { oral: 'Oral', iv: 'IV', im: 'IM', sc: 'SC', topical: 'Topical', inhaled: 'Inhaled', sublingual: 'Sublingual', rectal: 'Rectal', other: 'Other' };
const TRIAGE_COLOR = { 1: '#dc2626', 2: '#ea580c', 3: '#ca8a04', 4: '#16a34a', 5: '#2563eb' };

const loading = ref(false);
const all = ref(false);
const data = ref({ shifts: [], patients: [], can_all: false });
const time = iso => dayjs(iso).format('DD/MM HH:mm');

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('hospital/my-patients', { all: all.value ? 1 : '' });
  } catch (e) {
    message.error(e?.data?.message || s('Could not load'));
  } finally {
    loading.value = false;
  }
}
async function toggleVisited(p) {
  try {
    await http.post(`hospital/my-patients/${p.assignment_id}/visited`, {});
    load();
  } catch (e) {
    message.error(s('Could not save'));
  }
}
async function give(d, status) {
  try {
    await http.post(`hospital/doses/${d.id}`, { status });
    message.success(s('Saved'));
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  }
}

let timer = null;
onMounted(() => { load(); timer = setInterval(load, 120000); });
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.shifts { margin-bottom: 12px; display: flex; gap: 6px; flex-wrap: wrap; }
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 12px; }
.card { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 12px; padding: 12px; background: var(--ant-color-bg-container, #fff); }
.card.visited { border-color: #16a34a; }
.c-top { display: flex; gap: 8px; align-items: center; }
.c-who { flex: 1; min-width: 0; }
.c-name { font-weight: 600; }
.triage { width: 28px; height: 28px; border-radius: 50%; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; flex: none; }
.allergy { color: #dc2626; font-size: 12px; margin-top: 4px; }
.doses { margin-top: 8px; }
.d-title { font-weight: 600; font-size: 13px; margin-bottom: 4px; }
.dose { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; font-size: 13px; padding: 3px 0; }
.dose.late .d-time { color: #dc2626; font-weight: 700; }
.d-med { flex: 1; min-width: 120px; }
.c-actions { margin-top: 10px; display: flex; gap: 14px; font-size: 13px; }
.muted { opacity: 0.6; font-size: 12px; }
</style>
