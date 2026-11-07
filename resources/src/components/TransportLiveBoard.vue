<template>
  <!-- Control board (user's request, 2026-11-06): one screen, the map in the center. Where each bus is, where it is going
       and how late it runs. Long lists scroll inside their box; what needs explaining is in the (i). -->
  <div class="lb">
    <div v-if="alertLine" class="lb-alert" role="alert"><WarningFilled /> {{ alertLine }}</div>

    <div class="lb-top">
      <div class="lb-strip"><TransportStrip :items="strip" :toggle="false" /></div>
      <a-date-picker v-if="pickDate" v-model:value="date" class="lb-date" size="small" value-format="YYYY-MM-DD" :allow-clear="false" @change="load()" />
      <a-tooltip :title="T(`Late = more than ${lateMin} minutes against the plan. Solid marker: the crew's phone GPS. Hollow marker: the last terminal reported, with no live position. Refreshes every 45 seconds.`, `Tardanza = más de ${lateMin} minutos contra lo previsto. Marca llena: GPS del celular de la tripulación. Marca hueca: la última terminal reportada, sin posición en vivo. Se actualiza cada 45 segundos.`)"><InfoCircleOutlined class="lb-info" /></a-tooltip>
    </div>

    <div ref="grid" class="lb-grid" :style="fit ? { height: fit + 'px' } : null">
      <div ref="mapBox" class="lb-map-box">
        <svg :viewBox="`0 0 ${W} ${H}`" class="lb-map" role="img" :aria-label="T('Map of the buses', 'Mapa de los buses')">
          <rect :width="W" :height="H" fill="#dbeafe" />
          <image v-for="t in map.tiles" :key="t.key" :href="t.url" :x="t.x" :y="t.y" :width="t.size" :height="t.size" />
          <line v-for="b in map.buses.filter(x => x.tx !== null)" :key="'l' + b.id" :x1="b.x" :y1="b.y" :x2="b.tx" :y2="b.ty" class="lb-line" :class="{ 'lb-line-on': selected === b.id }" />
          <g v-for="p in map.places" :key="'p' + p.id">
            <circle :cx="p.x" :cy="p.y" r="5" fill="#1e293b" stroke="#fff" stroke-width="2" />
            <text :x="p.x + 8" :y="p.y + 4" class="lb-place">{{ p.name }}</text>
          </g>
          <g v-for="b in map.buses" :key="'b' + b.id" class="lb-bus" @click="openTrip(b.id)" @mouseenter="selected = b.id" @mouseleave="selected = null">
            <line :x1="b.x" :y1="b.y" :x2="b.lineX" :y2="b.ly - 4" :stroke="b.color" stroke-width="1.5" />
          </g>
          <!-- Markers over the guide lines, labels over everything, so a crowded stretch of road stays readable. -->
          <circle v-for="b in map.buses" :key="'m' + b.id" :cx="b.x" :cy="b.y" :r="selected === b.id ? 10 : 7" :fill="b.live ? b.color : '#fff'" :stroke="b.live ? '#fff' : b.color" stroke-width="2.5" class="lb-bus" @click="openTrip(b.id)" @mouseenter="selected = b.id" @mouseleave="selected = null"><title>{{ b.title }}</title></circle>
          <g v-for="b in map.buses" :key="'t' + b.id" class="lb-bus" @click="openTrip(b.id)" @mouseenter="selected = b.id" @mouseleave="selected = null">
            <title>{{ b.title }}</title>
            <rect :x="b.lx" :y="b.ly - 13" :width="b.lw" height="18" rx="4" :fill="b.color" :stroke="selected === b.id ? '#0f172a' : 'none'" stroke-width="2" />
            <text :x="b.lx + 5" :y="b.ly" class="lb-label">{{ b.label }}</text>
          </g>
        </svg>
        <div v-if="!map.buses.length && !loading" class="lb-empty">{{ T('No buses reporting right now', 'Ningún bus reportando en este momento') }}</div>
      </div>

      <div class="lb-list-box">
        <table class="lb-table">
          <thead>
            <tr>
              <th>{{ T('Bus', 'Bus') }}</th>
              <th>{{ T('Destination', 'Destino') }}</th>
              <th>{{ T('Last report', 'Último reporte') }}</th>
              <th class="num">{{ T('Delay', 'Tardanza') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in sorted" :key="t.id" class="lb-row" :class="{ 'lb-row-on': selected === t.id, 'lb-row-done': t.status === 'completed' }" @click="openTrip(t.id)" @mouseenter="selected = t.id" @mouseleave="selected = null">
              <td><b>{{ t.bus || '—' }}</b><div class="lb-muted">{{ time(t.departure_at) }} {{ t.origin }}</div></td>
              <td><b>{{ t.destination }}</b><div class="lb-muted">{{ t.eta ? `${t.eta_is_real ? T('Arrived', 'Llegó') : T('ETA', 'Llega')} ${dayTime(t.eta)}` : '' }}</div></td>
              <td>
                <template v-if="t.last_event">{{ t.last_event.name }}<div class="lb-muted">{{ t.last_event.action === 'arrive' ? T('Arrived', 'Llegó') : T('Left', 'Salió') }} {{ time(t.last_event.at) }}</div></template>
                <span v-else class="lb-muted">{{ t.status === 'in_progress' ? T('No reports', 'Sin reportes') : statusText(t.status) }}</span>
                <div v-if="signalLost(t)" class="lb-lost">{{ T('No signal', 'Sin señal') }} {{ t.position.age_min }} min</div>
              </td>
              <td class="num">
                <a-tag v-if="t.delay_min > 0" :color="t.late ? 'red' : 'default'" class="lb-tag">+{{ t.delay_min }} min</a-tag>
                <a-tag v-else-if="t.reported && t.delay_min !== null" color="green" class="lb-tag">{{ T('On time', 'A tiempo') }}</a-tag>
                <span v-else class="lb-muted">—</span>
                <div class="lb-icons">
                  <span v-if="t.reports" class="lb-icon-bad"><WarningOutlined /> {{ t.reports }}</span>
                  <span v-if="t.photos_missing" class="lb-icon-warn"><CameraOutlined /> {{ t.photos_missing }}</span>
                </div>
              </td>
            </tr>
            <tr v-if="!sorted.length && !loading"><td colspan="4" class="lb-none">{{ T('No trips that day.', 'No hay viajes ese día.') }}</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <a-drawer v-model:open="drawerOpen" :title="detail?.route" :width="drawerWidth" destroy-on-close>
      <template v-if="detail">
        <div class="lb-muted">{{ detail.vehicle }}</div>
        <div class="lb-muted lb-crew">{{ detail.crew.map(c => c.name).join(', ') }}</div>
        <router-link v-if="detail.next && ['scheduled', 'in_progress'].includes(detail.status)" :to="{ path: '/transport/my-trip', query: { trip: detail.id } }">
          <a-button size="small" class="lb-crew">{{ T('Report for the crew', 'Reportar por la tripulación') }}</a-button>
        </router-link>
        <a-timeline class="lb-timeline">
          <a-timeline-item v-for="p in detail.points" :key="p.location_id" :color="p.skipped ? 'gray' : p.departed_at || (p.last && p.arrived_at) ? 'green' : p.arrived_at ? 'blue' : 'gray'">
            <b>{{ p.name }}</b><a-tag v-if="p.skipped" class="lb-gap">{{ T('Did not call', 'No paró') }}</a-tag>
            <div v-if="p.plan_arrive" class="lb-muted">
              {{ T('Arrival', 'Llegada') }}: {{ time(p.plan_arrive) }}
              <template v-if="p.arrived_at"> → <b>{{ dayTime(p.arrived_at) }}</b> {{ delayText(p.arrive_delay_min) }}<a-tag v-if="p.arrive_source === 'gps'" class="lb-gap">GPS</a-tag></template>
            </div>
            <div v-if="p.plan_depart" class="lb-muted">
              {{ T('Departure', 'Salida') }}: {{ time(p.plan_depart) }}
              <template v-if="p.departed_at"> → <b>{{ dayTime(p.departed_at) }}</b> {{ delayText(p.depart_delay_min) }}<a-tag v-if="p.depart_source === 'gps'" class="lb-gap">GPS</a-tag></template>
            </div>
            <div v-if="p.arrive_off_m > 1000" class="lb-warn">{{ T(`Reported ${(p.arrive_off_m / 1000).toFixed(1)} km from the terminal's coordinates`, `Reportado a ${(p.arrive_off_m / 1000).toFixed(1)} km de las coordenadas de la terminal`) }}</div>
            <div v-if="p.note" class="lb-muted">{{ p.note }}</div>
            <a-button v-if="p.has_photo" size="small" class="lb-photo-btn" @click="showPhoto('checkpoint', p.checkpoint_id)"><CameraOutlined /> {{ T('Photo', 'Foto') }}</a-button>
            <a-tag v-else-if="p.arrived_at" color="gold" class="lb-photo-btn">{{ T('Photo missing', 'Falta la foto') }}</a-tag>
          </a-timeline-item>
        </a-timeline>
        <template v-if="detail.reports.length">
          <div class="lb-sub">{{ T('Incidents', 'Incidencias') }}</div>
          <div v-for="r in detail.reports" :key="r.id" class="lb-report">
            <b>{{ kindLabel(r.kind) }}</b> · {{ dayTime(r.created_at) }} · {{ r.username }}
            <div v-if="r.note">{{ r.note }}</div>
            <a-button v-if="Number(r.has_photo)" size="small" class="lb-photo-btn" @click="showPhoto('report', r.id)"><CameraOutlined /> {{ T('Photo', 'Foto') }}</a-button>
          </div>
        </template>
      </template>
    </a-drawer>

    <a-modal v-model:open="photoOpen" :footer="null" :width="720" :title="T('Photo', 'Foto')" @after-close="closePhoto">
      <a-spin :spinning="!photoUrl"><img v-if="photoUrl" :src="photoUrl" class="lb-photo" alt=""></a-spin>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * The control board (2026-11-06): the buses on the map with their delay and destination, on the dashboard (tab "Control board")
 * and on Vehicle monitoring. Positions come from the crew's phone while My trip is open; a bus without one stays at the
 * last terminal it reported (hollow marker). Refreshes every 45 s, the rhythm the phones send at.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { message } from 'ant-design-vue';
import { CameraOutlined, InfoCircleOutlined, WarningFilled, WarningOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import TransportStrip from './TransportStrip.vue';
import http from '../lib/http';
import { getActingCompanyId } from '../lib/actingCompany';
import { useFitHeight } from '../lib/transportCharts';
import { useUiStore } from '../stores/ui';

defineProps({ pickDate: Boolean });

const ui = useUiStore();
const es = computed(() => ui.locale === 'es');
const T = (en, sp) => (es.value ? sp : en);
const STATUS = { scheduled: ['Scheduled', 'Programado'], in_progress: ['On the road', 'En ruta'], completed: ['Completed', 'Completado'] };
const statusText = s => (STATUS[s] ? T(STATUS[s][0], STATUS[s][1]) : s);
const KINDS = {
  traffic: ['Traffic', 'Tráfico'], breakdown: ['Breakdown', 'Avería'], accident: ['Accident', 'Accidente'],
  road: ['Road closed or blocked', 'Vía cerrada o bloqueada'], passenger: ['Passenger', 'Pasajero'], other: ['Other', 'Otro'],
};
const kindLabel = k => (KINDS[k] ? T(KINDS[k][0], KINDS[k][1]) : k);
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };
const time = d => (d ? dayjs(d).format('HH:mm') : '');
const dayTime = d => {
  if (!d) return '';
  return dayjs(d).isSame(dayjs(date.value), 'day') ? dayjs(d).format('HH:mm') : `${new Date(String(d).replace(' ', 'T')).toLocaleDateString(es.value ? 'es-PE' : 'en-US', { day: 'numeric', month: 'short' })} ${dayjs(d).format('HH:mm')}`;
};
const delayText = m => (m === null || m === undefined ? '' : m > 0 ? `(+${m} min)` : m < 0 ? `(${m} min)` : '');
const SIGNAL_LOST_MIN = 5;
const signalLost = t => t.status === 'in_progress' && t.position && t.position.age_min >= SIGNAL_LOST_MIN;

const grid = ref(null);
const { fit, measure } = useFitHeight(grid);
const date = ref(dayjs().format('YYYY-MM-DD'));
const rows = ref([]);
const places = ref([]);
const lateMin = ref(10);
const loading = ref(false);
const selected = ref(null);

const onRoad = computed(() => rows.value.filter(t => t.status === 'in_progress'));
const strip = computed(() => {
  const late = onRoad.value.filter(t => t.late).length;
  const lost = onRoad.value.filter(signalLost).length;
  return [
    { label: T('On the road', 'En ruta'), value: onRoad.value.length },
    { label: T('Late', 'Con tardanza'), value: late, level: late ? 'bad' : 'ok' },
    { label: T('No signal', 'Sin señal'), value: lost, level: lost ? 'warn' : undefined },
    { label: T('Arrived', 'Llegaron'), value: rows.value.filter(t => t.status === 'completed').length },
    { label: T('Scheduled', 'Programados'), value: rows.value.filter(t => t.status === 'scheduled').length },
  ];
});
// Late ones first, the most late on top; then on the road, then what's coming, then what arrived.
const sorted = computed(() => {
  const rank = t => (t.status === 'in_progress' ? 0 : t.status === 'scheduled' ? 1 : 2);
  return [...rows.value].sort((a, b) => rank(a) - rank(b) || (b.delay_min || 0) - (a.delay_min || 0) || String(a.departure_at).localeCompare(String(b.departure_at)));
});
// The one thing that interrupts the board: a bus broken down or in an accident.
const alertLine = computed(() => {
  const list = onRoad.value.filter(t => t.alert);
  if (!list.length) return '';
  const a = list[0];
  return `${a.bus}: ${kindLabel(a.alert.kind)} · ${a.destination} · ${time(a.alert.at)}${list.length > 1 ? ` (+${list.length - 1})` : ''}`;
});

async function load(silent = false) {
  if (!silent) loading.value = true;
  try {
    const res = await http.get('transport/control/monitor', { date: date.value });
    rows.value = res.trips;
    places.value = res.places;
    lateMin.value = res.late_min;
    nextTick(measure); // the red line, when it comes, takes room from the map
  } catch (e) {
    if (!silent) message.error(errorOf(e));
  } finally {
    loading.value = false;
  }
}

// ------------------------------------------------------------------ the map (OpenStreetMap tiles, like the Transport board)
const mapBox = ref(null);
const W = ref(720);
const H = ref(460);
const PAD = 60;
let observer = null;
function project(lat, lng, z) {
  const s = 256 * 2 ** z;
  const sin = Math.sin((lat * Math.PI) / 180);
  return [((lng + 180) / 360) * s, (0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI)) * s];
}
/** Where a bus goes on the map: its live position, else the last terminal it reported. */
function placeOf(t) {
  if (t.status !== 'in_progress') return null;
  if (t.position) return { lat: t.position.lat, lng: t.position.lng, live: true };
  if (t.last_event?.lat != null) return { lat: t.last_event.lat, lng: t.last_event.lng, live: false };
  return null;
}
const map = computed(() => {
  const located = rows.value.map(t => ({ t, at: placeOf(t) })).filter(x => x.at);
  const all = [...places.value.map(p => [Number(p.lat), Number(p.lng)]), ...located.map(x => [x.at.lat, x.at.lng])];
  if (!all.length) return { tiles: [], places: [], buses: [] };
  const w = W.value;
  const h = H.value;
  const w0 = all.map(p => project(p[0], p[1], 0));
  const spanX = Math.max(...w0.map(p => p[0])) - Math.min(...w0.map(p => p[0]));
  const spanY = Math.max(...w0.map(p => p[1])) - Math.min(...w0.map(p => p[1]));
  const z = Math.max(2, Math.min(12, Math.log2(Math.min((w - PAD * 2) / (spanX || 1e-9), (h - PAD * 2) / (spanY || 1e-9)))));
  const tz = Math.floor(z);
  const ts = 256 * 2 ** (z - tz);
  const world = all.map(p => project(p[0], p[1], z));
  const ox = (Math.max(...world.map(p => p[0])) + Math.min(...world.map(p => p[0]))) / 2 - w / 2;
  const oy = (Math.max(...world.map(p => p[1])) + Math.min(...world.map(p => p[1]))) / 2 - h / 2;
  const at = (lat, lng) => { const [x, y] = project(lat, lng, z); return [x - ox, y - oy]; };
  const tiles = [];
  const n = 2 ** tz;
  for (let tx = Math.floor(ox / ts); tx <= Math.floor((ox + w) / ts); tx++) {
    for (let ty = Math.floor(oy / ts); ty <= Math.floor((oy + h) / ts); ty++) {
      if (ty >= 0 && ty < n) tiles.push({ key: `${tz}-${tx}-${ty}`, url: `https://tile.openstreetmap.org/${tz}/${((tx % n) + n) % n}/${ty}.png`, x: tx * ts - ox, y: ty * ts - oy, size: ts + 0.5 });
    }
  }
  const buses = located.map(({ t, at: pos }) => {
    const [x, y] = at(pos.lat, pos.lng);
    const target = t.destination_lat != null ? at(t.destination_lat, t.destination_lng) : [null, null];
    const delay = t.delay_min > 0 ? ` +${t.delay_min} min` : '';
    const label = `${t.bus} → ${t.destination}${delay}`;
    return {
      id: t.id, x, y, tx: target[0], ty: target[1], live: pos.live, label, lw: label.length * 6.7 + 10,
      color: signalLost(t) ? '#64748b' : t.late ? '#dc2626' : '#15803d',
      title: `${t.route} · ${t.vehicle}${t.position?.speed != null ? ` · ${Math.round(t.position.speed)} km/h` : ''}${t.position ? ` · ${time(t.position.at)}` : ''}`,
    };
  }).sort((a, b) => a.y - b.y);
  // The labels in one column beside the buses, in the order they sit on the road, each with its guide line.
  let lastY = -Infinity;
  const widest = Math.max(0, ...buses.map(b => b.lw));
  const column = Math.max(0, ...buses.map(b => b.x)) + 28;
  const onRight = column + widest <= w - 4;
  const left = onRight ? column : Math.max(4, Math.min(...buses.map(b => b.x)) - 28 - widest);
  buses.forEach(b => {
    b.ly = Math.max(b.y + 4, lastY + 21);
    lastY = b.ly;
    b.lx = left;
    b.lineX = onRight ? left : left + b.lw;
  });
  const overflow = buses.length ? buses[buses.length - 1].ly - (h - 8) : 0;
  if (overflow > 0) buses.forEach(b => { b.ly -= overflow; });
  return { tiles, places: places.value.map(p => { const [x, y] = at(Number(p.lat), Number(p.lng)); return { id: p.id, name: p.name, x, y }; }), buses };
});

// ------------------------------------------------------------------ one trip
const drawerOpen = ref(false);
const detail = ref(null);
const drawerWidth = computed(() => Math.min(480, window.innerWidth));
async function openTrip(id) {
  try {
    detail.value = (await http.get(`transport/control/trips/${id}`)).trip;
    drawerOpen.value = true;
  } catch (e) {
    message.error(errorOf(e));
  }
}

const photoOpen = ref(false);
const photoUrl = ref('');
async function showPhoto(kind, id) {
  photoUrl.value = '';
  photoOpen.value = true;
  try {
    const company = getActingCompanyId();
    photoUrl.value = URL.createObjectURL(await http.blob(`transport/control/photo/${kind}/${id}${company ? `?company_id=${company}` : ''}`, true));
  } catch (e) {
    photoOpen.value = false;
    message.error(errorOf(e));
  }
}
function closePhoto() {
  if (photoUrl.value) URL.revokeObjectURL(photoUrl.value);
  photoUrl.value = '';
}

let timer = null;
onMounted(() => {
  load();
  timer = setInterval(() => { if (document.visibilityState === 'visible') load(true); }, 45000);
  if (window.ResizeObserver && mapBox.value) {
    observer = new ResizeObserver(([e]) => {
      if (e.contentRect.width > 0) {
        W.value = Math.round(e.contentRect.width);
        H.value = Math.round(e.contentRect.height) || 460;
      }
    });
    observer.observe(mapBox.value);
  }
});
onBeforeUnmount(() => {
  clearInterval(timer);
  observer?.disconnect();
});
</script>

<style scoped>
.lb-alert { margin-bottom: 8px; padding: 5px 12px; border-radius: 6px; background: #dc2626; color: #fff; font-weight: 600; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lb-top { display: flex; align-items: center; gap: 8px; }
.lb-strip { flex: 1; min-width: 0; }
.lb-date { margin-bottom: 8px; width: 130px; }
.lb-info { margin-bottom: 8px; color: #64748b; cursor: help; }
.lb-grid { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(360px, 1fr); gap: 8px; min-height: 400px; }
.lb-map-box { position: relative; min-width: 0; min-height: 300px; overflow: hidden; border-radius: 8px; }
.lb-map { position: absolute; inset: 0; width: 100%; height: 100%; display: block; }
.lb-empty { position: absolute; left: 50%; top: 12px; transform: translateX(-50%); padding: 4px 12px; border-radius: 6px; background: rgba(15, 23, 42, .75); color: #fff; font-size: 12px; white-space: nowrap; }
.lb-place { font-size: 12px; font-weight: 600; fill: #1e293b; paint-order: stroke; stroke: #fff; stroke-width: 3px; }
.lb-line { stroke: #475569; stroke-width: 1.5; stroke-dasharray: 5 5; opacity: .55; }
.lb-line-on { stroke-width: 3; opacity: 1; }
.lb-bus { cursor: pointer; }
.lb-label { font-size: 11.5px; font-weight: 700; fill: #fff; }
.lb-list-box { min-width: 0; overflow-y: auto; border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; }
.lb-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.lb-table th { position: sticky; top: 0; z-index: 1; padding: 5px 8px; background: var(--ant-color-bg-container, #fff); border-bottom: 1px solid rgba(128, 128, 128, 0.25); font-size: 11px; font-weight: 600; text-align: left; opacity: .95; }
.lb-table td { padding: 5px 8px; border-bottom: 1px solid rgba(128, 128, 128, 0.12); vertical-align: top; }
.lb-table .num { text-align: right; white-space: nowrap; }
.lb-row { cursor: pointer; }
.lb-row:hover, .lb-row-on { background: rgba(37, 99, 235, .08); }
.lb-row-done { opacity: .6; }
.lb-muted { font-size: 11px; color: #64748b; }
.lb-lost { font-size: 11px; color: #c2410c; font-weight: 600; }
.lb-tag { margin: 0; }
.lb-icons { display: flex; justify-content: flex-end; gap: 6px; margin-top: 2px; font-size: 11px; }
.lb-icon-bad { color: #dc2626; }
.lb-icon-warn { color: #b45309; }
.lb-none { text-align: center; color: #64748b; padding: 24px 8px !important; }
.lb-sub { font-weight: 700; margin: 6px 0; }
.lb-crew { margin-bottom: 10px; }
.lb-timeline { margin-top: 8px; }
.lb-gap { margin-left: 6px; }
.lb-warn { font-size: 12px; color: #b45309; }
.lb-photo-btn { margin-top: 4px; }
.lb-report { padding: 8px 0; border-top: 1px solid #f1f5f9; font-size: 13px; }
.lb-photo { display: block; width: 100%; border-radius: 8px; }
@media (max-width: 900px) {
  .lb-grid { grid-template-columns: 1fr; height: auto !important; }
  .lb-map-box { height: 320px; }
  .lb-list-box { overflow: visible; }
}
</style>
