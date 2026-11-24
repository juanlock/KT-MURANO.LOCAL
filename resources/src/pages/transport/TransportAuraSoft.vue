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

    <a-card size="small" class="panel">
      <div class="side-head">
        <h3>{{ L('Files uploaded', 'Archivos subidos') }}</h3>
        <a-upload :show-upload-list="false" accept=".xlsx,.xls" :before-upload="upload">
          <a-button type="primary" size="small" :loading="uploading"><UploadOutlined /> {{ L('Upload AuraSoft file', 'Subir archivo AuraSoft') }}</a-button>
        </a-upload>
      </div>
      <DataTable :crud="crud" :columns="columns" :row-click="r => pick(r.id)" compact-toolbar table-key="aurasoft-imports">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'created_at'">{{ dateTime(record.created_at) }}</template>
          <template v-else-if="column.key === 'kind'"><span class="tag" :class="record.kind === 'liquidation' ? 't-liq' : 't-prod'">{{ kindLabel(record.kind) }}</span></template>
          <template v-else-if="column.key === 'period'"><span class="nowrap">{{ date(record.period_from) }} – {{ date(record.period_to) }}</span></template>
          <template v-else-if="column.key === 'rows'">
            {{ record.rows_total }}
            <span class="mini"><span class="c-new">+{{ record.rows_new }}</span> <span class="c-upd">~{{ record.rows_updated }}</span> <span class="c-same">={{ record.rows_same }}</span></span>
          </template>
          <template v-else-if="['seats'].includes(column.key)"><span class="n">{{ num(record.seats) }}</span></template>
          <template v-else-if="['ticket_income', 'production'].includes(column.key)"><span class="n">{{ money(record[column.key]) }}</span></template>
          <template v-else-if="column.key === 'actions'">
            <span class="acts" @click.stop>
              <a-tooltip :title="L('View', 'Ver')"><a-button size="small" type="text" @click="pick(record.id)"><EyeOutlined /></a-button></a-tooltip>
              <a-tooltip :title="L('Download the uploaded file', 'Descargar el archivo subido')"><a-button size="small" type="text" @click="downloadFile(record)"><DownloadOutlined /></a-button></a-tooltip>
              <a-tooltip :title="L('Export PDF', 'Exportar PDF')"><a-button size="small" type="text" :loading="busy === 'pdf' + record.id" @click="exportRows(record, 'pdf')"><FilePdfOutlined style="color: #dc2626" /></a-button></a-tooltip>
              <a-tooltip :title="L('Export Excel', 'Exportar XLS')"><a-button size="small" type="text" :loading="busy === 'xls' + record.id" @click="exportRows(record, 'xls')"><FileExcelOutlined style="color: #15803d" /></a-button></a-tooltip>
              <a-popconfirm :title="L('Remove this import? Its departures go back to the previous file\'s figures. The Excel stays saved.', '¿Quitar esta importación? Sus salidas vuelven a las cifras del archivo anterior. El Excel queda guardado.')"
                :ok-text="L('Remove', 'Quitar')" :cancel-text="L('No', 'No')" @confirm="remove(record)">
                <a-tooltip :title="L('Remove', 'Quitar')"><a-button size="small" type="text" danger><DeleteOutlined /></a-button></a-tooltip>
              </a-popconfirm>
            </span>
          </template>
        </template>
      </DataTable>
      <div v-if="!crud.rows.value?.length" class="hint">
        <p>{{ L('In AuraSoft, download the "Reporte producción por bus" for the dates you want (a day, a week or a month) and upload it here as it comes. If the dates overlap an earlier file, the departures are not repeated.', 'En AuraSoft, descargue el «Reporte producción por bus» de las fechas que quiera (un día, una semana o un mes) y súbalo aquí tal cual. Si las fechas se cruzan con un archivo anterior, las salidas no se repiten.') }}</p>
      </div>
    </a-card>

    <!-- The file's detail in a big window (user, 2026-11-23). -->
    <a-modal v-model:open="detailOpen" :footer="null" width="96vw" :style="{ top: '16px', maxWidth: '1500px' }" :title="sel ? sel.original_name : ''" wrap-class-name="aura-modal" destroy-on-close>
      <template v-if="sel">
        <div class="side-head">
          <div class="facts">
            <span><span class="lbl">{{ L('Period', 'Período') }}:</span> <b>{{ date(sel.period_from) }} – {{ date(sel.period_to) }}</b></span>
            <span><span class="lbl">{{ L('Uploaded', 'Subido') }}:</span> {{ dateTime(sel.created_at) }} · {{ sel.uploaded_by || '—' }}</span>
            <span v-if="sel.company_text"><span class="lbl">{{ L('Company', 'Empresa') }}:</span> {{ sel.company_text }}</span>
          </div>
          <a-space :size="6">
            <a-button size="small" @click="downloadFile(sel)"><DownloadOutlined /> {{ L('Uploaded file', 'Archivo subido') }}</a-button>
            <a-button size="small" :loading="busy === 'pdf' + sel.id" @click="exportRows(sel, 'pdf')"><FilePdfOutlined /> PDF</a-button>
            <a-button size="small" :loading="busy === 'xls' + sel.id" @click="exportRows(sel, 'xls')"><FileExcelOutlined /> XLS</a-button>
          </a-space>
        </div>
        <!-- A Liquidación General (2026-11-24): its own figures. -->
        <div v-if="sel.kind === 'liquidation'" class="kpis">
          <div class="k"><div class="v">{{ sel.rows_total }}</div><div class="t">{{ L('Rows', 'Filas') }}</div></div>
          <div class="k pick" :class="{ on: action === 'new' }" @click="setAction('new')"><div class="v c-new">{{ sel.rows_new }}</div><div class="t">{{ L('New', 'Nuevas') }}</div></div>
          <div class="k pick" :class="{ on: action === 'updated' }" @click="setAction('updated')"><div class="v c-upd">{{ sel.rows_updated }}</div><div class="t">{{ L('Changed', 'Actualizadas') }}</div></div>
          <div class="k pick" :class="{ on: action === 'same' }" @click="setAction('same')"><div class="v c-same">{{ sel.rows_same }}</div><div class="t">{{ L('Already had', 'Ya estaban') }}</div></div>
          <div class="k"><div class="v">{{ sel.totals?.points }} / {{ sel.totals?.cashiers }}</div><div class="t">{{ L('Points / cashiers', 'Puntos / cajeros') }}</div></div>
          <div class="k"><div class="v">S/ {{ money(sel.totals?.tickets) }}</div><div class="t">{{ L('Tickets', 'Pasajes') }}</div></div>
          <div class="k"><div class="v">S/ {{ money(sel.totals?.card) }}</div><div class="t">{{ L('Cards', 'Tarjetas') }}</div></div>
          <div class="k"><div class="v">S/ {{ money(sel.totals?.expenses) }}</div><div class="t">{{ L('Expenses', 'Egresos') }}</div></div>
          <div class="k main"><div class="v">S/ {{ money(sel.totals?.net) }}</div><div class="t">{{ L('Net', 'Neto') }}</div></div>
        </div>
        <div v-else class="kpis">
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

        <DataTable :key="sel.kind" :crud="rowsCrud" :columns="sel.kind === 'liquidation' ? liqColumns : rowColumns" compact-toolbar
          :table-key="sel.kind === 'liquidation' ? 'aurasoft-liq' : 'aurasoft-rows'">
          <template #filters>
            <a-segmented v-model:value="action" size="small" :options="actionOptions" @change="rowsCrud.fetchRows()" />
          </template>
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'sale_date'">{{ date(record.sale_date) }}</template>
            <template v-else-if="column.liq">{{ money(record[column.key]) }}<span
              v-if="record.before && ['income', 'net', 'tickets'].includes(column.key) && Number(record.before[column.key]) !== Number(record[column.key])"
              class="was"> ({{ money(record.before[column.key]) }})</span></template>
            <template v-else-if="column.key === 'trip_date'">{{ date(record.trip_date) }} <span class="muted">{{ String(record.departure_time).slice(0, 5) }}</span></template>
            <template v-else-if="column.key === 'plate'">{{ record.plate }}<span v-if="record.bus" class="muted"> · {{ record.bus }}</span></template>
            <template v-else-if="column.key === 'sellers'">
              <span class="sellers">{{ sellersText(record) }}</span>
            </template>
            <template v-else-if="['ticket_income', 'parcel_income', 'production'].includes(column.key)"><span class="n">{{ money(record[column.key]) }}</span></template>
            <template v-else-if="column.key === 'action'"><span class="tag" :class="'t-' + record.action">{{ actionLabel(record.action) }}</span></template>
          </template>
        </DataTable>
      </template>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Integración -> Producción AuraSoft (2026-11-23, Turismo Cautivo; AuraSoft = the ticket system once called Jelaf).
 * The Manager uploads AuraSoft's "Reporte producción por bus" Excel (a day or a range, on demand). Every file is kept and
 * shows what it brought: departures new, changed (different figures than the earlier file) or already there.
 * The files in a full-width table with their actions (view, the uploaded file, PDF, XLS, remove); a click opens the file's summary and rows in a big window (user, 2026-11-23). Server: TransportAuraSoftController.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { UploadOutlined, DownloadOutlined, WarningOutlined, InfoCircleOutlined, EyeOutlined, FilePdfOutlined, FileExcelOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import DataTable from '../../components/DataTable.vue';
