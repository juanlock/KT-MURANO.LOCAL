<template>
  <div class="page">
    <!-- IT Admin -> Networks IP (2026-11-28, from HTD-IT): the networks of each location, their IP list and "Scan this network". -->
    <DataTable :crud="crud" :columns="columns">
      <template #filters>
        <a-select v-model:value="filters.location_id" allow-clear :placeholder="L('Location', 'Ubicación')" :options="locationOptions" style="width: 180px" @change="crud.reload()" />
        <a-select v-model:value="filters.type" allow-clear :placeholder="L('Type', 'Tipo')" :options="typeOptions" style="width: 130px" @change="crud.reload()" />
        <a-button v-if="opts.can?.manage" type="primary" @click="openForm()"><PlusOutlined /> {{ L('Add new', 'Agregar nuevo') }}</a-button>
      </template>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <a @click="openIps(record)"><b>{{ record.name }}</b></a>
          <div class="it-muted">{{ record.location || '—' }}</div>
        </template>
        <template v-else-if="column.key === 'network'">
          <span class="it-mono">{{ record.network_address }}/{{ record.cidr }}</span>
          <div class="it-muted it-mono">{{ record.mask }}</div>
        </template>
        <template v-else-if="column.key === 'type'">
          <a-tag>{{ record.type }}</a-tag>
          <WifiOutlined v-if="record.is_wifi" :title="[record.wifi24_ssid, record.wifi5_ssid, record.wifi6_ssid].filter(Boolean).join(' · ')" />
        </template>
        <template v-else-if="column.key === 'use'">
          <a-progress :percent="record.usable ? Math.round((record.used / record.usable) * 100) : 0" size="small" :show-info="false" style="width: 90px; margin: 0" />
          <div class="it-muted">{{ record.used }} / {{ record.usable }}</div>
        </template>
        <template v-else-if="column.key === 'scan'">
          <a-badge v-if="record.scans_to_review" :count="record.scans_to_review" :title="L('Scans to review', 'Escaneos por revisar')">
            <PermLink :to="`/itadmin/scans?network_id=${record.id}`" permission="itadmin_view"><ScanOutlined /></PermLink>
          </a-badge>
          <span v-else class="it-muted">{{ record.last_scan ? dateTime(record.last_scan) : L('Never', 'Nunca') }}</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="L('IP list', 'Lista de IPs')"><a-button size="small" @click="openIps(record)"><UnorderedListOutlined /></a-button></a-tooltip>
            <a-tooltip v-if="opts.can?.scan" :title="L('Scan this network', 'Escanear esta red')"><a-button size="small" @click="openScan(record)"><ScanOutlined /></a-button></a-tooltip>
            <a-tooltip v-if="opts.can?.manage" :title="L('Edit', 'Editar')"><a-button size="small" @click="openForm(record)"><EditOutlined /></a-button></a-tooltip>
            <a-tooltip v-if="opts.can?.manage" :title="L('Delete', 'Eliminar')"><a-button size="small" danger @click="crud.remove(record, { label: record.name, deleteEndpoint: `itadmin/networks/${record.id}` })"><DeleteOutlined /></a-button></a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Network form -->
    <a-modal v-model:open="form.open" :title="form.id ? L('Edit network', 'Editar red') : L('New network', 'Nueva red')" width="760px" :confirm-loading="form.saving" :ok-text="L('Save', 'Guardar')" @ok="saveForm">
      <a-form layout="vertical" class="it-form">
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item :label="L('Name', 'Nombre')" required><a-input v-model:value="form.d.name" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="L('Location', 'Ubicación')" required><a-select v-model:value="form.d.location_id" :options="locationOptions" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="L('Network (e.g. 192.168.1.0/24)', 'Red (ej. 192.168.1.0/24)')" required><a-input v-model:value="form.d.network" class="it-mono" @change="calc" /></a-form-item></a-col>
          <a-col :span="5"><a-form-item :label="L('Type', 'Tipo')" required><a-select v-model:value="form.d.type" :options="typeOptions" /></a-form-item></a-col>
          <a-col :span="5"><a-form-item label="VLAN"><a-input v-model:value="form.d.vlan" /></a-form-item></a-col>
          <a-col :span="6"><a-form-item :label="L('Medium', 'Medio')"><a-checkbox v-model:checked="form.d.is_wired">{{ L('Wired', 'Cable') }}</a-checkbox><a-checkbox v-model:checked="form.d.is_wifi">Wi-Fi</a-checkbox></a-form-item></a-col>
        </a-row>
        <div v-if="form.calc?.ok" class="it-calc">
          <span>{{ L('Mask', 'Máscara') }} <b>{{ form.calc.mask }}</b></span><span>{{ L('Hosts', 'Hosts') }} <b>{{ form.calc.first }} – {{ form.calc.last }}</b></span>
          <span>Broadcast <b>{{ form.calc.broadcast }}</b></span><span>{{ L('Usable', 'Utilizables') }} <b>{{ form.calc.usable }}</b></span><span>{{ L('Class', 'Clase') }} {{ form.calc.class }}{{ form.calc.private ? ' · ' + L('private', 'privada') : '' }}</span>
        </div>
        <a-row :gutter="12">
          <a-col :span="6"><a-form-item :label="L('Gateway', 'Puerta de enlace')"><a-input v-model:value="form.d.gateway" class="it-mono" /></a-form-item></a-col>
          <a-col :span="6"><a-form-item label="DNS 1"><a-input v-model:value="form.d.dns1" class="it-mono" /></a-form-item></a-col>
          <a-col :span="6"><a-form-item label="DNS 2"><a-input v-model:value="form.d.dns2" class="it-mono" /></a-form-item></a-col>
          <a-col :span="6"><a-form-item :label="L('Device linked', 'Equipo que la sirve')"><a-select v-model:value="form.d.linked_asset_id" allow-clear show-search option-filter-prop="label" :options="deviceOptions" /></a-form-item></a-col>
          <a-col :span="6"><a-form-item label="DHCP"><a-switch v-model:checked="form.d.dhcp_enabled" /></a-form-item></a-col>
          <template v-if="form.d.dhcp_enabled">
            <a-col :span="9"><a-form-item :label="L('DHCP from', 'DHCP desde')"><a-input v-model:value="form.d.dhcp_start" class="it-mono" /></a-form-item></a-col>
            <a-col :span="9"><a-form-item :label="L('DHCP to', 'DHCP hasta')"><a-input v-model:value="form.d.dhcp_end" class="it-mono" /></a-form-item></a-col>
          </template>
        </a-row>
        <template v-if="form.d.is_wifi">
          <div class="it-sub">Wi-Fi</div>
          <a-row v-for="b in ['24', '5', '6']" :key="b" :gutter="12" align="middle">
            <a-col :span="5"><a-checkbox v-model:checked="form.d['wifi' + b + '_enabled']">{{ b === '24' ? '2.4' : b }} GHz</a-checkbox></a-col>
            <a-col :span="9"><a-input v-model:value="form.d['wifi' + b + '_ssid']" placeholder="SSID" :disabled="!form.d['wifi' + b + '_enabled']" /></a-col>
            <a-col :span="10"><a-input-password v-model:value="form.d['wifi' + b + '_password']" :placeholder="form.has['wifi' + b] ? L('(saved — leave empty to keep)', '(guardada — vacío = no cambia)') : L('Password', 'Contraseña')" :disabled="!form.d['wifi' + b + '_enabled']" /></a-col>
          </a-row>
        </template>
        <a-row :gutter="12" style="margin-top: 8px">
          <a-col :span="6"><a-form-item :label="L('Status', 'Estado')"><a-select v-model:value="form.d.status" :options="[{ value: 'Active', label: L('Active', 'Activa') }, { value: 'Inactive', label: L('Inactive', 'Inactiva') }]" /></a-form-item></a-col>
          <a-col :span="18"><a-form-item :label="L('Notes', 'Notas')"><a-textarea v-model:value="form.d.notes" :rows="2" /></a-form-item></a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- IP list -->
    <a-drawer v-model:open="ips.open" :width="isPhone ? '100%' : '60%'" :title="ips.data ? `${ips.data.network.name} · ${ips.data.network.network_address}/${ips.data.network.cidr}` : ''" destroy-on-close>
      <a-spin :spinning="ips.loading">
        <template v-if="ips.data">
          <div class="it-iptools">
            <a-radio-group v-model:value="ips.show" size="small" button-style="solid">
              <a-radio-button value="all">{{ L('All', 'Todas') }} ({{ ips.data.rows.length }})</a-radio-button>
              <a-radio-button value="used">{{ L('Assigned', 'Asignadas') }} ({{ ips.data.used }})</a-radio-button>
              <a-radio-button value="free">{{ L('Free', 'Libres') }} ({{ ips.data.rows.length - ips.data.used }})</a-radio-button>
            </a-radio-group>
            <a-tag v-if="ips.data.duplicates" color="red">{{ ips.data.duplicates }} {{ L('duplicated', 'duplicadas') }}</a-tag>
            <a-input v-model:value="ips.search" size="small" allow-clear :placeholder="L('Search IP or device', 'Buscar IP o equipo')" style="width: 220px" />
            <a-button v-if="opts.can?.scan" size="small" @click="openScan(ips.row)"><ScanOutlined /> {{ L('Scan this network', 'Escanear esta red') }}</a-button>
          </div>
          <a-table :data-source="ipRows" :columns="ipCols" size="small" row-key="ip" :pagination="{ pageSize: 50, size: 'small' }">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'ip'">
                <span class="it-mono">{{ record.ip }}</span>
                <a-tag v-if="record.gateway" color="blue" class="it-mini">GW</a-tag><a-tag v-if="record.dhcp" class="it-mini">DHCP</a-tag>
              </template>
              <template v-else-if="column.key === 'holder'">
                <div v-for="h in record.holders" :key="h.asset_id + h.iface" :class="{ 'it-dup': record.holders.length > 1 }">
                  <b>{{ h.tag }}</b> <span class="it-muted">{{ h.type }} · {{ h.iface }}{{ h.hostname ? ' · ' + h.hostname : '' }}</span>
                  <a v-if="opts.can?.manage" class="it-x" :title="L('Take off this IP', 'Quitar de esta IP')" @click="unassign(record, h)">✕</a>
                </div>
                <a v-if="!record.holders.length && opts.can?.manage" class="it-muted" @click="assignOpen(record)">+ {{ L('assign', 'asignar') }}</a>
              </template>
              <template v-else-if="column.key === 'seen'">{{ record.last_seen ? dateTime(record.last_seen) : '' }}</template>
              <template v-else-if="column.key === 'hist'">
                <a v-if="record.has_history" @click="history(record)"><HistoryOutlined /></a>
              </template>
            </template>
          </a-table>
        </template>
      </a-spin>
    </a-drawer>

    <a-modal v-model:open="assign.open" :title="L('Assign ', 'Asignar ') + assign.ip" :ok-text="L('Assign', 'Asignar')" :confirm-loading="assign.saving" @ok="assignSave">
      <a-form layout="vertical">
        <a-form-item :label="L('Device', 'Equipo')"><a-select v-model:value="assign.asset_id" show-search option-filter-prop="label" :options="assignDevices" /></a-form-item>
        <a-form-item :label="L('Interface', 'Interfaz')"><a-select v-model:value="assign.iface" :options="ifaceOptions" /></a-form-item>
      </a-form>
    </a-modal>

    <a-modal v-model:open="hist.open" :title="L('History of ', 'Historial de ') + hist.ip" :footer="null" width="760px">
      <a-table :data-source="hist.rows" :columns="histCols" size="small" :pagination="false" row-key="first_at">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'event'"><a-tag :color="{ assigned: 'green', removed: 'red', seen: 'blue' }[record.event]">{{ eventName(record.event) }}</a-tag></template>
          <template v-else-if="column.key === 'when'">{{ dateTime(record.last_at) }}<span v-if="record.times_seen > 1" class="it-muted"> · {{ record.times_seen }}×</span></template>
        </template>
      </a-table>
    </a-modal>

    <ItScanModal v-model:open="scan.open" :network="scan.network" @done="crud.fetchRows()" />
  </div>
