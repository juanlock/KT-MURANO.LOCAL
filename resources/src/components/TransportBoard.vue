<template>
  <!-- Transport board on the dashboard, one screen with no scroll (2026-11-05): the map in the center, agencies and
       routes on the left, the fleet and the pilots' hours on the right. Long lists scroll inside their box.
       An agency = a city's hub (Turismo: LIMA, CHICLAYO...); its terminals = the points of sale / boarding
       that belong to it (user's rule, 2026-11-01). -->
  <div ref="root">
    <a-card v-if="data.enabled && data.locations.length" class="tb" :bordered="false" :body-style="{ padding: '8px' }">
      <a-spin :spinning="loading">
        <TransportStrip :items="totals" :toggle="false" />
        <!-- Super critical only: a pilot over the safe hours. One line. -->
        <div v-if="record && level(record.pct) === 'danger'" class="tb-critical" :title="criticalText"><WarningFilled /> <span>{{ criticalText }}</span></div>

        <div class="tb-grid" :style="gridHeight ? { height: gridHeight + 'px' } : null">
          <div class="tb-col">
            <div class="tb-box">
              <div class="tb-sub-title">{{ tf('Transport_Agencies', 'Agencies') }}</div>
              <div class="tb-list">
                <table class="tb-table">
                  <thead>
                    <tr>
                      <th />
                      <th class="num" :title="tf('Transport_Terminals', 'Terminals')"><ShopOutlined /></th>
                      <th class="num" :title="tf('Transport_Buses_Here', 'Buses here')"><CarOutlined /></th>
                      <th class="num" :title="tf('Transport_Crew', 'Crew')"><TeamOutlined /></th>
                      <th class="num" :title="tf('Transport_Departures', 'Departures')"><SwapOutlined /></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="h in hubCards" :key="h.id">
                      <td class="tb-name"><span class="tb-hub-dot" :style="h.image_url ? { backgroundImage: `url(${h.image_url})`, borderColor: h.color } : { background: h.color, borderColor: h.color }" />{{ h.name }}</td>
                      <td class="num">{{ h.terminals }}</td>
                      <td class="num">{{ h.parked }}</td>
                      <td class="num">{{ h.crew }}</td>
                      <td class="num">{{ h.departures }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            <div class="tb-box tb-grow">
              <div class="tb-sub-title">{{ tf('Transport_Routes', 'Routes') }}</div>
              <div class="tb-list">
                <div v-for="r in routeList" :key="'rl' + r.key" class="tb-route-row" :title="r.title">
                  <span class="tb-route-names">{{ r.names }}</span>
                  <span class="tb-route-km">{{ r.label }}</span>
                  <b class="tb-route-trips">{{ r.trips }}</b>
                </div>
              </div>
            </div>
          </div>

          <!-- The map holds the center. -->
          <div ref="mapBox" class="tb-map-wrap">
            <svg :viewBox="`0 0 ${W} ${H}`" class="tb-map" role="img" :aria-label="tf('Transport_Map', 'Routes map')">
              <rect :width="W" :height="H" class="tb-sea" />
              <image v-for="t in map.tiles" :key="t.key" :href="t.url" :x="t.x" :y="t.y" :width="t.size" :height="t.size" class="tb-tile" />
              <rect :width="W" :height="H" class="tb-veil" />

              <!-- Routes: one line per pair of agencies, km and hours on the long ones -->
              <g v-for="r in map.routes" :key="'r' + r.key">
                <path :d="r.d" class="tb-route-shadow" />
                <path :d="r.d" class="tb-route" :stroke-width="r.width">
                  <title>{{ r.title }}</title>
                </path>
              </g>
              <g v-for="r in map.routes.filter(x => x.showLabel)" :key="'l' + r.key" :transform="`translate(${r.mx},${r.my})`">
                <rect :x="-r.labelW / 2" y="-11" :width="r.labelW" height="22" rx="11" class="tb-route-label" />
                <text text-anchor="middle" y="4" class="tb-route-text">{{ r.label }}</text>
              </g>

              <!-- Buses on the road now -->
              <g v-for="b in map.live" :key="'b' + b.id" :transform="`translate(${b.x},${b.y})`" class="tb-bus">
                <circle r="13" class="tb-bus-halo" />
                <circle r="9" class="tb-bus-dot" />
                <text text-anchor="middle" y="4" class="tb-bus-icon">🚌</text>
                <title>{{ b.title }}</title>
              </g>

              <!-- Agencies: the number = buses parked there now -->
              <g v-for="h in map.hubs" :key="'h' + h.id" :transform="`translate(${h.x},${h.y})`">
                <circle :r="h.r + 5" :fill="h.color" opacity=".18" />
                <circle :r="h.r" :fill="h.color" class="tb-hub" />
                <text text-anchor="middle" y="5" class="tb-hub-count">{{ h.parked }}</text>
                <text :x="h.r + 8" y="5" class="tb-hub-name">{{ h.name }}</text>
                <title>{{ h.name }}: {{ h.parked }} {{ tf('Transport_Buses_Here', 'Buses here now').toLowerCase() }}</title>
              </g>
              <text :x="W - 8" :y="H - 8" text-anchor="end" class="tb-attrib">© OpenStreetMap</text>
            </svg>
            <a-tooltip :title="tf('Transport_Map_Hint', 'The number on each agency = buses parked there now; the moving dots = buses on the road.')"><InfoCircleOutlined class="tb-map-info" /></a-tooltip>
          </div>

          <div class="tb-col">
            <!-- Fleet now: where every bus is and the trips on the road -->
            <div class="tb-box tb-grow">
              <div class="tb-sub-title">{{ tf('Transport_Fleet_Now', 'Fleet now') }}</div>
              <div class="tb-states">
                <span v-for="s in fleetStates" :key="s.key" class="tb-state" :class="'tb-state-' + s.key" :title="s.label"><b>{{ s.count }}</b> {{ s.label }}</span>
              </div>
              <div class="tb-list">
                <a-tooltip v-for="t in data.live" :key="'ct' + t.id" :title="tripHelp(t)">
                  <div class="tb-trip">
                    <div class="tb-trip-row">
                      <span class="tb-trip-route">{{ t.origin }} → {{ t.destination }}</span>
                      <span class="tb-trip-bus">{{ dayjs(t.arrival_at).format('HH:mm') }}</span>
                    </div>
                    <div class="tb-bar"><span :style="{ width: Math.round(t.progress * 100) + '%', background: '#f59e0b' }" /></div>
                  </div>
                </a-tooltip>
                <div v-for="v in data.fleet" :key="'v' + v.id" class="tb-bus-row" :title="busWhere(v)">
                  <span class="tb-bus-state" :class="'tb-state-' + v.state" />
                  <span class="tb-bus-name">{{ v.name }}</span>
                  <span class="tb-bus-where">{{ busWhere(v) }}</span>
                </div>
              </div>
            </div>

            <!-- Pilots' driving hours -->
            <div class="tb-box tb-grow">
              <div class="tb-sub-title">
                {{ tf('Transport_Pilot_Hours', 'Pilot hours') }}
                <a-tooltip :title="`${tf('Transport_Hours_Limit', 'Safe limit')}: ${data.pilot_limit} h / ${data.days} d`"><InfoCircleOutlined class="tb-info" /></a-tooltip>
              </div>
              <div v-if="record" class="tb-list">
                <div v-for="p in data.pilots.slice(0, 6)" :key="p.id" class="tb-pilot" :title="`${p.name}: ${p.hours} h · ${p.trips} ${tf('Transport_Trips_Period', 'Trips').toLowerCase()} · ${p.pct} %`">
                  <div class="tb-pilot-row">
                    <span class="tb-pilot-name">{{ p.name }}</span>
                    <span class="tb-pilot-hours">{{ p.hours }} h</span>
                  </div>
                  <div class="tb-bar"><span :style="{ width: Math.min(100, p.pct) + '%', background: barColor(p.pct) }" /></div>
                </div>
              </div>
              <a-empty v-else :image="simpleImage" />
            </div>
          </div>
        </div>
      </a-spin>
    </a-card>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onBeforeUnmount, onMounted } from 'vue';
import dayjs from 'dayjs';
import { Empty } from 'ant-design-vue';
import {
  CarOutlined, EnvironmentOutlined, ShopOutlined, GlobalOutlined, TeamOutlined, SwapOutlined, DashboardOutlined,
  WarningFilled, InfoCircleOutlined,
} from '@ant-design/icons-vue';
import http from '../lib/http';
import { t as tf } from '../i18n';
import { staffTypeLabel } from '../pages/hrm/hrmVocab';
import { useFitHeight } from '../lib/transportCharts';
import TransportStrip from './TransportStrip.vue';

const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
const H = 700;
const W = ref(640); // follows its box's shape
const PAD = 56;
const COLORS = ['#0e7490', '#7c3aed', '#c2410c', '#15803d', '#be123c', '#1d4ed8', '#a16207', '#0f766e'];

// The dashboard's date filter (2026-11-04): [from, to]; without one the server takes the last 30 days.
// The pilots' safe limit is scaled to the days chosen (TransportController::board).
const props = defineProps({ range: { type: Array, default: null } });
const root = ref(null);
const mapBox = ref(null);
const boxObserver = window.ResizeObserver ? new ResizeObserver(() => fitMap()) : null;
function fitMap() {
  const box = mapBox.value?.getBoundingClientRect();
  if (box?.width && box?.height) W.value = Math.round((H * box.width) / box.height);
  if (boxObserver && mapBox.value) boxObserver.observe(mapBox.value);
}
onBeforeUnmount(() => boxObserver?.disconnect());
const { fit, measure } = useFitHeight(root);
const loading = ref(false);
const data = ref({ enabled: false, locations: [], routes: [], live: [], hubs: [], fleet: [], pilots: [], totals: {}, pilot_limit: 0, days: 30 });

async function load() {
  loading.value = true;
  try {
    const res = await http.get('transport/board', { from: props.range?.[0], to: props.range?.[1] });
    data.value = { ...data.value, ...res, locations: res.locations || [], routes: res.routes || [], live: res.live || [], hubs: res.hubs || [], fleet: res.fleet || [], pilots: res.pilots || [] };
  } catch (e) {
    data.value = { ...data.value, enabled: false };
  } finally {
    loading.value = false;
    nextTick(() => { measure(); nextTick(fitMap); });
  }
}
onMounted(load);

const hubColor = computed(() => {
  const out = {};
  data.value.locations.filter(l => l.kind === 'agency').forEach((l, i) => { out[l.id] = COLORS[i % COLORS.length]; });
  return out;
});

const hours = m => {
  if (!m) return '';
  const h = Math.floor(m / 60);
  const r = m % 60;
  return h ? (r ? `${h} h ${r} min` : `${h} h`) : `${r} min`;
};

/** Web Mercator: world pixels at zoom z. */
function project(lat, lng, z) {
  const s = 256 * 2 ** z;
  const sin = Math.sin((lat * Math.PI) / 180);
  return [((lng + 180) / 360) * s, (0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI)) * s];
}