import { exportExcel, exportPdf } from '../../lib/exporters';
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
const detailOpen = ref(false);
const busy = ref('');

const crud = useCrudTable('transport/aurasoft', { rowsKey: 'rows', sortField: 'created_at', sortType: 'desc', limit: 20 });
const current = computed(() => crud.payload.value?.current || null);
const columns = computed(() => [
  { title: L('Uploaded', 'Subido'), key: 'created_at', dataIndex: 'created_at', sorter: true, width: 132 },
  { title: L('Report', 'Reporte'), key: 'kind', dataIndex: 'kind', width: 150 },
  { title: L('File', 'Archivo'), key: 'original_name', dataIndex: 'original_name', sorter: true, ellipsis: { showTitle: true } },
  { title: L('Period', 'Período'), key: 'period', dataIndex: 'period_from', width: 178 },
  { title: L('Rows', 'Filas'), key: 'rows', dataIndex: 'rows_total', width: 116 },
  { title: L('Seats', 'Pasajes'), key: 'seats', dataIndex: 'seats', width: 74, align: 'right' },
  { title: L('Tickets S/', 'Pasajes S/'), key: 'ticket_income', dataIndex: 'ticket_income', width: 104, align: 'right' },
  { title: L('Production / income', 'Producción / ingresos'), key: 'production', dataIndex: 'production', sorter: true, width: 130, align: 'right' },
  { title: L('Uploaded by', 'Subido por'), key: 'uploaded_by', dataIndex: 'uploaded_by', width: 124, ellipsis: true },
  { title: L('Actions', 'Acciones'), key: 'actions', width: 132, align: 'center', className: 'col-acts' },
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
  { title: L('Date', 'Fecha'), key: 'trip_date', dataIndex: 'trip_date', sorter: true, width: 140 },
  { title: L('Plate', 'Placa'), key: 'plate', dataIndex: 'plate', sorter: true, width: 150, ellipsis: { showTitle: true } },
  { title: L('Route', 'Ruta'), key: 'route', dataIndex: 'route', sorter: true, width: 150, ellipsis: { showTitle: true } },
  { title: L('Seats', 'Pas.'), key: 'seats', dataIndex: 'seats', sorter: true, width: 56, align: 'right' },
  { title: L('Sellers', 'Vendedores'), key: 'sellers', dataIndex: 'channels', ellipsis: { showTitle: true } },
  { title: L('Tickets S/', 'Pasajes S/'), key: 'ticket_income', dataIndex: 'ticket_income', sorter: true, width: 100, align: 'right' },
  { title: L('Parcels S/', 'Encom. S/'), key: 'parcel_income', dataIndex: 'parcel_income', sorter: true, width: 86, align: 'right' },
  { title: L('Production', 'Producción'), key: 'production', dataIndex: 'production', sorter: true, width: 96, align: 'right' },
  { title: L('In this file', 'En este archivo'), key: 'action', dataIndex: 'action', sorter: true, width: 120 },
]);
// AuraSoft's Liquidación General (2026-11-24): its own columns.
const kindLabel = k => (k === 'liquidation' ? L('General liquidation', 'Liquidación general') : L('Production per bus', 'Producción por bus'));
const liqColumns = computed(() => [
  { title: L('Date', 'Fecha'), key: 'sale_date', dataIndex: 'sale_date', sorter: true, width: 100 },
  { title: L('Branch', 'Sucursal'), key: 'branch', dataIndex: 'branch', sorter: true, width: 110, ellipsis: { showTitle: true } },
  { title: L('Point of sale', 'Punto de venta'), key: 'point', dataIndex: 'point', sorter: true, width: 150, ellipsis: { showTitle: true } },
  { title: L('Cashier', 'Cajero'), key: 'cashier', dataIndex: 'cashier', sorter: true, width: 170, ellipsis: { showTitle: true } },
  ...[['tickets', 'Tickets', 'Pasajes'], ['parcels', 'Parcels', 'Encom.'], ['excess', 'Excess', 'Exceso'], ['income', 'Income', 'Ingresos'],
    ['card', 'Cards', 'Tarjetas'], ['web', 'Web', 'Web'], ['channel', 'Channels', 'Canales'], ['expenses', 'Expenses', 'Egresos'], ['net', 'Net', 'Neto']]
    .map(([k, en, es]) => ({ title: L(en, es), key: k, dataIndex: k, align: 'right', width: 92, liq: true, sorter: ['tickets', 'parcels', 'income', 'card', 'expenses', 'net'].includes(k) })),
  { title: L('In this file', 'En este archivo'), key: 'action', dataIndex: 'action', sorter: true, width: 110 },
]);
const sellersText = r => Object.entries(r.channels || {}).filter(([, n]) => n > 0).map(([k, n]) => `${short(k)} ${n}`).join(' · ');
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
  if (show) detailOpen.value = true;
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
    const what = r.kind === 'liquidation' ? L('rows', 'filas') : L('departures', 'salidas');
    message.success(L(`${kindLabel(r.kind)}: ${r.rows_total} ${what}, ${r.rows_new} new, ${r.rows_updated} changed, ${r.rows_same} already there.`,
      `${kindLabel(r.kind)}: ${r.rows_total} ${what}, ${r.rows_new} nuevas, ${r.rows_updated} actualizadas, ${r.rows_same} ya estaban.`), 6);
    await crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || L('The file could not be imported.', 'No se pudo importar el archivo.'));
  } finally {
    uploading.value = false;
  }
  return false; // the upload is ours, not the component's
}

