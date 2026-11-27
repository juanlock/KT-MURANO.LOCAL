<template>
  <!-- Flota (the user, 2026-11-27, Operaciones): the buses table that was under Programación, with the fleet's key figures
       on top. Same data as Programación (`transport/programming` -> buses: the last 30 days of Control de Embarque). -->
  <div class="fl">
    <a-spin :spinning="loading">
      <TransportStrip :items="tiles" :toggle="false" />
      <div class="fl-box">
        <div class="fl-band"><span>{{ L('BUSES', 'BUSES') }}</span>
          <a-segmented v-model:value="busSort" size="small" :options="busSortOptions" class="fl-sort" />
        </div>
        <div class="fl-scroll">
          <table class="fl-t">
            <thead>
              <tr>
                <th>Bus</th><th class="c">{{ L('Seats', 'Asientos') }}</th><th>{{ L('Where it is', 'Dónde está') }}</th><th>{{ L('Last trip', 'Último viaje') }}</th>
                <th class="c">{{ L('Trips 30 d', 'Viajes 30 d') }}</th><th class="c">{{ L('Passengers 30 d', 'Pasajeros 30 d') }}</th><th>{{ L('Occupancy 30 d', 'Ocupación 30 d') }}</th>
                <th v-if="money" class="r">{{ L('Tickets 30 d', 'Pasajes 30 d') }}</th><th class="c">{{ L('This week', 'Esta semana') }}</th><th>{{ L('Status', 'Estado') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in buses" :key="b.id" :class="{ bad: !b.ok }">
                <td><b>{{ b.name }}</b> <small>{{ b.plate }}</small></td>
                <td class="c">{{ b.seats || '—' }}</td>
                <td>{{ place(b.where) }}<small v-if="b.idle_days !== null"> · {{ b.idle_days === 0 ? L('today', 'hoy') : L(`${b.idle_days} d ago`, `hace ${b.idle_days} d`) }}</small></td>
                <td><small>{{ b.last ? fmt.dateTime(b.last) : '—' }} {{ b.last_route || '' }}</small></td>
                <td class="c">{{ b.trips }}</td>
                <td class="c">{{ b.pax }}</td>
                <td><div class="fl-occ"><div class="fl-bar"><i :style="{ width: occW(b), background: occColor(b) }" /></div><span>{{ b.occupancy !== null ? Math.round(b.occupancy * 100) + ' %' : '—' }}</span></div></td>
                <td v-if="money" class="r">{{ b.amount ? 'S/ ' + money0(b.amount) : '—' }}</td>
                <td class="c">{{ b.week_sure }}<small v-if="b.week_usual" :title="L('in the base timetable', 'en el horario base')"> +{{ b.week_usual }}</small></td>
                <td>
                  <span v-if="!b.alerts.length" class="fl-ok">{{ L('Available', 'Disponible') }}</span>
                  <span v-for="(a, i) in b.alerts" :key="i" class="fl-al" :class="a[0]">{{ a[1] }}</span>
                </td>
              </tr>
              <tr v-if="!buses.length"><td :colspan="money ? 10 : 9" class="fl-empty">{{ L('No buses.', 'No hay buses.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </a-spin>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import http from '../lib/http';
import TransportStrip from './TransportStrip.vue';
import { useFormat } from '../composables/useFormat';
import { useUiStore } from '../stores/ui';
import { useAuthStore } from '../stores/auth';

const ui = useUiStore();
const auth = useAuthStore();
const es = computed(() => String(ui.locale || 'en').startsWith('es'));
const L = (e, s) => (es.value ? s : e);
const fmt = useFormat();
const money = computed(() => auth.can?.('transport_profit') ?? false);
const money0 = n => Math.round(Number(n || 0)).toLocaleString('es-PE');
const place = w => (w ? (w === 'LIMA' ? 'Lima' : w.charAt(0) + w.slice(1).toLowerCase()) : '—');

const data = ref({ buses: [] });
const loading = ref(false);
async function load() {
  loading.value = true;
  try { data.value = await http.get('transport/programming', { start: dayjs().format('YYYY-MM-DD') }); } catch (e) { message.error(e?.data?.message || L('Could not load.', 'No se pudo cargar.')); }
  loading.value = false;
}
onMounted(load);

const busSort = ref('most');
const busSortOptions = computed(() => [{ value: 'most', label: L('Most used', 'Más usados') }, { value: 'least', label: L('Least used', 'Menos usados') },
  { value: 'where', label: L('By place', 'Por lugar') }, { value: 'status', label: L('Status', 'Estado') }]);
const buses = computed(() => {
  const list = [...(data.value.buses || [])];
  const by = { most: (a, b) => b.trips - a.trips, least: (a, b) => a.trips - b.trips, where: (a, b) => String(a.where).localeCompare(String(b.where)) || b.trips - a.trips,
    status: (a, b) => Number(a.ok) - Number(b.ok) || b.alerts.length - a.alerts.length };
  return list.sort(by[busSort.value]);
});
const occW = b => (b.occupancy !== null ? Math.min(100, Math.round(b.occupancy * 100)) + '%' : '0%');
// Power BI colours: green full, blue fair, orange low.
const occColor = b => (b.occupancy === null ? '#c8c6c4' : b.occupancy >= 0.8 ? '#1AAB40' : b.occupancy >= 0.55 ? '#118DFF' : '#E66C37');

const tiles = computed(() => {
  const list = data.value.buses || [];
  const used = list.filter(b => b.trips > 0);
  const trips = list.reduce((n, b) => n + b.trips, 0);
  const seatTrips = list.reduce((n, b) => n + b.trips * (b.seats || 0), 0);
  const pax = list.reduce((n, b) => n + b.pax, 0);
  const idle = list.filter(b => b.idle_days === null || b.idle_days >= 3).length;
  const t = [
    { label: L('Buses', 'Buses'), value: list.length },
    { label: L('Available', 'Disponibles'), value: list.filter(b => b.ok).length },
    { label: L('With alerts', 'Con alertas'), value: list.filter(b => b.alerts.length).length },
    { label: L('Idle 3+ days', 'Parados 3+ días'), value: idle },
    { label: L('Trips 30 d', 'Viajes 30 d'), value: trips.toLocaleString('es-PE') },
    { label: L('Trips per bus', 'Viajes por bus'), value: used.length ? (trips / used.length).toFixed(1) : '—' },
    { label: L('Occupancy 30 d', 'Ocupación 30 d'), value: seatTrips ? `${Math.round((pax / seatTrips) * 100)} %` : '—' },
  ];
  if (money.value) t.push({ label: L('Tickets 30 d', 'Pasajes 30 d'), value: `S/ ${money0(list.reduce((n, b) => n + b.amount, 0))}` });
  return t;
});
</script>

<style scoped>
.fl-box { margin-top: 8px; border: 1px solid #e1e4e8; border-radius: 8px; overflow: hidden; background: #fff; }
.fl-band { display: flex; align-items: center; gap: 10px; padding: 6px 12px; background: #f3f4f6; border-bottom: 1px solid #e1e4e8; }
.fl-band span { font-size: 12px; font-weight: 800; letter-spacing: .12em; color: #12239e; }
.fl-sort { margin-left: auto; }
.fl-scroll { overflow: auto; max-height: calc(100vh - 360px); }
.fl-t { width: 100%; border-collapse: collapse; font-size: 12.5px; color: #252423; }
.fl-t th { position: sticky; top: 0; background: #fafbfc; text-align: left; font-size: 11px; color: #605e5c; padding: 6px 10px; border-bottom: 1px solid #e1e4e8; font-weight: 600; white-space: nowrap; }
.fl-t td { padding: 4px 10px; border-bottom: 1px solid #f0f1f3; white-space: nowrap; font-variant-numeric: tabular-nums; }
.fl-t td small { color: #98a2b3; }
.fl-t .c { text-align: center; }
.fl-t .r { text-align: right; }
.fl-t tr.bad td { background: rgba(214, 69, 80, 0.08); }
.fl-t tbody tr:hover td { background: #f5f8fc; }
.fl-occ { display: flex; align-items: center; gap: 6px; max-width: 170px; }
.fl-occ span { font-size: 11px; color: #605e5c; min-width: 34px; }
.fl-bar { position: relative; flex: 1; height: 5px; background: #eef1f5; border-radius: 3px; overflow: hidden; }
.fl-bar i { position: absolute; left: 0; top: 0; bottom: 0; border-radius: 3px; }
.fl-ok { font-size: 11px; color: #1aab40; font-weight: 600; }
.fl-al { font-size: 10.5px; border-radius: 4px; padding: 0 5px; margin-right: 4px; }
.fl-al.bad { background: #fde8e8; color: #b42318; }
.fl-al.warn { background: #fff1e0; color: #b45f06; }
.fl-empty { text-align: center; color: #98a2b3; padding: 24px; }
</style>