const map = computed(() => {
  // Agencies only: their terminals are counted on the cards, not drawn.
  const locs = data.value.locations.filter(l => l.kind === 'agency');
  const placed = locs.filter(l => l.lat != null && l.lng != null);
  if (!placed.length) return { tiles: [], hubs: [], routes: [], live: [] };

  // The (fractional) zoom that fits every located place; tiles of the zoom below, scaled.
  const w0 = placed.map(l => project(l.lat, l.lng, 0));
  const spanX = Math.max(...w0.map(p => p[0])) - Math.min(...w0.map(p => p[0]));
  const spanY = Math.max(...w0.map(p => p[1])) - Math.min(...w0.map(p => p[1]));
  const z = Math.max(2, Math.min(12, Math.log2(Math.min((W.value - PAD * 2) / (spanX || 1e-9), (H - PAD * 2) / (spanY || 1e-9)))));
  const tz = Math.floor(z);
  const ts = 256 * 2 ** (z - tz);
  const world = placed.map(l => project(l.lat, l.lng, z));
  const cx = (Math.max(...world.map(p => p[0])) + Math.min(...world.map(p => p[0]))) / 2;
  const cy = (Math.max(...world.map(p => p[1])) + Math.min(...world.map(p => p[1]))) / 2;
  const ox = cx - W.value / 2;
  const oy = cy - H / 2;
  const at = l => { const [x, y] = project(l.lat, l.lng, z); return [x - ox, y - oy]; };

  const tiles = [];
  const n = 2 ** tz;
  for (let tx = Math.floor(ox / ts); tx <= Math.floor((ox + W.value) / ts); tx++) {
    for (let ty = Math.floor(oy / ts); ty <= Math.floor((oy + H) / ts); ty++) {
      if (ty < 0 || ty >= n) continue;
      tiles.push({ key: `${tz}-${tx}-${ty}`, url: `https://tile.openstreetmap.org/${tz}/${((tx % n) + n) % n}/${ty}.png`, x: tx * ts - ox, y: ty * ts - oy, size: ts + 0.5 });
    }
  }

  const hubInfo = Object.fromEntries(data.value.hubs.map(h => [h.id, h]));
  const hubs = placed.map(l => {
    const [x, y] = at(l);
    const parked = hubInfo[l.id]?.parked ?? 0;
    return { id: l.id, name: l.name, x, y, color: hubColor.value[l.id], parked, r: 14 + Math.min(8, parked * 3) };
  });
  const hubById = Object.fromEntries(hubs.map(h => [h.id, h]));

  // Routes as gentle curves, so lines that share an agency don't sit on top of each other.
  const maxTrips = Math.max(1, ...data.value.routes.map(r => r.trips));
  const curve = {};
  const routes = data.value.routes.map((r, i) => {
    const a = hubById[r.a];
    const b = hubById[r.b];
    if (!a || !b) return null;
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const len = Math.hypot(dx, dy) || 1;
    const bend = 0.12 * len;
    const c = [(a.x + b.x) / 2 - (dy / len) * bend, (a.y + b.y) / 2 + (dx / len) * bend];
    curve[`${r.a}-${r.b}`] = { a, b, c };
    const km = r.km != null ? `${r.estimated ? '≈' : ''}${Math.round(r.km)} km` : '';
    const label = [km, hours(r.minutes)].filter(Boolean).join(' · ');
    // Labels on long lines only (short ones are in the list under the map), staggered
    // along the line so the ones leaving the same agency don't stack.
    const t = [0.5, 0.36, 0.64][i % 3];
    return {
      key: `${r.a}-${r.b}`, d: `M${a.x},${a.y} Q${c[0]},${c[1]} ${b.x},${b.y}`,
      mx: (1 - t) ** 2 * a.x + 2 * (1 - t) * t * c[0] + t ** 2 * b.x, my: (1 - t) ** 2 * a.y + 2 * (1 - t) * t * c[1] + t ** 2 * b.y,
      showLabel: len >= 260, label, labelW: Math.max(40, label.length * 6.4 + 16), width: 2.5 + (r.trips / maxTrips) * 4,
      names: `${a.name} ↔ ${b.name}`, trips: r.trips, estimated: r.estimated,
      title: `${a.name} ↔ ${b.name}: ${label}${r.estimated ? ` (${tf('Transport_Estimated', 'estimated')})` : ''} · ${r.trips} ${tf('Transport_Trips_Period', 'Trips').toLowerCase()}`,
    };
  }).filter(Boolean);

  // Buses on the road: where they are along their line.
  const live = data.value.live.map(t => {
    const lo = Math.min(t.origin_id, t.destination_id);
    const hi = Math.max(t.origin_id, t.destination_id);
    const cv = curve[`${lo}-${hi}`];
    if (!cv) return null;
    const p = t.origin_id === lo ? t.progress : 1 - t.progress;
    const x = (1 - p) ** 2 * cv.a.x + 2 * (1 - p) * p * cv.c[0] + p ** 2 * cv.b.x;
    const y = (1 - p) ** 2 * cv.a.y + 2 * (1 - p) * p * cv.c[1] + p ** 2 * cv.b.y;
    const from = t.origin_id === lo ? cv.a : cv.b;
    const to = t.origin_id === lo ? cv.b : cv.a;
    return { id: t.id, x, y, title: `${t.vehicle || ''} · ${from.name} → ${to.name} · ${dayjs(t.arrival_at).format('HH:mm')}` };
  }).filter(Boolean);

  return { tiles, hubs, routes, live };
});

