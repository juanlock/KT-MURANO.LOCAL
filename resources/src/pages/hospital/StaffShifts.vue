<template>
  <div class="page">
    <PageHeader :title="s('Staff shifts')" :breadcrumb="[s('Hospital'), s('Staff shifts')]">
      <template #actions>
        <a-button @click="shiftDay(-1)"><LeftOutlined /></a-button>
        <a-date-picker v-model:value="date" value-format="YYYY-MM-DD" :allow-clear="false" @change="load" />
        <a-button @click="shiftDay(1)"><RightOutlined /></a-button>
        <a-button v-if="canManage" :loading="copying" @click="copyPrevious">
          <template #icon><CopyOutlined /></template>{{ s('Copy the previous day') }}
        </a-button>
      </template>
    </PageHeader>

    <p class="hint">{{ s('Morning 07-15, afternoon 15-23, night 23-07; the emergency doctor works 24 h (07-07). Each 8-hour shift: 3 specialists, 1 nurse supervisor and 4 nurses.') }}</p>

    <a-spin :spinning="loading">
      <div class="grid">
        <a-card v-for="sh in SHIFT_ORDER" :key="sh" size="small" class="shift" :class="{ now: isNow(sh) }">
          <template #title>
            <span>{{ s(SHIFT_LABEL[sh]) }}</span>
            <span class="hours">{{ hours(sh) }}</span>
            <a-tag v-if="isNow(sh)" color="green" style="margin-left: 6px">{{ s('Now') }}</a-tag>
          </template>
          <div v-for="pos in positionsOf(sh)" :key="pos" class="pos">
            <div class="pos-title">{{ s(POS_LABEL[pos]) }} <span class="muted">({{ rowsOf(sh, pos).length }}/{{ quota(pos) }})</span></div>
            <div v-for="r in rowsOf(sh, pos)" :key="r.id" class="person">
              <div class="p-top">
                <b>{{ r.name }}</b>
                <span v-if="r.ward_id" class="muted"> · {{ wardName(r.ward_id) }}</span>
                <span class="p-actions" v-if="canManage">
                  <a-button size="small" @click="openPatients(r)">{{ s('Patients') }} ({{ r.patients.length }})</a-button>
                  <a-popconfirm :title="s('Remove from this shift?')" :ok-text="s('Yes')" :cancel-text="s('No')" @confirm="remove(r)">
                    <a-button size="small" danger type="text"><DeleteOutlined /></a-button>
                  </a-popconfirm>
                </span>
              </div>
              <div v-if="r.patients.length" class="chips">
                <a-tag v-for="p in r.patients" :key="p.id" :color="p.visited_at ? 'green' : 'default'">{{ p.name }}</a-tag>
              </div>
            </div>
            <div v-if="canManage && rowsOf(sh, pos).length < quota(pos)" class="add">
              <a-select
                v-model:value="pick[sh + pos]" show-search option-filter-prop="label" size="small" style="flex: 1; min-width: 0"
                :placeholder="KIND[pos] === 'doctor' ? s('Add a doctor') : s('Add a nurse')"
                :options="KIND[pos] === 'doctor' ? doctorOptions : nurseOptions(pos)"
              />
              <a-select v-if="KIND[pos] === 'nurse'" v-model:value="pickWard[sh + pos]" size="small" allow-clear style="width: 130px" :placeholder="s('Area')" :options="wardOptions" />
              <a-button size="small" type="primary" :disabled="!pick[sh + pos]" @click="add(sh, pos)"><PlusOutlined /></a-button>
            </div>
          </div>
        </a-card>
      </div>
    </a-spin>

    <a-modal v-model:open="patientsOpen" :title="target ? `${s('Patients of')} ${target.name}` : ''" :ok-text="s('Save')" :cancel-text="s('Cancel')" :confirm-loading="saving" @ok="savePatients">
      <p class="muted">{{ s('Patients in the hospital now. Each person sees only their own list in My patients.') }}</p>
      <a-checkbox-group v-model:value="chosen" style="width: 100%">
        <div v-for="c in census" :key="c.patient_id" class="census-row">
          <a-checkbox :value="c.patient_id">
            <b>{{ c.name }}</b> <span class="muted">{{ c.mrn }} · {{ s(AREA_LABEL[c.area] || c.area) }}<template v-if="c.where"> · {{ c.where }}</template></span>
          </a-checkbox>
        </div>
      </a-checkbox-group>
      <a-empty v-if="!census.length" :description="s('Nobody in the hospital now')" />
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Staff shifts (2026-10-21): the day's roster -- the 24 h emergency doctor and,
 * for the morning / afternoon / night shifts, 3 specialists, 1 nurse
 * supervisor and 4 nurses -- and the patients each one sees in the shift.
 * The head nurse / admin (hms_shifts) edits it; others only read it.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { LeftOutlined, RightOutlined, CopyOutlined, PlusOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/StaffShifts.es.js';

const { s } = useHospitalText(ES);

const SHIFT_ORDER = ['er24', 'morning', 'afternoon', 'night'];
const SHIFT_LABEL = { er24: 'Emergency guard (24 h)', morning: 'Morning', afternoon: 'Afternoon', night: 'Night' };
const POS_LABEL = { er_doctor: 'Emergency doctor', specialist: 'Specialists', nurse_supervisor: 'Nurse supervisor', nurse: 'Nurses' };
const KIND = { er_doctor: 'doctor', specialist: 'doctor', nurse_supervisor: 'nurse', nurse: 'nurse' };
const AREA_LABEL = { ward: 'Ward', er_bed: 'ER bed', waiting_room: 'Waiting room' };

const date = ref(dayjs().format('YYYY-MM-DD'));
const loading = ref(false);
const copying = ref(false);
const saving = ref(false);
const data = ref({ roster: [], shifts: {}, positions: {}, doctors: [], nurses: [], wards: [], census: [], can_manage: false });
const pick = reactive({});
const pickWard = reactive({});

const canManage = computed(() => data.value.can_manage);
const census = computed(() => data.value.census || []);
const doctorOptions = computed(() => sortByLabel((data.value.doctors || []).map(d => ({ value: d.id, label: d.specialty ? `${d.name} (${d.specialty})` : d.name }))));
const wardOptions = computed(() => sortByLabel((data.value.wards || []).map(w => ({ value: w.id, label: w.name }))));
const nurseOptions = pos => sortByLabel((data.value.nurses || [])
  .filter(n => pos !== 'nurse_supervisor' || n.head || !(data.value.nurses || []).some(x => x.head))
  .map(n => ({ value: n.id, label: n.name })));

const positionsOf = sh => Object.keys(data.value.positions || {}).filter(p => (data.value.positions[p][0] || []).includes(sh));
const quota = pos => (data.value.positions[pos] || [])[1] || 0;
const rowsOf = (sh, pos) => (data.value.roster || []).filter(r => r.shift === sh && r.position === pos);
const wardName = id => (data.value.wards.find(w => w.id === id) || {}).name;
function hours(sh) {
  const [h, len] = data.value.shifts[sh] || [0, 0];
  const end = (h + len) % 24;
  return `${String(h).padStart(2, '0')}:00 – ${String(end).padStart(2, '0')}:00`;
}
function isNow(sh) {
  const [h, len] = data.value.shifts[sh] || [0, 0];
  const start = dayjs(date.value).hour(h).minute(0);
  const now = dayjs();
  return now.isAfter(start) && now.isBefore(start.add(len, 'hour'));
}

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('hospital/shifts', { date: date.value });
  } catch (e) {
    message.error(e?.data?.message || s('Could not load'));
  } finally {
    loading.value = false;
  }
}
function shiftDay(n) {
  date.value = dayjs(date.value).add(n, 'day').format('YYYY-MM-DD');
  load();
}
async function add(sh, pos) {
  const id = pick[sh + pos];
  const body = { date: date.value, shift: sh, position: pos, ward_id: pickWard[sh + pos] || null };
  if (KIND[pos] === 'doctor') body.doctor_id = id; else body.employee_id = id;
  try {
    await http.post('hospital/shifts', body);
    pick[sh + pos] = undefined;
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  }
}
async function remove(r) {
  try {
    await http.delete(`hospital/shifts/${r.id}`);
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not delete'));
  }
}
async function copyPrevious() {
  copying.value = true;
  try {
    const d = await http.post('hospital/shifts/copy-day', { from: dayjs(date.value).subtract(1, 'day').format('YYYY-MM-DD'), to: date.value });
    message.success(`${s('Copied')}: ${d.copied}`);
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  } finally {
    copying.value = false;
  }
}

