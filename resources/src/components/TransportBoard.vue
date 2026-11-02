<template>
  <!-- Transport board on the dashboard (2026-11-01): the routes map, the totals, the
       pilots' driving hours (the record one as a danger) and a card per terminal. -->
  <a-card v-if="data.enabled && data.locations.length" class="tb" :bordered="false">
    <template #title>
      <span class="tb-title"><CarOutlined /> {{ tf('Transport_Board', 'Transport board') }}</span>
    </template>
    <template #extra>
      <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" compact @change="load" />
    </template>

    <a-spin :spinning="loading">
      <!-- Totals -->
      <div class="tb-totals">
        <div v-for="c in totals" :key="c.key" class="tb-total" :title="c.hint || ''">
          <span class="tb-total-icon" :style="{ background: c.tint, color: c.color }"><component :is="c.icon" /></span>
          <div>
            <div class="tb-total-value">{{ c.value }}</div>
            <div class="tb-total-label">{{ c.label }}</div>
          </div>
        </div>
      </div>

      <a-row :gutter="[16, 16]">
        <!-- Map -->
        <a-col :xs="24" :lg="16">
          <div class="tb-map-wrap">
            <svg :viewBox="`0 0 ${W} ${H}`" class="tb-map" role="img" :aria-label="tf('Transport_Map', 'Routes map')">
              <rect :width="W" :height="H" class="tb-sea" />
              <image v-for="t in map.tiles" :key="t.key" :href="t.url" :x="t.x" :y="t.y" :width="t.size" :height="t.size" class="tb-tile" />
              <rect :width="W" :height="H" class="tb-veil" />

              <!-- Routes: one line per pair of terminals, km and hours at the middle -->
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

              <!-- Terminals: the number = buses parked there now -->
              <g v-for="h in map.hubs" :key="'h' + h.id" :transform="`translate(${h.x},${h.y})`">
                <circle :r="h.r + 5" :fill="h.color" opacity=".18" />
                <circle :r="h.r" :fill="h.color" class="tb-hub" />
                <text text-anchor="middle" y="5" class="tb-hub-count">{{ h.parked }}</text>
                <text :x="h.r + 8" y="5" class="tb-hub-name">{{ h.name }}</text>
                <title>{{ h.name }}: {{ h.parked }} {{ tf('Transport_Buses_Here', 'Buses here now').toLowerCase() }}</title>
              </g>
              <text :x="W - 8" :y="H - 8" text-anchor="end" class="tb-attrib">© OpenStreetMap</text>
            </svg>
          </div>
          <div class="tb-map-hint">{{ tf('Transport_Map_Hint', 'The number on each terminal = buses parked there now; the moving dots = buses on the road.') }}</div>
          <!-- Every route with its distance and time (the map labels only the long ones). -->
          <div class="tb-routes">
            <div v-for="r in routeList" :key="'rl' + r.key" class="tb-route-row">
              <span class="tb-route-names">{{ r.names }}</span>
              <span class="tb-route-km">{{ r.label }}<small v-if="r.estimated"> ({{ tf('Transport_Estimated', 'estimated') }})</small></span>
              <span class="tb-route-trips">{{ r.trips }} {{ tf('Transport_Trips_Period', 'Trips').toLowerCase() }}</span>
            </div>
          </div>

          <!-- One card per terminal -->
          <div class="tb-hubs">
            <div v-for="h in hubCards" :key="h.id" class="tb-hubcard" :style="{ borderTopColor: h.color }">
              <div class="tb-hubcard-img" :style="h.image_url ? { backgroundImage: `url(${h.image_url})` } : { background: h.color }">
                <span class="tb-hubcard-name">{{ h.name }}</span>
              </div>
              <div class="tb-hubcard-stats">
                <div><b>{{ h.agencies }}</b><span>{{ tf('Transport_Agencies', 'Agencies') }}</span></div>
                <div><b>{{ h.parked }}</b><span>{{ tf('Transport_Buses_Here', 'Buses here') }}</span></div>
                <div><b>{{ h.crew }}</b><span>{{ tf('Transport_Crew', 'Crew') }}</span></div>
                <div><b>{{ h.departures }}</b><span>{{ tf('Transport_Departures', 'Departures') }}</span></div>
              </div>
            </div>
          </div>
        </a-col>

        <a-col :xs="24" :lg="8">
          <!-- Fleet now: where every bus is and the trips on the road -->
          <div class="tb-box">
            <div class="tb-sub-title">{{ tf('Transport_Fleet_Now', 'Fleet now') }}</div>
            <div class="tb-states">
              <div v-for="s in fleetStates" :key="s.key" class="tb-state" :class="'tb-state-' + s.key">
                <b>{{ s.count }}</b><span>{{ s.label }}</span>
              </div>
            </div>

            <div class="tb-mini-title">{{ tf('Transport_Current_Trips', 'Current trips') }}</div>
            <div v-if="!data.live.length" class="tb-muted">{{ tf('Transport_No_Trips_Now', 'No trip on the road right now') }}</div>
            <div v-for="t in data.live" :key="'ct' + t.id" class="tb-trip">
              <div class="tb-trip-row">
                <span class="tb-trip-route">{{ t.origin }} → {{ t.destination }}</span>
                <span class="tb-trip-bus">{{ t.vehicle }}</span>
              </div>
              <a-progress :percent="Math.round(t.progress * 100)" size="small" stroke-color="#f59e0b" />
              <div class="tb-trip-meta">
                {{ dayjs(t.departure_at).format('HH:mm') }} → {{ tf('Transport_Arrives', 'arrives') }} {{ dayjs(t.arrival_at).format('DD/MM HH:mm') }}
                <span v-if="t.pilots.length"> · {{ tf('Transport_Pilots', 'Pilots') }}: {{ t.pilots.join(', ') }}</span>
                <span v-if="t.hostesses.length"> · {{ tf('Transport_Hostesses', 'Hostesses') }}: {{ t.hostesses.join(', ') }}</span>
              </div>
            </div>

            <div class="tb-mini-title">{{ tf('Transport_Fleet', 'Fleet') }}</div>
            <div v-for="v in data.fleet" :key="'v' + v.id" class="tb-bus-row">
              <span class="tb-bus-state" :class="'tb-state-' + v.state" />
              <span class="tb-bus-name">{{ v.name }}</span>
              <span class="tb-bus-where">
                <template v-if="v.state === 'transit'">{{ v.location }} · {{ tf('Transport_Arrives', 'arrives') }} {{ dayjs(v.detail).format('HH:mm') }}</template>
                <template v-else-if="v.state === 'maintenance'">{{ [v.detail, v.location].filter(Boolean).join(' · ') || tf('Transport_In_Maintenance', 'In maintenance') }}</template>
                <template v-else>{{ v.location || tf('Transport_No_Location', 'Location unknown') }}<span v-if="v.detail" class="tb-muted"> · {{ tf('Transport_Next_Departure', 'next departure') }} {{ dayjs(v.detail).format('DD/MM HH:mm') }}</span></template>
              </span>
            </div>
          </div>

          <!-- Pilots' driving hours -->
          <div class="tb-pilots tb-box">
            <div class="tb-sub-title">{{ tf('Transport_Pilot_Hours', 'Pilot hours') }}</div>
            <div class="tb-limit">{{ tf('Transport_Hours_Limit', 'Safe limit') }}: <b>{{ data.pilot_limit }} h</b> / {{ data.days }} d</div>
            <template v-if="record">
              <div class="tb-record" :class="'tb-' + level(record.pct)">
                <div class="tb-record-head">
                  <WarningFilled v-if="level(record.pct) !== 'ok'" class="tb-record-icon" />
                  <TrophyOutlined v-else class="tb-record-icon" />
                  <span>{{ tf('Transport_Pilot_Record', 'Most hours in the period') }}</span>
                </div>
                <div class="tb-record-name">{{ record.name }}</div>
                <div class="tb-record-hours">{{ record.hours }} h <small>· {{ record.trips }} {{ tf('Transport_Trips_Period', 'Trips').toLowerCase() }}</small></div>
                <a-progress :percent="Math.min(100, record.pct)" :stroke-color="barColor(record.pct)" :show-info="false" />
                <div v-if="level(record.pct) === 'danger'" class="tb-record-msg">{{ tf('Transport_Hours_Danger', 'Danger: over the safe limit, check his rest days') }} ({{ record.pct }} %)</div>
                <div v-else-if="level(record.pct) === 'warn'" class="tb-record-msg">{{ tf('Transport_Hours_Warning', 'Close to the safe limit') }} ({{ record.pct }} %)</div>
              </div>
              <div v-for="p in data.pilots.slice(1, 6)" :key="p.id" class="tb-pilot">
                <div class="tb-pilot-row">
                  <span class="tb-pilot-name">{{ p.name }}</span>
                  <span class="tb-pilot-hours">{{ p.hours }} h</span>
                </div>
                <a-progress :percent="Math.min(100, p.pct)" size="small" :stroke-color="barColor(p.pct)" :show-info="false" />
              </div>
            </template>
            <a-empty v-else :image="simpleImage" />
          </div>
        </a-col>
      </a-row>
    </a-spin>
  </a-card>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { Empty } from 'ant-design-vue';