</template>

<script setup>
/**
 * Networks IP (itadmin/networks, ItAdminController): list, form with the live subnet calculator, the IP list (assign / take off,
 * each IP's history) and the scan (ItScanModal). The acting company only.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { DeleteOutlined, EditOutlined, HistoryOutlined, PlusOutlined, ScanOutlined, UnorderedListOutlined, WifiOutlined } from '@ant-design/icons-vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useBarTitle } from '../../composables/useBarTitle';
import { useIsPhone } from '../../lib/useIsPhone';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';
import { useTr } from '../../lib/useTr';
import ItScanModal from './ItScanModal.vue';

const L = useTr();
useBarTitle(() => L('Networks IP', 'Redes IP'));
const { dateTime } = useFormat();
const isPhone = useIsPhone();
const route = useRoute();
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };

const filters = reactive({ location_id: route.query.location_id ? Number(route.query.location_id) : undefined, type: undefined });
const crud = useCrudTable('itadmin/networks', { rowsKey: 'networks', sortField: 'location', sortType: 'asc', params: () => ({ ...filters }) });
const opts = ref({});
const assetsAll = ref([]);

const columns = computed(() => [
  { title: L('Network', 'Red'), key: 'name', dataIndex: 'name', sorter: true },
  { title: L('Address', 'Dirección'), key: 'network', dataIndex: 'network_address', sorter: true },
  { title: L('Type', 'Tipo'), key: 'type', dataIndex: 'type', sorter: true },
  { title: 'VLAN', dataIndex: 'vlan', key: 'vlan', sorter: true },
  { title: L('Gateway', 'Puerta de enlace'), dataIndex: 'gateway', key: 'gateway' },
  { title: L('In use', 'En uso'), key: 'use' },
  { title: L('Last scan', 'Último escaneo'), key: 'scan' },
  { title: '', key: 'actions', width: 170, align: 'right' },
]);
const locationOptions = computed(() => sortByLabel((opts.value.locations || []).map(l => ({ value: l.id, label: l.name }))));
const typeOptions = computed(() => (opts.value.network_types || []).map(t => ({ value: t, label: t })));
const deviceOptions = computed(() => sortByLabel(assetsAll.value.filter(a => !form.d.location_id || a.location_id === form.d.location_id)
  .filter(a => ['Firewall', 'Router', 'Switch', 'Access Point', 'Server'].includes(a.type)).map(a => ({ value: a.id, label: `${a.asset_tag} (${a.type})` }))));

async function loadOptions() {
  try {
    opts.value = await http.get('itadmin/options');
    assetsAll.value = (await http.get('itadmin/assets', { limit: -1 })).assets || [];
  } catch (e) { message.error(errorOf(e)); }
}

// ---- form
const blank = () => ({ name: '', location_id: filters.location_id, network: '', type: 'LAN', is_wired: true, is_wifi: false, vlan: '', gateway: '', dns1: '', dns2: '',
  dhcp_enabled: false, dhcp_start: '', dhcp_end: '', linked_asset_id: null, notes: '', status: 'Active',
  wifi24_enabled: false, wifi24_ssid: '', wifi24_password: '', wifi5_enabled: false, wifi5_ssid: '', wifi5_password: '', wifi6_enabled: false, wifi6_ssid: '', wifi6_password: '' });
const form = reactive({ open: false, id: null, saving: false, d: blank(), calc: null, has: {} });
async function openForm(r) {
  form.id = r?.id || null;
  form.d = blank();
  form.has = {};
  form.calc = null;
  if (r) {
    const { network: n } = await http.get(`itadmin/networks/${r.id}`);
    Object.keys(form.d).forEach(k => { if (n[k] !== undefined && n[k] !== null) form.d[k] = typeof form.d[k] === 'boolean' ? !!n[k] : n[k]; });
    form.d.network = `${n.network_address}/${n.cidr}`;
    ['24', '5', '6'].forEach(b => { form.has['wifi' + b] = n[`wifi${b}_has_password`]; form.d[`wifi${b}_password`] = ''; });
    calc();
  }
  form.open = true;
}
let calcT;
function calc() {
  clearTimeout(calcT);
  calcT = setTimeout(async () => { form.calc = form.d.network ? await http.get('itadmin/networks-calc', { network: form.d.network }).catch(() => null) : null; }, 300);
}
async function saveForm() {
  form.saving = true;
  try {
    if (form.id) await http.put(`itadmin/networks/${form.id}`, form.d); else await http.post('itadmin/networks', form.d);
    message.success(L('Saved', 'Guardado'));
    form.open = false;
    crud.fetchRows();
    loadOptions();
  } catch (e) { message.error(errorOf(e)); } finally { form.saving = false; }
}

// ---- IP list
const ips = reactive({ open: false, loading: false, data: null, row: null, show: 'all', search: '' });
async function openIps(r) {
  ips.open = true;
  ips.row = r;
  ips.loading = true;
  try { ips.data = await http.get(`itadmin/networks/${r.id}/ips`); } catch (e) { message.error(errorOf(e)); ips.open = false; } finally { ips.loading = false; }
}
const ipRows = computed(() => {
  const s = ips.search.trim().toLowerCase();
  return (ips.data?.rows || []).filter(r => (ips.show === 'all' || (ips.show === 'used' ? r.holders.length : !r.holders.length))
    && (!s || r.ip.includes(s) || r.holders.some(h => (h.tag + ' ' + (h.hostname || '')).toLowerCase().includes(s))));
});
const ipCols = computed(() => [
  { title: 'IP', key: 'ip', width: 170 }, { title: L('Device', 'Equipo'), key: 'holder' },
  { title: L('Last seen by a scan', 'Visto por un escaneo'), key: 'seen', width: 170 }, { title: '', key: 'hist', width: 40 },
]);
const assign = reactive({ open: false, ip: '', asset_id: null, iface: 'lan', saving: false });
const ifaceOptions = computed(() => [['lan', 'LAN'], ['wifi', 'Wi-Fi'], ['wan', 'WAN / ISP'], ['mgmt', 'MGMT']].map(([v, l]) => ({ value: v, label: l })));
const assignDevices = computed(() => sortByLabel(assetsAll.value.filter(a => a.location_id === ips.row?.location_id).map(a => ({ value: a.id, label: `${a.asset_tag} (${a.type})` }))));
function assignOpen(r) {
  Object.assign(assign, { open: true, ip: r.ip, asset_id: null, iface: (ips.data.network.kinds || ['lan'])[0] || 'lan' });
}
async function assignSave() {
  assign.saving = true;
  try {
    await http.post(`itadmin/networks/${ips.row.id}/ips/assign`, { ip: assign.ip, asset_id: assign.asset_id, iface: assign.iface });
    assign.open = false;
    await openIps(ips.row);
    crud.fetchRows();
  } catch (e) { message.error(errorOf(e)); } finally { assign.saving = false; }
}
function unassign(r, h) {
  Modal.confirm({ title: L(`Take ${h.tag} off ${r.ip}?`, `¿Quitar ${h.tag} de ${r.ip}?`), okText: L('Yes', 'Sí'), cancelText: L('No', 'No'), async onOk() {
    try { await http.post(`itadmin/networks/${ips.row.id}/ips/unassign`, { ip: r.ip, asset_id: h.asset_id }); await openIps(ips.row); crud.fetchRows(); } catch (e) { message.error(errorOf(e)); }
  } });
}
const hist = reactive({ open: false, ip: '', rows: [] });
async function history(r) {
  hist.ip = r.ip;
  hist.rows = (await http.get(`itadmin/networks/${ips.row.id}/ips/history`, { ip: r.ip })).rows || [];
  hist.open = true;
}
const eventName = e => ({ assigned: L('Assigned', 'Asignada'), removed: L('Removed', 'Quitada'), seen: L('Seen', 'Vista') }[e] || e);
const histCols = computed(() => [
  { title: L('Event', 'Evento'), key: 'event', width: 100 }, { title: L('Device', 'Equipo'), dataIndex: 'asset_tag', key: 'tag' },
  { title: 'Hostname', dataIndex: 'hostname', key: 'host' }, { title: 'MAC', dataIndex: 'mac', key: 'mac' },
  { title: L('Source', 'Origen'), dataIndex: 'source', key: 'src' }, { title: L('When', 'Cuándo'), key: 'when', width: 170 },
]);

// ---- scan
const scan = reactive({ open: false, network: null });
function openScan(r) { scan.network = r; scan.open = true; }

onMounted(() => { loadOptions(); crud.fetchRows(); });
</script>

<style scoped>
.it-muted { color: #6b7280; font-size: 12px; }
.it-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.it-calc { display: flex; flex-wrap: wrap; gap: 6px 16px; background: #f0f7ff; border: 1px solid #cfe3ff; border-radius: 6px; padding: 6px 10px; margin: -6px 0 10px; font-size: 12px; }
.it-sub { font-weight: 700; margin: 4px 0 6px; }
.it-iptools { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
.it-mini { font-size: 10px; line-height: 16px; padding: 0 4px; margin-left: 4px; }
.it-dup { color: #b91c1c; }
.it-x { margin-left: 6px; color: #9ca3af; }
.it-x:hover { color: #dc2626; }
.it-form :deep(.ant-form-item) { margin-bottom: 10px; }
</style>
