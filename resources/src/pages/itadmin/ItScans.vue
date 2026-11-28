<template>
  <div class="page">
    <!-- Scan imports (2026-11-28, from HTD-IT): every uploaded scan; its review compares what was found with the devices. -->
    <template v-if="!scanId">
      <DataTable :crud="crud" :columns="columns">
        <template #filters>
          <a-radio-group v-model:value="filters.status" button-style="solid" @change="crud.reload()">
            <a-radio-button value="">{{ L('All', 'Todos') }}</a-radio-button>
            <a-radio-button value="uploaded">{{ L('To review', 'Por revisar') }}</a-radio-button>
            <a-radio-button value="reviewed">{{ L('Reviewed', 'Revisados') }}</a-radio-button>
            <a-radio-button value="discarded">{{ L('Discarded', 'Descartados') }}</a-radio-button>
          </a-radio-group>
        </template>
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'network'">
            <router-link :to="`/itadmin/scans/${record.id}`"><b>{{ record.network }}</b></router-link>
            <div class="sc-muted">{{ record.location }} · <span class="sc-mono">{{ record.network_address }}/{{ record.cidr }}</span></div>
          </template>
          <template v-else-if="column.key === 'when'">{{ dateTime(record.uploaded_at) }}</template>
          <template v-else-if="column.key === 'pc'">{{ record.scanner_computer || '—' }}<div class="sc-muted">{{ record.directly_connected ? L('on the network', 'en la red') : L('not on the network (no MACs)', 'fuera de la red (sin MAC)') }}</div></template>
          <template v-else-if="column.key === 'found'">{{ record.found_count }} / {{ record.hosts_scanned }}</template>
          <template v-else-if="column.key === 'status'"><a-tag :color="{ uploaded: 'orange', reviewed: 'green', discarded: 'default' }[record.status]">{{ statusName(record.status) }}</a-tag></template>
        </template>
      </DataTable>
    </template>

    <a-spin v-else :spinning="loading">
      <template v-if="data">
        <div class="sc-head">
          <a-button @click="$router.push('/itadmin/scans')"><ArrowLeftOutlined /></a-button>
          <div>
            <div class="sc-title">{{ data.network.name }} <span class="sc-mono">{{ data.network.network_address }}/{{ data.network.cidr }}</span></div>
            <div class="sc-muted">{{ data.network.location }} · {{ L('scan', 'escaneo') }} #{{ data.scan.id }} · {{ dateTime(data.scan.uploaded_at) }} · {{ data.scan.scanner_computer || '—' }}
              · <a-tag :color="{ uploaded: 'orange', reviewed: 'green', discarded: 'default' }[data.scan.status]">{{ statusName(data.scan.status) }}</a-tag></div>
          </div>
          <a-space class="sc-actions" v-if="can.scan && data.scan.status === 'uploaded'">
            <a-button @click="close('discard')">{{ L('Discard', 'Descartar') }}</a-button>
            <a-button type="primary" @click="close('reviewed')"><CheckOutlined /> {{ L('Mark as reviewed', 'Marcar como revisado') }}</a-button>
          </a-space>
        </div>
        <div class="sc-kinds">
          <a v-for="k in KINDS" :key="k" class="sc-kind" :class="['k-' + k, { on: kind === k }]" @click="kind = kind === k ? '' : k">
            <b>{{ data.counts[k] || 0 }}</b> {{ kindName(k) }}
          </a>
        </div>
        <a-table :data-source="rows" :columns="rowCols" size="small" :pagination="{ pageSize: 100, size: 'small' }" :row-key="r => (r.id || 'm') + r.ip + (r.asset_id || '')">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'ip'"><span class="sc-mono">{{ record.ip }}</span></template>
            <template v-else-if="column.key === 'kind'"><a-tag :color="KCOLOR[record.kind]">{{ kindName(record.kind) }}</a-tag></template>
            <template v-else-if="column.key === 'found'">
              <div class="sc-mono">{{ record.mac || '' }}</div>
              <div>{{ record.hostname || '' }}</div>
              <div class="sc-muted">{{ record.vendor || '' }}</div>
            </template>
            <template v-else-if="column.key === 'asset'">
              <router-link v-if="record.asset_id" :to="`/itadmin/assets?open=${record.asset_id}`"><b>{{ record.asset }}</b></router-link>
              <span v-if="record.asset_type" class="sc-muted"> {{ record.asset_type }}</span>
            </template>
            <template v-else-if="column.key === 'what'">
              <div v-if="record.problem" class="sc-problem">{{ record.problem }}</div>
              <div v-for="(c, w) in record.changes" :key="w" class="sc-change">{{ changeText(w, c) }}</div>
              <div v-if="record.conflict?.useNew" class="sc-muted">{{ L('Use new:', 'Usar lo nuevo:') }} {{ record.conflict.useNew }}</div>
              <div v-if="record.kind === 'missing'" class="sc-muted">{{ L('Registered here but did not answer.', 'Registrado aquí pero no respondió.') }}</div>
            </template>
            <template v-else-if="column.key === 'act' && can.scan && data.scan.status !== 'discarded' && record.id">
              <a-space>
                <a-button v-if="record.kind === 'changed'" size="small" type="primary" @click="act('apply', record)">{{ L('Apply', 'Aplicar') }}</a-button>
                <a-button v-if="record.kind === 'conflict' && record.conflict?.useNew" size="small" type="primary" @click="act('use-new', record)">{{ L('Use new', 'Usar lo nuevo') }}</a-button>
                <a-button v-if="record.kind === 'new' && can.manage" size="small" @click="createFrom(record)">{{ L('Create device', 'Crear equipo') }}</a-button>
                <a-button v-if="['new', 'changed', 'conflict'].includes(record.kind)" size="small" @click="act('ignore', record)">{{ L('Ignore', 'Ignorar') }}</a-button>
                <a-button v-if="record.kind === 'ignored'" size="small" @click="act('ignore', record, true)">{{ L('Undo ignore', 'Deshacer') }}</a-button>
              </a-space>
            </template>
          </template>
        </a-table>
      </template>
    </a-spin>
  </div>
