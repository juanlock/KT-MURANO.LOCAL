<template>
  <div class="page aura">
    <!-- What is held now: the latest version of every departure received, whatever file brought it. -->
    <div v-if="current && current.n" class="held">
      <span><b>{{ num(current.n) }}</b> {{ L('departures held', 'salidas guardadas') }}</span>
      <span>{{ date(current.d1) }} – {{ date(current.d2) }}</span>
      <span><b>{{ num(current.seats) }}</b> {{ L('seats', 'pasajes') }}</span>
      <span>{{ L('Production', 'Producción') }} <b>S/ {{ money(current.production) }}</b></span>
      <a-tooltip :title="L('Each departure (plate + date + time + route) counts once: when two files bring it, the latest file\'s figures are kept.', 'Cada salida (placa + fecha + hora + ruta) cuenta una sola vez: si dos archivos la traen, se guardan las cifras del último archivo.')">
        <InfoCircleOutlined class="i" />
      </a-tooltip>
    </div>

    <a-row :gutter="14">
      <a-col :span="24" class="col-list">
        <a-card size="small" class="panel">
          <div class="side-head">
            <h3>{{ L('Files uploaded', 'Archivos subidos') }}</h3>
            <a-upload :show-upload-list="false" accept=".xlsx,.xls" :before-upload="upload">
              <a-button type="primary" size="small" :loading="uploading"><UploadOutlined /> {{ L('Upload AuraSoft file', 'Subir archivo AuraSoft') }}</a-button>
            </a-upload>
          </div>
          <DataTable :crud="crud" :columns="columns" :row-click="r => pick(r.id)" :selected-key="sel?.id ?? null" compact-toolbar table-key="aurasoft-imports">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'created_at'">{{ dateTime(record.created_at) }}</template>
              <template v-else-if="column.key === 'period'">{{ date(record.period_from) }} – {{ date(record.period_to) }}</template>
              <template v-else-if="column.key === 'rows'">
                {{ record.rows_total }}
                <span class="mini"><span class="c-new">+{{ record.rows_new }}</span> <span class="c-upd">~{{ record.rows_updated }}</span> <span class="c-same">={{ record.rows_same }}</span></span>
              </template>
              <template v-else-if="column.key === 'production'"><span class="n">{{ money(record.production) }}</span></template>
            </template>
          </DataTable>
        </a-card>
      </a-col>

      <DetailPanel :open="phoneOpen" :span="24" class="col-detail" @close="phoneOpen = false">
        <a-card size="small" class="panel">
          <template v-if="sel">
            <div class="side-head">
              <h3 class="dt-title" :title="sel.original_name">{{ sel.original_name }}</h3>
              <a-space :size="6">
                <a-button size="small" @click="downloadFile"><DownloadOutlined /> Excel</a-button>
                <a-popconfirm :title="L('Remove this import? Its departures go back to the previous file\'s figures. The Excel stays saved.', '¿Quitar esta importación? Sus salidas vuelven a las cifras del archivo anterior. El Excel queda guardado.')"
                  :ok-text="L('Remove', 'Quitar')" :cancel-text="L('No', 'No')" @confirm="remove">
                  <a-button size="small" danger>{{ L('Remove', 'Quitar') }}</a-button>
                </a-popconfirm>
              </a-space>
            </div>
            <div class="facts">
              <span><span class="lbl">{{ L('Period', 'Período') }}:</span> <b>{{ date(sel.period_from) }} – {{ date(sel.period_to) }}</b></span>
              <span><span class="lbl">{{ L('Uploaded', 'Subido') }}:</span> {{ dateTime(sel.created_at) }} · {{ sel.uploaded_by || '—' }}</span>
              <span v-if="sel.company_text"><span class="lbl">{{ L('Company', 'Empresa') }}:</span> {{ sel.company_text }}</span>
            </div>
            <div class="kpis">
              <div class="k"><div class="v">{{ sel.rows_total }}</div><div class="t">{{ L('Departures', 'Salidas') }}</div></div>
              <div class="k pick" :class="{ on: action === 'new' }" @click="setAction('new')"><div class="v c-new">{{ sel.rows_new }}</div><div class="t">{{ L('New', 'Nuevas') }}</div></div>
              <div class="k pick" :class="{ on: action === 'updated' }" @click="setAction('updated')"><div class="v c-upd">{{ sel.rows_updated }}</div><div class="t">{{ L('Changed', 'Actualizadas') }}</div></div>
              <div class="k pick" :class="{ on: action === 'same' }" @click="setAction('same')"><div class="v c-same">{{ sel.rows_same }}</div><div class="t">{{ L('Already had', 'Ya estaban') }}</div></div>
              <div class="k"><div class="v">{{ num(sel.seats) }}</div><div class="t">{{ L('Seats', 'Pasajes') }}</div></div>
              <div class="k"><div class="v">S/ {{ money(sel.ticket_income) }}</div><div class="t">{{ L('Tickets', 'Pasajes S/') }}</div></div>
              <div class="k"><div class="v">S/ {{ money(sel.parcel_income) }}</div><div class="t">{{ L('Parcels', 'Encomiendas') }}</div></div>
              <div class="k"><div class="v">S/ {{ money(sel.excess_income) }}</div><div class="t">{{ L('Excess', 'Exceso') }}</div></div>
              <div class="k main"><div class="v">S/ {{ money(sel.production) }}</div><div class="t">{{ L('Production', 'Producción') }}</div></div>
            </div>
            <div v-if="Object.keys(sel.channels || {}).length" class="chips">
              <span class="lbl">{{ L('Seats by seller', 'Pasajes por vendedor') }}:</span>
              <span v-for="(n, k) in sel.channels" :key="k" class="chip">{{ k }} <b>{{ num(n) }}</b> <span class="pct">{{ pct(n) }}</span></span>
            </div>
            <div v-if="sel.buses_unknown?.length" class="warn">
              <WarningOutlined /> {{ L('Plates without a bus in Murano', 'Placas sin bus en Murano') }} ({{ sel.buses_unknown.length }}/{{ sel.buses }}): {{ sel.buses_unknown.join(', ') }}
            </div>
            <div v-if="sel.skipped?.length" class="warn">
              <WarningOutlined /> {{ L('Rows left out', 'Filas no tomadas') }}: {{ sel.skipped.map(s => `${L('row', 'fila')} ${s.row} (${s.reason})`).join('; ') }}
            </div>

            <DataTable :crud="rowsCrud" :columns="rowColumns" compact-toolbar table-key="aurasoft-rows">
              <template #filters>
                <a-segmented v-model:value="action" size="small" :options="actionOptions" @change="rowsCrud.fetchRows()" />
              </template>
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'trip_date'">{{ date(record.trip_date) }} <span class="muted">{{ String(record.departure_time).slice(0, 5) }}</span></template>
                <template v-else-if="column.key === 'plate'">{{ record.plate }}<span v-if="record.bus" class="muted"> · {{ record.bus }}</span></template>
                <template v-else-if="column.key === 'sellers'">
                  <span class="sellers">{{ Object.entries(record.channels || {}).filter(([, n]) => n > 0).map(([k, n]) => `${short(k)} ${n}`).join(' · ') }}</span>
                </template>
                <template v-else-if="['ticket_income', 'parcel_income', 'production'].includes(column.key)"><span class="n">{{ money(record[column.key]) }}</span></template>
                <template v-else-if="column.key === 'action'"><span class="tag" :class="'t-' + record.action">{{ actionLabel(record.action) }}</span></template>
              </template>
            </DataTable>
          </template>
          <div v-else class="hint">
            <p>{{ L('No file yet.', 'Todavía no hay archivos.') }}</p>
            <p>{{ L('In AuraSoft, download the "Reporte producción por bus" for the dates you want (a day, a week or a month) and upload it here as it comes. If the dates overlap an earlier file, the departures are not repeated.', 'En AuraSoft, descargue el «Reporte producción por bus» de las fechas que quiera (un día, una semana o un mes) y súbalo aquí tal cual. Si las fechas se cruzan con un archivo anterior, las salidas no se repiten.') }}</p>
          </div>
        </a-card>
      </DetailPanel>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Integración -> Producción AuraSoft (2026-11-23, Turismo Cautivo; AuraSoft = the ticket system once called Jelaf).
 * The Manager uploads AuraSoft's "Reporte producción por bus" Excel (a day or a range, on demand). Every file is kept and
 * shows what it brought: departures new, changed (different figures than the earlier file) or already there.
 * Left: the files (latest opened). Right: the file's summary and its rows. Server: TransportAuraSoftController.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { UploadOutlined, DownloadOutlined, WarningOutlined, InfoCircleOutlined } from '@ant-design/icons-vue';