async function downloadFile(rec) {
  try {
    const cid = getActingCompanyId();
    await http.download(`transport/aurasoft/${rec.id}/file${cid ? `?company_id=${cid}` : ''}`, rec.original_name);
  } catch (e) { message.error(L('The file could not be downloaded.', 'No se pudo descargar el archivo.')); }
}

async function remove(rec) {
  try {
    await http.delete(`transport/aurasoft/${rec.id}`);
    message.success(L('Import removed', 'Importación quitada'));
    if (sel.value?.id === rec.id) { sel.value = null; detailOpen.value = false; }
    await crud.fetchRows();
  } catch (e) { message.error(e?.data?.message || L('It could not be removed.', 'No se pudo quitar.')); }
}

// Every row of one file as PDF or Excel (all its departures, not only the page on screen).
const exportColumns = computed(() => [
  { title: L('Date', 'Fecha'), key: 'trip_date', exportValue: r => `${date(r.trip_date)} ${String(r.departure_time).slice(0, 5)}` },
  { title: 'Bus', key: 'bus', exportValue: r => r.bus || '' },
  { title: L('Plate', 'Placa'), key: 'plate', dataIndex: 'plate' },
  { title: L('Route', 'Ruta'), key: 'route', dataIndex: 'route' },
  { title: L('Seats', 'Pasajes'), key: 'seats', dataIndex: 'seats' },
  { title: L('Sellers', 'Vendedores'), key: 'sellers', exportValue: sellersText },
  { title: L('Tickets S/', 'Pasajes S/'), key: 'ticket_income', exportValue: r => Number(r.ticket_income || 0) },
  { title: L('Parcels', 'Encom.'), key: 'parcels', dataIndex: 'parcels' },
  { title: L('Parcels S/', 'Encom. S/'), key: 'parcel_income', exportValue: r => Number(r.parcel_income || 0) },
  { title: L('Excess S/', 'Exceso S/'), key: 'excess_income', exportValue: r => Number(r.excess_income || 0) },
  { title: L('Production S/', 'Producción S/'), key: 'production', exportValue: r => Number(r.production || 0) },
  { title: L('In this file', 'En este archivo'), key: 'action', exportValue: r => actionLabel(r.action) },
]);
async function exportRows(rec, kind) {
  busy.value = kind + rec.id;
  try {
    const all = [];
    for (let page = 1; page < 50; page++) {
      const r = await http.get(`transport/aurasoft/${rec.id}/rows`, { page, limit: 500, SortField: 'trip_date', SortType: 'asc' });
      all.push(...(r.rows || []));
      if (all.length >= (r.totalRows || 0) || !(r.rows || []).length) break;
    }
    const liq = rec.kind === 'liquidation';
    const cols = liq
      ? liqColumns.value.map(c => ({ ...c, exportValue: c.liq ? r => Number(r[c.key] || 0) : c.key === 'sale_date' ? r => date(r.sale_date) : c.key === 'action' ? r => actionLabel(r.action) : undefined }))
      : exportColumns.value;
    const sum = k => all.reduce((s, x) => s + Number(x[k] || 0), 0);
    const totals = liq
      ? Object.fromEntries([['sale_date', L('Total', 'Total')], ...liqColumns.value.filter(c => c.liq).map(c => [c.key, money(sum(c.key))])])
      : { trip_date: L('Total', 'Total'), seats: sum('seats'), parcels: sum('parcels'), ticket_income: money(sum('ticket_income')),
        parcel_income: money(sum('parcel_income')), excess_income: money(sum('excess_income')), production: money(sum('production')) };
    const foot = cols.map(c => totals[c.key] ?? '');
    const title = `AuraSoft ${date(rec.period_from)} - ${date(rec.period_to)}`;
    if (kind === 'pdf') await exportPdf(title, cols, all, { landscape: true, foot });
    else await exportExcel(title.replace(/[\\/:*?"<>|]/g, '-'), cols, all, { foot });
  } catch (e) {
    message.error(L('The export failed.', 'No se pudo exportar.'));
  } finally {
    busy.value = '';
  }
}

useBarTitle(L('AuraSoft production', 'Producción AuraSoft'));
onMounted(() => crud.fetchRows());
</script>

<style scoped>
.acts { display: inline-flex; gap: 2px; }
.side-head .facts { margin-bottom: 0; }
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
.t-prod { background: rgba(58, 79, 196, 0.12); color: #3A4FC4; }
.t-liq { background: rgba(15, 157, 138, 0.14); color: #0b7a6b; }
.was { opacity: 0.55; font-size: 11px; text-decoration: line-through; }
.hint { opacity: 0.75; padding: 12px 4px; }
.acts { display: inline-flex; gap: 2px; }
.side-head .facts { margin-bottom: 0; }
.aura :deep(.dt-managed .ant-table-cell) { white-space: nowrap; }
@media (max-width: 1100px) { .kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
</style>

<style>
/* One line per value, compact rows (user, 2026-11-23): the list and the file's window (teleported out of .aura). */
.aura .dt-fit .ant-table-tbody > tr > td, .aura .dt-fit .ant-table-thead > tr > th,
.aura-modal .dt-fit .ant-table-tbody > tr > td, .aura-modal .dt-fit .ant-table-thead > tr > th,
.aura .ant-table-cell, .aura-modal .ant-table-cell { white-space: nowrap !important; overflow: hidden; text-overflow: ellipsis; }
.aura .ant-table-cell.col-acts { overflow: visible; padding-left: 2px !important; padding-right: 2px !important; }
.aura .acts .ant-btn { padding: 0 4px; width: auto; min-width: 0; }
.aura-modal .ant-table-tbody > tr > td, .aura-modal .ant-table-thead > tr > th { padding-top: 3px !important; padding-bottom: 3px !important; font-size: 12.5px; }
.aura-modal .ant-table-thead > tr > th { line-height: 1.2; }
</style>
