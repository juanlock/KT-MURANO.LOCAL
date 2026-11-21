<template>
  <div class="page b2b hx">
    <a-spin :spinning="loading">
      <a-row :gutter="[18, 18]">
        <!-- Left: what is still to collect, grouped like Hordex's home. -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" class="card pend">
            <template #title><span class="ttl">{{ tr('Invoices pending payment', 'Facturas pendientes de pago') }}</span></template>
            <a-empty v-if="!(d.overdue || []).length" :description="tr('Nothing pending', 'Nada pendiente')" />
            <template v-for="g in GROUPS" :key="g.key">
              <template v-if="groups[g.key]?.length">
                <div class="lapso">{{ tr(...g.label) }}</div>
                <div class="chips">
                  <a-tooltip v-for="o in groups[g.key]" :key="o.id" :title="`${o.client} · ${money(o.balance)} · ${tr('due', 'vence')} ${o.due_date ? date(o.due_date) : '—'}`">
                    <router-link :to="'/b2b/collections'" class="chip" :class="g.key">{{ o.Ref }}</router-link>
                  </a-tooltip>
                </div>
              </template>
            </template>
          </a-card>
        </a-col>

        <!-- Right: month / year by chain, then the annual rebate cards per year. -->
        <a-col :xs="24" :lg="16">
          <!-- One screen, no vertical scroll (rule "a dashboard fits one screen", 2026-11-21): month and year side by side,
               the four rebate cards in a 2 x 2 grid with the year in their title. -->
          <a-row :gutter="14" class="mb">
          <a-col :xs="24" :md="12">
          <a-card size="small" class="card fill">
            <template #title><span class="ttl">{{ tr('Sales of the month', 'Acumulado de ventas del mes') }}</span></template>
            <div v-for="(r, i) in d.month || []" :key="'m' + i" class="bar">
              <span class="cli">{{ r.client }}</span>
              <span class="amt">{{ money(r.total) }}</span>
              <span class="trk"><i :style="{ width: pct(r.total, d.month) + '%', background: COLORS[i % COLORS.length] }" /></span>
            </div>
            <div v-if="!(d.month || []).length" class="muted">{{ tr('No sales this month yet', 'Sin ventas este mes todavía') }}</div>
            <div class="bar total"><span class="cli">Total :</span><span class="amt">{{ money(sum(d.month)) }}</span><span /></div>
          </a-card>
          </a-col>

          <a-col :xs="24" :md="12">
          <a-card size="small" class="card fill">
            <template #title><span class="ttl">{{ tr('Sales of the year', 'Acumulado de ventas del año') }}</span></template>
            <div v-for="(r, i) in yearRows" :key="'y' + i" class="bar">
              <span class="cli">{{ r.client }}</span>
              <span class="amt">{{ money(r.total) }}</span>
              <span class="trk"><i :style="{ width: pct(r.total, yearRows) + '%', background: COLORS[i % COLORS.length] }" /></span>
            </div>
            <div class="bar total"><span class="cli">Total :</span><span class="amt">{{ money(sum(yearRows)) }}</span><span /></div>
          </a-card>
          </a-col>
          </a-row>

          <a-row :gutter="[14, 14]">
            <template v-for="y in annualYears" :key="y">
              <a-col v-for="(c, i) in d.annual[y]" :key="y + '-' + i" :xs="24" :md="12">
                <a-card size="small" class="card big">
                  <template #title><span class="ttl">{{ c.label }} {{ c.client }} <b class="yr">{{ y }}</b></span></template>
                  <!-- How it is made, readable at a glance (user, 2026-11-21: "más grande o notorio el texto total"):
                       the year's sales x the % = the rebate. -->
                  <div class="calc">
                    <div class="calc-part">
                      <span class="calc-lbl">{{ tr('Sales', 'Ventas') }} {{ y }}</span>
                      <span class="calc-val">{{ money(c.base) }}</span>
                    </div>
                    <span class="calc-op">×</span>
                    <div class="calc-part">
                      <span class="calc-lbl">{{ tr('Rate', 'Porcentaje') }}</span>
                      <span class="calc-val pct">{{ c.percent }}%</span>
                    </div>
                    <span class="calc-op">=</span>
                  </div>
                  <div class="figure" :style="{ color: COLORS[i % COLORS.length] }">{{ money(c.amount) }}</div>
                  <div class="figure-lbl">{{ c.label }}</div>
                </a-card>
              </a-col>
            </template>
          </a-row>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Distribución B2B -> Tablero (2026-11-20): Hordex's home as it was ("asi como está en el sistema anterior"): left, the
 * invoices pending payment grouped (overdue / this week / next 15 days / later) as number chips; right, the sales of the
 * month and of the year by chain (trade name) with bars, and per year the big cards of each chain's annual rebate
 * (b2b_settings.annual -- Hordex's own screen showed the year's sales there by a bug; here the rebate and its base).
 */
