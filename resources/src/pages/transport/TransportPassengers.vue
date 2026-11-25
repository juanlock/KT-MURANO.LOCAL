<template>
  <!-- Pasajeros -> Base de pasajeros (2026-11-24): AuraSoft's buyers in their own table (transport_passengers), never in
       Customers -- DNI / RUC from the Liquidación Detallada, phone from the RedBus / web sales report. Server:
       TransportOperationsController::passengers. -->
  <div class="page tpx">
    <div v-if="sum" class="held">
      <span><b>{{ num(sum.n) }}</b> {{ L('passengers', 'pasajeros') }}</span>
      <span><b>{{ num(sum.companies) }}</b> {{ L('companies (RUC)', 'empresas (RUC)') }}</span>
      <span><b>{{ num(sum.with_phone) }}</b> {{ L('with phone', 'con celular') }}</span>
      <span><b>{{ num(sum.no_doc) }}</b> {{ L('without DNI (name + phone only)', 'sin DNI (solo nombre y celular)') }}</span>
      <span>{{ L('Passengers', 'Pasajeros') }} <b>{{ num(sum.k_passenger) }}</b> · {{ L('Parcels', 'Encomiendas') }} <b>{{ num(sum.k_parcel) }}</b> · {{ L('Both', 'Ambos') }} <b>{{ num(sum.k_both) }}</b></span>
    </div>
    <a-card size="small">
      <DataTable :crud="crud" :columns="columns" compact-toolbar table-key="transport-passengers">
        <template #filters>
          <a-segmented v-model:value="type" size="small" :options="typeOptions" @change="reload" />
          <a-segmented v-model:value="kind" size="small" :options="kindOptions" @change="reload" />
        </template>
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'">
            <span class="tag" :class="record.is_company ? 'ruc' : (record.doc ? 'dni' : 'nod')">{{ record.is_company ? 'RUC' : (record.doc ? 'DNI' : '—') }}</span> {{ record.name }}
          </template>
          <template v-else-if="column.key === 'phone'">
            <template v-if="record.phone">
              <a :href="`https://wa.me/${record.phone}`" target="_blank" rel="noopener" class="wa"><WhatsAppOutlined /></a> +{{ record.phone }}
              <a-tooltip v-if="record.phones?.length > 1" :title="record.phones.map(p => '+' + p).join(', ')"><span class="muted"> (+{{ record.phones.length - 1 }})</span></a-tooltip>
            </template>
            <span v-else class="muted">—</span>
          </template>
          <template v-else-if="['first_purchase', 'last_purchase', 'last_online'].includes(column.key)">{{ record[column.key] ? date(record[column.key]) : '—' }}</template>
          <template v-else-if="column.key === 'kind'"><span class="k" :class="'k-' + record.kind">{{ typeLabel(record.kind) }}</span></template>
          <template v-else-if="column.key === 'amount'"><span class="n">{{ money(record.amount) }}</span></template>
        </template>
      </DataTable>
    </a-card>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { WhatsAppOutlined } from '@ant-design/icons-vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useBarTitle } from '../../composables/useBarTitle';
import { useUiStore } from '../../stores/ui';

const ui = useUiStore();
const L = (en, es) => (String(ui.locale || 'en').startsWith('es') ? es : en);
const { date } = useFormat();
const num = v => Number(v || 0).toLocaleString('en-US');
const money = v => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const kind = ref('all');
const kindOptions = computed(() => [
  { value: 'all', label: L('All', 'Todos') }, { value: 'person', label: L('People', 'Personas') }, { value: 'company', label: L('Companies', 'Empresas') },
  { value: 'phone', label: L('With phone', 'Con celular') }, { value: 'nodoc', label: L('Without DNI', 'Sin DNI') },
]);
// Pasajero / Encomienda / Ambos (2026-11-24): one record per DNI, whatever he did.
const type = ref('all');
const typeLabel = k => ({ passenger: L('Passenger', 'Pasajero'), parcel: L('Parcels', 'Encomienda'), both: L('Both', 'Ambos') }[k] || k);
const typeOptions = computed(() => [{ value: 'all', label: L('Any type', 'Todo tipo') },
  ...['passenger', 'parcel', 'both'].map(k => ({ value: k, label: typeLabel(k) }))]);
const crud = useCrudTable('transport/passengers', { rowsKey: 'rows', sortField: 'last_purchase', sortType: 'desc', limit: 50,
  params: () => ({ kind: kind.value === 'all' ? undefined : kind.value, type: type.value === 'all' ? undefined : type.value }) });
const sum = computed(() => crud.payload.value?.summary || null);
function reload() { crud.page.value = 1; crud.fetchRows(); }
const columns = computed(() => [
  { title: L('Passenger', 'Pasajero'), key: 'name', dataIndex: 'name', sorter: true, ellipsis: { showTitle: true } },
  { title: 'DNI / RUC', key: 'doc', dataIndex: 'doc', sorter: true, width: 116 },
  { title: L('Type', 'Tipo'), key: 'kind', dataIndex: 'kind', width: 104 },
  { title: L('Phone', 'Celular'), key: 'phone', dataIndex: 'phone', sorter: true, width: 170 },
  { title: L('First purchase', 'Primera compra'), key: 'first_purchase', dataIndex: 'first_purchase', sorter: true, width: 118 },
  { title: L('Last purchase', 'Última compra'), key: 'last_purchase', dataIndex: 'last_purchase', sorter: true, width: 118 },
  { title: L('Tickets', 'Pasajes'), key: 'tickets', dataIndex: 'tickets', sorter: true, width: 80, align: 'right' },
  { title: L('Parcels', 'Encom.'), key: 'parcels', dataIndex: 'parcels', sorter: true, width: 76, align: 'right' },
  { title: 'S/', key: 'amount', dataIndex: 'amount', sorter: true, width: 104, align: 'right' },
  { title: L('Online tickets', 'Pasajes en línea'), key: 'online_tickets', dataIndex: 'online_tickets', sorter: true, width: 110, align: 'right' },
]);
useBarTitle(L('Passenger base', 'Base de pasajeros'));
onMounted(() => crud.fetchRows());
</script>

<style scoped>
.held { display: flex; flex-wrap: wrap; gap: 4px 18px; font-size: 13px; padding: 6px 10px; margin-bottom: 10px; border-radius: 4px;
  background: rgba(var(--app-primary-rgb, 0, 120, 212), 0.08); }
.tag { font-size: 10px; padding: 0 4px; border-radius: 3px; }
.tag.ruc { background: rgba(58, 79, 196, 0.14); color: #3A4FC4; }
.tag.dni { background: rgba(128, 128, 128, 0.16); }
.tag.nod { background: rgba(230, 108, 55, 0.15); color: #C2410C; }
.k { font-size: 11px; padding: 0 6px; border-radius: 8px; }
.k-passenger { background: rgba(17, 141, 255, 0.12); color: #0b6fd0; }
.k-parcel { background: rgba(230, 108, 55, 0.15); color: #C2410C; }
.k-both { background: rgba(142, 44, 158, 0.14); color: #8E2C9E; }
.wa { color: #25D366; margin-right: 4px; }
.muted { opacity: 0.55; }
.n { font-variant-numeric: tabular-nums; }
</style>
