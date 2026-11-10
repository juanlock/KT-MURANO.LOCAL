<template>
  <!-- Parcels board (2026-11-09), one screen with no scroll (App\Services\TransportParcelsBoard): attended / pending,
       per agency (buttons) and route, what they are worth against the client's monthly figure, and their complaints.
       Never who sent or receives a parcel. -->
  <div ref="root">
    <a-card v-if="data.enabled" class="tp" :bordered="false" :body-style="{ padding: '8px' }">
      <a-spin :spinning="loading">
        <div class="tp-buttons">
          <a-radio-group v-model:value="agency" size="small" button-style="solid" @change="load">
            <a-radio-button :value="0">{{ tr('All', 'Todas') }} ({{ allCount }})</a-radio-button>
            <a-radio-button v-for="b in data.buttons" :key="b.id" :value="b.id">{{ b.name }} ({{ b.n }})</a-radio-button>
          </a-radio-group>
        </div>
        <a-empty v-if="!data.total && !data.pending" :description="tr('No parcels on these dates. Pick a longer period above.', 'No hay encomiendas en estas fechas. Elige un período mayor en el filtro de arriba.')" />
        <template v-else>
          <TransportStrip v-model:as-table="asTables" :items="tiles" />
          <div class="tp-grid">
            <template v-for="c in charts" :key="c.key">
              <div v-if="c.pair" :class="`tp-${c.key} tp-pair`"><TransportChartBlock v-for="x in c.pair" :key="x.key" :chart="x" :as-table="asTables" /></div>
              <TransportChartBlock v-else :chart="c" :as-table="asTables" :class="`tp-${c.key}`" />
            </template>
          </div>
        </template>
      </a-spin>
    </a-card>
    <a-empty v-else-if="!loading" :description="tr('Pick a company with Transport in the top bar to see this.', 'Elige una compañía con Transporte en la barra superior para ver esto.')" />
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import dayjs from 'dayjs';
import http from '../lib/http';
import { BLUE, GRAY, ORANGE, RED, YELLOW, useFitHeight, useTransportCharts } from '../lib/transportCharts';
import { claimDetailName } from '../lib/transportClaimTopics';
import TransportChartBlock from './TransportChartBlock.vue';
import TransportStrip from './TransportStrip.vue';

// The dashboard's own date filter: [from, to].
const props = defineProps({ range: { type: Array, default: null } });

const { ui, money, tr, small, base, hbar } = useTransportCharts();
const root = ref(null);
const { fit, measure } = useFitHeight(root);
// Card padding + agency buttons + the strip, and what a block adds around its chart.
const CHROME = 16 + 32 + 58;
const BLOCK = 36;
const half = computed(() => (fit.value ? Math.floor((fit.value - CHROME - 2 * BLOCK - 8) / 2) : 220));

const asTables = ref(false);
const loading = ref(false);
const data = ref({ enabled: false });
const agency = ref(0);
const allCount = computed(() => (data.value.buttons || []).reduce((s, b) => s + b.n, 0));

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('transport/parcels/board', { from: props.range?.[0], to: props.range?.[1], agency: agency.value || undefined });
  } catch (e) {
    data.value = { enabled: false };
  } finally {
    loading.value = false;
    nextTick(measure);
  }
}
onMounted(load);

