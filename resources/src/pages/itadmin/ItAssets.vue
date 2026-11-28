<template>
  <div class="page">
    <!-- IT Admin -> Assets (2026-11-28, from HTD-IT): every device by category, in the network flow order; the record opens on the right. -->
    <div class="as-cats">
      <router-link v-for="c in cats" :key="c.key" :to="c.key ? `/itadmin/assets/${c.key}` : '/itadmin/assets'" class="as-cat" :class="{ on: (category || '') === c.key }">
        {{ c.label }} <b>{{ c.count }}</b>
      </router-link>
    </div>
    <a-row :gutter="12">
    <a-col :xs="24" :xl="form.open && !isPhone ? 12 : 24">
    <DataTable :crud="crud" :columns="columns">
      <template #filters>
        <a-select v-model:value="filters.location_id" allow-clear :placeholder="L('Location', 'Ubicación')" :options="locationOptions" style="width: 170px" @change="crud.reload()" />
        <a-select v-model:value="filters.status" allow-clear :placeholder="L('Status', 'Estado')" :options="statusOptions" style="width: 130px" @change="crud.reload()" />
        <a-button v-if="opts.can?.manage" type="primary" @click="openForm()"><PlusOutlined /> {{ L('Add new', 'Agregar nuevo') }}</a-button>
      </template>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'asset_tag'">
          <a @click="openForm(record)"><b>{{ record.asset_tag }}</b></a>
          <div class="as-muted">{{ record.type }}{{ record.hostname ? ' · ' + record.hostname : '' }}</div>
        </template>
        <template v-else-if="column.key === 'model'">{{ [record.manufacturer, record.model].filter(Boolean).join(' ') || '—' }}<div class="as-muted">{{ record.serial_number }}</div></template>
        <template v-else-if="column.key === 'ip_address'">
          <span class="as-mono" :class="{ 'as-dup': record.duplicate_ip }">{{ record.ip_address || (record.no_ip ? L('no IP', 'sin IP') : '') }}</span>
          <div v-if="record.wifi_ip_address" class="as-mono as-muted">Wi-Fi {{ record.wifi_ip_address }}</div>
          <div v-if="record.macs?.length" class="as-mono as-muted">{{ record.macs[0].mac }}{{ record.macs.length > 1 ? ' +' + (record.macs.length - 1) : '' }}</div>
        </template>
        <template v-else-if="column.key === 'where'">{{ record.location || '—' }}<div class="as-muted">{{ record.employee || record.assigned_note || '' }}</div></template>
        <template v-else-if="column.key === 'status'"><a-tag :color="{ Active: 'green', Spare: 'blue', 'In Storage': 'default', 'In Repair': 'orange', Retired: 'red' }[record.status]">{{ statusName(record.status) }}</a-tag></template>
        <template v-else-if="column.key === 'warranty_end'">{{ record.warranty_end ? date(record.warranty_end) : '' }}</template>
      </template>
    </DataTable>
    </a-col>

    <DetailPanel v-if="form.open" :open="form.open" :xs="24" :xl="12" @close="form.open = false">
      <div class="as-panel">
        <div class="as-ptitle">
          <b>{{ form.id ? form.d.asset_tag : L('New device', 'Nuevo equipo') }}</b>
          <a-space>
            <a-button v-if="form.id && opts.can?.manage" danger size="small" @click="remove"><DeleteOutlined /></a-button>
            <a-button v-if="opts.can?.manage" type="primary" size="small" :loading="form.saving" @click="save">{{ L('Save', 'Guardar') }}</a-button>
            <a-button size="small" @click="form.open = false">✕</a-button>
          </a-space>
        </div>
        <a-form layout="vertical" class="as-form" :disabled="!opts.can?.manage">
          <a-row :gutter="10">
            <a-col :span="8"><a-form-item :label="L('Asset tag', 'Etiqueta')" required><a-input v-model:value="form.d.asset_tag" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="L('Type', 'Tipo')" required><a-select v-model:value="form.d.type" show-search :options="typeOptions" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="L('Status', 'Estado')"><a-select v-model:value="form.d.status" :options="statusOptions" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="L('Location', 'Ubicación')" required><a-select v-model:value="form.d.location_id" :options="locationOptions" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="L('Manufacturer', 'Fabricante')"><a-input v-model:value="form.d.manufacturer" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="L('Model', 'Modelo')"><a-input v-model:value="form.d.model" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="L('Serial number', 'N.º de serie')"><a-input v-model:value="form.d.serial_number" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="L('Assigned to', 'Asignado a')"><a-select v-model:value="form.d.employee_id" allow-clear show-search option-filter-prop="label" :options="employeeOptions" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="L('Work mode', 'Modalidad')"><a-select v-model:value="form.d.work_mode" :options="(opts.work_modes || []).map(v => ({ value: v, label: v }))" /></a-form-item></a-col>
          </a-row>

          <div class="as-sub">{{ L('Network', 'Red') }}</div>
          <a-row v-for="r in IPROWS" :key="r.ip" :gutter="10" align="bottom">
            <a-col :span="5"><div class="as-iflabel">{{ r.label }}</div></a-col>
            <a-col :span="9"><a-form-item :label="r.ip === 'ip_address' ? 'IP' : ''"><a-input v-model:value="form.d[r.ip]" class="as-mono" :placeholder="r.ip === 'ip_address' && form.d.no_ip ? L('no IP', 'sin IP') : ''" /></a-form-item></a-col>
            <a-col :span="10"><a-form-item :label="r.ip === 'ip_address' ? L('Network (empty = found by the IP)', 'Red (vacío = la de la IP)') : ''">
              <a-select v-model:value="form.d[r.net]" allow-clear :options="networkOptions(r.kind)" /></a-form-item></a-col>
          </a-row>
          <a-row :gutter="10">
            <a-col :span="8"><a-checkbox v-model:checked="form.d.no_ip">{{ L('Has no IP (e.g. an unmanaged switch)', 'No tiene IP (ej. switch no administrable)') }}</a-checkbox></a-col>
            <a-col :span="8"><a-form-item :label="L('Connected to (uplink)', 'Conectado a (uplink)')"><a-select v-model:value="form.d.uplink_asset_id" allow-clear show-search option-filter-prop="label" :options="uplinkOptions" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="L('Uplink medium', 'Medio del uplink')"><a-select v-model:value="form.d.uplink_medium" allow-clear :options="(opts.uplink_media || []).map(v => ({ value: v, label: v }))" /></a-form-item></a-col>
          </a-row>

          <div class="as-sub">MAC <a v-if="opts.can?.manage" @click="form.d.macs.push({ mac: '', label: '' })">+</a></div>
          <a-row v-for="(m, i) in form.d.macs" :key="'m' + i" :gutter="10">
            <a-col :span="10"><a-input v-model:value="m.mac" class="as-mono" placeholder="AA-BB-CC-DD-EE-FF" /></a-col>
            <a-col :span="10"><a-auto-complete v-model:value="m.label" :options="['LAN', 'Wi-Fi', 'MGMT', 'Ethernet'].map(v => ({ value: v }))" :placeholder="L('Label', 'Etiqueta')" style="width: 100%" /></a-col>
            <a-col :span="4"><a @click="form.d.macs.splice(i, 1)">✕</a></a-col>
          </a-row>
          <div class="as-sub">{{ L('Additional IPs', 'IPs adicionales') }} <a v-if="opts.can?.manage" @click="form.d.extra_ips.push({ ip_address: '', network_id: null, label: '' })">+</a></div>
          <a-row v-for="(x, i) in form.d.extra_ips" :key="'x' + i" :gutter="10">
            <a-col :span="7"><a-input v-model:value="x.ip_address" class="as-mono" /></a-col>
            <a-col :span="8"><a-select v-model:value="x.network_id" allow-clear :options="networkOptions()" style="width: 100%" /></a-col>
            <a-col :span="7"><a-input v-model:value="x.label" :placeholder="L('Label', 'Etiqueta')" /></a-col>
            <a-col :span="2"><a @click="form.d.extra_ips.splice(i, 1)">✕</a></a-col>
          </a-row>

          <template v-if="typeFields.length">
            <div class="as-sub">{{ form.d.type }}</div>
            <a-row :gutter="10">
              <a-col v-for="f in typeFields" :key="f.key" :span="8">
                <a-form-item :label="f.label">
                  <a-select v-if="f.type === 'select'" v-model:value="form.d.details[f.key]" allow-clear :options="f.options.map(o => ({ value: o, label: o }))" />
                  <a-input-number v-else-if="f.type === 'number'" v-model:value="form.d.details[f.key]" style="width: 100%" />
                  <a-date-picker v-else-if="f.type === 'date'" v-model:value="form.d.details[f.key]" value-format="YYYY-MM-DD" style="width: 100%" />
                  <a-input v-else v-model:value="form.d.details[f.key]" />
                </a-form-item>
              </a-col>
            </a-row>
          </template>

          <a-row :gutter="10">
            <a-col :span="8"><a-form-item :label="L('Purchase date', 'Fecha de compra')"><a-date-picker v-model:value="form.d.purchase_date" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="L('Warranty until', 'Garantía hasta')"><a-date-picker v-model:value="form.d.warranty_end" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="L('Assigned note', 'Nota de asignación')"><a-input v-model:value="form.d.assigned_note" /></a-form-item></a-col>
            <a-col :span="24"><a-form-item :label="L('Notes', 'Notas')"><a-textarea v-model:value="form.d.notes" :rows="2" /></a-form-item></a-col>
          </a-row>
          <div v-if="form.source" class="as-source"><div class="as-sub">{{ L('Source of the changes', 'Origen de los cambios') }}</div><pre>{{ form.source }}</pre></div>
        </a-form>
      </div>
    </DetailPanel>
    </a-row>
  </div>
