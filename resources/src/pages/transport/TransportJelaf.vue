<template>
  <div class="page jelaf">
    <a-alert v-if="info && !info.login_ready" type="warning" show-icon class="mb"
      :message="L('The Jelaf login is not set on this server: the nightly import and Import now cannot run here.', 'El usuario de Jelaf no está configurado en este servidor: la importación nocturna y «Importar ahora» no pueden correr aquí.')" />
    <!-- "Actualizar todo" (the user, 2026-11-27): every report with its card's dates, one after the other, never at once. -->
    <div class="all-bar">
      <a-button type="primary" size="large" :loading="!!allRun" :disabled="!info?.login_ready || !!busy" @click="runAll"><SyncOutlined /> {{ L('Update all', 'Actualizar todo') }}</a-button>
      <span v-if="allRun" class="all-st">{{ L(`Running ${allRun.n} of ${allRun.total}: ${allRun.name}…`, `Corriendo ${allRun.n} de ${allRun.total}: ${allRun.name}…`) }}</span>
      <span v-else class="muted">{{ L('Runs the reports below one after the other, each with its dates.', 'Corre los reportes de abajo uno detrás de otro, cada uno con sus fechas.') }}</span>
    </div>
    <div class="cards">
      <a-card v-for="r in info?.reports || []" :key="r.key" size="small" class="rep">
        <div class="rep-h">
          <b>{{ r.name }}</b>
          <span class="night"><ClockCircleOutlined /> {{ L('every night', 'cada noche') }} {{ info.night_at }}</span>
        </div>
        <div class="what">{{ r.what }}</div>
        <div class="last">
          <template v-if="r.last">
            <span :class="['st', 'st-' + r.last.status]">{{ statusLabel(r.last.status) }}</span>
            {{ dateTime(r.last.started_at) }} · {{ r.last.trigger === 'manual' ? L('by hand', 'a mano') : L('night', 'noche') }}
            · {{ date(r.last.date_from) }} – {{ date(r.last.date_to) }} · <b>{{ num(r.last.rows) }}</b> {{ L('rows', 'filas') }}
            <div v-if="r.last.status === 'failed'" class="err">{{ r.last.message }}</div>
          </template>
          <span v-else class="muted">{{ L('Not run yet.', 'Todavía no se ha corrido.') }}</span>
        </div>
        <div class="run">
          <a-date-picker v-model:value="form[r.key].from" value-format="YYYY-MM-DD" :placeholder="L('From', 'Desde')" style="width: 122px" />
          <a-date-picker v-model:value="form[r.key].to" value-format="YYYY-MM-DD" :placeholder="L('To', 'Hasta')" style="width: 122px" />
          <a-button type="primary" :loading="busy === r.key" :disabled="!info.login_ready || (!!busy && busy !== r.key)" @click="runNow(r)"><CloudDownloadOutlined /> {{ L('Import now', 'Importar ahora') }}</a-button>
          <a-tooltip :title="L(`Up to ${r.max_days} days at a time. It downloads from Jelaf and imports, like the night.`, `Hasta ${r.max_days} días por vez. Baja de Jelaf e importa, igual que en la noche.`)"><InfoCircleOutlined class="i" /></a-tooltip>
        </div>
      </a-card>
    </div>

    <a-card size="small" class="mt" :title="L('History', 'Historial')">
      <DataTable :crud="crud" :columns="columns" compact-toolbar table-key="jelaf-runs">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'started_at'">{{ dateTime(record.started_at) }}</template>
          <template v-else-if="column.key === 'report'">{{ record.report_name }}</template>
          <template v-else-if="column.key === 'trigger'">{{ record.trigger === 'manual' ? L('By hand', 'A mano') + (record.user_name ? ' · ' + record.user_name : '') : L('Night', 'Noche') }}</template>
          <template v-else-if="column.key === 'date_from'"><span class="nowrap">{{ date(record.date_from) }} – {{ date(record.date_to) }}</span></template>
          <template v-else-if="column.key === 'status'"><span :class="['st', 'st-' + record.status]">{{ statusLabel(record.status) }}</span></template>
          <template v-else-if="column.key === 'rows'"><span class="n">{{ num(record.rows) }}</span></template>
          <template v-else-if="column.key === 'detail'"><span class="det">{{ detail(record) }}</span></template>
        </template>
      </DataTable>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Integración -> Jelaf automático (2026-11-26, Turismo Cautivo): the Jelaf / AuraSoft reports Murano imports by itself every
 * night at 22:00 on the server, each one's last run, "Importar ahora" for a date range (the same import), and the history.
 * Server: TransportJelafController / App\Services\JelafSync.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { ClockCircleOutlined, CloudDownloadOutlined, InfoCircleOutlined, SyncOutlined } from '@ant-design/icons-vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useBarTitle } from '../../composables/useBarTitle';