import { ref, computed, onMounted } from 'vue';
import { useFormat } from '../../composables/useFormat';
import { useTr } from '../../lib/useTr';
import http from '../../lib/http';
import { useBarTitle } from '../../composables/useBarTitle';

const tr = useTr();
const { date, money } = useFormat();
const d = ref({});
const loading = ref(false);
// Hordex's colours, in its order.
const COLORS = ['#9561e2', '#6574cd', '#3d76e5', '#339900', '#ffb74a', '#ff7f50', '#e3342f'];
const GROUPS = [
  { key: 'overdue', label: ['Overdue', 'Vencidas'] },
  { key: 'week', label: ['This week', 'Esta semana'] },
  { key: 'fortnight', label: ['Next 15 days', 'Próximos 15 días'] },
  { key: 'later', label: ['Later', 'Posteriores'] },
];
const groups = computed(() => (d.value.overdue || []).reduce((m, o) => { (m[o.group] = m[o.group] || []).push(o); return m; }, {}));
const yearRows = computed(() => d.value.years?.[d.value.year] || []);
const annualYears = computed(() => Object.keys(d.value.annual || {}).filter(y => (d.value.annual[y] || []).length));
const sum = rows => (rows || []).reduce((s, r) => s + (Number(r.total) || 0), 0);
const pct = (v, rows) => { const max = Math.max(...(rows || []).map(r => Number(r.total) || 0), 1); return Math.max(1, (Number(v) / max) * 100); };
async function load() { loading.value = true; try { d.value = await http.get('b2b/dashboard'); } finally { loading.value = false; } }
onMounted(load);

useBarTitle('Escritorio');
</script>

<style scoped>
.hx { padding-top: 0; }
.card { border-radius: 4px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); }
.card :deep(.ant-card-head) { min-height: 0; padding: 7px 14px; }
.ttl { font-size: 18px; font-weight: 400; }
.ttl .yr { font-weight: 700; margin-left: 4px; }
.mb { margin-bottom: 14px; }
.fill { height: 100%; }
/* A long pending list scrolls inside its card, never the page. */
.pend :deep(.ant-card-body) { max-height: calc(100vh - 170px); overflow-y: auto; }
.lapso { font-size: 15px; margin: 6px 0 4px; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.chip { font-size: 12px; padding: 2px 10px 2px 8px; border: 1px solid #e3342f; border-left-width: 6px; border-radius: 2px; color: #1f2937; background: #fff; }
.chip.week { border-color: #ffb74a; }
.chip.fortnight { border-color: #3d76e5; }
.chip.later { border-color: #94a3b8; }
.chip:hover { background: rgba(var(--app-primary-rgb, 0, 120, 212), 0.08); }
.bar { display: grid; grid-template-columns: minmax(80px, 140px) 130px 1fr; gap: 10px; align-items: center; font-size: 13px; padding: 3px 0; }
.bar .cli { letter-spacing: 0.04em; }
.bar .amt { text-align: right; font-variant-numeric: tabular-nums; }
.bar .trk { height: 14px; }
.bar .trk i { display: block; height: 100%; }
/* The Total line stands out: its own band, bigger figure (user, 2026-11-21). */
.bar.total { grid-template-columns: minmax(80px, 140px) auto 1fr; white-space: nowrap; margin-top: 6px; padding: 5px 10px; font-size: 20px; font-weight: 700; border-radius: 4px;
  background: rgba(var(--app-primary-rgb, 0, 120, 212), 0.1); border-left: 5px solid rgb(var(--app-primary-rgb, 0, 120, 212)); }
.bar.total .amt { font-size: 21px; }
.calc { display: flex; align-items: center; justify-content: center; gap: 14px; padding: 8px 10px; border-radius: 4px;
  background: rgba(var(--app-primary-rgb, 0, 120, 212), 0.07); }
.calc-part { display: flex; flex-direction: column; align-items: center; line-height: 1.15; }
.calc-lbl { font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.65; }
.calc-val { font-size: 22px; font-weight: 700; font-variant-numeric: tabular-nums; }
.calc-val.pct { color: rgb(var(--app-primary-rgb, 0, 120, 212)); }
.calc-op { font-size: 24px; font-weight: 300; opacity: 0.55; }
.big .figure { font-size: 40px; font-weight: 700; line-height: 1.1; text-align: center; font-variant-numeric: tabular-nums; letter-spacing: -0.01em; margin-top: 4px; }
.figure-lbl { text-align: center; font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.6; }
.muted { opacity: 0.6; }
</style>