const totals = computed(() => {
  const t = data.value.totals || {};
  const staff = t.staff || {};
  const people = Object.values(staff).reduce((s, n) => s + n, 0);
  const help = Object.entries(staff).map(([k, n]) => `${staffTypeLabel(k)}: ${n}`).join(' · ');
  return [
    { label: tf('Transport_Agencies', 'Agencies'), value: t.agencies ?? 0 },
    { label: tf('Transport_Terminals', 'Terminals'), value: t.terminals ?? 0 },
    { label: tf('Transport_Online', 'Online channels'), value: t.online ?? 0 },
    { label: tf('Transport_Fleet', 'Fleet'), value: t.buses ?? 0 },
    { label: tf('Transport_Staff_Count', 'Staff'), value: people, help },
    { label: tf('Transport_Trips_Period', 'Trips'), value: t.trips ?? 0 },
    { label: tf('Transport_Km', 'Km traveled'), value: (t.km ?? 0).toLocaleString() },
  ];
});

const fleetStates = computed(() => [
  { key: 'agency', label: tf('Transport_At_Agency', 'At an agency') },
  { key: 'transit', label: tf('Transport_In_Transit', 'In transit') },
  { key: 'maintenance', label: tf('Transport_In_Maintenance', 'In maintenance') },
].map(s => ({ ...s, count: data.value.fleet.filter(v => v.state === s.key).length })));
const routeList = computed(() => [...map.value.routes].sort((x, y) => x.names.localeCompare(y.names)));
const record = computed(() => data.value.pilots[0] || null);
const level = pct => (pct >= 90 ? 'danger' : pct >= 75 ? 'warn' : 'ok');
const barColor = pct => ({ danger: '#dc2626', warn: '#f59e0b', ok: '#16a34a' })[level(pct)];
const criticalText = computed(() => (record.value ? `${record.value.name}: ${record.value.hours} h / ${data.value.pilot_limit} h (${record.value.pct} %). ${tf('Transport_Hours_Danger', 'Danger: over the safe limit, check the rest days')}` : ''));
// The room left for the three columns: card padding + the strip + the red line, when there is one.
const gridHeight = computed(() => {
  if (!fit.value) return null;
  return fit.value - 16 - 58 - (record.value && level(record.value.pct) === 'danger' ? 34 : 0);
});
const tripHelp = t => [
  `${t.vehicle || ''} · ${dayjs(t.departure_at).format('HH:mm')} → ${tf('Transport_Arrives', 'arrives')} ${dayjs(t.arrival_at).format('DD/MM HH:mm')}`,
  t.pilots.length ? `${tf('Transport_Pilots', 'Pilots')}: ${t.pilots.join(', ')}` : null,
  t.hostesses.length ? `${tf('Transport_Hostesses', 'Hostesses')}: ${t.hostesses.join(', ')}` : null,
].filter(Boolean).join(' · ');
function busWhere(v) {
  if (v.state === 'transit') return `${v.location} · ${tf('Transport_Arrives', 'arrives')} ${dayjs(v.detail).format('HH:mm')}`;
  if (v.state === 'maintenance') return [v.detail, v.location].filter(Boolean).join(' · ') || tf('Transport_In_Maintenance', 'In maintenance');
  const where = v.location || tf('Transport_No_Location', 'Location unknown');
  return v.detail ? `${where} · ${dayjs(v.detail).format('DD/MM HH:mm')}` : where;
}
const hubCards = computed(() => data.value.hubs.map(h => ({ ...h, color: hubColor.value[h.id] })));
</script>