import { useUiStore } from '../../stores/ui';
import http from '../../lib/http';

const ui = useUiStore();
const L = (en, es) => (String(ui.locale || 'en').startsWith('es') ? es : en);
const { date, dateTime } = useFormat();
const num = v => Number(v || 0).toLocaleString('en-US');
useBarTitle(L('Jelaf automatic', 'Jelaf automático'));

const info = ref(null);
const busy = ref('');
const yesterday = dayjs().subtract(1, 'day').format('YYYY-MM-DD');
const today = dayjs().format('YYYY-MM-DD');
const form = reactive({ production: { from: dayjs().subtract(2, 'day').format('YYYY-MM-DD'), to: today }, boarding: { from: yesterday, to: today },
  ahead: { from: today, to: dayjs().add(14, 'day').format('YYYY-MM-DD') },
  passengers: { from: dayjs().subtract(2, 'day').format('YYYY-MM-DD'), to: dayjs().add(14, 'day').format('YYYY-MM-DD') } });

const statusLabel = s => ({ ok: L('OK', 'Correcto'), nodata: L('No data', 'Sin datos'), failed: L('Failed', 'Falló'), running: L('Running', 'Corriendo') }[s] || s);
const detail = r => {
  const s = r.summary || {};
  if (r.status === 'failed') return r.message || '';
  if (r.report === 'production') return L(`new ${s.new ?? 0}, changed ${s.changed ?? 0}, same ${s.same ?? 0}`, `nuevas ${s.new ?? 0}, cambiadas ${s.changed ?? 0}, iguales ${s.same ?? 0}`);
  if (r.report === 'passengers') return L(`${num(s.rows)} tickets with their document (new ${num(s.new)})`, `${num(s.rows)} pasajes con su documento (nuevos ${num(s.new)})`);
  if (r.report === 'ahead') return L(`${s.days ?? 0} days, ${num(s.tickets)} tickets sold so far, ${s.departures ?? 0} departures`, `${s.days ?? 0} días, ${num(s.tickets)} pasajes vendidos a hoy, ${s.departures ?? 0} salidas`);
  if (r.report === 'boarding') return L(`${s.days ?? 0} days, ${s.departures ?? 0} departures, ${s.no_show ?? 0} not boarded, S/ ${num(s.amount)}`,
    `${s.days ?? 0} días, ${s.departures ?? 0} salidas, ${s.no_show ?? 0} no embarcados, S/ ${num(s.amount)}`);
  return r.message || '';
};

const crud = useCrudTable('transport/jelaf/runs', { rowsKey: 'rows', sortField: 'started_at', sortType: 'desc', limit: 20 });
const columns = computed(() => [
  { title: L('When', 'Cuándo'), key: 'started_at', dataIndex: 'started_at', sorter: true, width: 140 },
  { title: L('Report', 'Reporte'), key: 'report', dataIndex: 'report', sorter: true, width: 170 },
  { title: L('How', 'Cómo'), key: 'trigger', dataIndex: 'trigger', width: 150 },
  { title: L('Dates', 'Fechas'), key: 'date_from', dataIndex: 'date_from', sorter: true, width: 180 },
  { title: L('Status', 'Estado'), key: 'status', dataIndex: 'status', sorter: true, width: 100 },
  { title: L('Rows', 'Filas'), key: 'rows', dataIndex: 'rows', sorter: true, width: 80, align: 'right' },
  { title: L('What it brought', 'Qué trajo'), key: 'detail', dataIndex: 'summary' },
]);