const fmtDate = d => dayjs(d).format('DD/MM/YYYY');
const pct = (a, b) => (b ? Math.round((a / b) * 100) : 0);
const tiles = computed(() => {
  const d = data.value;
  const period = `${fmtDate(d.from)} – ${fmtDate(d.to)}`;
  const diff = d.income - d.income_prev;
  const out = [
    { label: tr('Received', 'Recibidas'), value: d.total, help: `${period}. ${tr('Parcels received in the period.', 'Encomiendas recibidas en el período.')}` },
    { label: tr('Attended', 'Atendidas'), value: `${d.delivered} · ${pct(d.delivered, d.total)} %`,
      help: tr('Delivered to the receiver.', 'Entregadas al destinatario.') + (d.avg_days != null ? ` ${tr('Average', 'Promedio')}: ${d.avg_days} ${tr('days', 'días')}.` : '') + (d.on_time != null ? ` ${d.on_time} % ${tr('within the date given to the customer.', 'dentro de la fecha dada al cliente.')}` : '') },
    { label: tr('Pending today', 'Pendientes hoy'), value: d.pending, level: d.late ? 'warn' : null, note: d.late ? `${d.late} ${tr('late', 'atrasadas')}` : null,
      help: tr('Not delivered yet, whatever day they were received. Late = past the date given to the customer.', 'Aún sin entregar, sin importar el día en que se recibieron. Atrasada = pasó la fecha dada al cliente.') + (d.on_board ? ` ${d.on_board} ${tr('on a bus.', 'en un bus.')}` : '') },
    { label: tr('Income', 'Ingresos'), value: money(d.income), note: d.income_prev ? `${diff >= 0 ? '+' : ''}${pct(diff, d.income_prev)} %` : null,
      help: `${money(d.income_prev)} ${tr('in the same number of days before.', 'en la misma cantidad de días anteriores.')} ${money(d.per_day)} ${tr('a day.', 'por día.')}` + (d.unpaid ? ` ${money(d.unpaid)} ${tr('not paid yet.', 'sin pagar aún.')}` : '') },
  ];
  if (d.ref) {
    out.push({ label: tr('Vs. the client\'s figure', 'Vs. cifra del cliente'), value: `${d.ref_pct} %`, level: d.ref_pct >= 90 ? 'ok' : (d.ref_pct >= 60 ? 'warn' : 'bad'),
      help: `${tr('The client says the business moves', 'El cliente indica que mueve')} ${money(d.month_ref)} ${tr('a month; for these days that is', 'al mes; para estos días son')} ${money(d.ref)}. ${tr('Recorded here:', 'Registrado aquí:')} ${money(d.income)}.` });
  }
  out.push(
    { label: tr('Average parcel', 'Encomienda promedio'), value: d.avg_price != null ? money(d.avg_price) : '—', help: `${d.fragile} % ${tr('fragile.', 'frágiles.')}` },
    { label: tr('Per kg', 'Por kg'), value: d.per_kg != null ? money(d.per_kg) : '—', help: `${Math.round(d.kg).toLocaleString()} kg ${tr('carried.', 'transportados.')}` },
    { label: tr('Complaints', 'Reclamos'), value: d.claims?.total ?? 0, level: d.claims?.total ? 'warn' : null,
      help: d.claims?.has_form ? tr('Complaints-book entries about parcels in the period.', 'Reclamos del libro sobre encomiendas en el período.') : tr('No complaints-book form in Forms.', 'No hay formulario de libro de reclamos.') },
  );
  return out;
});

function bars(key, title, help, names, series, colors, height, { stacked = false, isMoney = false, labelWidth = 120, table } = {}) {
  const fmt = isMoney ? money : (v => v);
  return {
    key, type: 'bar', height, title, help, series,
    options: hbar(names, series, { colors, stacked, labels: fmt, tooltip: fmt, offset: 16, labelWidth, height }),
    table,
  };
}

function donut(key, title, help, labels, values, colors, height, tip, table) {
  const o = base('donut');
  return {
    key, type: 'donut', height, title, help, series: values, table,
    options: { ...o, labels, colors,
      legend: { ...o.legend, position: 'bottom', horizontalAlign: 'center', height: undefined, itemMargin: { horizontal: 4, vertical: 0 } },
      dataLabels: { enabled: true, formatter: v => `${Math.round(v)} %`, style: { fontSize: '10px', fontWeight: 500 }, dropShadow: { enabled: false } },
      plotOptions: { pie: { donut: { size: '55%' } } },
      stroke: { width: 1, colors: [ui.dark ? '#1f1f1e' : '#fff'] },
      tooltip: { theme: ui.dark ? 'dark' : 'light', y: { formatter: (v, { seriesIndex }) => tip(seriesIndex) } } },
  };
}

