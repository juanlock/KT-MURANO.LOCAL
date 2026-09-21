<template>
  <div class="page">
    <PageHeader :title="$t('Webhooks')" :breadcrumb="[$t('Settings'), $t('Webhooks')]">
      <template #actions>
        <a-button v-if="tab === 'list'" type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-tabs v-model:active-key="tab">
      <!-- ============ Outgoing webhooks ============ -->
      <a-tab-pane key="list" :tab="$t('Webhooks')">
        <DataTable :crud="crud" :columns="columns">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'events'">
              <a-tag v-for="ev in record.events || []" :key="ev" style="margin: 2px">{{ ev }}</a-tag>
            </template>
            <template v-else-if="column.key === 'is_active'">
              <a-switch :checked="!!record.is_active" size="small" @change="toggle(record)" />
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-space>
                <a-tooltip title="Test">
                  <a-button type="text" size="small" @click="test(record)">
                    <template #icon><ThunderboltOutlined style="color: #faad14" /></template>
                  </a-button>
                </a-tooltip>
                <a-tooltip :title="$t('Edit')">
                  <a-button type="text" size="small" @click="openEdit(record)">
                    <template #icon><EditOutlined style="color: #52c41a" /></template>
                  </a-button>
                </a-tooltip>
                <a-tooltip :title="$t('Del')">
                  <a-button type="text" size="small" danger @click="crud.remove(record)">
                    <template #icon><DeleteOutlined /></template>
                  </a-button>
                </a-tooltip>
              </a-space>
            </template>
          </template>
        </DataTable>
      </a-tab-pane>

      <!-- ============ Delivery logs ============ -->
      <a-tab-pane key="deliveries" :tab="$t('Delivery_Logs') || 'Delivery Logs'">
        <DataTable :crud="deliveries" :columns="deliveryColumns">
          <template #toolbar>
            <a-select
              v-model:value="deliveryStatus" style="width: 180px"
              :options="DELIVERY_STATUSES" @change="deliveries.reload()"
            />
          </template>
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'webhook'">{{ record.webhook?.name || record.webhook }}</template>
            <template v-else-if="column.key === 'status'">
              <a-tag :color="deliveryStatusColor(record.status)">{{ record.status }}</a-tag>
            </template>
            <template v-else-if="column.key === 'response_code'">
              <a-tag v-if="record.response_code" :color="httpColor(record.response_code)">
                {{ record.response_code }}
              </a-tag>
              <span v-else class="muted">—</span>
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-button type="text" size="small" @click="showLog(record)">
                <template #icon><EyeOutlined style="color: #1677ff" /></template>
              </a-button>
            </template>
          </template>
        </DataTable>
      </a-tab-pane>

      <!-- ============ Incoming logs ============ -->
      <a-tab-pane key="incoming" :tab="$t('Incoming_Logs') || 'Incoming Logs'">
        <DataTable :crud="incoming" :columns="incomingColumns">
          <template #toolbar>
            <a-input
              v-model:value="incomingSource" placeholder="Filter source…"
              allow-clear style="width: 180px" @press-enter="incoming.reload()"
            />
            <a-select
              v-model:value="incomingStatus" style="width: 180px"
              :options="INCOMING_STATUSES" @change="incoming.reload()"
            />
          </template>
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'status'">
              <a-tag :color="record.status === 'processed' ? 'success' : record.status === 'failed' ? 'error' : 'default'">
                {{ record.status }}
              </a-tag>
            </template>
            <template v-else-if="column.key === 'signature_valid'">
              <a-tag :color="record.signature_valid ? 'success' : 'error'">
                {{ record.signature_valid ? '✓' : '✗' }}
              </a-tag>
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-button type="text" size="small" @click="showLog(record)">
                <template #icon><EyeOutlined style="color: #1677ff" /></template>
              </a-button>
            </template>
          </template>
        </DataTable>
      </a-tab-pane>
    </a-tabs>

    <!-- Webhook form -->
    <a-modal
      v-model:open="modalOpen"
      :title="editing ? $t('Edit') : $t('Add')"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      width="640px"
      @ok="save"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-form-item :label="$t('Name')" name="name">
          <a-input v-model:value="form.name" />
        </a-form-item>
        <a-form-item :label="$t('URL')" name="url">
          <a-input v-model:value="form.url" placeholder="https://" />
        </a-form-item>
        <a-form-item :label="$t('Events')" name="events">
          <a-checkbox
            :checked="form.events.length === availableEvents.length && availableEvents.length > 0"
            style="margin-bottom: 8px"
            @change="e => { form.events = e.target.checked ? [...availableEvents] : []; }"
          >
            {{ $t('All') }}
          </a-checkbox>
          <a-select
            v-model:value="form.events" mode="multiple" style="width: 100%"
            :options="availableEvents.map(ev => ({ value: ev, label: ev }))"
          />
        </a-form-item>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('Timeout_seconds')">
              <a-input-number v-model:value="form.timeout_seconds" style="width: 100%" :min="1" :max="120" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item label=" ">
              <a-checkbox v-model:checked="form.is_active">{{ $t('Active') }}</a-checkbox>
            </a-form-item>
          </a-col>
        </a-row>
        <template v-if="editing && editing.secret">
          <a-form-item :label="$t('Secret')">
            <a-input-group compact>
              <a-input :value="editing.secret" readonly style="width: calc(100% - 130px)" />
              <a-button style="width: 130px" @click="regenerateSecret">Regenerate</a-button>
            </a-input-group>
          </a-form-item>
        </template>
      </a-form>
    </a-modal>

    <!-- Log detail: labelled fields + pretty JSON, like legacy -->
    <a-modal v-model:open="logOpen" :title="$t('Details')" :footer="null" width="760px">
      <a-descriptions v-if="activeLog" :column="1" size="small" bordered>
        <a-descriptions-item v-for="f in activeLogFields" :key="f.label" :label="f.label">
          <a-tag v-if="f.tag" :color="f.tag">{{ f.value }}</a-tag>
          <template v-else>{{ f.value }}</template>
        </a-descriptions-item>
      </a-descriptions>

      <template v-for="block in activeLogJson" :key="block.label">
        <h4 style="margin-top: 16px">{{ block.label }}</h4>
        <pre class="log-json">{{ block.value }}</pre>
      </template>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * One page for the legacy webhooks section (list + delivery logs + incoming
 * logs as tabs). Contracts:
 * - GET webhooks → {webhooks, totalRows}; POST/PUT {name, url, events[],
 *   is_active 1/0, timeout_seconds}; POST webhooks/{id}/toggle | /test |
 *   /regenerate-secret; DELETE webhooks/{id}; events from
 *   GET webhooks/available-events
 * - GET webhooks/deliveries → {deliveries, totalRows} (statuses incl.
 *   retrying); GET webhooks/incoming-logs → {logs, totalRows}
 * - log rows open a raw JSON view (legacy Show_Log modal)
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, EyeOutlined, ThunderboltOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { date } = useFormat();

