<template>
  <!-- "Reporte del día de ventas" (2026-11-23): Mayte's daily report as a board -- one day, Lima's and Chiclayo's departures
       side by side, as on her sheet (App\Services\TransportProduction::daySales). -->
  <div>
    <a-card v-if="data.enabled" :bordered="false" :body-style="{ padding: '8px' }">
      <a-spin :spinning="loading">
        <!-- The day is picked only in the filter above ("Día" with its ‹ ›; user, 2026-11-27: this second selector was too much). -->
        <div class="ds-day">
          <!-- Her "REPORTE DEL DIA VENTAS" (2026-11-24): Excel = one sheet per day of the filter's period; PDF = this day. -->
          <span class="ds-tools">
            <!-- Mayte's day in one place (2026-11-24): upload AuraSoft's report, then complete what it lacks, then her files. -->
            <a-upload v-if="canUpload" :show-upload-list="false" accept=".xlsx,.xls" :before-upload="uploadAura">
              <a-button size="small" type="primary" :loading="uploading"><UploadOutlined /> {{ tr('Upload AuraSoft', 'Subir AuraSoft') }}</a-button>
            </a-upload>
            <a-tooltip v-if="canUpload" :title="tr('Uploaded files (AuraSoft)', 'Archivos subidos (AuraSoft)')">
              <a-button size="small" @click="goFiles"><FolderOpenOutlined /> {{ tr('Files', 'Archivos') }}</a-button>
            </a-tooltip>
            <a-button v-if="canFuel" size="small" @click="openComplete(null)"><EditOutlined /> {{ tr('Complete', 'Completar') }}</a-button>
            <a-tooltip :title="tr('One sheet per day of the period in the filter above', 'Una hoja por día del período del filtro de arriba')">
              <a-button size="small" :loading="exporting === 'xlsx'" @click="exportFile('xlsx')"><FileExcelOutlined /> Excel</a-button>
            </a-tooltip>
            <a-button size="small" :loading="exporting === 'pdf'" @click="exportFile('pdf')"><FilePdfOutlined /> PDF</a-button>
          </span>
        </div>
        <!-- The day's figures split by direction, side by side with a vertical line between them (the user, 2026-11-27). -->
        <div class="ds-tiles">
          <div v-for="h in halves" :key="h.key" class="ds-tside">
            <div class="ds-tside-h">{{ h.key === 'lima' ? 'LIMA → CHICLAYO' : 'CHICLAYO → LIMA' }}</div>
            <TransportStrip :items="halfTiles(h)" :toggle="false" />
          </div>
        </div>
        <div class="ds-grid">
          <div v-for="h in halves" :key="h.key" class="ds-half">
            <div class="ds-head" :class="'ds-' + h.key">{{ h.title }} <span>{{ h.rows.length }} {{ tr('departures', 'salidas') }}</span></div>
            <a-empty v-if="!h.rows.length" :description="tr('No departures.', 'Sin salidas.')" />
            <table v-else class="ds-table">
              <colgroup><col v-for="(w, k) in colWidths(h)" :key="k" :style="{ width: w + '%' }" /></colgroup>
              <thead>
                <tr>
                  <th>Bus</th><th>{{ tr('Boarding and time', 'Embarque y horario') }}</th><th class="r">Pax</th><th class="r" :title="tr('Free seats', 'Asientos libres')">{{ tr('Free', 'Lib.') }}</th>
                  <th class="r">{{ tr('Amount', 'Monto') }}</th><th class="r">{{ tr('Parcels', 'Encom.') }}</th><th class="r">{{ tr('Excess', 'Exc.') }}</th>
                  <th v-if="h.costs" class="r">{{ tr('Toll', 'Peaje') }}</th><th v-if="h.costs" class="r"><a-tooltip :title="FUEL_EST_HELP"><span class="fuel-h">{{ tr('Fuel', 'Comb.') }}</span></a-tooltip></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(r, i) in h.rows" :key="r.id" :class="{ tint: i % 2 === 0 }">
                  <td class="nw" :title="r.category || ''"><b>{{ busName(r.bus) }}</b> <span v-if="r.category" class="svc">{{ String(r.category).slice(0, 3).toUpperCase() }}</span></td>
                  <!-- One line per bus (the user, 2026-11-27: the figures are the bus's total, so its boarding points go side by side). -->
                  <td class="nw pts" :class="{ est: r.points_timetable }" :title="r.points_timetable ? tr('From the timetable (the last same departure)', 'Del horario (la última salida igual)') : ''">
                    <span v-for="(p, k) in r.points" :key="k"><span v-if="k" class="mut"> › </span><a-tooltip :title="pointName(p.name)"><span class="ini">{{ pointShort(p.name) }}</span></a-tooltip> <span class="mut">{{ hm(p.at) }}</span></span></td>
                  <td class="r">{{ r.pax }}<span v-if="r.seats" class="mut">/{{ r.seats }}</span></td>
                  <td class="r">{{ r.free ?? '—' }}</td>
                  <td class="r"><b :class="{ low: r.amount < lowAmount }">{{ plain(r.amount) }}</b></td>
                  <td class="r">{{ r.parcels ? plain(r.parcels) : '—' }}</td>
                  <td class="r">{{ r.excess ? plain(r.excess) : '—' }}</td>
                  <td v-if="h.costs" class="r" :class="{ est: r.toll_est }" :title="r.toll_est ? tr('The route\'s toll', 'El peaje de la ruta') : ''">{{ r.toll != null ? plain(r.toll) : '—' }}</td>
                  <td v-if="h.costs" class="r" :class="{ 'fuel-est': r.fuel_est }" :title="r.fuel_est ? FUEL_EST_HELP : ''">{{ r.fuel == null ? '—' : (r.fuel_est ? Math.round(r.fuel).toLocaleString('es-PE') : plain(r.fuel)) }}</td>
                </tr>
              </tbody>
            </table>
            <div v-if="h.channels.length" class="ds-chips">
              <span class="mut">{{ tr('Seats by seller', 'Pasajes por vendedor') }}:</span>
              <span v-for="c in h.channels" :key="c[0]" class="chip">{{ c[0] }} <b>{{ c[1] }}</b></span>
            </div>
            <!-- The totals at the bottom of both halves, on the same line (user, 2026-11-23). -->
            <div class="ds-fill" />
            <table v-if="h.rows.length" class="ds-table ds-foot">
              <colgroup><col v-for="(w, k) in colWidths(h)" :key="k" :style="{ width: w + '%' }" /></colgroup>
              <tfoot>
                <tr>
                  <td colspan="2"><b>{{ tr('Total', 'Total') }}</b></td>
                  <td class="r"><b>{{ sum(h.rows, 'pax') }}</b></td><td class="r"><b>{{ sum(h.rows, 'free') }}</b></td>
                  <td class="r"><b>{{ plain(sum(h.rows, 'amount')) }}</b></td><td class="r"><b>{{ plain(sum(h.rows, 'parcels')) }}</b></td>
                  <td class="r"><b>{{ plain(sum(h.rows, 'excess')) }}</b></td>
                  <td v-if="h.costs" class="r"><b>{{ plain(sum(h.rows, 'toll')) }}</b></td><td v-if="h.costs" class="r"><b>{{ plain(sum(h.rows, 'fuel')) }}</b></td>
                </tr>
              </tfoot>
            </table>

          </div>
        </div>
      </a-spin>
    </a-card>
    <a-empty v-else-if="!loading" :description="tr('Pick a company with Transport in the top bar to see this.', 'Elige una compañía con Transporte en la barra superior para ver esto.')" />

    <!-- "Completar el reporte": after an upload (or from the button), the period's round trips and their fuel. -->
    <a-modal v-model:open="cmp.open" width="96vw" :style="{ top: '16px', maxWidth: '1180px' }" :title="tr('Complete the report', 'Completar el reporte')"
      :ok-text="tr('Save', 'Guardar')" :cancel-text="tr('Close', 'Cerrar')" :confirm-loading="cmp.saving" :ok-button-props="{ disabled: !changedCount }" @ok="saveComplete">
      <div v-if="cmp.imported" class="cm-imp">
        <CheckCircleFilled class="cm-ok" />
        <b>{{ cmp.imported.original_name }}</b> ·
        {{ tr('period', 'período') }} {{ fmtDate(cmp.imported.period_from) }} – {{ fmtDate(cmp.imported.period_to) }} ·
        {{ cmp.imported.rows_total }} {{ tr('departures', 'salidas') }}:
        <span class="c-new">{{ cmp.imported.rows_new }} {{ tr('new', 'nuevas') }}</span>,
        <span class="c-upd">{{ cmp.imported.rows_updated }} {{ tr('changed', 'actualizadas') }}</span>,
        {{ cmp.imported.rows_same }} {{ tr('already there', 'ya estaban') }}
        <a-button size="small" type="link" class="cm-link" @click="goFiles"><FolderOpenOutlined /> {{ tr('Uploaded files', 'Archivos subidos') }}</a-button>
        <div v-if="!cmp.imported.rows_new && !cmp.imported.rows_updated" class="cm-same">
          {{ tr('This file brought nothing new: every departure was already there with the same figures. It is kept in the uploaded files anyway.', 'Este archivo no trajo nada nuevo: todas sus salidas ya estaban con las mismas cifras. Igual queda guardado en Archivos subidos.') }}
        </div>
        <!-- Departures that came with other figures: before -> now (the new ones replace the earlier, both kept). -->
        <div v-if="cmp.changes.length" class="cm-changes">
          <b>{{ tr('Changed departures', 'Salidas con cambios') }} ({{ cmp.changes.length }})</b>
          <table class="ds-table">
            <thead><tr><th>Bus</th><th>{{ tr('Date', 'Fecha') }}</th><th>{{ tr('Route', 'Ruta') }}</th><th class="r">Pax</th><th class="r">{{ tr('Tickets', 'Pasajes') }}</th><th class="r">{{ tr('Production', 'Producción') }}</th></tr></thead>
            <tbody>
              <tr v-for="c in cmp.changes" :key="c.id">
                <td><b>{{ c.bus ? busName(c.bus) : c.plate }}</b></td>
                <td>{{ fmtDate(c.trip_date) }} {{ String(c.departure_time).slice(0, 5) }}</td><td>{{ c.route }}</td>
                <td class="r"><span class="was">{{ c.before?.seats }}</span> {{ c.seats }}</td>
                <td class="r"><span class="was">{{ plain(c.before?.ticket_income) }}</span> {{ plain(c.ticket_income) }}</td>
                <td class="r"><span class="was">{{ plain(c.before?.production) }}</span> <b>{{ plain(c.production) }}</b></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div v-else class="cm-imp cm-plain">
        <a-button size="small" type="link" class="cm-link" @click="goFiles"><FolderOpenOutlined /> {{ tr('Uploaded files (AuraSoft)', 'Archivos subidos (AuraSoft)') }}</a-button>
      </div>
      <div class="cm-bar">
        <span>{{ tr('Period', 'Período') }}</span>
        <PresetRangeFilter v-model:value="cmp.range" compact @change="loadPending" />
        <a-checkbox v-model:checked="cmp.onlyMissing">{{ tr('Only missing fuel', 'Solo sin combustible') }} ({{ cmp.data.missing || 0 }})</a-checkbox>
        <span class="cm-help">{{ tr('Type the round trip\'s fuel (Lima departure + return); it is shared half and half. Then Save.', 'Escriba el combustible de la vuelta (salida de Lima + retorno); se reparte mitad y mitad. Luego Guardar.') }}</span>
      </div>
      <a-spin :spinning="cmp.loading">
        <a-empty v-if="!cmpRows.length && !cmp.loading" :description="cmp.onlyMissing ? tr('Nothing missing in this period.', 'Nada pendiente en este período.') : tr('No departures in this period.', 'Sin salidas en este período.')" />
        <div v-else class="cm-scroll">
          <table class="ds-table cm-table">
            <thead>
              <tr>
                <th>Bus</th><th>{{ tr('Departure from Lima', 'Salida de Lima') }}</th><th class="r">Pax</th>
                <th>{{ tr('Return from Chiclayo', 'Retorno de Chiclayo') }}</th><th class="r">Pax</th>
                <th class="r">{{ tr('Round trip income', 'Ingreso vuelta') }}</th><th class="r">{{ tr('Toll', 'Peaje') }}</th><th class="r">{{ tr('Fuel', 'Combustible') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, i) in cmpRows" :key="r.trip_ids.join('-')" :class="{ tint: i % 2 === 0 }">
                <td><b>{{ r.bus }}</b> <span class="mut">{{ r.plate }}</span></td>
                <td>{{ r.out ? dtm(r.out.at) : '—' }}</td><td class="r">{{ r.out?.pax ?? '' }}</td>
                <td>{{ r.back ? dtm(r.back.at) : '—' }}</td><td class="r">{{ r.back?.pax ?? '' }}</td>
                <td class="r">{{ plain(r.income) }}</td>
                <td class="r" :class="{ est: r.toll_est }">{{ plain(r.toll) }}</td>
                <td class="r">
                  <a-input-number v-model:value="r.edit" size="small" :min="0" :step="10" :precision="2" :controls="false" placeholder="0.00"
                    :class="['cm-fuel', { 'cm-miss': r.fuel_missing && r.edit == null }]" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </a-spin>
    </a-modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { UploadOutlined, EditOutlined, CheckCircleFilled, FolderOpenOutlined } from '@ant-design/icons-vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import PresetRangeFilter from './PresetRangeFilter.vue';
import dayjs from 'dayjs';
import 'dayjs/locale/es';
import { LeftOutlined, RightOutlined, InfoCircleOutlined, FileExcelOutlined, FilePdfOutlined } from '@ant-design/icons-vue';
import { message } from 'ant-design-vue';
import http from '../lib/http';
import { getActingCompanyId } from '../lib/actingCompany';
import { useTransportCharts } from '../lib/transportCharts';
import { useFormat } from '../composables/useFormat';
import TransportStrip from './TransportStrip.vue';

const props = defineProps({ range: { type: Array, default: null } });
const { money, tr, ui } = useTransportCharts();
const { date: fmtDate } = useFormat();
const lowAmount = 2000; // as Reporte de salidas (TransportDepartures::LOW_AMOUNT)

const loading = ref(false);
const day = ref(null);
const data = ref({ enabled: false, lima: [], chiclayo: [], channels: {} });
async function load() {
  loading.value = true;
  try {
    const today = dayjs().format('YYYY-MM-DD');
    const end = day.value || (props.range?.[1] && props.range[1] < today ? props.range[1] : today);
    data.value = await http.get('transport/day-sales', { date: end });
  } catch (e) { data.value = { enabled: false, lima: [], chiclayo: [], channels: {} }; } finally { loading.value = false; }
}
function go(d) { day.value = d; load(); }

// ---- upload AuraSoft + complete the report (2026-11-24, for Mayte)
const auth = useAuthStore();
const canUpload = computed(() => auth.can('transport_aurasoft_import'));
const canFuel = computed(() => auth.can('transport_departures_manage'));
const uploading = ref(false);
async function uploadAura(file) {
  uploading.value = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    const r = await http.postForm('transport/aurasoft', fd);
    // AuraSoft's Liquidación General (2026-11-24): recorded for "Ventas por punto de venta"; nothing to complete here.
    if (r.kind === 'liquidation') {
      message.success(tr(`General liquidation: ${r.rows_total} rows (${r.rows_new} new, ${r.rows_updated} changed, ${r.rows_same} already there). See "Sales by point of sale".`,
        `Liquidación general: ${r.rows_total} filas (${r.rows_new} nuevas, ${r.rows_updated} actualizadas, ${r.rows_same} ya estaban). Véala en «Ventas por punto de venta».`), 8);
      return false;
    }
    // The Liquidación Detallada and the parcels kardex (2026-11-24): their own boards.
    if (r.kind === 'detail') {
      message.success(tr(`Detailed liquidation: ${r.rows_total} documents (${r.rows_new} new, ${r.rows_updated} changed, ${r.rows_same} already there). See "Cash and customers" and "AuraSoft parcels".`,
        `Liquidación detallada: ${r.rows_total} documentos (${r.rows_new} nuevos, ${r.rows_updated} actualizados, ${r.rows_same} ya estaban). Véala en «Caja y clientes» y «Encomiendas AuraSoft».`), 8);
      return false;
    }
    if (r.kind === 'parcels') {
      message.success(tr(`Parcels to deliver: ${r.rows_total} parcels. See "AuraSoft parcels".`, `Encomiendas por entregar: ${r.rows_total} encomiendas. Véalas en «Encomiendas AuraSoft».`), 8);
      return false;
    }
    // The board jumps to the file's last day; the modal opens on the file's period.
    if (r.period_to) day.value = r.period_to > dayjs().format('YYYY-MM-DD') ? null : r.period_to;
    load();
    openComplete(r);
  } catch (e) {
    message.error(e?.data?.message || tr('The file could not be imported.', 'No se pudo importar el archivo.'));
  } finally { uploading.value = false; }
  return false;
}
const cmp = reactive({ open: false, imported: null, changes: [], range: [], onlyMissing: true, loading: false, saving: false, data: { rows: [], missing: 0 } });
const router = useRouter();
// The uploaded files' table (Integración -> Producción AuraSoft).
function goFiles() { cmp.open = false; router.push('/transport/aurasoft'); }
async function loadChanges(imp) {
  cmp.changes = [];
  if (!imp?.rows_updated) return;
  try { cmp.changes = (await http.get(`transport/aurasoft/${imp.id}/rows`, { action: 'updated', limit: 200 })).rows || []; } catch (e) { /* only a help */ }
}
function openComplete(imported) {
  cmp.imported = imported;
  loadChanges(imported);
  const shown = data.value.date || dayjs().format('YYYY-MM-DD');
  cmp.range = imported?.period_from ? [imported.period_from, imported.period_to] : (props.range?.[0] ? [props.range[0], props.range[1]] : [shown, shown]);
  cmp.onlyMissing = true;
  cmp.open = true;
  loadPending();
}
async function loadPending() {
  cmp.loading = true;
  try {
    const [from, to] = cmp.range?.length ? cmp.range : [];
    const d = await http.get('transport/production/pending', { from, to });
    d.rows.forEach(r => { r.edit = r.fuel; });
    cmp.data = d;
  } catch (e) { cmp.data = { rows: [], missing: 0 }; message.error(e?.data?.message || tr('It could not be loaded.', 'No se pudo cargar.')); } finally { cmp.loading = false; }
}
const cmpRows = computed(() => (cmp.data.rows || []).filter(r => !cmp.onlyMissing || r.fuel_missing));
const changed = computed(() => (cmp.data.rows || []).filter(r => (r.edit ?? null) !== (r.fuel ?? null)));
const changedCount = computed(() => changed.value.length);
async function saveComplete() {
  cmp.saving = true;
  try {
    await http.post('transport/production/fuel', { items: changed.value.map(r => ({ trip_ids: r.trip_ids, amount: r.edit ?? null })) });
    message.success(tr(`${changedCount.value} round trips saved`, `${changedCount.value} vueltas guardadas`));
    await loadPending();
    load();
  } catch (e) { message.error(e?.data?.message || tr('It could not be saved.', 'No se pudo guardar.')); } finally { cmp.saving = false; }
}
const dtm = d => (d ? `${fmtDate(d)} ${dayjs(d).format('hh:mm A')}` : '');

const exporting = ref('');
async function exportFile(format) {
  exporting.value = format;
  try {
    const today = dayjs().format('YYYY-MM-DD');
    const shown = data.value.date || today;
    // Excel: the filter's period (the day shown when there is none); PDF: the day shown.
    const from = format === 'xlsx' && props.range?.[0] ? props.range[0] : shown;
    const to = format === 'xlsx' && props.range?.[1] ? (props.range[1] < today ? props.range[1] : today) : shown;
    const cid = getActingCompanyId();
    const q = new URLSearchParams({ kind: 'daysales', format, from, to, date: shown, ...(cid ? { company_id: cid } : {}) });
    const name = format === 'pdf' ? `Reporte del dia ventas ${dayjs(shown).format('DD-MM-YYYY')}.pdf`
      : `REPORTE DEL DIA VENTAS ${dayjs(from).format('DD-MM-YYYY')}_al_${dayjs(to).format('DD-MM-YYYY')}.xlsx`;
    await http.download(`transport/production/export?${q}`, name);
  } catch (e) { message.error(tr('The file could not be made.', 'No se pudo generar el archivo.')); } finally { exporting.value = ''; }
}
onMounted(load);
watch(() => props.range?.[1], () => { day.value = null; load(); });
// Each boarding code with its full name on hover (the user, 2026-11-27); codes as in the client's report.
const POINT_NAMES = { CV: 'Terminal Cavassa (La Victoria, Lima)', PN: 'Terminal Plaza Lima Norte (Independencia, Lima)', TOLIVOS: 'Terminal Los Olivos (Lima)',
  OLIVOS: 'Terminal Los Olivos (Lima)', CIX: 'Terminal Chiclayo', TCIX: 'Terminal Chiclayo', CHEPEN: 'Agencia Chepén', TRUX: 'Agencia Trujillo',
  TRUJILLO: 'Agencia Trujillo', LAMBAYEQUE: 'Agencia Lambayeque', LAMB: 'Agencia Lambayeque', SERVCIXTRUX: 'Servicio Chiclayo – Trujillo' };
// Long names shortened on the row (the full one in the helper).
const SHORT_POINTS = { LAMBAYEQUE: 'LAMB', CHEPEN: 'CHEP', TOLIVOS: 'T.OLI', OLIVOS: 'T.OLI', TRUJILLO: 'TRUX', SERVCIXTRUX: 'CIX-TRUX' };
const pointShort = n => SHORT_POINTS[String(n || '').toUpperCase().replace(/[^A-Z]/g, '')] || n || '—';
const pointName = n => POINT_NAMES[String(n || '').toUpperCase().replace(/[^A-Z]/g, '')] || n || '';const plain = v => money(Number(v || 0)).replace(/^[^\d-]+/, '');
const busName = n => String(n).replace(/^Bus\s+/i, '');
const hm = d => (d ? dayjs(d).format('h:mm') + (dayjs(d).hour() < 12 ? 'a' : 'p') : '');
const sum = (rows, k) => rows.reduce((s, r) => s + Number(r[k] || 0), 0);
const longDate = d => `${dayjs(d).locale(String(ui.locale || 'en').startsWith('es') ? 'es' : 'en').format('dddd')} ${fmtDate(d)}`;
const sourceLabel = s => ({ aurasoft: tr('Figures from AuraSoft', 'Cifras de AuraSoft'), report: tr("From the client's daily report", 'Del reporte del día del cliente') }[s] || s);

// The same column widths for the rows and the totals table below them.
const colWidths = h => (h.costs ? [10, 28, 8, 5, 11, 10, 9, 9, 10] : [11, 34, 10, 6, 14, 12, 13]);
const chans = k => Object.entries(data.value.channels?.[k] || {}).filter(([, n]) => n > 0).sort((a, b) => b[1] - a[1]);
const halves = computed(() => [
  { key: 'lima', title: tr('Departures from Lima', 'Salidas de Lima'), rows: data.value.lima || [], channels: chans('lima') },
  { key: 'chiclayo', title: tr('Departures from Chiclayo', 'Salidas de Chiclayo'), rows: data.value.chiclayo || [], channels: chans('chiclayo') },
].map(h => ({ ...h, costs: h.rows.some(r => r.toll != null || r.fuel != null) })));

// Fuel: typed by hand (Mayte's Excel / Completar); a red figure is the system's estimate until she types it (2026-11-27).
const FUEL_EST_HELP = tr('Fuel is typed by hand (Mayte, per round trip). A red figure is an estimate until it is typed: the bus\'s cost per km (its real fuel of the last year) × the route\'s km.',
  'El combustible se ingresa a mano (Mayte, por vuelta). Una cifra en rojo es un estimado hasta que se ingrese: el costo por km del bus (su combustible real del último año) × los km de la ruta.');// One set of cards per direction (2026-11-27).
const soles = v => `S/ ${Math.round(Number(v || 0)).toLocaleString('es-PE')}`;
const halfTiles = h => {
  const r = h.rows;
  const seats = sum(r, 'seats');
  return [
    { label: tr('Departures', 'Salidas'), value: r.length },
    { label: tr('Passengers', 'Pasajeros'), value: sum(r, 'pax') },
    { label: tr('Occupancy', 'Ocupación'), value: seats ? `${Math.round((sum(r, 'pax') / seats) * 100)} %` : '—' },
    { label: tr('Tickets', 'Pasajes'), value: soles(sum(r, 'amount')) },
    { label: tr('Parcels + excess', 'Encom. + exceso'), value: soles(sum(r, 'parcels') + sum(r, 'excess')) },
    { label: tr("Day's income", 'Ingreso del día'), value: soles(sum(r, 'amount') + sum(r, 'parcels') + sum(r, 'excess')) },
    { label: tr('Tolls', 'Peaje'), value: soles(sum(r, 'toll')) },
    { label: r.some(x => x.fuel_est) ? tr('Fuel ≈', 'Combustible ≈') : tr('Fuel', 'Combustible'), value: soles(sum(r, 'fuel')),
      help: r.some(x => x.fuel_est) ? FUEL_EST_HELP : null },
  ];
};
const tiles = computed(() => {
  const all = [...(data.value.lima || []), ...(data.value.chiclayo || [])];
  const income = sum(all, 'amount') + sum(all, 'parcels') + sum(all, 'excess');
  const seats = sum(all, 'seats');
  return [
    { label: tr('Departures', 'Salidas'), value: all.length },
    { label: tr('Passengers', 'Pasajeros'), value: sum(all, 'pax') },
    { label: tr('Occupancy', 'Ocupación'), value: seats ? `${Math.round((sum(all, 'pax') / seats) * 100)} %` : '—' },
    { label: tr('Lima', 'Lima'), value: money(sum(data.value.lima || [], 'amount')) },
    { label: tr('Chiclayo', 'Chiclayo'), value: money(sum(data.value.chiclayo || [], 'amount')) },
    { label: tr('Parcels + excess', 'Encom. + exceso'), value: money(sum(all, 'parcels') + sum(all, 'excess')) },
    { label: tr("Day's income", 'Ingreso del día'), value: money(income) },
    { label: tr('Tolls', 'Peaje'), value: money(sum(all, 'toll')) },
    { label: tr('Fuel', 'Combustible'), value: money(sum(all, 'fuel')) },
  ];
});
</script>

<style scoped>
.ds-day { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 14px; text-transform: capitalize; }
.ds-i { opacity: 0.6; cursor: help; }
.ds-tools { margin-left: auto; display: inline-flex; gap: 6px; text-transform: none; }
.ds-table td.est { opacity: 0.5; font-style: italic; }
.cm-imp { font-size: 12.5px; padding: 6px 8px; margin-bottom: 8px; border-radius: 6px; background: rgba(21, 128, 61, 0.08); }
.cm-ok { color: #15803d; margin-right: 4px; }
.cm-plain { background: transparent; padding: 0; }
.svc { font-size: 9.5px; opacity: 0.6; line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cm-link { padding: 0 4px; height: auto; }
.cm-same { margin-top: 4px; color: #6b7280; }
.cm-changes { margin-top: 6px; max-height: 180px; overflow: auto; background: rgba(180, 83, 9, 0.06); padding: 4px 6px; border-radius: 4px; }
.was { opacity: 0.5; text-decoration: line-through; margin-right: 4px; font-size: 11px; }
.c-new { color: #15803d; }
.c-upd { color: #b45309; }
.cm-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; margin-bottom: 8px; font-size: 12.5px; }
.cm-help { opacity: 0.6; font-size: 11.5px; }
.cm-scroll { max-height: calc(100vh - 260px); overflow: auto; }
.cm-table th { position: sticky; top: 0; background: var(--ant-color-bg-container, #fff); z-index: 1; }
.cm-table td { vertical-align: middle; }
.cm-fuel { width: 100px; }
.cm-fuel :deep(input) { text-align: right; height: 22px; padding: 0 6px; font-variant-numeric: tabular-nums; }
.cm-miss { border-color: #d97706; background: rgba(217, 119, 6, 0.08); }
.ds-grid { display: grid; gap: 10px; grid-template-columns: 1fr; }
@media (min-width: 1100px) { .ds-grid { grid-template-columns: 1fr 1fr; } }
.ds-half { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; overflow: hidden; min-width: 0; display: flex; flex-direction: column; }
.ds-fill { flex: 1 1 auto; }
.ds-foot td { overflow: visible !important; text-overflow: clip !important; }
.ds-table { table-layout: fixed; }
.ds-table td, .ds-table th { overflow: hidden; text-overflow: ellipsis; }
.ds-head { padding: 5px 10px; font-weight: 600; font-size: 13px; }
.ds-head span { font-weight: 400; opacity: 0.75; font-size: 12px; margin-left: 6px; }
.ds-lima { background: #e6f4ff; color: #0958d9; }
.ds-chiclayo { background: #f9f0ff; color: #531dab; }
.ds-table { width: 100%; border-collapse: collapse; font-size: 12.5px; font-variant-numeric: tabular-nums; }
.ds-table th { font-size: 11.5px; font-weight: 600; padding: 3px 5px; text-align: left; border-bottom: 1px solid rgba(128, 128, 128, 0.25); white-space: nowrap; }
.ds-table td { padding: 2px 5px; vertical-align: top; white-space: nowrap; border-bottom: 1px solid rgba(128, 128, 128, 0.1); }
.ds-table .r { text-align: right; }
.ds-table tr.tint td { background: rgba(42, 120, 214, 0.06); }
.ds-table tfoot td { background: rgba(42, 120, 214, 0.12); border-top: 1px solid rgba(128, 128, 128, 0.3); border-bottom: none; }
.ds-table .ln + .ln { border-top: 1px dashed rgba(128, 128, 128, 0.25); }
.mut { opacity: 0.55; font-size: 11px; }
.low { color: #d03b3b; }
.ds-src { font-size: 9px; margin-left: 3px; padding: 0 3px; border-radius: 3px; background: rgba(128, 128, 128, 0.15); vertical-align: super; cursor: help; }
.ds-chips { display: flex; flex-wrap: wrap; gap: 4px 6px; align-items: center; padding: 6px 8px; font-size: 12px; }
.chip { padding: 1px 7px; border-radius: 10px; background: rgba(128, 128, 128, 0.12); }
.ds-table td.nw { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ds-table td.pts { font-size: 11.5px; }
.ds-table .ini { cursor: help; border-bottom: 1px dotted #98a2b3; }
.ds-foot td { font-size: 11.5px; padding-left: 2px !important; padding-right: 3px !important; white-space: nowrap; }
.ds-table td.fuel-est { color: #d64550; font-style: italic; cursor: help; }
.fuel-h { cursor: help; border-bottom: 1px dotted #98a2b3; }
.ds-tiles { display: grid; grid-template-columns: 1fr; gap: 10px; margin-bottom: 8px; }
@media (min-width: 1100px) { .ds-tiles { grid-template-columns: 1fr 1fr; gap: 0; } .ds-tside + .ds-tside { border-left: 2px solid #c8d1dc; padding-left: 10px; margin-left: 10px; } }
.ds-tside { min-width: 0; }
.ds-tside-h { font-size: 12px; font-weight: 800; letter-spacing: .1em; color: #12239e; text-align: center; margin-bottom: 4px; }
.ds-tside :deep(.ts) { margin-bottom: 0; gap: 6px; }
.ds-tside :deep(.ts-item) { flex: 1 1 calc(25% - 6px); padding: 4px 6px; }
.ds-tside :deep(.ts-value) { font-size: 17px; }
</style>
