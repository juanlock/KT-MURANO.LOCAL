<template>
  <!--
    My round (user's request, 2026-10-25): the doctor's / nurse's patients of
    the shift drawn on the floor plan with the route to follow (1 -> 2 -> 3...),
    and a card per patient in that order with what matters today: who, what
    they suffer from, medication given / still due / not given, care due, last
    vitals and recent lab results. A card (or its number on the plan) opens the
    visit: the doctor writes the progress or a prescription; the nurse records
    doses, vital signs or a change seen.
  -->
  <div class="page">
    <PageHeader :title="s('My round')" :subtitle="subtitle" :breadcrumb="[s('Hospital'), s('My round')]">
      <template #actions>
        <a-tag :color="data.mode === 'doctor' ? 'blue' : 'green'">{{ data.mode === 'doctor' ? s('Doctor') : s('Nursing') }}</a-tag>
        <a-switch v-if="data.can_all" v-model:checked="showAll" @change="load" /> <span v-if="data.can_all">{{ s('Every patient') }}</span>
        <a-button :loading="loading" @click="load"><template #icon><ReloadOutlined /></template>{{ s('Refresh') }}</a-button>
      </template>
    </PageHeader>

    <div class="sum">
      <div class="sum-i"><b>{{ patients.length }}</b><span>{{ s('patients') }}</span></div>
      <div class="sum-i red"><b>{{ total('late') }}</b><span>{{ s('doses late') }}</span></div>
      <div class="sum-i orange"><b>{{ total('pending') }}</b><span>{{ s('doses still due today') }}</span></div>
      <div class="sum-i blue"><b>{{ careDue }}</b><span>{{ s('care due') }}</span></div>
      <div class="sum-i green"><b>{{ visited }}</b><span>{{ s('visited') }}</span></div>
    </div>

    <a-spin :spinning="loading">
      <a-empty v-if="!loading && !patients.length" :description="s('You have no patients in this shift.')" />

      <!-- The plan with the route -->
      <a-card v-if="floors.length" size="small" class="plan-card">
        <a-segmented v-if="floors.length > 1" v-model:value="floorId" :options="floors.map(f => ({ value: f.id, label: `${f.name} (${onFloor(f.id).length})` }))" style="margin-bottom: 8px" />
        <div ref="planWrap" class="plan-wrap">
          <div v-if="floor" class="plan" :style="{ width: cell * cols + 'px', height: cell * rows + 'px', backgroundSize: `${cell}px ${cell}px` }">
            <div v-for="r in floor.rooms" :key="'r' + r.id" class="room" :class="'k-' + r.kind"
              :style="{ left: r.x * cell + 'px', top: r.y * cell + 'px', width: r.w * cell + 'px', height: r.h * cell + 'px' }">
              <span>{{ r.name }}</span>
            </div>
            <div v-for="b in floor.beds.filter(x => x.x != null)" :key="'b' + b.id" class="bed" :class="{ mine: mineBed(b.id), occ: b.patient_id }"
              :style="{ left: b.x * cell + 3 + 'px', top: b.y * cell + 3 + 'px', width: cell - 6 + 'px', height: cell - 6 + 'px' }" />
            <svg class="route" :width="cell * cols" :height="cell * rows">
              <polyline :points="routePoints" fill="none" stroke="#2563eb" stroke-width="3" stroke-dasharray="7 5" stroke-linecap="round" />
            </svg>
            <a-tooltip v-for="p in onFloor(floorId)" :key="'p' + p.patient_id" :title="`${p.route_no}. ${p.name} — ${p.suffers || ''}`">
              <button type="button" class="stop" :class="stopClass(p)" :style="{ left: p.x * cell + cell / 2 - 15 + 'px', top: p.y * cell + cell / 2 - 15 + 'px' }" @click="open(p)">{{ p.route_no }}</button>
            </a-tooltip>
          </div>
        </div>
        <div class="legend"><span class="lg red" />{{ s('late doses') }} <span class="lg orange" />{{ s('due today') }} <span class="lg green" />{{ s('up to date') }} <span class="lg grey" />{{ s('visited') }}</div>
      </a-card>

      <!-- Cards in route order -->
      <div class="cards">
        <div v-for="p in patients" :key="p.patient_id" class="pcard" :class="[stopClass(p), { visited: p.visited_at }]" @click="open(p)">
          <div class="pc-top">
            <span class="no">{{ p.route_no }}</span>
            <div class="pc-id">
              <b>{{ p.name }}</b>
              <div class="muted">{{ [p.age != null ? p.age + ' ' + s('y') : null, p.mrn, p.day ? s('day {n}', { n: p.day }) : null].filter(Boolean).join(' · ') }}</div>
            </div>
            <a-tag v-if="p.visited_at" color="default">✓ {{ s('Visited') }}</a-tag>
          </div>
          <div class="where">📍 {{ [p.floor_name, p.room, p.bed].filter(Boolean).join(' · ') || p.where || s(AREA[p.area] || p.area) }}</div>
          <div v-if="p.suffers" class="suffers"><b>{{ s('Suffers from') }}:</b> {{ p.suffers }}</div>
          <div v-if="p.allergies" class="allergy">⚠ {{ s('Allergies') }}: {{ p.allergies }}</div>

          <div class="sec">{{ s('Medication today') }}
            <span class="muted"> · {{ s('{g} given, {p} still due', { g: p.doses_count.given, p: p.doses_count.pending }) }}</span>
          </div>
          <div class="doses">
            <span v-for="d in p.doses" :key="d.id" class="dose" :class="doseClass(d)">
              {{ hm(d.scheduled_at) }} {{ d.medicine.split(' (')[0] }}
            </span>
            <span v-if="!p.doses.length" class="muted">{{ s('No medication today.') }}</span>
          </div>
          <div v-if="p.doses_count.missed_yesterday" class="miss">✕ {{ s('{n} dose(s) not given yesterday', { n: p.doses_count.missed_yesterday }) }}</div>

          <template v-if="p.care_due.length">
            <div class="sec">{{ s('Care and diet') }}</div>
            <div v-for="o in p.care_due" :key="o.id" class="care" :class="{ due: o.due }">{{ o.due ? '⏰ ' : '' }}{{ o.description }}</div>
          </template>
          <div v-if="p.vitals" class="vit">❤ {{ vitalsLine(p.vitals) }} <span class="muted">· {{ hm(p.vitals.taken_at) }}</span></div>
          <template v-if="p.labs.length">
            <div class="sec">{{ s('Recent lab results') }}</div>
            <div class="labs">
              <span v-for="(l, i) in p.labs.slice(0, 4)" :key="i" class="lab" :class="'f-' + (l.flag || 'none')">
                {{ l.test_name }}: <b>{{ l.result_value ?? s('pending') }}</b>{{ l.unit && l.result_value ? ' ' + l.unit : '' }}
              </span>
            </div>
          </template>
        </div>
      </div>
    </a-spin>

    <RoundVisit :open="!!current" :patient="current" :mode="data.mode" :can-prescribe="data.can_prescribe" :routes="data.routes || []"
      @close="current = null" @changed="reload" @chart="openChart" />
    <ClinicalDrawer :open="!!chartId" :admission-id="chartId" @close="chartId = null" @changed="load" />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { ReloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import RoundVisit from './RoundVisit.vue';
import ClinicalDrawer from './ClinicalDrawer.vue';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/Rounds.es.js';

const { s } = useHospitalText(ES);
const AREA = { ward: 'Ward', er_bed: 'ER bed', waiting_room: 'Waiting room' };

const loading = ref(false);
const showAll = ref(false);
const data = ref({ patients: [], floors: [], shifts: [] });
const patients = computed(() => data.value.patients || []);
const floors = computed(() => data.value.floors || []);
const floorId = ref(null);
const floor = computed(() => floors.value.find(f => f.id === floorId.value) || null);
const current = ref(null);
const chartId = ref(null);

const subtitle = computed(() => {
  const sh = (data.value.shifts || []).map(x => x.name).join(', ');
  return sh ? s('Shift: {s}', { s: sh }) : s('Your patients, in the order to visit them.');
});
const total = k => patients.value.reduce((n, p) => n + (p.doses_count?.[k] || 0), 0);
const careDue = computed(() => patients.value.reduce((n, p) => n + p.care_due.filter(o => o.due).length, 0));
const visited = computed(() => patients.value.filter(p => p.visited_at).length);
const onFloor = id => patients.value.filter(p => p.floor_id === id && p.x != null);
const mineBed = id => patients.value.some(p => p.bed_id === id);
const hm = v => (v ? dayjs(v).format('HH:mm') : '');

// The plan: same grid as Configuration -> Floor map, sized to the card.
const planWrap = ref(null);
const width = ref(900);
let ro = null;
const cols = computed(() => floor.value?.map_cols || 24);
const rows = computed(() => {
  const bottom = Math.max(0, ...(floor.value?.rooms || []).map(r => (r.y ?? 0) + (r.h ?? 0)));
  return Math.min(floor.value?.map_rows || 14, Math.max(4, bottom + 1));
});
const cell = computed(() => Math.max(18, Math.floor(width.value / cols.value)));
const routePoints = computed(() => onFloor(floorId.value).map(p => `${p.x * cell.value + cell.value / 2},${p.y * cell.value + cell.value / 2}`).join(' '));

function stopClass(p) {
  if (p.visited_at) return 'st-visited';
  if (p.doses_count.late) return 'st-late';
  if (p.doses_count.pending || p.care_due.some(o => o.due)) return 'st-due';
  return 'st-ok';
}
function doseClass(d) {
  if (d.status === 'given') return 'given';
  if (['omitted', 'refused', 'missed'].includes(d.status)) return 'notgiven';
  return d.late ? 'late' : 'pending';
}
function vitalsLine(v) {
  return [v.temperature ? `${v.temperature}°C` : null, v.pulse ? `${v.pulse} lpm` : null,
    v.bp_systolic ? `PA ${v.bp_systolic}/${v.bp_diastolic || ''}` : null, v.spo2 ? `SpO₂ ${v.spo2}%` : null].filter(Boolean).join(' · ');
}

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('hospital/rounds', showAll.value ? { all: 1 } : {});
    if (!floors.value.find(f => f.id === floorId.value)) floorId.value = floors.value[0]?.id ?? null;
    if (current.value) current.value = patients.value.find(p => p.patient_id === current.value.patient_id) || null;
    await nextTick();
    if (planWrap.value && !ro) {
      ro = new ResizeObserver(e => { width.value = e[0].contentRect.width || 900; });
      ro.observe(planWrap.value);
    }
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not load'));
  } finally {
    loading.value = false;
  }
}
const reload = () => load();
function open(p) { current.value = p; }
function openChart(admissionId) { current.value = null; chartId.value = admissionId; }