const tab = ref('list');
const availableEvents = ref([]);

const crud = useCrudTable('webhooks', { rowsKey: 'webhooks' });
crud.fetchRows();

// Legacy offered a status filter on deliveries and source + status on
// incoming; all three are sent as plain query params alongside the usual
// page/limit/search.
const DELIVERY_STATUSES = [
  { value: '', label: 'All statuses' },
  { value: 'pending', label: 'Pending' },
  { value: 'retrying', label: 'Retrying' },
  { value: 'success', label: 'Success' },
  { value: 'failed', label: 'Failed' },
];
const INCOMING_STATUSES = [
  { value: '', label: 'All statuses' },
  { value: 'received', label: 'Received' },
  { value: 'processed', label: 'Processed' },
  { value: 'failed', label: 'Failed' },
  { value: 'ignored', label: 'Ignored' },
];

const deliveryStatus = ref('');
const incomingStatus = ref('');
const incomingSource = ref('');

const deliveries = useCrudTable('webhooks/deliveries', {
  rowsKey: 'deliveries',
  params: () => ({ status: deliveryStatus.value }),
});
deliveries.fetchRows();

const incoming = useCrudTable('webhooks/incoming-logs', {
  rowsKey: 'logs',
  params: () => ({ status: incomingStatus.value, source: incomingSource.value }),
});
incoming.fetchRows();

function httpColor(code) {
  if (code >= 200 && code < 300) return 'success';
  if (code >= 400 && code < 500) return 'warning';
  if (code >= 500) return 'error';
  return 'default';
}

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('URL'), dataIndex: 'url', key: 'url' },
  { title: t('Events'), dataIndex: 'events', key: 'events' },
  { title: t('Status'), dataIndex: 'is_active', key: 'is_active', width: 90 },
  { title: t('Action'), key: 'actions', width: 140, align: 'center' },
]);

const deliveryColumns = computed(() => [
  { title: 'ID', dataIndex: 'id', key: 'id', width: 80 },
  { title: t('Event'), dataIndex: 'event', key: 'event' },
  { title: t('Webhook'), key: 'webhook' },
  { title: t('Status'), dataIndex: 'status', key: 'status' },
  { title: 'HTTP', dataIndex: 'response_code', key: 'response_code', align: 'center' },
  { title: t('Attempt'), dataIndex: 'attempt', key: 'attempt', align: 'right' },
  { title: t('Created_At'), dataIndex: 'created_at', key: 'created_at' },
  { title: t('Action'), key: 'actions', width: 70, align: 'center' },
]);