import DataTable from '../../components/DataTable.vue';
import DetailPanel from '../../components/DetailPanel.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useBarTitle } from '../../composables/useBarTitle';
import { useUiStore } from '../../stores/ui';
import { getActingCompanyId } from '../../lib/actingCompany';
import http from '../../lib/http';

const ui = useUiStore();
const L = (en, es) => (String(ui.locale || 'en').startsWith('es') ? es : en);
const { date, dateTime } = useFormat();
const num = v => Number(v || 0).toLocaleString('en-US');
const money = v => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const phoneOpen = ref(false);

const crud = useCrudTable('transport/aurasoft', { rowsKey: 'rows', sortField: 'created_at', sortType: 'desc', limit: 20 });
const current = computed(() => crud.payload.value?.current || null);
const columns = computed(() => [
  { title: L('Uploaded', 'Subido'), key: 'created_at', dataIndex: 'created_at', sorter: true, width: 128 },
  { title: L('File', 'Archivo'), key: 'original_name', dataIndex: 'original_name', sorter: true, ellipsis: { showTitle: true } },
  { title: L('Period', 'Período'), key: 'period', dataIndex: 'period_from', width: 160 },
  { title: L('Departures', 'Salidas'), key: 'rows', dataIndex: 'rows_total', width: 128 },
  { title: L('Production', 'Producción'), key: 'production', dataIndex: 'production', sorter: true, width: 104, align: 'right' },
]);

