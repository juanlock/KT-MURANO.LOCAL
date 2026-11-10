<template>
  <!-- Complaints board (2026-11-05), fitting one screen with no scroll, from the company's complaints-book form
       (App\Services\TransportClaims): counts only, never who complained or what they wrote. -->
  <div ref="root">
    <a-card v-if="data.enabled" class="tc" :bordered="false" :body-style="{ padding: '8px' }">
      <a-spin :spinning="loading">
        <a-empty v-if="!data.has_form" :description="tr('This company has no complaints-book form in Forms.', 'Esta compañía no tiene un formulario de libro de reclamos en Formularios.')" />
        <a-empty v-else-if="!data.total" :description="tr('No complaints on these dates. Pick a longer period above.', 'No hay reclamos en estas fechas. Elige un período mayor en el filtro de arriba.')" />
        <template v-else>
          <TransportStrip v-model:as-table="asTables" :items="tiles" />
          <div class="tc-grid">
            <TransportChartBlock v-for="c in charts" :key="c.key" :chart="c" :as-table="asTables" :class="`tc-${c.key}`" />
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
import { BLUE, GRAY, ORANGE, useFitHeight, useTransportCharts } from '../lib/transportCharts';
import TransportChartBlock from './TransportChartBlock.vue';
import TransportStrip from './TransportStrip.vue';
import { CLAIM_GROUPS, claimDetailName } from '../lib/transportClaimTopics';

// The dashboard's own date filter: [from, to], null = its "All" (all the history here).
const props = defineProps({ range: { type: Array, default: null } });

const { ui, money, tr, small, base, hbar } = useTransportCharts();
const root = ref(null);
const { fit, measure } = useFitHeight(root);
// Card padding + the strip, and what a block adds around its chart.
const CHROME = 16 + 58;
const BLOCK = 36;
// Trend above service on the left; reasons and agencies the full height.
const tall = computed(() => (fit.value ? fit.value - CHROME - BLOCK : 280));
const serviceHeight = computed(() => (fit.value ? 110 : 150));
const trendHeight = computed(() => (fit.value ? tall.value - BLOCK - 8 - serviceHeight.value : 240));

const asTables = ref(false);
const loading = ref(false);
const data = ref({ enabled: false });

onMounted(async () => {
  loading.value = true;
  try {
    data.value = await http.get('transport/claims', { from: props.range?.[0], to: props.range?.[1] });
  } catch (e) {
    data.value = { enabled: false };
  } finally {
    loading.value = false;
    nextTick(measure);
  }
});

const fmtDate = d => dayjs(d).format('DD/MM/YYYY');
const tiles = computed(() => {
  const d = data.value;
  const diff = d.total - d.previous;
  const period = `${d.forms.join(', ')}: ${fmtDate(d.from)} – ${fmtDate(d.to)}.`;
  return [
    { label: tr('Total', 'Total'), value: d.total, note: d.previous === null ? null : `${diff > 0 ? '+' : ''}${diff}`,
      help: d.previous === null ? `${period} ${tr('The whole history.', 'Toda la historia.')}` : `${period} ${d.previous} ${tr('in the same number of days before.', 'en la misma cantidad de días anteriores.')}` },
    { label: tr('Claims', 'Reclamos'), value: d.types.claim },
    { label: tr('Complaints', 'Quejas'), value: d.types.complaint, help: d.types.other ? `${d.types.other} ${tr('with no type', 'sin tipo')}` : null },
    { label: tr('Typical amount', 'Monto típico'), value: d.amount.median != null ? money(d.amount.median) : '—', help: `${tr('Median of the', 'Mediana de los')} ${d.amount.count} ${tr('with an amount: the field holds typing errors, which would swamp a sum.', 'con monto: el campo tiene errores de digitación que distorsionarían una suma.')}` },
    { label: tr('Attended', 'Atendidos'), value: `${d.attended} / ${d.total}`, warn: !d.attended,
      help: d.attended
        ? (d.avg_hours != null ? `${d.avg_hours} h ${tr('on average', 'en promedio')}` : null)
        : tr('None is marked as attended, so response times cannot be measured. Each one is marked from Forms -> Submissions -> Attended.', 'Ninguno está marcado como atendido, así que no se puede medir el tiempo de respuesta. Se marca cada uno desde Formularios -> Envíos -> Atendido.') },
    { label: tr('Unread', 'Sin leer'), value: d.unread },
  ];
});

const named = n => (n === null ? tr('All the rest', 'Todos los demás') : (n === '' ? tr('Not specified', 'Sin especificar') : n));
function ranked(key, rows, title, help, height, labelWidth) {
  const total = data.value.total;
  const names = rows.map(r => named(r.name));
  return {
    key, type: 'bar', height, title, help,
    series: [{ name: tr('Complaints', 'Reclamos'), data: rows.map(r => r.n) }],
    options: hbar(names, [{ data: rows.map(r => r.n) }], { colors: [BLUE], labels: v => v, tooltip: v => v, offset: 16, labelWidth, height }),
    table: { cols: [title, tr('Number', 'Cantidad'), '%'], rows: rows.map((r, i) => [names[i], r.n, `${Math.round((r.n / total) * 100)} %`]) },
  };
}