watch(floorId, () => nextTick(() => planWrap.value && (width.value = planWrap.value.clientWidth || width.value)));
let timer = null;
onMounted(() => { load(); timer = setInterval(() => { if (!current.value && !chartId.value) load(); }, 60000); });
onBeforeUnmount(() => { clearInterval(timer); if (ro) ro.disconnect(); });
</script>

<style scoped>
.sum { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 12px; }
.sum-i { border-radius: 12px; padding: 10px 14px; background: rgba(100, 116, 139, 0.1); display: flex; flex-direction: column; }
.sum-i b { font-size: 24px; line-height: 1.1; }
.sum-i span { font-size: 12.5px; opacity: 0.8; }
.sum-i.red { background: rgba(220, 38, 38, 0.1); color: #b91c1c; }
.sum-i.orange { background: rgba(217, 119, 6, 0.12); color: #b45309; }
.sum-i.blue { background: rgba(37, 99, 235, 0.1); color: #1d4ed8; }
.sum-i.green { background: rgba(22, 163, 74, 0.1); color: #15803d; }
.plan-card { margin-bottom: 12px; }
.plan-wrap { width: 100%; overflow: auto; }
.plan { position: relative; border: 1px solid rgba(128, 128, 128, 0.3); border-radius: 8px; background-color: rgba(128, 128, 128, 0.03);
  background-image: linear-gradient(rgba(128, 128, 128, 0.1) 1px, transparent 1px), linear-gradient(90deg, rgba(128, 128, 128, 0.1) 1px, transparent 1px); }
.room { position: absolute; border: 1.5px solid rgba(15, 23, 42, 0.22); border-radius: 6px; padding: 2px 5px; font-size: 11.5px; font-weight: 700; color: #0f172a; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.k-room { background: #e0f2fe; } .k-office { background: #f1f5f9; } .k-er { background: #fee2e2; } .k-nursing { background: #dcfce7; }
.k-or { background: #ede9fe; } .k-lab { background: #fef9c3; } .k-imaging { background: #fae8ff; } .k-pharmacy { background: #ccfbf1; }
.k-reception { background: #ffedd5; } .k-waiting { background: #f5f5f4; } .k-bath { background: #e2e8f0; } .k-stairs { background: #d4d4d8; } .k-other { background: #f8fafc; }
.bed { position: absolute; border-radius: 4px; background: rgba(148, 163, 184, 0.5); }
.bed.occ { background: rgba(239, 68, 68, 0.35); }
.bed.mine { background: rgba(37, 99, 235, 0.25); }
.route { position: absolute; left: 0; top: 0; pointer-events: none; z-index: 4; }
.stop { position: absolute; z-index: 5; width: 30px; height: 30px; border-radius: 50%; border: 3px solid #fff; color: #fff; font-weight: 800; cursor: pointer;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.35); display: flex; align-items: center; justify-content: center; font-size: 13px; transition: transform 0.12s; }
.stop:hover { transform: scale(1.15); }
.stop.st-late { background: #dc2626; } .stop.st-due { background: #d97706; } .stop.st-ok { background: #16a34a; } .stop.st-visited { background: #94a3b8; }
.legend { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; font-size: 12px; margin-top: 6px; }
.lg { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-left: 8px; }
.lg.red { background: #dc2626; } .lg.orange { background: #d97706; } .lg.green { background: #16a34a; } .lg.grey { background: #94a3b8; }
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 12px; }
.pcard { border: 1px solid rgba(128, 128, 128, 0.22); border-left: 6px solid #16a34a; border-radius: 12px; padding: 10px 12px; cursor: pointer; background: var(--ant-color-bg-container, #fff); transition: box-shadow 0.15s, transform 0.15s; }
.pcard:hover { box-shadow: 0 6px 18px rgba(15, 23, 42, 0.12); transform: translateY(-1px); }
.pcard.st-late { border-left-color: #dc2626; } .pcard.st-due { border-left-color: #d97706; } .pcard.st-visited { border-left-color: #94a3b8; opacity: 0.85; }
.pc-top { display: flex; align-items: center; gap: 10px; }
.no { width: 30px; height: 30px; border-radius: 50%; background: #2563eb; color: #fff; font-weight: 800; display: flex; align-items: center; justify-content: center; flex: none; }
.pc-id { flex: 1; min-width: 0; }
.pc-id b { font-size: 15px; }
.muted { opacity: 0.6; font-size: 12px; }
.where { font-size: 12.5px; margin-top: 6px; }
.suffers { font-size: 13px; margin-top: 4px; }
.allergy { font-size: 12.5px; color: #dc2626; font-weight: 600; margin-top: 2px; }
.sec { font-weight: 700; font-size: 12.5px; margin-top: 8px; }
.doses { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px; }
.dose { font-size: 11.5px; padding: 1px 6px; border-radius: 10px; border: 1px solid transparent; }
.dose.given { background: rgba(22, 163, 74, 0.12); color: #15803d; }
.dose.given::before { content: '✓ '; }
.dose.pending { background: rgba(217, 119, 6, 0.12); color: #b45309; }
.dose.late { background: rgba(220, 38, 38, 0.12); color: #b91c1c; font-weight: 700; }
.dose.notgiven { background: rgba(100, 116, 139, 0.15); color: #475569; text-decoration: line-through; }
.miss { font-size: 12px; color: #b91c1c; margin-top: 3px; }
.care { font-size: 12.5px; }
.care.due { color: #1d4ed8; font-weight: 600; }
.vit { font-size: 12.5px; margin-top: 6px; }
.labs { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px; }
.lab { font-size: 11.5px; padding: 1px 6px; border-radius: 6px; background: rgba(100, 116, 139, 0.1); }
.lab.f-critical { background: rgba(220, 38, 38, 0.15); color: #b91c1c; }
.lab.f-high, .lab.f-low { background: rgba(217, 119, 6, 0.15); color: #b45309; }
</style>