import {
  CarOutlined, EnvironmentOutlined, ShopOutlined, GlobalOutlined, TeamOutlined, SwapOutlined, DashboardOutlined,
  WarningFilled, TrophyOutlined,
} from '@ant-design/icons-vue';
import PresetRangeFilter from './PresetRangeFilter.vue';
import http from '../lib/http';
import { t as tf } from '../i18n';
import { staffTypeLabel } from '../pages/hrm/hrmVocab';

const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
const W = 800;
const H = 620;
const PAD = 56;
const COLORS = ['#0e7490', '#7c3aed', '#c2410c', '#15803d', '#be123c', '#1d4ed8', '#a16207', '#0f766e'];

// Last 30 days: a month of driving hours whatever the day of the month.
const range = ref([dayjs().subtract(29, 'day').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')]);
const loading = ref(false);
const data = ref({ enabled: false, locations: [], routes: [], live: [], hubs: [], fleet: [], pilots: [], totals: {}, pilot_limit: 0, days: 30 });

async function load() {
  loading.value = true;
  try {
    const res = await http.get('transport/board', { from: range.value?.[0], to: range.value?.[1] });
    data.value = { ...data.value, ...res, locations: res.locations || [], routes: res.routes || [], live: res.live || [], hubs: res.hubs || [], fleet: res.fleet || [], pilots: res.pilots || [] };
  } catch (e) {
    data.value = { ...data.value, enabled: false };
  } finally {
    loading.value = false;
  }
}
onMounted(load);

const hubColor = computed(() => {
  const out = {};
  data.value.locations.filter(l => l.kind === 'terminal').forEach((l, i) => { out[l.id] = COLORS[i % COLORS.length]; });
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
  // Terminals only: the agencies are counted on the cards below, not drawn.
  const locs = data.value.locations.filter(l => l.kind === 'terminal');
  const placed = locs.filter(l => l.lat != null && l.lng != null);
  if (!placed.length) return { tiles: [], hubs: [], routes: [], live: [] };

  // The (fractional) zoom that fits every located place; tiles of the zoom below, scaled.
  const w0 = placed.map(l => project(l.lat, l.lng, 0));
  const spanX = Math.max(...w0.map(p => p[0])) - Math.min(...w0.map(p => p[0]));
  const spanY = Math.max(...w0.map(p => p[1])) - Math.min(...w0.map(p => p[1]));
  const z = Math.max(2, Math.min(12, Math.log2(Math.min((W - PAD * 2) / (spanX || 1e-9), (H - PAD * 2) / (spanY || 1e-9)))));
  const tz = Math.floor(z);
  const ts = 256 * 2 ** (z - tz);
  const world = placed.map(l => project(l.lat, l.lng, z));
  const cx = (Math.max(...world.map(p => p[0])) + Math.min(...world.map(p => p[0]))) / 2;
  const cy = (Math.max(...world.map(p => p[1])) + Math.min(...world.map(p => p[1]))) / 2;
  const ox = cx - W / 2;
  const oy = cy - H / 2;
  const at = l => { const [x, y] = project(l.lat, l.lng, z); return [x - ox, y - oy]; };

  const tiles = [];
  const n = 2 ** tz;
  for (let tx = Math.floor(ox / ts); tx <= Math.floor((ox + W) / ts); tx++) {
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

  // Routes as gentle curves, so lines that share a terminal don't sit on top of each other.
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
    // along the line so the ones leaving the same terminal don't stack.
    const t = [0.5, 0.36, 0.64][i % 3];
    return {
      key: `${r.a}-${r.b}`, d: `M${a.x},${a.y} Q${c[0]},${c[1]} ${b.x},${b.y}`,
      mx: (1 - t) ** 2 * a.x + 2 * (1 - t) * t * c[0] + t ** 2 * b.x, my: (1 - t) ** 2 * a.y + 2 * (1 - t) * t * c[1] + t ** 2 * b.y,
      showLabel: len >= 170, label, labelW: Math.max(40, label.length * 6.4 + 16), width: 2.5 + (r.trips / maxTrips) * 4,
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
  const hint = Object.entries(staff).map(([k, n]) => `${staffTypeLabel(k)}: ${n}`).join(' · ');
  return [
    { key: 'terminals', label: tf('Transport_Terminals', 'Terminals'), value: t.terminals ?? 0, icon: EnvironmentOutlined, tint: '#e0f2fe', color: '#0369a1' },
    { key: 'agencies', label: tf('Transport_Agencies', 'Agencies'), value: t.agencies ?? 0, icon: ShopOutlined, tint: '#ede9fe', color: '#6d28d9' },
    { key: 'online', label: tf('Transport_Online', 'Online channels'), value: t.online ?? 0, icon: GlobalOutlined, tint: '#fce7f3', color: '#be185d' },
    { key: 'fleet', label: tf('Transport_Fleet', 'Fleet'), value: t.buses ?? 0, icon: CarOutlined, tint: '#dcfce7', color: '#15803d' },
    { key: 'staff', label: tf('Transport_Staff_Count', 'Staff'), value: people, icon: TeamOutlined, tint: '#fef3c7', color: '#b45309', hint },
    { key: 'trips', label: tf('Transport_Trips_Period', 'Trips'), value: t.trips ?? 0, icon: SwapOutlined, tint: '#e0e7ff', color: '#4338ca' },
    { key: 'km', label: tf('Transport_Km', 'Km traveled'), value: (t.km ?? 0).toLocaleString(), icon: DashboardOutlined, tint: '#f1f5f9', color: '#334155' },
  ];
});

const fleetStates = computed(() => [
  { key: 'terminal', label: tf('Transport_At_Terminal', 'At a terminal') },
  { key: 'transit', label: tf('Transport_In_Transit', 'In transit') },
  { key: 'maintenance', label: tf('Transport_In_Maintenance', 'In maintenance') },
].map(s => ({ ...s, count: data.value.fleet.filter(v => v.state === s.key).length })));
const routeList = computed(() => [...map.value.routes].sort((x, y) => x.names.localeCompare(y.names)));
const record = computed(() => data.value.pilots[0] || null);
const level = pct => (pct >= 90 ? 'danger' : pct >= 75 ? 'warn' : 'ok');
const barColor = pct => ({ danger: '#dc2626', warn: '#f59e0b', ok: '#16a34a' })[level(pct)];
const hubCards = computed(() => data.value.hubs.map(h => ({ ...h, color: hubColor.value[h.id] })));
</script>

<style scoped>
.tb { margin-bottom: 16px; }
.tb-title { display: inline-flex; gap: 8px; align-items: center; font-weight: 600; }
.tb-totals { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 12px; margin-bottom: 16px; }
.tb-total { display: flex; gap: 10px; align-items: center; padding: 10px 12px; border: 1px solid #eef0f3; border-radius: 10px; background: #fff; }
.tb-total-icon { width: 36px; height: 36px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: 17px; flex: none; }
.tb-total-value { font-size: 20px; font-weight: 700; line-height: 1.1; }
.tb-total-label { font-size: 12px; color: #64748b; }

.tb-map-wrap { border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; background: #dbeafe; }
.tb-map { display: block; width: 100%; height: auto; }
.tb-sea { fill: #dbeafe; }
.tb-tile { image-rendering: auto; }
.tb-veil { fill: #fff; opacity: .28; }
.tb-hubline { stroke-width: 1.2; stroke-dasharray: 3 3; opacity: .7; }
.tb-route-shadow { fill: none; stroke: #fff; stroke-width: 9; opacity: .75; }
.tb-route { fill: none; stroke: #0f172a; stroke-linecap: round; opacity: .8; }
.tb-route-label { fill: #0f172a; opacity: .86; }
.tb-route-text { fill: #fff; font-size: 11.5px; font-weight: 600; }
.tb-agency { stroke: #fff; stroke-width: 1.5; }
.tb-hub { stroke: #fff; stroke-width: 3; }
.tb-hub-count { fill: #fff; font-size: 13px; font-weight: 800; }
.tb-hub-name, .tb-hub-sub { paint-order: stroke; stroke: #fff; stroke-width: 4px; stroke-linejoin: round; }
.tb-hub-name { font-size: 15px; font-weight: 800; fill: #0f172a; }
.tb-hub-sub { font-size: 11.5px; fill: #334155; }
.tb-bus-halo { fill: #f59e0b; opacity: .35; animation: tb-pulse 1.8s ease-in-out infinite; transform-origin: center; transform-box: fill-box; }
.tb-bus-dot { fill: #f59e0b; stroke: #fff; stroke-width: 2; }
.tb-bus-icon { font-size: 11px; }
.tb-attrib { font-size: 10px; fill: #475569; }
@keyframes tb-pulse { 0%, 100% { transform: scale(1); opacity: .35; } 50% { transform: scale(1.6); opacity: .1; } }

.tb-routes { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 4px 16px; margin-top: 10px; font-size: 12.5px; }
.tb-route-row { display: flex; gap: 8px; align-items: baseline; border-bottom: 1px dashed #e2e8f0; padding: 3px 0; }
.tb-route-names { font-weight: 600; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tb-route-km { color: #0f172a; white-space: nowrap; }
.tb-route-km small { color: #94a3b8; }
.tb-route-trips { color: #64748b; white-space: nowrap; }
.tb-live { margin-top: 8px; font-size: 12.5px; color: #475569; display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: center; }
.tb-live-dot { width: 8px; height: 8px; border-radius: 50%; background: #f59e0b; display: inline-block; }
.tb-live-item { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 999px; padding: 1px 10px; }

.tb-box { border: 1px solid #eef0f3; border-radius: 12px; padding: 14px; background: #fff; margin-bottom: 16px; }
.tb-pilots { margin-bottom: 0; }
.tb-map-hint { font-size: 12px; color: #64748b; margin-top: 6px; }
.tb-states { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 10px 0 12px; }
.tb-state { border-radius: 10px; padding: 8px 6px; text-align: center; }
.tb-state b { display: block; font-size: 22px; line-height: 1.1; }
.tb-state span { font-size: 11.5px; }
.tb-state.tb-state-terminal { background: #f0fdf4; color: #15803d; }
.tb-state.tb-state-transit { background: #fffbeb; color: #b45309; }
.tb-state.tb-state-maintenance { background: #fef2f2; color: #b91c1c; }
.tb-mini-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: #475569; margin: 10px 0 6px; }
.tb-muted { color: #94a3b8; font-size: 12.5px; }
.tb-trip { border: 1px solid #fde68a; background: #fffbeb; border-radius: 8px; padding: 6px 10px; margin-bottom: 6px; }
.tb-trip-row { display: flex; justify-content: space-between; gap: 8px; font-size: 13px; }
.tb-trip-route { font-weight: 700; }
.tb-trip-bus { color: #92400e; white-space: nowrap; }
.tb-trip-meta { font-size: 11.5px; color: #64748b; }
.tb-bus-row { display: flex; gap: 8px; align-items: center; font-size: 12.5px; padding: 3px 0; border-bottom: 1px dashed #eef0f3; }
.tb-bus-state { width: 10px; height: 10px; border-radius: 50%; flex: none; }
.tb-bus-state.tb-state-terminal { background: #16a34a; }
.tb-bus-state.tb-state-transit { background: #f59e0b; }
.tb-bus-state.tb-state-maintenance { background: #dc2626; }
.tb-bus-name { font-weight: 600; white-space: nowrap; }
.tb-bus-where { color: #334155; flex: 1; min-width: 0; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tb-sub-title { font-weight: 700; font-size: 15px; }
.tb-limit { font-size: 12px; color: #64748b; margin-bottom: 10px; }
.tb-record { border-radius: 10px; padding: 12px; margin-bottom: 12px; }
.tb-danger { background: #fef2f2; border: 1px solid #fecaca; animation: tb-alert 2.4s ease-in-out infinite; }
.tb-warn { background: #fffbeb; border: 1px solid #fde68a; }
.tb-ok { background: #f0fdf4; border: 1px solid #bbf7d0; }
@keyframes tb-alert { 0%, 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, .0); } 50% { box-shadow: 0 0 0 4px rgba(220, 38, 38, .15); } }
.tb-record-head { display: flex; gap: 6px; align-items: center; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: #475569; }
.tb-danger .tb-record-icon { color: #dc2626; font-size: 16px; }
.tb-warn .tb-record-icon { color: #d97706; font-size: 16px; }
.tb-ok .tb-record-icon { color: #16a34a; font-size: 16px; }
.tb-record-name { font-size: 17px; font-weight: 700; margin-top: 4px; }
.tb-record-hours { font-size: 26px; font-weight: 800; line-height: 1.1; }
.tb-record-hours small { font-size: 13px; font-weight: 500; color: #64748b; }
.tb-danger .tb-record-hours, .tb-danger .tb-record-msg { color: #b91c1c; }
.tb-warn .tb-record-msg { color: #b45309; }
.tb-record-msg { font-size: 12.5px; font-weight: 600; }
.tb-pilot { margin-bottom: 6px; }
.tb-pilot-row { display: flex; justify-content: space-between; font-size: 13px; }
.tb-pilot-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tb-pilot-hours { font-weight: 600; flex: none; padding-left: 8px; }

.tb-hubs { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; margin-top: 14px; }
.tb-hubcard { border: 1px solid #eef0f3; border-top: 4px solid; border-radius: 12px; overflow: hidden; background: #fff; }
.tb-hubcard-img { height: 92px; background-size: cover; background-position: center; position: relative; }
.tb-hubcard-name { position: absolute; left: 10px; bottom: 8px; color: #fff; font-weight: 800; font-size: 16px; text-shadow: 0 1px 4px rgba(0, 0, 0, .7); }
.tb-hubcard-stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px 0; text-align: center; padding: 10px 4px; }
.tb-hubcard-stats b { display: block; font-size: 18px; }
.tb-hubcard-stats span { font-size: 11px; color: #64748b; }
</style>