// The reasons in a few groups (2026-11-09: the list was too long, and "Otros reclamos" said nothing). The server sorts
// each "Otros reclamos" into a topic by the words of its detail; the text itself never reaches the screen.
const GROUPS = CLAIM_GROUPS;
const detailName = n => claimDetailName(n, tr);
const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
function grouped(groups, height) {
  const total = data.value.total;
  const names = groups.map(g => (GROUPS[g.key] ? tr(...GROUPS[g.key]) : g.key));
  const options = hbar(names, [{ data: groups.map(g => g.n) }], { colors: [BLUE], labels: v => v, tooltip: v => v, offset: 16, labelWidth: 170, height });
  options.tooltip = { ...options.tooltip, custom: ({ dataPointIndex }) => {
    const g = groups[dataPointIndex];
    const rows = g.details.map(x => `<div style="display:flex;justify-content:space-between;gap:12px"><span>${esc(detailName(x.name))}</span><b>${x.n}</b></div>`).join('');
    return `<div style="padding:6px 8px;max-width:340px;font-size:11px"><div style="font-weight:600;margin-bottom:4px">${esc(names[dataPointIndex])}: ${g.n}</div>${rows}</div>`;
  } };
  return {
    key: 'reasons', type: 'bar', height,
    title: tr('Why they complain', 'Por qué reclaman'),
    help: tr('The reasons in groups; hover a bar for its detail. "Other complaints" are sorted by the words of what the customer wrote.', 'Los motivos agrupados; pasa el mouse por una barra para ver su detalle. Los "Otros reclamos" se clasifican por las palabras de lo que escribió el cliente.'),
    series: [{ name: tr('Complaints', 'Reclamos'), data: groups.map(g => g.n) }],
    options,
    table: { cols: [tr('Group', 'Grupo'), tr('Reason', 'Motivo'), tr('Number', 'Cantidad'), '%'],
      rows: groups.flatMap((g, i) => g.details.map((x, j) => [j ? '' : `${names[i]} (${g.n})`, detailName(x.name), x.n, `${Math.round((x.n / total) * 100)} %`])) },
  };
}

const charts = computed(() => {
  const d = data.value;
  const out = [];
  const points = d.trend?.points || [];
  if (points.length) {
    const label = p => (d.trend.unit === 'month' ? dayjs(p.date).format('MM/YY') : dayjs(p.date).format('DD/MM'));
    const unit = { day: ['By day', 'Por día'], week: ['By week (Monday)', 'Por semana (lunes)'], month: ['By month', 'Por mes'] }[d.trend.unit];
    const o = base('bar');
    const series = [{ name: tr('Claims', 'Reclamos'), data: points.map(p => p.claim) }, { name: tr('Complaints', 'Quejas'), data: points.map(p => p.complaint) }];
    const colors = [BLUE, ORANGE];
    if (d.types.other) { series.push({ name: tr('No type', 'Sin tipo'), data: points.map(p => p.other) }); colors.push(GRAY); }
    out.push({
      key: 'trend', type: 'bar', height: trendHeight.value,
      title: tr('Complaints over time', 'Reclamos en el tiempo'), help: `${tr(...unit)}.`,
      series,
      options: { ...o, chart: { ...o.chart, stacked: true }, colors,
        plotOptions: { bar: { columnWidth: points.length > 24 ? '78%' : (points.length > 6 ? '52%' : '26%'), borderRadius: 3, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
        stroke: { show: true, width: 1, colors: ['transparent'] },
        xaxis: { categories: points.map(label), labels: { rotate: 0, hideOverlappingLabels: true, style: small }, axisBorder: { show: false }, axisTicks: { show: false }, tooltip: { enabled: false } },
        yaxis: { labels: { formatter: v => Math.round(v), style: small }, forceNiceScale: true },
        tooltip: { theme: ui.dark ? 'dark' : 'light', shared: true, intersect: false, y: { formatter: v => v } } },
      table: { cols: [tr('Date', 'Fecha'), tr('Claims', 'Reclamos'), tr('Complaints', 'Quejas'), tr('No type', 'Sin tipo')], rows: points.map(p => [label(p), p.claim, p.complaint, p.other]) },
    });
  }
  if (d.products?.length) out.push(ranked('products', d.products, tr('About which service', 'Sobre qué servicio'), tr('Tickets, parcels or not specified.', 'Pasajes, encomiendas o sin especificar.'), serviceHeight.value, 110));
  if (d.groups?.length) out.push(grouped(d.groups, tall.value));
  else if (d.reasons?.length) out.push(ranked('reasons', d.reasons, tr('Why they complain', 'Por qué reclaman'), tr('The reason the customer picked.', 'El motivo que eligió el cliente.'), tall.value, 190));
  if (d.agencies?.length) out.push(ranked('agencies', d.agencies, tr('In which agency', 'En qué agencia'), tr('The agency the customer picked.', 'La agencia que eligió el cliente.'), tall.value, 110));
  return out;
});
</script>

<style scoped>
.tc { margin-bottom: 0; }
.tc-grid { display: grid; gap: 8px; grid-template-columns: 1fr; }
@media (min-width: 900px) {
  .tc-grid { grid-template-columns: 1.15fr 1.25fr 0.8fr; grid-template-rows: auto auto; grid-template-areas: "trend reasons agencies" "products reasons agencies"; }
  .tc-trend { grid-area: trend; }
  .tc-products { grid-area: products; }
  .tc-reasons { grid-area: reasons; }
  .tc-agencies { grid-area: agencies; }
}
</style>