const incomingColumns = computed(() => [
  { title: 'ID', dataIndex: 'id', key: 'id', width: 80 },
  { title: t('Source'), dataIndex: 'source', key: 'source' },
  { title: t('Event'), dataIndex: 'event', key: 'event' },
  { title: t('Status'), dataIndex: 'status', key: 'status' },
  { title: t('Signature'), dataIndex: 'signature_valid', key: 'signature_valid', align: 'center' },
  { title: 'IP', dataIndex: 'ip', key: 'ip' },
  { title: t('Created_At'), dataIndex: 'created_at', key: 'created_at' },
  { title: t('Action'), key: 'actions', width: 70, align: 'center' },
]);

function deliveryStatusColor(s) {
  return { success: 'success', delivered: 'success', failed: 'error', retrying: 'warning', pending: 'default' }[s] || 'default';
}

// ---------------- webhook form ----------------

const modalOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const formRef = ref();
const form = ref({ name: '', url: '', events: [], is_active: true, timeout_seconds: 15 });

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
  url: [{ required: true, message: t('Field_is_required') }],
  events: [{ required: true, type: 'array', min: 1, message: t('Field_is_required') }],
}));

function openCreate() {
  editing.value = null;
  form.value = { name: '', url: '', events: [], is_active: true, timeout_seconds: 15 };
  modalOpen.value = true;
}

function openEdit(record) {
  editing.value = record;
  form.value = {
    name: record.name,
    url: record.url,
    events: [...(record.events || [])],
    is_active: !!record.is_active,
    timeout_seconds: record.timeout_seconds || 15,
  };
  modalOpen.value = true;
}

async function save() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  const body = {
    name: form.value.name,
    url: form.value.url,
    events: form.value.events,
    is_active: form.value.is_active ? 1 : 0,
    timeout_seconds: form.value.timeout_seconds,
  };
  try {
    if (editing.value) {
      await http.put(`webhooks/${editing.value.id}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('webhooks', body);
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

async function toggle(record) {
  try {
    await http.post(`webhooks/${record.id}/toggle`);
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

async function test(record) {
  try {
    await http.post(`webhooks/${record.id}/test`);
    message.success(t('Success'));
    deliveries.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

async function regenerateSecret() {
  try {
    await http.post(`webhooks/${editing.value.id}/regenerate-secret`);
    message.success(t('Successfully_Updated'));
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

// ---------------- log viewer ----------------

const logOpen = ref(false);
const activeLog = ref(null);

/** Pretty-print a JSON string; fall back to the raw text if it will not parse. */
function formatJson(value) {
  if (!value) return '—';
  try {
    return JSON.stringify(typeof value === 'string' ? JSON.parse(value) : value, null, 2);
  } catch (e) {
    return value;
  }
}

/** Delivery and incoming rows carry different fields; show whichever apply. */
const activeLogFields = computed(() => {
  const r = activeLog.value;
  if (!r) return [];
  const out = [];
  const add = (label, value, tag) => {
    if (value !== undefined && value !== null && value !== '') out.push({ label, value, tag });
  };
  add('Event', r.event);
  add('Source', r.source);
  add('Webhook', r.webhook?.name);
  add('URL', r.webhook?.url);
  add('Status', r.status, deliveryStatusColor(r.status));
  add('Response code', r.response_code);
  add('Attempt', r.attempt);
  if (r.duration_ms !== undefined && r.duration_ms !== null) add('Duration', `${r.duration_ms} ms`);
  if (r.signature_valid !== undefined) {
    out.push({
      label: 'Signature',
      value: r.signature_valid ? 'Valid' : 'Invalid',
      tag: r.signature_valid ? 'success' : 'error',
    });
  }
  add('IP', r.ip);
  add('Error', r.error_message);
  return out;
});

const activeLogJson = computed(() => {
  const r = activeLog.value;
  if (!r) return [];
  const blocks = [];
  if (r.headers !== undefined) blocks.push({ label: 'Headers', value: formatJson(r.headers) });
  if (r.payload !== undefined) blocks.push({ label: 'Payload', value: formatJson(r.payload) });
  if (r.response_body !== undefined) {
    blocks.push({ label: 'Response body', value: r.response_body || '—' });
  }
  return blocks;
});

function showLog(record) {
  activeLog.value = record;
  logOpen.value = true;
}

onMounted(async () => {
  try {
    const data = await http.get('webhooks/available-events');
    availableEvents.value = data.events || data || [];
  } catch (e) {
    availableEvents.value = [];
  }
});
</script>

<style scoped>
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
.log-json {
  max-height: 60vh;
  overflow: auto;
  background: rgba(5, 5, 5, 0.04);
  padding: 12px;
  border-radius: 8px;
  font-size: 12px;
}
</style>