</template>

<script setup>
/** IT assets (itadmin/assets, ItAdminController): categories, the list and the record on the right (type fields, IP rows, MACs, additional IPs). */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons-vue';
import DataTable from '../../components/DataTable.vue';
import DetailPanel from '../../components/DetailPanel.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useBarTitle } from '../../composables/useBarTitle';
import { sortByLabel } from '../../lib/filterOptions';
import { useIsPhone } from '../../lib/useIsPhone';
import http from '../../lib/http';
import { useTr } from '../../lib/useTr';

const L = useTr();
const isPhone = useIsPhone();
const { date } = useFormat();
const route = useRoute();
const router = useRouter();
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };
const category = computed(() => route.params.category || '');
const CAT_ES = { firewalls: 'Firewalls', network: 'Equipos de red', servers: 'Servidores', computers: 'Computadoras', printers: 'Impresoras', phones: 'Teléfonos', mobiles: 'Móviles', devices: 'Otros equipos' };
useBarTitle(() => (category.value ? L((opts.value.categories || []).find(c => c.key === category.value)?.label || '', CAT_ES[category.value]) : L('Assets', 'Equipos')));

const opts = ref({});
const networks = computed(() => opts.value.networks || []);
const filters = reactive({ location_id: undefined, status: undefined });
const crud = useCrudTable('itadmin/assets', { rowsKey: 'assets', sortField: 'asset_tag', sortType: 'asc', params: () => ({ ...filters, category: category.value || undefined }) });
const allAssets = ref([]);