</template>

<script setup>
/** Scan imports (itadmin/scans, NetworkScanController): the list and one scan's review (apply / use new / ignore / create device). */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { ArrowLeftOutlined, CheckOutlined } from '@ant-design/icons-vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useBarTitle } from '../../composables/useBarTitle';
import http from '../../lib/http';
import { useTr } from '../../lib/useTr';

const L = useTr();
useBarTitle(() => L('Scan imports', 'Escaneos importados'));
const { dateTime } = useFormat();
const route = useRoute();
const router = useRouter();
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };
const scanId = computed(() => (route.params.id ? Number(route.params.id) : null));

const filters = reactive({ status: '', network_id: route.query.network_id || undefined });
const crud = useCrudTable('itadmin/scans', { rowsKey: 'scans', params: () => ({ ...filters }) });
const columns = computed(() => [
  { title: L('Network', 'Red'), key: 'network' }, { title: L('Uploaded', 'Subido'), key: 'when' },
  { title: L('Computer', 'Computadora'), key: 'pc' }, { title: L('Found / IPs', 'Encontrados / IPs'), key: 'found' },
  { title: L('By', 'Por'), dataIndex: 'username', key: 'by' }, { title: L('Status', 'Estado'), key: 'status' },
]);
const statusName = s => ({ uploaded: L('To review', 'Por revisar'), reviewed: L('Reviewed', 'Revisado'), discarded: L('Discarded', 'Descartado') }[s] || s);

const KINDS = ['conflict', 'changed', 'new', 'missing', 'ok', 'ignored'];
const KCOLOR = { conflict: 'red', changed: 'orange', new: 'blue', missing: 'purple', ok: 'green', ignored: 'default' };
const kindName = k => ({ conflict: L('Conflict', 'Conflicto'), changed: L('Changed', 'Cambió'), new: L('New', 'Nuevo'), missing: L('Not found', 'No respondió'),
  ok: 'OK', ignored: L('Ignored', 'Ignorado') }[k] || k);
const data = ref(null);
const loading = ref(false);
const kind = ref('');
const can = ref({});
const rows = computed(() => (data.value?.rows || []).filter(r => !kind.value || r.kind === kind.value));
const rowCols = computed(() => [
  { title: 'IP', key: 'ip', width: 130 }, { title: '', key: 'kind', width: 110 }, { title: L('Found', 'Encontrado'), key: 'found' },
  { title: L('Device', 'Equipo'), key: 'asset' }, { title: L('What it means', 'Qué significa'), key: 'what' }, { title: '', key: 'act', width: 260, align: 'right' },
]);
function changeText(w, c) {
  if (w === 'ip') return `IP ${c.from || '—'} → ${c.to} (${c.iface})`;
  if (w === 'mac') return L(`New MAC ${c.to}`, `MAC nueva ${c.to}`) + (c.label ? ` (${c.label})` : '');
  return `Hostname ${c.from || '—'} → ${c.to}`;
}
async function load() {
  if (!scanId.value) return;
  loading.value = true;
  try { data.value = await http.get(`itadmin/scans/${scanId.value}`); } catch (e) { message.error(errorOf(e)); } finally { loading.value = false; }
}
async function act(action, r, undo = false) {
  try {
    await http.post(`itadmin/scans/${scanId.value}/${action}`, { id: r.id, undo: undo ? 1 : 0 });
    await load();
  } catch (e) { message.error(errorOf(e)); }
}
async function close(action) {
  try { await http.post(`itadmin/scans/${scanId.value}/close/${action}`); message.success(L('Done', 'Listo')); router.push('/itadmin/scans'); } catch (e) { message.error(errorOf(e)); }
}
function createFrom(r) {
  router.push({ path: '/itadmin/assets', query: { create: 1, ip: r.ip, mac: r.mac || undefined, hostname: r.hostname || undefined, vendor: r.vendor || undefined,
    network_id: data.value.network.id } });
}
watch(scanId, id => { if (id) load(); else crud.fetchRows(); });
onMounted(async () => {
  try { can.value = (await http.get('itadmin/options')).can || {}; } catch (e) { /* read only */ }
  if (scanId.value) load(); else crud.fetchRows();
});
</script>

<style scoped>
.sc-muted { color: #6b7280; font-size: 12px; }
.sc-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.sc-head { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; flex-wrap: wrap; }
.sc-title { font-size: 18px; font-weight: 800; }
.sc-actions { margin-left: auto; }
.sc-kinds { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
.sc-kind { border: 1px solid #e5e7eb; border-radius: 6px; padding: 4px 10px; color: #374151; font-size: 12px; }
.sc-kind.on { border-color: #1677ff; background: #e6f4ff; }
.sc-kind b { font-size: 14px; margin-right: 2px; }
.k-conflict b { color: #dc2626; } .k-changed b { color: #ea580c; } .k-new b { color: #2563eb; } .k-missing b { color: #7c3aed; } .k-ok b { color: #16a34a; }
.sc-problem { color: #b91c1c; font-size: 12px; }
.sc-change { font-size: 12px; color: #9a3412; }
</style>
