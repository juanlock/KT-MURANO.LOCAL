<template>
  <!-- "Mando operativo" (2026-11-18): the departures still to leave (today .. +21 days), how they are selling and whether
       they will cover their costs, and which week marketing should push (App\Services\TransportOutlook). One screen:
       what doesn't fit scrolls inside the table. -->
  <div ref="root">
    <a-card v-if="data.enabled" class="to" :bordered="false" :body-style="{ padding: '8px' }">
      <a-spin :spinning="loading">
        <a-empty v-if="!loading && !data.trips.length" :description="tr('No departures scheduled for the next weeks.', 'No hay salidas programadas para las próximas semanas.')" />
        <template v-else>
          <TransportStrip :items="tiles" :toggle="false" />

          <div class="to-top">
            <div v-for="w in data.weeks" :key="w.index" class="to-week" :class="{ 'to-week-focus': data.focus && data.focus.week === w.index }">
              <div class="to-week-head">
                <b>{{ weekName(w.index) }}</b>
                <span class="to-muted">{{ dayMonth(w.from) }} – {{ dayMonth(w.to) }}</span>
                <a-tooltip :title="weekHelp(w)"><InfoCircleOutlined class="to-info" /></a-tooltip>
              </div>
              <template v-if="w.trips">
                <div class="to-week-counts">
                  <span class="to-dot" :style="{ background: COLORS.red }" />{{ w.red }}
                  <span class="to-dot" :style="{ background: COLORS.amber }" />{{ w.amber }}
                  <span class="to-dot" :style="{ background: COLORS.green }" />{{ w.green }}
                  <span class="to-muted">· {{ w.trips }} {{ tr('dep.', 'sal.') }}</span>
                </div>
                <div class="to-week-figs">
                  <span>{{ w.projected_occupancy != null ? w.projected_occupancy + ' %' : '—' }}</span>
                  <span v-if="showMoney" :class="w.projected_profit >= 0 ? 'pos' : 'neg'">{{ money(w.projected_profit, 0) }}</span>
                  <span v-if="w.seats_missing" class="to-muted">{{ tr('missing', 'faltan') }} {{ w.seats_missing }}</span>
                </div>
              </template>
              <div v-else class="to-muted to-week-empty">{{ tr('No departures loaded yet', 'Sin salidas cargadas aún') }}</div>
            </div>
            <div class="to-week to-channels">
              <div class="to-week-head">
                <b>{{ tr('Sold by channel', 'Vendido por canal') }}</b>
                <a-tooltip :title="channelHelp"><InfoCircleOutlined class="to-info" /></a-tooltip>
              </div>
              <div class="to-bar">
                <span v-for="c in channelsShown" :key="c.channel" :style="{ width: c.pct + '%', background: CHANNEL[c.channel].color }" :title="`${channelName(c.channel)}: ${c.tickets} (${c.pct} %)`" />
              </div>
              <div class="to-legend">
                <span v-for="c in channelsShown" :key="c.channel"><span class="to-dot" :style="{ background: CHANNEL[c.channel].color }" />{{ channelName(c.channel) }} {{ c.pct }} %</span>
                <span v-if="!channelsShown.length" class="to-muted">{{ tr('No tickets sold yet', 'Aún no hay pasajes vendidos') }}</span>
              </div>
            </div>
          </div>

          <div class="to-focus">
            <b>{{ tr('Marketing focus', 'Foco de marketing') }}:</b>
            <template v-if="data.focus">
              <span>{{ weekName(data.focus.week) }} ({{ dayMonth(data.focus.from) }} – {{ dayMonth(data.focus.to) }}) · {{ tr('missing', 'faltan') }} <b>{{ data.focus.seats_missing }}</b> {{ tr('tickets', 'pasajes') }}</span>
              <span v-if="data.focus.routes.length" class="to-muted"> · {{ data.focus.routes.map(r => `${r.label} (${r.seats_missing})`).join(', ') }}</span>
              <span v-if="data.focus.days.length" class="to-muted"> · {{ data.focus.days.map(d => `${weekday(d.label)} ${dayMonth(d.label)} (${d.seats_missing})`).join(', ') }}</span>
            </template>
            <span v-else class="to-muted">{{ tr('No week needs a push: the departures that must leave are covered.', 'Ninguna semana necesita impulso: las salidas que deben salir están cubiertas.') }}</span>
            <a-tooltip :title="focusHelp"><InfoCircleOutlined class="to-info" /></a-tooltip>
          </div>

          <a-table class="to-table" :columns="columns" :data-source="data.trips" row-key="id" size="small" :pagination="false" :scroll="tableScroll">
            <template #bodyCell="{ column, record: t }">
              <template v-if="column.key === 'departure'">{{ weekday(t.departure_at) }} {{ dayMonthTime(t.departure_at) }}</template>
              <template v-else-if="column.key === 'route'"><b>{{ t.route }}</b></template>
              <template v-else-if="column.key === 'sold'">{{ t.sold }}<span class="to-muted"> / {{ t.seats || '—' }}</span></template>
              <template v-else-if="column.key === 'projected'">
                <a-tooltip :title="methodHelp(t)">
                  <span>{{ t.projected }}<span class="to-muted"> · {{ t.projected_occupancy != null ? t.projected_occupancy + ' %' : '—' }}</span></span>
                </a-tooltip>
              </template>
              <template v-else-if="column.key === 'risk'">
                <a-tooltip :title="costHelp(t)">
                  <span class="to-chip" :style="{ background: COLORS[t.risk] }">{{ riskName(t.risk) }}</span>
                </a-tooltip>
              </template>
              <template v-else-if="column.key === 'missing'">
                <b v-if="t.seats_to_breakeven" :style="{ color: COLORS[t.risk] }">{{ t.seats_to_breakeven }}</b>
                <span v-else-if="t.seats_to_breakeven === 0" class="to-muted">—</span>
                <span v-else class="to-muted">?</span>
              </template>
              <template v-else-if="column.key === 'profit'"><span :class="t.projected_profit >= 0 ? 'pos' : 'neg'">{{ money(t.projected_profit, 0) }}</span></template>
            </template>
          </a-table>
        </template>
      </a-spin>
    </a-card>
    <a-empty v-else-if="!loading" :description="tr('Pick a company with Transport in the top bar to see this.', 'Elige una compañía con Transporte en la barra superior para ver esto.')" />
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import dayjs from 'dayjs';
import { InfoCircleOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { useFormat } from '../composables/useFormat';
import { useFitHeight, useTransportCharts } from '../lib/transportCharts';
import TransportStrip from './TransportStrip.vue';

const { tr } = useTransportCharts();
const { money, dayMonth, dayMonthTime } = useFormat();
const root = ref(null);
const { fit, measure } = useFitHeight(root);
// Card padding + strip + the week cards + the focus line + the table's header.
const CHROME = 16 + 58 + 84 + 30 + 34;
const tableScroll = computed(() => (fit.value ? { x: 'max-content', y: Math.max(160, fit.value - CHROME) } : { x: 'max-content' }));

const COLORS = { red: '#dc2626', amber: '#d97706', green: '#16a34a' };
const CHANNEL = {
  agency: { color: '#118DFF', en: 'Agency', es: 'Agencia' },
  jelaf: { color: '#8E2C9E', en: 'Jelaf', es: 'Jelaf' },
  perubus: { color: '#E044A7', en: 'PeruBus', es: 'PeruBus' },
  phone: { color: '#B08F00', en: 'Phone', es: 'Teléfono' },
  unknown: { color: '#898781', en: 'Not recorded', es: 'Sin registrar' },
};
const WEEKDAYS = { en: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], es: ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'] };

const loading = ref(false);
const EMPTY = { enabled: false, trips: [], weeks: [], totals: {}, channels: [], focus: null, history: {}, missing: {} };
const data = ref({ ...EMPTY });
const totals = computed(() => data.value.totals || {});
// A role without the profit permission gets the board without amounts (server side, 2026-11-19: Operaciones).
const showMoney = computed(() => data.value.money !== false);

async function load() {
  loading.value = true;
  try {
    const res = await http.get('transport/outlook');
    data.value = { ...EMPTY, ...res };
  } catch (e) {
    data.value = { ...EMPTY };
  } finally {
    loading.value = false;
    nextTick(measure);
  }
}
onMounted(load);

const weekday = d => WEEKDAYS[tr('en', 'es')][dayjs(d).day()];
const weekName = i => [tr('This week', 'Esta semana'), tr('Next week', 'Próxima semana'), tr('The week after', 'Subsiguiente')][i] || '';
const riskName = r => ({ red: tr('Loss', 'No cubre'), amber: tr('Tight', 'Ajustada'), green: tr('Covered', 'Cubre') }[r]);
const channelName = c => tr(CHANNEL[c].en, CHANNEL[c].es);
const channelsShown = computed(() => (data.value.channels || []).filter(c => c.tickets > 0));

const missingLines = computed(() => {
  const m = data.value.missing || {};
  const line = (list, en, sp) => (list?.length ? `${tr(en, sp)}: ${list.join(', ')}` : null);
  return [
    line(m.seats, 'Buses without seats', 'Buses sin asientos'),
    line(m.depreciation, 'Buses without price or useful life: no depreciation counted', 'Buses sin precio o vida útil: no se cuenta depreciación'),
    line(m.fuel, 'Buses without fuel records', 'Buses sin registros de combustible'),
    line(m.tolls, 'Routes without tolls', 'Rutas sin peajes'),
    line(m.distance, 'Routes without distance', 'Rutas sin distancia'),
  ].filter(Boolean);
});

const tiles = computed(() => {
  const t = totals.value;
  const h = data.value.history || {};
  const out = [
    { label: tr('Coming departures', 'Salidas próximas'), value: t.trips || 0,
      help: tr(`From today to ${dayMonth(data.value.to)}. ${t.sold || 0} tickets sold so far, ${t.projected || 0} projected. The projection learns from ${h.trips || 0} trips completed in the last ${h.days || 120} days.`,
        `De hoy al ${dayMonth(data.value.to)}. ${t.sold || 0} pasajes vendidos hasta ahora, ${t.projected || 0} proyectados. La proyección aprende de ${h.trips || 0} viajes realizados en los últimos ${h.days || 120} días.`) },
    { label: tr('Red', 'En rojo'), value: t.red || 0, level: t.red ? 'bad' : 'ok',
      help: tr('The projected income does not even pay what the trip itself costs (fuel, tolls, crew, maintenance, commissions): consider merging or cancelling it.', 'El ingreso proyectado no paga ni lo que cuesta el viaje en sí (combustible, peajes, tripulación, mantenimiento, comisiones): evaluar juntarla con otra o cancelarla.') },
    { label: tr('Amber', 'En ámbar'), value: t.amber || 0, level: t.amber ? 'warn' : 'ok',
      help: tr('Pays the trip but not its share of the fixed costs (payroll, agency, depreciation). It must leave: these are marketing\'s target.', 'Paga el viaje pero no su parte de los costos fijos (planilla, agencia, depreciación). Debe salir: son el objetivo de marketing.') },
    { label: tr('Projected occupancy', 'Ocupación proyectada'), value: t.projected_occupancy != null ? `${t.projected_occupancy} %` : '—',
      help: showMoney.value
        ? tr(`Projected profit ${money(t.projected_profit, 0)} (income ${money(t.projected_income, 0)} - costs ${money(t.projected_cost, 0)}).`, `Ganancia proyectada ${money(t.projected_profit, 0)} (ingresos ${money(t.projected_income, 0)} - costos ${money(t.projected_cost, 0)}).`)
        : tr('Projected passengers over the seats of the coming departures.', 'Pasajeros proyectados sobre los asientos de las salidas próximas.') },
    { label: tr('Tickets to break even', 'Pasajes que faltan'), value: t.seats_missing || 0, level: t.seats_missing ? 'warn' : 'ok',
      help: tr('Extra tickets the red and amber departures need, over the projection, to cover all their costs.', 'Pasajes extra que necesitan las salidas en rojo y ámbar, sobre lo proyectado, para cubrir todos sus costos.') },
    { label: tr('Sold online', '% online'), value: t.online_pct != null ? `${t.online_pct} %` : '—', help: channelHelp.value },
  ];
  if (data.value.low_data) {
    out.push({ label: tr('Little history', 'Pocos datos'), value: `${h.advance_share || 0} %`, level: 'warn', warn: true,
      help: tr(`Only ${h.advance_share || 0} % of past tickets were sold a day or more ahead: far departures are projected from each route's usual passengers for that weekday, not from their advance sales.`,
        `Solo el ${h.advance_share || 0} % de los pasajes pasados se vendió con un día o más de anticipación: las salidas lejanas se proyectan con los pasajeros usuales de la ruta en ese día de la semana, no con sus ventas anticipadas.`) });
  }
  if (missingLines.value.length) {
    out.push({ label: tr('Data missing', 'Faltan datos'), value: missingLines.value.length, level: 'warn', warn: true, help: missingLines.value.join(' · ') });
  }
  return out;
});

const channelHelp = computed(() => {
  const list = (data.value.channels || []).map(c => `${channelName(c.channel)}: ${c.tickets}${showMoney.value ? ` (${money(c.income, 0)})` : ''}`).join(' · ');
  const comm = Object.entries(data.value.commission || {}).filter(([, v]) => v > 0).map(([k, v]) => `${channelName(k)} ${v} %`).join(', ');
  return `${tr('Tickets sold so far on the coming departures', 'Pasajes vendidos hasta ahora en las salidas próximas')}: ${list}. ${comm ? tr(`Commission counted: ${comm}.`, `Comisión contada: ${comm}.`) : tr('No channel commission set: none counted.', 'Sin comisión por canal configurada: no se cuenta.')}`;
});

const focusHelp = computed(() => tr(
  `The week with the most tickets missing on amber departures (the ones that must leave). When next week is already selling well (projected occupancy ${75} % or more), push this week or the one after instead. Between brackets: tickets missing.`,
  `La semana con más pasajes faltantes en salidas en ámbar (las que deben salir). Si la próxima semana ya vende bien (ocupación proyectada de ${75} % o más), se impulsa esta semana o la subsiguiente. Entre paréntesis: pasajes que faltan.`,
));

function weekHelp(w) {
  return tr(
    `${w.trips} departures: ${w.red} red, ${w.amber} amber, ${w.green} green. Sold ${w.sold}, projected ${w.projected} tickets, occupancy ${w.projected_occupancy ?? '—'} %.${showMoney.value ? ` Projected profit ${money(w.projected_profit, 0)}.` : ''} Tickets missing to break even: ${w.seats_missing} (${w.amber_seats_missing} on amber).`,
    `${w.trips} salidas: ${w.red} en rojo, ${w.amber} en ámbar, ${w.green} en verde. Vendidos ${w.sold}, proyectados ${w.projected} pasajes, ocupación ${w.projected_occupancy ?? '—'} %.${showMoney.value ? ` Ganancia proyectada ${money(w.projected_profit, 0)}.` : ''} Pasajes que faltan para equilibrio: ${w.seats_missing} (${w.amber_seats_missing} en ámbar).`,
  );
}

function methodHelp(t) {
  const pct = t.curve != null ? Math.round(t.curve * 100) : null;
  if (t.method === 'final') return tr('Already on the road or reported: final figure.', 'Ya en ruta o reportada: cifra final.');
  if (t.method === 'curve') return tr(`${t.sold} sold; at ${t.hours_left} h from leaving this route usually has ${pct} % of its tickets sold.`, `${t.sold} vendidos; a ${t.hours_left} h de salir esta ruta suele tener vendido el ${pct} % de sus pasajes.`);
  if (t.method === 'usual') return tr(`${t.sold} sold + what this route usually still sells on that weekday (usual final: ${t.usual} passengers${pct != null ? `, ${pct} % sold by now` : ''}).`, `${t.sold} vendidos + lo que esta ruta suele vender aún ese día de la semana (final usual: ${t.usual} pasajeros${pct != null ? `, ${pct} % vendido a esta altura` : ''}).`);
  return tr('No history for this route: only what is sold.', 'Sin historia para esta ruta: solo lo vendido.');
}

function costHelp(t) {
  if (!t.costs) return riskName(t.risk);
  const v = t.costs.variable;
  const f = t.costs.fixed;
  const est = t.estimated?.length ? tr(` (estimated: ${t.estimated.join(', ')})`, ` (estimado: ${t.estimated.map(x => ({ fuel: 'combustible', tolls: 'peajes' }[x] || x)).join(', ')})`) : '';
  return tr(
    `Projected income ${money(t.income.projected, 0)} (parcels ${money(t.income.parcels, 0)}). Trip costs ${money(v.total, 0)}: crew ${money(v.staff, 0)}, fuel ${money(v.fuel, 0)}, tolls ${money(v.tolls, 0)}, maintenance ${money(v.maintenance, 0)}${v.meals ? `, snack ${money(v.meals, 0)}` : ''}, commission ${money(v.commission, 0)}${est}. Fixed share ${money(f.total, 0)}: payroll ${money(f.payroll, 0)}, agency ${money(f.agency, 0)}, depreciation ${money(f.depreciation, 0)}.`,
    `Ingreso proyectado ${money(t.income.projected, 0)} (encomiendas ${money(t.income.parcels, 0)}). Costo del viaje ${money(v.total, 0)}: tripulación ${money(v.staff, 0)}, combustible ${money(v.fuel, 0)}, peajes ${money(v.tolls, 0)}, mantenimiento ${money(v.maintenance, 0)}${v.meals ? `, snack ${money(v.meals, 0)}` : ''}, comisión ${money(v.commission, 0)}${est}. Parte fija ${money(f.total, 0)}: planilla ${money(f.payroll, 0)}, agencia ${money(f.agency, 0)}, depreciación ${money(f.depreciation, 0)}.`,
  );
}

const columns = computed(() => [
  { title: tr('Departure', 'Salida'), key: 'departure', width: 112 },
  { title: tr('Route', 'Ruta'), key: 'route' },
  { title: 'Bus', dataIndex: 'vehicle', key: 'vehicle' },
  { title: tr('Sold / seats', 'Vendidos / asientos'), key: 'sold', align: 'right' },
  { title: tr('Projected', 'Proyectado'), key: 'projected', align: 'right' },
  { title: tr('Status', 'Estado'), key: 'risk', align: 'center' },
  { title: tr('Missing', 'Faltan'), key: 'missing', align: 'right' },
  showMoney.value && { title: tr('Proj. profit', 'Ganancia proy.'), key: 'profit', align: 'right' },
].filter(Boolean));
</script>

<style scoped>
.to { margin-bottom: 0; }
.to-top { display: grid; gap: 8px; grid-template-columns: 1fr; margin-bottom: 6px; }
.to-week { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; padding: 5px 10px; min-width: 0; font-size: 12px; line-height: 1.45; }
.to-week-focus { border-color: #d97706; box-shadow: inset 0 0 0 1px #d97706; }
.to-week-head { display: flex; align-items: baseline; gap: 6px; white-space: nowrap; overflow: hidden; }
.to-week-counts { display: flex; align-items: center; gap: 4px; font-weight: 600; }
.to-week-figs { display: flex; gap: 10px; font-weight: 600; white-space: nowrap; overflow: hidden; }
.to-week-empty { padding-top: 4px; }
.to-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin: 0 2px 0 6px; }
.to-week-counts .to-dot:first-child, .to-legend .to-dot { margin-left: 0; }
.to-bar { display: flex; height: 10px; border-radius: 5px; overflow: hidden; background: rgba(128, 128, 128, 0.15); margin: 4px 0 3px; }
.to-legend { display: flex; flex-wrap: wrap; gap: 2px 10px; font-size: 11px; }
.to-focus { font-size: 12px; padding: 4px 10px; margin-bottom: 6px; border-left: 3px solid #d97706; background: rgba(217, 119, 6, 0.08); border-radius: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.to-info { opacity: 0.45; font-size: 11px; cursor: help; margin-left: 4px; }
.to-muted { opacity: 0.68; font-weight: 400; }
.to-chip { display: inline-block; color: #fff; font-size: 11px; font-weight: 600; border-radius: 10px; padding: 0 8px; line-height: 18px; white-space: nowrap; }
.to-table :deep(.ant-table-thead > tr > th) { padding: 3px 6px !important; white-space: nowrap; font-size: 11.5px; }
.to-table :deep(.ant-table-tbody > tr > td) { padding: 2px 6px !important; font-size: 12px; white-space: nowrap; }
.pos { color: #15803d; }
.neg { color: #b91c1c; }
@media (min-width: 900px) {
  .to-top { grid-template-columns: repeat(3, minmax(0, 1fr)) minmax(0, 1.2fr); }
}
</style>