const charts = computed(() => {
  const d = data.value;
  const out = [];
  const h = half.value;
  const routes = d.routes || [];
  out.push(bars('routes', tr('Income by route', 'Ingresos por ruta'), tr('Agency that receives → agency that delivers.', 'Agencia que recibe → agencia que entrega.'),
    routes.map(r => r.route), [{ name: tr('Income', 'Ingresos'), data: routes.map(r => Math.round(r.income)) }], [BLUE], h,
    { isMoney: true, labelWidth: 150, table: { cols: [tr('Route', 'Ruta'), tr('Parcels', 'Encomiendas'), tr('Income', 'Ingresos'), tr('Average', 'Promedio'), 'kg', tr('Attended', 'Atendidas')],
      rows: routes.map(r => [r.route, r.n, money(r.income), money(r.n ? r.income / r.n : 0), Math.round(r.kg), `${pct(r.delivered, r.n)} %`]) } }));

  const ag = d.agencies || [];
  out.push(bars('agencies', tr('Income by agency', 'Ingresos por agencia'), tr('Every agency, whatever button is picked: where the parcel was received and charged.', 'Todas las agencias, sea cual sea el botón elegido: donde se recibió y cobró la encomienda.'),
    ag.map(a => a.name), [{ name: tr('Income', 'Ingresos'), data: ag.map(a => Math.round(a.income)) }], [BLUE], h,
    { isMoney: true, table: { cols: [tr('Agency', 'Agencia'), tr('Parcels', 'Encomiendas'), tr('Income', 'Ingresos'), '%', tr('Attended', 'Atendidas')],
      rows: ag.map(a => [a.name, a.n, money(a.income), `${pct(a.income, ag.reduce((s, x) => s + x.income, 0))} %`, `${pct(a.delivered, a.n)} %`]) } }));

  const pb = d.pending_by || [];
  out.push(bars('pending', tr('Pending, by where they are delivered', 'Pendientes, por dónde se entregan'), tr('Today. In the agency = waiting for a bus or for the receiver; late = past the date given to the customer.', 'Hoy. En agencia = esperando bus o destinatario; atrasada = pasó la fecha dada al cliente.'),
    pb.map(p => p.name), [
      { name: tr('On time', 'A tiempo'), data: pb.map(p => p.waiting + p.on_board - p.late) },
      { name: tr('Late', 'Atrasadas'), data: pb.map(p => p.late) },
    ], [GRAY, RED], h, { stacked: true, table: { cols: [tr('Agency', 'Agencia'), tr('In the agency', 'En agencia'), tr('On a bus', 'En bus'), tr('Late', 'Atrasadas')], rows: pb.map(p => [p.name, p.waiting, p.on_board, p.late]) } }));

  const tr_ = d.trend || [];
  const o = base('bar');
  out.push({
    key: 'trend', type: 'bar', height: h, title: tr('Income by day', 'Ingresos por día'), help: tr('Day the parcel was received.', 'Día en que se recibió la encomienda.'),
    series: [{ name: tr('Income', 'Ingresos'), data: tr_.map(p => Math.round(p.income)) }],
    options: { ...o, colors: [ORANGE],
      plotOptions: { bar: { columnWidth: tr_.length > 20 ? '75%' : '50%', borderRadius: 2, borderRadiusApplication: 'end' } },
      // One date in every few, so they never run together.
      xaxis: { categories: tr_.map((p, i) => (i % Math.max(1, Math.ceil(tr_.length / 10)) === 0 ? dayjs(p.date).format('DD/MM') : '')), labels: { rotate: 0, hideOverlappingLabels: true, style: small }, axisBorder: { show: false }, axisTicks: { show: false }, tooltip: { enabled: false } },
      yaxis: { labels: { formatter: v => money(v), style: small } },
      tooltip: { theme: ui.dark ? 'dark' : 'light', x: { formatter: (v, { dataPointIndex }) => (tr_[dataPointIndex] ? fmtDate(tr_[dataPointIndex].date) : v) },
        y: { formatter: (v, { dataPointIndex }) => `${money(v)} · ${tr_[dataPointIndex]?.n ?? 0} ${tr('parcels', 'encomiendas')}` } } },
    table: { cols: [tr('Date', 'Fecha'), tr('Parcels', 'Encomiendas'), tr('Income', 'Ingresos')], rows: tr_.map(p => [fmtDate(p.date), p.n, money(p.income)]) },
  });

  // Size (by weight) and what the sender declared inside each size.
  const sz = d.sizes || [];
  const SIZE = { s: ['Small', 'Pequeño'], m: ['Medium', 'Mediano'], l: ['Large', 'Grande'], xl: ['Extra large', 'Extra grande'] };
  const sizeName = (s, i) => `${tr(...SIZE[s.key])} (${s.max ? `${sz[i - 1]?.max ?? 0}–${s.max}` : `+${sz[i - 1]?.max ?? 0}`} kg)`;
  const sizeNames = sz.map(sizeName);
  const contents = [...new Set(sz.flatMap(s => Object.keys(s.contents)))].sort((a, b) => a.localeCompare(b));
  const PALETTE = [BLUE, ORANGE, YELLOW, GRAY, '#3a9b7a', '#8a5cc2', RED, '#c27a5c'];
  // Two donuts side by side (user, 2026-11-09: the stacked bars did not read well): by size, by type.
  const typeCounts = contents.map(c => sz.reduce((s, x) => s + (x.contents[c] || 0), 0));
  const typeNames = contents.map(c => c || tr('Not declared', 'Sin declarar'));
  out.push({
    key: 'sizes', pair: [
      donut('size', tr('Parcels by size', 'Encomiendas por tamaño'), tr('Size by weight (our cuts until the client gives theirs). Hover for the income.', 'Tamaño por peso (cortes nuestros hasta que el cliente dé los suyos). Pasa el mouse para ver los ingresos.'),
        sizeNames, sz.map(s => s.n), [BLUE, ORANGE, YELLOW, GRAY], h, i => `${sz[i].n} · ${money(sz[i].income)}`,
        { cols: [tr('Size', 'Tamaño'), tr('Parcels', 'Encomiendas'), '%', tr('Income', 'Ingresos'), tr('Average', 'Promedio'), 'kg'],
          rows: sz.map((s, i) => [sizeNames[i], s.n, `${pct(s.n, d.total)} %`, money(s.income), money(s.n ? s.income / s.n : 0), Math.round(s.kg)]) }),
      donut('type', tr('Parcels by type', 'Encomiendas por tipo'), tr('What the sender declared.', 'Lo que declaró el remitente.'),
        typeNames, typeCounts, contents.map((_, i) => PALETTE[i % PALETTE.length]), h, i => `${typeCounts[i]}`,
        { cols: [tr('Type', 'Tipo'), tr('Parcels', 'Encomiendas'), '%'], rows: typeNames.map((n, i) => [n, typeCounts[i], `${pct(typeCounts[i], d.total)} %`]) }),
    ],
  });

  const cr = d.claims?.reasons || [];
  if (cr.length) {
    const names = cr.map(r => claimDetailName(r.name, tr));
    out.push(bars('claims', tr('Complaints about parcels', 'Reclamos de encomiendas'), tr('Product "Encomiendas" or a parcel reason in the complaints book. Counts only.', 'Producto "Encomiendas" o un motivo de encomienda en el libro de reclamos. Solo cantidades.'),
      names, [{ name: tr('Complaints', 'Reclamos'), data: cr.map(r => r.n) }], [ORANGE], h,
      { labelWidth: 170, table: { cols: [tr('Reason', 'Motivo'), tr('Number', 'Cantidad')], rows: cr.map((r, i) => [names[i], r.n]) } }));
  } else {
    out.push({ key: 'claims', type: 'bar', height: h, title: tr('Complaints about parcels', 'Reclamos de encomiendas'), help: tr('None in the period.', 'Ninguno en el período.'),
      series: [{ data: [] }], options: { ...base('bar'), grid: { show: false }, xaxis: { labels: { show: false }, axisBorder: { show: false }, axisTicks: { show: false } }, yaxis: { show: false }, noData: { text: tr('No complaints about parcels in the period.', 'Sin reclamos de encomiendas en el período.'), style: { fontSize: '12px' } } },
      table: { cols: [tr('Reason', 'Motivo'), tr('Number', 'Cantidad')], rows: [] } });
  }
  return out;
});
</script>

<style scoped>
.tp { margin-bottom: 0; }
.tp-buttons { margin-bottom: 8px; overflow-x: auto; white-space: nowrap; }
.tp-grid { display: grid; gap: 8px; grid-template-columns: repeat(12, minmax(0, 1fr)); }
/* Row 1: route, agency, pending (4 + 4 + 4); row 2: day, the two donuts, complaints (3 + 6 + 3). */
.tp-routes, .tp-agencies, .tp-pending { grid-column: span 4; }
.tp-trend, .tp-claims { grid-column: span 3; }
.tp-sizes { grid-column: span 6; }
.tp-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; min-width: 0; }
@media (max-width: 900px) { .tp-grid { grid-template-columns: 1fr; } .tp-grid > * { grid-column: auto !important; } .tp-pair { grid-template-columns: 1fr; } }
</style>