const patientsOpen = ref(false);
const target = ref(null);
const chosen = ref([]);
function openPatients(r) {
  target.value = r;
  chosen.value = r.patients.map(p => p.patient_id);
  patientsOpen.value = true;
}
async function savePatients() {
  saving.value = true;
  try {
    await http.post(`hospital/shifts/${target.value.id}/patients`, { patient_ids: chosen.value });
    patientsOpen.value = false;
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.hint { opacity: 0.7; margin: -4px 0 12px; font-size: 13px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 12px; }
.shift.now { border-color: #16a34a; box-shadow: 0 0 0 1px #16a34a inset; }
.hours { font-weight: 400; opacity: 0.6; margin-left: 8px; font-size: 12px; }
.pos { margin-bottom: 12px; }
.pos-title { font-weight: 600; margin-bottom: 6px; }
.person { padding: 6px 8px; border: 1px solid rgba(128, 128, 128, 0.18); border-radius: 8px; margin-bottom: 6px; }
.p-top { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.p-actions { margin-left: auto; display: flex; gap: 4px; }
.chips { margin-top: 4px; display: flex; flex-wrap: wrap; gap: 2px; }
.add { display: flex; gap: 6px; }
.muted { opacity: 0.6; font-size: 12px; }
.census-row { padding: 4px 0; border-bottom: 1px dashed rgba(128, 128, 128, 0.2); }
</style>