const cats = computed(() => {
  const counts = crud.payload.value?.counts || {};
  return [{ key: '', label: L('All', 'Todos'), count: Object.values(counts).reduce((s, n) => s + n, 0) },
    ...(opts.value.categories || []).map(c => ({ key: c.key, label: L(c.label, CAT_ES[c.key]), count: counts[c.key] || 0 }))];
});
const columns = computed(() => [
  { title: L('Device', 'Equipo'), key: 'asset_tag', dataIndex: 'asset_tag', sorter: true },
  { title: L('Model', 'Modelo'), key: 'model', dataIndex: 'model', sorter: true },
  { title: 'IP / MAC', key: 'ip_address', dataIndex: 'ip_address', sorter: true },
  { title: L('Location / assigned', 'Ubicación / asignado'), key: 'where' },
  { title: L('Warranty', 'Garantía'), key: 'warranty_end', dataIndex: 'warranty_end', sorter: true },
  { title: L('Status', 'Estado'), key: 'status', dataIndex: 'status', sorter: true },
]);
const ST_ES = { Active: 'Activo', Spare: 'De repuesto', 'In Storage': 'En almacén', 'In Repair': 'En reparación', Retired: 'Retirado' };
const statusName = s => L(s, ST_ES[s] || s);
const statusOptions = computed(() => (opts.value.statuses || []).map(s => ({ value: s, label: statusName(s) })));
const locationOptions = computed(() => sortByLabel((opts.value.locations || []).map(l => ({ value: l.id, label: l.name }))));
const employeeOptions = computed(() => sortByLabel((opts.value.employees || []).map(e => ({ value: e.id, label: e.name }))));
const typeOptions = computed(() => (opts.value.types || []).map(t => ({ value: t.type, label: t.type })));
const typeFields = computed(() => (opts.value.types || []).find(t => t.type === form.d.type)?.fields || []);
const IPROWS = [{ ip: 'ip_address', net: 'ip_network_id', label: 'LAN', kind: 'lan' }, { ip: 'wifi_ip_address', net: 'wifi_network_id', label: 'Wi-Fi', kind: 'wifi' },
  { ip: 'wan_ip_address', net: 'wan_network_id', label: 'WAN / ISP', kind: 'wan' }, { ip: 'mgmt_ip_address', net: 'mgmt_network_id', label: 'MGMT', kind: 'lan' }];