const sel = ref(null);
const action = ref('all');
const actionOptions = computed(() => [
  { value: 'all', label: L('All', 'Todas') }, { value: 'new', label: L('New', 'Nuevas') },
  { value: 'updated', label: L('Changed', 'Actualizadas') }, { value: 'same', label: L('Already had', 'Ya estaban') },
]);
const actionLabel = a => ({ new: L('New', 'Nueva'), updated: L('Changed', 'Actualizada'), same: L('Already had', 'Ya estaba') }[a] || a);
const rowsCrud = useCrudTable(() => `transport/aurasoft/${sel.value?.id || 0}/rows`, {
  rowsKey: 'rows', sortField: 'trip_date', sortType: 'asc', limit: 50,
  params: () => ({ action: action.value === 'all' ? undefined : action.value }),
});
const rowColumns = computed(() => [
  { title: L('Date', 'Fecha'), key: 'trip_date', dataIndex: 'trip_date', sorter: true, width: 118 },
  { title: L('Plate', 'Placa'), key: 'plate', dataIndex: 'plate', sorter: true, width: 128, ellipsis: { showTitle: true } },
  { title: L('Route', 'Ruta'), key: 'route', dataIndex: 'route', sorter: true, width: 150, ellipsis: { showTitle: true } },
  { title: L('Seats', 'Pas.'), key: 'seats', dataIndex: 'seats', sorter: true, width: 56, align: 'right' },
  { title: L('Sellers', 'Vendedores'), key: 'sellers', dataIndex: 'channels', ellipsis: { showTitle: true } },
  { title: L('Tickets S/', 'Pasajes S/'), key: 'ticket_income', dataIndex: 'ticket_income', sorter: true, width: 92, align: 'right' },
  { title: L('Parcels S/', 'Encom. S/'), key: 'parcel_income', dataIndex: 'parcel_income', sorter: true, width: 86, align: 'right' },
  { title: L('Production', 'Producción'), key: 'production', dataIndex: 'production', sorter: true, width: 96, align: 'right' },
  { title: L('In this file', 'En este archivo'), key: 'action', dataIndex: 'action', sorter: true, width: 104 },
]);
const short = k => ({ 'AURA SOFT': 'Aura', 'NO REGISTRADO': 'No reg.', REDBUS: 'RedBus', KUPOS: 'Kupos', CARRITOC: 'Carritoc', KIOSCO: 'Kiosco', PERUBUS: 'PeruBus' }[k] || k);
const pct = n => {
  const t = Object.values(sel.value?.channels || {}).reduce((s, x) => s + Number(x || 0), 0);
  return t ? `${Math.round((Number(n) / t) * 100)} %` : '';
};

async function pick(id, show = true) {
  sel.value = await http.get(`transport/aurasoft/${id}`);
  action.value = 'all';
  rowsCrud.page.value = 1;
  await rowsCrud.fetchRows();
  if (show) phoneOpen.value = true;
}
function setAction(a) {
  action.value = action.value === a ? 'all' : a;
  rowsCrud.page.value = 1;
  rowsCrud.fetchRows();
}

