<template>
  <!--
    Hospital control tower (user's analysis, 2026-10-25): every bed by status in
    real time (refreshes every 30 s), the floor maps, and the alerts with their
    one-click action -- beds waiting cleaning +30 min (mark clean), discharged
    patients still in the room (empty bed), long waits in the waiting room.
  -->
  <div class="page">
    <PageHeader :title="s('Control tower')" :subtitle="s('Beds in real time, alerts and the maps of every floor.')" :breadcrumb="[s('Hospital'), s('Control tower')]">
      <template #actions><a-button @click="load"><template #icon><ReloadOutlined /></template>{{ s('Refresh') }}</a-button></template>
    </PageHeader>

    <div class="counts">
      <div v-for="st in STATUSES" :key="st" class="cnt" :class="'s-' + st"><b>{{ t.counts?.[st] || 0 }}</b><span>{{ s(LABEL[st]) }}</span></div>
    </div>

    <a-row :gutter="12">
      <a-col :xs="24" :lg="8">
        <a-card size="small" :title="s('Alerts')" class="alerts">
          <a-empty v-if="!alertCount" :description="s('All in order.')" />
          <div v-for="b in t.alerts?.cleaning_late || []" :key="'c' + b.bed_id" class="al bad">
            <span>🧹 <b>{{ b.ward }} · {{ b.bed }}</b> — {{ s('waiting cleaning {m}', { m: dur(b.minutes) }) }}</span>
            <a-button size="small" @click="clean(b.bed_id)">{{ s('Mark clean') }}</a-button>
          </div>
          <div v-for="x in t.alerts?.to_free || []" :key="'f' + x.admission_id" class="al warn">
            <span>🛏 <b>{{ x.patient }}</b> — {{ x.ward }} · {{ x.bed }}: {{ s('discharged, still in the room') }}<template v-if="x.minutes != null"> ({{ dur(x.minutes) }})</template></span>
            <a-button size="small" @click="emptyBed(x.admission_id)">{{ s('Empty bed') }}</a-button>
          </div>
          <div v-for="w in t.alerts?.waiting_long || []" :key="'w' + w.er_case_id" class="al warn">
            <span>⏱ <b>{{ w.patient }}</b> — {{ s('triage {t}, waiting {m}', { t: w.triage || '?', m: dur(w.minutes) }) }}</span>
            <PermLink to="/hospital/admission-desk" :permission="['hms_admission_desk', 'hms_visitors']"><a-button size="small">{{ s('Admission desk') }}</a-button></PermLink>
          </div>
        </a-card>
      </a-col>
      <a-col :xs="24" :lg="16">
        <a-card size="small">
          <a-segmented v-if="floors.length" v-model:value="floorId" :options="floors.map(f => ({ value: f.id, label: `${f.name} (${f.beds.filter(b => b.status === 'available').length})` }))" style="margin-bottom: 10px" />
          <FloorMap v-if="floor" :floor="floor" :status-label="st => s(LABEL[st] || st)" :pickable="() => false" />
          <a-empty v-else :description="s('No floor maps yet: Hospital -> Configuration -> Floor map.')" />
          <div class="legend"><span v-for="st in STATUSES" :key="st"><i :class="'s-' + st" /> {{ s(LABEL[st]) }}</span></div>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { message } from 'ant-design-vue';
import { ReloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import FloorMap from './FloorMap.vue';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/ControlTower.es.js';

const { s } = useHospitalText(ES);
const STATUSES = ['available', 'occupied', 'cleaning', 'reserved', 'maintenance'];
const LABEL = { available: 'Available', occupied: 'Occupied', cleaning: 'Cleaning', reserved: 'Reserved', maintenance: 'Maintenance' };
const t = ref({});
const floors = ref([]);
const floorId = ref(null);
const floor = computed(() => floors.value.find(f => f.id === floorId.value) || null);
// 45 -> "45 min", 914 -> "15 h 14 min"
const dur = (m) => (m >= 60 ? `${Math.floor(m / 60)} h${m % 60 ? ` ${m % 60} min` : ''}` : `${m} min`);
const alertCount = computed(() => ['cleaning_late', 'to_free', 'waiting_long'].reduce((n, k) => n + (t.value.alerts?.[k]?.length || 0), 0));

async function load() {
  try {
    const [tower, map] = await Promise.all([http.get('hospital/control-tower'), http.get('hospital/bed-map')]);
    t.value = tower;
    floors.value = (map?.floors || []).filter(f => f.beds.length || f.rooms.length);
    if (!floors.value.find(f => f.id === floorId.value)) floorId.value = floors.value[0]?.id ?? null;
  } catch (e) {
    message.error(s('Could not load'));
  }
}
async function clean(bedId) {
  try { await http.post(`hospital/beds/${bedId}/clean`); message.success(s('Bed available')); load(); } catch (e) { message.error(e?.data?.message || s('Could not save')); }
}
async function emptyBed(admissionId) {
  try { await http.post(`hospital/admissions/${admissionId}/left`); message.success(s('Bed sent to cleaning')); load(); } catch (e) { message.error(e?.data?.message || s('Could not save')); }
}
let timer = null;
onMounted(() => { load(); timer = setInterval(load, 30000); });
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.counts { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 12px; }
.cnt { border-radius: 12px; padding: 10px 14px; color: #fff; display: flex; flex-direction: column; }
.cnt b { font-size: 26px; line-height: 1.1; }
.cnt span { font-size: 12.5px; opacity: 0.9; }
.s-available { background: #16a34a; } .s-occupied { background: #ef4444; } .s-cleaning { background: #7c3aed; } .s-reserved { background: #d97706; } .s-maintenance { background: #94a3b8; }
.alerts :deep(.ant-card-body) { display: flex; flex-direction: column; gap: 8px; }
.al { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 8px; font-size: 13px; }
.al.bad { background: rgba(220, 38, 38, 0.08); border: 1px solid rgba(220, 38, 38, 0.3); }
.al.warn { background: rgba(217, 119, 6, 0.08); border: 1px solid rgba(217, 119, 6, 0.3); }
.legend { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 8px; font-size: 12.5px; }
.legend i { display: inline-block; width: 11px; height: 11px; border-radius: 3px; vertical-align: -1px; margin-inline-end: 4px; }
</style>