function networkOptions(kind) {
  return networks.value.filter(n => !form.d.location_id || n.location_id === form.d.location_id)
    .filter(n => !kind || (kind === 'wan' ? n.type === 'WAN' : kind === 'wifi' ? n.is_wifi : n.type !== 'WAN' && n.is_wired))
    .map(n => ({ value: n.id, label: `${n.name} · ${n.network_address}/${n.cidr}` }));
}
const uplinkOptions = computed(() => sortByLabel(allAssets.value.filter(a => a.id !== form.id && (!form.d.location_id || a.location_id === form.d.location_id))
  .filter(a => ['Firewall', 'Router', 'Switch'].includes(a.type)).map(a => ({ value: a.id, label: `${a.asset_tag} (${a.type})` }))));

const blank = () => ({ asset_tag: '', type: (opts.value.categories || []).find(c => c.key === category.value)?.types?.[0] || 'Computer', status: 'Active',
  location_id: filters.location_id, manufacturer: '', model: '', serial_number: '', employee_id: null, work_mode: 'Office',
  ip_address: '', ip_network_id: null, wifi_ip_address: '', wifi_network_id: null, wan_ip_address: '', wan_network_id: null, mgmt_ip_address: '', mgmt_network_id: null,
  no_ip: false, uplink_asset_id: null, uplink_medium: null, purchase_date: null, warranty_end: null, assigned_note: '', notes: '', details: {}, macs: [], extra_ips: [] });