<style scoped>
.tb { margin-bottom: 0; }
.tb-critical { display: flex; align-items: center; gap: 6px; height: 26px; margin-bottom: 8px; padding: 0 10px; border-radius: 6px; background: #d03b3b; color: #fff; font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; }
.tb-critical span { overflow: hidden; text-overflow: ellipsis; }

.tb-grid { display: grid; gap: 8px; grid-template-columns: 1fr; }
.tb-col { display: flex; flex-direction: column; gap: 8px; min-height: 0; min-width: 0; }
.tb-box { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; padding: 6px 8px; display: flex; flex-direction: column; min-height: 0; }
.tb-grow { flex: 1 1 0; }
.tb-list { flex: 1; min-height: 0; overflow-y: auto; }
.tb-sub-title { font-weight: 600; font-size: 13px; line-height: 20px; margin-bottom: 2px; }
.tb-info, .tb-map-info { opacity: 0.5; font-size: 12px; cursor: help; }
.tb-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.tb-table th { font-weight: 400; opacity: 0.6; padding: 0 4px 2px; }
.tb-table td { padding: 2px 4px; border-top: 1px solid rgba(128, 128, 128, 0.15); }
.tb-table .num { text-align: right; font-variant-numeric: tabular-nums; }
.tb-name { font-weight: 600; white-space: nowrap; }
.tb-hub-dot { display: inline-block; width: 16px; height: 16px; border-radius: 50%; border: 2px solid; background-size: cover; background-position: center; margin-right: 6px; vertical-align: -3px; }

.tb-map-wrap { position: relative; border-radius: 8px; overflow: hidden; border: 1px solid rgba(128, 128, 128, 0.2); background: #dbeafe; min-height: 340px; min-width: 0; }
.tb-map { position: absolute; inset: 0; display: block; width: 100%; height: 100%; }
.tb-map-info { position: absolute; top: 8px; right: 8px; opacity: 0.8; background: #fff; border-radius: 50%; padding: 3px; }
.tb-sea { fill: #dbeafe; }
.tb-tile { image-rendering: auto; }
.tb-veil { fill: #fff; opacity: .28; }
.tb-route-shadow { fill: none; stroke: #fff; stroke-width: 9; opacity: .75; }
.tb-route { fill: none; stroke: #0f172a; stroke-linecap: round; opacity: .8; }
.tb-route-label { fill: #0f172a; opacity: .86; }
.tb-route-text { fill: #fff; font-size: 11.5px; font-weight: 600; }
.tb-hub { stroke: #fff; stroke-width: 3; }
.tb-hub-count { fill: #fff; font-size: 13px; font-weight: 800; }
.tb-hub-name { paint-order: stroke; stroke: #fff; stroke-width: 4px; stroke-linejoin: round; font-size: 15px; font-weight: 800; fill: #0f172a; }
.tb-bus-halo { fill: #f59e0b; opacity: .35; animation: tb-pulse 1.8s ease-in-out infinite; transform-origin: center; transform-box: fill-box; }
.tb-bus-dot { fill: #f59e0b; stroke: #fff; stroke-width: 2; }
.tb-bus-icon { font-size: 11px; }
.tb-attrib { font-size: 10px; fill: #475569; }
@keyframes tb-pulse { 0%, 100% { transform: scale(1); opacity: .35; } 50% { transform: scale(1.6); opacity: .1; } }

.tb-route-row { display: flex; gap: 6px; align-items: baseline; font-size: 11.5px; padding: 2px 0; border-top: 1px solid rgba(128, 128, 128, 0.15); }
.tb-route-names { font-weight: 600; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tb-route-km { opacity: 0.7; white-space: nowrap; }
.tb-route-trips { min-width: 18px; text-align: right; }

.tb-states { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 4px; }
.tb-state { flex: 1 1 auto; border-radius: 6px; padding: 1px 6px; font-size: 11px; white-space: nowrap; text-align: center; }
.tb-state b { font-size: 13px; }
.tb-state.tb-state-agency { background: #f0fdf4; color: #15803d; }
.tb-state.tb-state-transit { background: #fffbeb; color: #b45309; }
.tb-state.tb-state-maintenance { background: #fef2f2; color: #b91c1c; }
.tb-trip { border: 1px solid #fde68a; background: #fffbeb; color: #0f172a; border-radius: 6px; padding: 2px 6px 4px; margin-bottom: 4px; }
.tb-trip-row { display: flex; justify-content: space-between; gap: 8px; font-size: 12px; }
.tb-trip-route { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tb-trip-bus { color: #92400e; white-space: nowrap; }
.tb-bar { height: 4px; border-radius: 2px; background: rgba(128, 128, 128, 0.2); overflow: hidden; margin-top: 2px; }
.tb-bar span { display: block; height: 100%; border-radius: 2px; }
.tb-bus-row { display: flex; gap: 6px; align-items: center; font-size: 11.5px; padding: 2px 0; border-top: 1px solid rgba(128, 128, 128, 0.15); }
.tb-bus-state { width: 8px; height: 8px; border-radius: 50%; flex: none; }
.tb-bus-state.tb-state-agency { background: #16a34a; }
.tb-bus-state.tb-state-transit { background: #f59e0b; }
.tb-bus-state.tb-state-maintenance { background: #dc2626; }
.tb-bus-name { font-weight: 600; white-space: nowrap; }
.tb-bus-where { opacity: 0.75; flex: 1; min-width: 0; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tb-pilot { margin-bottom: 4px; }
.tb-pilot-row { display: flex; justify-content: space-between; font-size: 11.5px; }
.tb-pilot-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tb-pilot-hours { font-weight: 600; flex: none; padding-left: 8px; }

@media (min-width: 900px) {
  .tb-grid { grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.5fr) minmax(0, 0.95fr); }
}
</style>