async function load() { info.value = await http.get('transport/jelaf'); }
async function runNow(r) {
  const f = form[r.key];
  if (!f.from || !f.to) { message.warning(L('Pick the dates.', 'Elija las fechas.')); return; }
  busy.value = r.key;
  try {
    const res = await http.post('transport/jelaf/run', { report: r.key, from: f.from, to: f.to });
    const run = res.run || {};
    run.status === 'failed' ? message.error(run.message || L('Failed.', 'Falló.')) : message.success(L(`Done: ${num(run.rows)} rows.`, `Listo: ${num(run.rows)} filas.`));
    await load();
    crud.reload();
  } catch (e) {
    message.error(e?.data?.message || L('Could not run.', 'No se pudo correr.'));
  } finally { busy.value = ''; }
}
// Every report, one after the other (each waits for the one before; the server also refuses two Jelaf imports at once).
const allRun = ref(null);
async function runAll() {
  const reps = info.value?.reports || [];
  const results = [];
  for (let i = 0; i < reps.length; i++) {
    const r = reps[i];
    const f = form[r.key];
    if (!f?.from || !f?.to) continue;
    allRun.value = { n: i + 1, total: reps.length, name: r.name };
    busy.value = r.key;
    try {
      const run = (await http.post('transport/jelaf/run', { report: r.key, from: f.from, to: f.to })).run || {};
      results.push([r.name, run.status, run.rows]);
    } catch (e) {
      results.push([r.name, 'failed', 0]);
      message.error(`${r.name}: ${e?.data?.message || L('could not run.', 'no se pudo correr.')}`);
    }
    await load();
    crud.reload();
  }
  busy.value = '';
  allRun.value = null;
  const bad = results.filter(x => x[1] === 'failed').length;
  (bad ? message.warning : message.success)(L(`Update all finished: ${results.length - bad} of ${results.length} reports OK.`,
    `Actualizar todo terminó: ${results.length - bad} de ${results.length} reportes correctos.`), 6);
}
onMounted(() => { load(); crud.fetchRows(); });
</script>

<style scoped>
.mb { margin-bottom: 10px; }
.mt { margin-top: 12px; }
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 10px; }
.rep-h { display: flex; justify-content: space-between; align-items: baseline; }
.night { font-size: 12px; color: var(--ant-color-text-secondary, #888); }
.what { font-size: 12px; color: var(--ant-color-text-secondary, #666); margin: 4px 0 8px; }
.last { font-size: 12.5px; margin-bottom: 8px; }
.muted { color: var(--ant-color-text-tertiary, #aaa); }
.err { color: #cf1322; font-size: 12px; margin-top: 2px; }
.run { display: flex; gap: 6px; align-items: center; flex-wrap: nowrap; margin-top: auto; }
.i { color: var(--ant-color-text-secondary, #888); cursor: help; }
.st { display: inline-block; padding: 0 6px; border-radius: 3px; font-size: 11.5px; font-weight: 600; margin-right: 4px; }
.st-ok { background: #f6ffed; color: #389e0d; }
.st-nodata { background: #fffbe6; color: #d48806; }
.st-failed { background: #fff1f0; color: #cf1322; }
.st-running { background: #e6f4ff; color: #1677ff; }
.n { font-variant-numeric: tabular-nums; }
.nowrap { white-space: nowrap; }
.det { font-size: 12px; }
.all-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
.all-st { font-weight: 600; color: #1677ff; }
.rep { display: flex; flex-direction: column; }
.rep :deep(.ant-card-body) { display: flex; flex-direction: column; flex: 1; }
</style>