const form = reactive({ open: false, id: null, saving: false, d: blank(), source: '' });
async function openForm(r, prefill = null) {
  form.id = r?.id || null;
  form.d = blank();
  form.source = '';
  if (r) {
    const { asset: a } = await http.get(`itadmin/assets/${r.id}`);
    Object.keys(form.d).forEach(k => { if (a[k] !== undefined && a[k] !== null) form.d[k] = a[k]; });
    form.d.no_ip = !!a.no_ip;
    form.d.details = { ...(a.details || {}) };
    form.d.macs = (a.macs || []).map(m => ({ mac: m.mac, label: m.label }));
    form.d.extra_ips = (a.extra_ips || []).map(x => ({ ip_address: x.ip_address, network_id: x.network_id, label: x.label }));
    form.source = a.source_note || '';
  }
  if (prefill) Object.assign(form.d, prefill);
  form.open = true;
}
async function save() {
  form.saving = true;
  try {
    const body = { ...form.d, macs: form.d.macs.filter(m => m.mac), extra_ips: form.d.extra_ips.filter(x => x.ip_address) };
    const res = form.id ? await http.put(`itadmin/assets/${form.id}`, body) : await http.post('itadmin/assets', body);
    message.success(L('Saved', 'Guardado'));
    form.id = res.id;
    crud.fetchRows();
    loadAll();
  } catch (e) { message.error(errorOf(e)); } finally { form.saving = false; }
}
function remove() {
  Modal.confirm({ title: L(`Delete ${form.d.asset_tag}?`, `¿Eliminar ${form.d.asset_tag}?`), content: L('Its IPs stay in the IP history.', 'Sus IPs quedan en el historial de IPs.'),
    okType: 'danger', okText: L('Delete', 'Eliminar'), cancelText: L('Cancel', 'Cancelar'), async onOk() {
      try { await http.delete(`itadmin/assets/${form.id}`); form.open = false; crud.fetchRows(); loadAll(); } catch (e) { message.error(errorOf(e)); }
    } });
}
async function loadAll() { try { allAssets.value = (await http.get('itadmin/assets', { limit: -1 })).assets || []; } catch (e) { /* lists only */ } }

watch(category, () => crud.reload());
onMounted(async () => {
  try { opts.value = await http.get('itadmin/options'); } catch (e) { message.error(errorOf(e)); }
  await crud.fetchRows();
  loadAll();
  // From a scan's review: "Create device" with what the scan saw.
  if (route.query.create) {
    const net = networks.value.find(n => n.id === Number(route.query.network_id));
    openForm(null, { ip_address: route.query.ip || '', ip_network_id: net?.id || null, location_id: net?.location_id,
      macs: route.query.mac ? [{ mac: route.query.mac, label: 'LAN' }] : [], details: route.query.hostname ? { hostname: route.query.hostname } : {},
      manufacturer: route.query.vendor || '', asset_tag: route.query.hostname ? String(route.query.hostname).split('.')[0].toUpperCase() : '' });
    router.replace({ query: {} });
  } else if (route.query.open) {
    openForm({ id: Number(route.query.open) });
  }
});
</script>

<style scoped>
.as-cats { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
.as-cat { border: 1px solid #e5e7eb; border-radius: 16px; padding: 3px 12px; font-size: 12px; color: #374151; background: #fff; }
.as-cat b { margin-left: 4px; color: #6b7280; }
.as-cat.on { border-color: #1677ff; background: #e6f4ff; color: #0958d9; }
.as-muted { color: #6b7280; font-size: 12px; }
.as-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.as-dup { color: #dc2626; font-weight: 700; }
.as-panel { padding: 10px 12px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; max-height: calc(100vh - 150px); overflow-y: auto; }
.as-ptitle { display: flex; justify-content: space-between; align-items: center; font-size: 16px; margin-bottom: 8px; }
.as-sub { font-weight: 700; margin: 6px 0 4px; font-size: 13px; }
.as-sub a { margin-left: 6px; }
.as-iflabel { font-size: 12px; color: #4b5563; padding-bottom: 14px; }
.as-form :deep(.ant-form-item) { margin-bottom: 8px; }
.as-source pre { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 6px; font-size: 11px; white-space: pre-wrap; }
</style>