const uploading = ref(false);
async function upload(file) {
  uploading.value = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    const r = await http.postForm('transport/aurasoft', fd);
    message.success(L(`${r.rows_total} departures: ${r.rows_new} new, ${r.rows_updated} changed, ${r.rows_same} already there.`,
      `${r.rows_total} salidas: ${r.rows_new} nuevas, ${r.rows_updated} actualizadas, ${r.rows_same} ya estaban.`), 6);
    await crud.fetchRows();
    await pick(r.id, false);
  } catch (e) {
    message.error(e?.data?.message || L('The file could not be imported.', 'No se pudo importar el archivo.'));
  } finally {
    uploading.value = false;
  }
  return false; // the upload is ours, not the component's
}

async function downloadFile() {
  try {
    const cid = getActingCompanyId();
    await http.download(`transport/aurasoft/${sel.value.id}/file${cid ? `?company_id=${cid}` : ''}`, sel.value.original_name);
  } catch (e) { message.error(L('The file could not be downloaded.', 'No se pudo descargar el archivo.')); }
}

async function remove() {
  try {
    await http.delete(`transport/aurasoft/${sel.value.id}`);
    message.success(L('Import removed', 'Importación quitada'));
    sel.value = null;
    phoneOpen.value = false;
    await crud.fetchRows();
    if (crud.rows.value?.length) await pick(crud.rows.value[0].id, false);
  } catch (e) { message.error(e?.data?.message || L('It could not be removed.', 'No se pudo quitar.')); }
}

useBarTitle(L('AuraSoft production', 'Producción AuraSoft'));
onMounted(async () => {
  await crud.fetchRows();
  // The latest file opened (user's rule, 2026-11-23).
  if (crud.rows.value?.length) await pick(crud.rows.value[0].id, false);
});
</script>

<style scoped>
@media (min-width: 1200px) { .col-list { flex: 0 0 40%; max-width: 40%; } .col-detail { flex: 0 0 60%; max-width: 60%; } }
.panel { border-radius: 2px; }
.held { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 18px; font-size: 13px; padding: 6px 10px; margin-bottom: 10px;
  border-radius: 4px; background: rgba(var(--app-primary-rgb, 0, 120, 212), 0.08); }
.held .i { opacity: 0.6; cursor: help; }
.side-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.side-head h3 { margin: 0; font-size: 17px; font-weight: 500; }
.dt-title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.facts { display: flex; flex-wrap: wrap; gap: 2px 18px; font-size: 12.5px; margin-bottom: 8px; }
.lbl { opacity: 0.65; }
.kpis { display: grid; grid-template-columns: repeat(9, minmax(0, 1fr)); gap: 6px; margin-bottom: 8px; }
.k { padding: 5px 6px; border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 4px; min-width: 0; }
.k .v { font-weight: 600; font-size: 14px; font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.k .t { font-size: 11px; opacity: 0.7; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.k.main { border-color: rgba(var(--app-primary-rgb, 0, 120, 212), 0.6); }
.k.pick { cursor: pointer; }
.k.pick.on { background: rgba(var(--app-primary-rgb, 0, 120, 212), 0.1); }
.c-new { color: #15803d; }
.c-upd { color: #b45309; }
.c-same { opacity: 0.6; }
.mini { font-size: 11px; margin-left: 4px; }
.chips { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 6px; font-size: 12px; margin-bottom: 6px; }
.chip { padding: 1px 7px; border-radius: 10px; background: rgba(128, 128, 128, 0.12); }
.chip .pct { opacity: 0.6; font-size: 11px; }
.warn { font-size: 12px; color: #b45309; margin-bottom: 6px; }
.n { font-variant-numeric: tabular-nums; }
.muted { opacity: 0.6; }
.sellers { font-size: 11.5px; }
.tag { font-size: 11px; padding: 0 6px; border-radius: 8px; }
.t-new { background: rgba(21, 128, 61, 0.14); color: #15803d; }
.t-updated { background: rgba(180, 83, 9, 0.14); color: #b45309; }
.t-same { background: rgba(128, 128, 128, 0.14); }
.hint { opacity: 0.75; padding: 12px 4px; }
.aura :deep(.dt-managed .ant-table-cell) { white-space: nowrap; }
@media (max-width: 1100px) { .kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
</style>
