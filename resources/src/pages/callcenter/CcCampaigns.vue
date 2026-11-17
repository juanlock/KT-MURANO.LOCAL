<template>
  <!-- Campaigns (2026-11-17): each one picks its leads by age, sex, location and other values; "Load leads" fills its queue. -->
  <div class="page">
    <PageHeader :title="tr('Campaigns', 'Campañas')" :breadcrumb="[tr('Call center', 'Centro de llamadas'), tr('Campaigns', 'Campañas')]">
      <template #actions>
        <a-button type="primary" @click="openForm()"><PlusOutlined /> {{ tr('New campaign', 'Nueva campaña') }}</a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns" compact-toolbar>
      <template #filters>
        <a-select v-model:value="status" :options="opts.statuses" allow-clear :placeholder="tr('Status', 'Estado')" style="width: 150px" @change="crud.reload" />
      </template>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <a @click="openForm(record)"><b>{{ record.name }}</b></a>
          <div class="muted small">{{ record.mode === 'telephony' ? tr('Through the exchange', 'Con central telefónica') : tr('From the screen', 'Desde la pantalla') }}</div>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="{ draft: 'default', active: 'green', paused: 'orange', finished: 'blue' }[record.status]">{{ record.status_label }}</a-tag>
        </template>
        <template v-else-if="column.key === 'filters'">
          <span class="small">{{ filterText(record.filters) }}</span>
        </template>
        <template v-else-if="column.key === 'progress'">
          <a-progress :percent="record.progress.percent" size="small" />
          <div class="muted small">{{ record.progress.total }} leads · {{ tr('to call', 'por llamar') }} {{ record.progress.pending + record.progress.callback + record.progress.locked }}</div>
        </template>
        <template v-else-if="column.key === 'sales'">
          <b>{{ record.progress.sales }}</b> <span class="muted small">/ {{ record.calls }} {{ tr('calls', 'llamadas') }}</span>
        </template>
        <template v-else-if="column.key === 'period'">
          <span class="small">{{ record.starts_on ? fmt.date(record.starts_on) : '—' }} → {{ record.ends_on ? fmt.date(record.ends_on) : '—' }}</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space size="small" wrap>
            <a-tooltip :title="tr('Add the leads that match the filter and are not in it yet', 'Agrega los leads que coinciden con el filtro y aún no están')">
              <a-button size="small" :disabled="record.status === 'finished'" @click="load(record)"><CloudDownloadOutlined /> {{ tr('Load leads', 'Cargar leads') }}</a-button>
            </a-tooltip>
            <a-button v-if="record.status !== 'active' && record.status !== 'finished'" size="small" type="primary" @click="setStatus(record, 'active')">{{ tr('Start', 'Iniciar') }}</a-button>
            <a-button v-if="record.status === 'active'" size="small" @click="setStatus(record, 'paused')">{{ tr('Pause', 'Pausar') }}</a-button>
            <a-popconfirm v-if="record.status !== 'finished'" :title="tr('Finish the campaign? Agents will not see it any more.', '¿Terminar la campaña? Los agentes ya no la verán.')" @confirm="setStatus(record, 'finished')">
              <a-button size="small">{{ tr('Finish', 'Terminar') }}</a-button>
            </a-popconfirm>
            <a-button v-if="record.status === 'finished'" size="small" @click="setStatus(record, 'paused')">{{ tr('Reopen', 'Reabrir') }}</a-button>
            <a-button v-if="!record.calls" size="small" danger @click="crud.remove(record, { label: record.name })"><DeleteOutlined /></a-button>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-drawer :open="!!editing" :width="isPhone ? '100%' : 720" :title="editing?.id ? tr('Edit campaign', 'Editar campaña') : tr('New campaign', 'Nueva campaña')" @close="editing = null">
      <a-form v-if="editing" layout="vertical">
        <a-row :gutter="10">
          <a-col :span="14"><a-form-item :label="tr('Name', 'Nombre')" required><a-input v-model:value="editing.name" :placeholder="tr('e.g. Health insurance', 'ej. Seguros de salud')" /></a-form-item></a-col>
          <a-col :span="10">
            <a-form-item :label="tr('Client (who the calls are for)', 'Cliente (para quién se llama)')">
              <a-select v-model:value="editing.client_id" :options="clientOptions" allow-clear show-search option-filter-prop="label" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="tr('How the agents call', 'Cómo llaman los agentes')">
              <a-segmented v-model:value="editing.mode" :options="[{ value: 'screen', label: tr('From the screen (lead list)', 'Desde la pantalla (lista de leads)') }, { value: 'telephony', label: tr('Through the telephone exchange', 'Con la central telefónica') }]" />
            </a-form-item>
          </a-col>
        </a-row>

        <!-- Which leads: the filter, with the live count. -->
        <a-card size="small" class="filters">
          <template #title>{{ tr('Leads of the campaign', 'Leads de la campaña') }}</template>
          <template #extra>
            <a-spin :spinning="counting" size="small">
              <a-tag color="blue" class="count">{{ count ?? '…' }} {{ tr('leads match', 'leads coinciden') }}</a-tag>
              <a-tag v-if="editing.id && countNew !== null" color="green">{{ countNew }} {{ tr('new', 'nuevos') }}</a-tag>
            </a-spin>
          </template>
          <a-row :gutter="10">
            <a-col :span="8">
              <div class="lbl">{{ tr('Age', 'Edad') }}</div>
              <a-space>
                <a-input-number v-model:value="editing.filters.age_min" :min="0" :max="120" :placeholder="tr('from', 'desde')" style="width: 80px" />
                <span>–</span>
                <a-input-number v-model:value="editing.filters.age_max" :min="0" :max="120" :placeholder="tr('to', 'hasta')" style="width: 80px" />
              </a-space>
            </a-col>
            <a-col :span="16">
              <div class="lbl">{{ tr('Sex', 'Sexo') }}</div>
              <a-radio-group v-model:value="editing.filters.gender" button-style="solid" size="small">
                <a-radio-button value="">{{ tr('Both', 'Ambos') }}</a-radio-button>
                <a-radio-button value="F">{{ tr('Women', 'Mujeres') }}</a-radio-button>
                <a-radio-button value="M">{{ tr('Men', 'Hombres') }}</a-radio-button>
              </a-radio-group>
            </a-col>
            <a-col :span="12" class="mt8">
              <div class="lbl">{{ tr('Region / department', 'Departamento / región') }}</div>
              <a-select v-model:value="editing.filters.regions" mode="multiple" :options="toOpts(leadOpts.regions)" allow-clear style="width: 100%" :placeholder="tr('All', 'Todos')" />
            </a-col>
            <a-col :span="12" class="mt8">
              <div class="lbl">{{ tr('Province', 'Provincia') }}</div>
              <a-select v-model:value="editing.filters.provinces" mode="multiple" :options="toOpts(leadOpts.provinces)" allow-clear style="width: 100%" :placeholder="tr('All', 'Todas')" />
            </a-col>
            <a-col :span="12" class="mt8">
              <div class="lbl">{{ tr('District', 'Distrito') }}</div>
              <a-select v-model:value="editing.filters.districts" mode="multiple" :options="toOpts(leadOpts.districts)" allow-clear style="width: 100%" :placeholder="tr('All', 'Todos')" />
            </a-col>
            <a-col :span="12" class="mt8">
              <div class="lbl">{{ tr('Source / list', 'Fuente / lista') }}</div>
              <a-select v-model:value="editing.filters.sources" mode="multiple" :options="toOpts(leadOpts.sources)" allow-clear style="width: 100%" :placeholder="tr('All', 'Todas')" />
            </a-col>
            <a-col :span="24" class="mt8">
              <div class="lbl">{{ tr('Other values (the import\'s extra columns)', 'Otros valores (las columnas extra de la importación)') }}</div>
              <div v-for="(c, i) in editing.filters.extra" :key="i" class="extra-row">
                <a-select v-model:value="c.key" :options="toOpts(leadOpts.extra_keys)" show-search :placeholder="tr('Column', 'Columna')" style="width: 200px" />
                <span>=</span>
                <a-input v-model:value="c.value" :placeholder="tr('value', 'valor')" style="width: 200px" />
                <a-button size="small" @click="editing.filters.extra.splice(i, 1)"><DeleteOutlined /></a-button>
              </div>
              <a-button size="small" :disabled="!leadOpts.extra_keys?.length" @click="editing.filters.extra.push({ key: undefined, value: '' })"><PlusOutlined /> {{ tr('Add a condition', 'Agregar condición') }}</a-button>
            </a-col>
            <a-col :span="24" class="mt8">
              <a-checkbox v-model:checked="editing.filters.exclude_active">{{ tr('Leave out leads still being called in another active campaign', 'Excluir leads que aún se llaman en otra campaña activa') }}</a-checkbox>
            </a-col>
          </a-row>
        </a-card>

        <a-row :gutter="10" class="mt8">
          <a-col :span="12"><a-form-item :label="tr('Starts', 'Inicio')"><a-date-picker v-model:value="editing.starts_on" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="tr('Ends', 'Fin')"><a-date-picker v-model:value="editing.ends_on" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12">
            <a-form-item :label="tr('Calling hours', 'Horario de llamadas')">
              <a-space>
                <a-time-picker v-model:value="editing.call_from" value-format="HH:mm" format="HH:mm" :minute-step="15" />
                <span>–</span>
                <a-time-picker v-model:value="editing.call_to" value-format="HH:mm" format="HH:mm" :minute-step="15" />
              </a-space>
            </a-form-item>
          </a-col>
          <a-col :span="6"><a-form-item :label="tr('Attempts per lead', 'Intentos por lead')"><a-input-number v-model:value="editing.max_attempts" :min="1" :max="20" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="6">
            <a-form-item>
              <template #label>{{ tr('Retry after (min)', 'Reintentar tras (min)') }}
                <a-tooltip :title="tr('No answer, busy or voicemail: the lead comes back after these minutes.', 'No contesta, ocupado o buzón: el lead vuelve después de estos minutos.')"><InfoCircleOutlined class="info" /></a-tooltip>
              </template>
              <a-input-number v-model:value="editing.retry_minutes" :min="5" :max="10080" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="12"><a-form-item :label="tr('Billing to the client', 'Facturación al cliente')"><a-select v-model:value="editing.billing_mode" :options="opts.billing" /></a-form-item></a-col>
          <a-col :span="12">
            <a-form-item :label="tr('Rate', 'Tarifa')">
              <a-input-number v-model:value="editing.billing_rate" :min="0" :disabled="editing.billing_mode === 'none'" :prefix="opts.currency" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item>
              <template #label>{{ tr('Script', 'Guion') }}
                <a-tooltip :title="tr('{nombre} = the lead\'s name, {agente} = the agent\'s.', '{nombre} = nombre del lead, {agente} = el del agente.')"><InfoCircleOutlined class="info" /></a-tooltip>
              </template>
              <a-textarea v-model:value="editing.script" :rows="8" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
      <template #footer>
        <div class="foot">
          <a-button @click="editing = null">{{ tr('Cancel', 'Cancelar') }}</a-button>
          <a-button type="primary" :loading="saving" @click="save">{{ tr('Save', 'Guardar') }}</a-button>
        </div>
      </template>
    </a-drawer>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { PlusOutlined, DeleteOutlined, CloudDownloadOutlined, InfoCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useListState } from '../../composables/useListState';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';
import { useTr, errText } from '../../lib/callcenter';

const tr = useTr();
const fmt = useFormat();
const status = ref(undefined);
const crud = useCrudTable('callcenter/campaigns', { rowsKey: 'rows', params: () => ({ status: status.value }) });
useListState('cc-campaigns', { status }, crud);
const opts = ref({ clients: [], statuses: [], billing: [], currency: 'S/' });
const leadOpts = ref({});
const editing = ref(null);
const saving = ref(false);
const count = ref(null);
const countNew = ref(null);
const counting = ref(false);
const isPhone = window.innerWidth < 768;

const clientOptions = computed(() => sortByLabel(opts.value.clients.map(c => ({ value: c.id, label: c.name }))));
const toOpts = list => sortByLabel((list || []).map(v => ({ value: v, label: v })));

const columns = computed(() => [
  { title: tr('Campaign', 'Campaña'), key: 'name', dataIndex: 'name', sorter: true, width: 210 },
  { title: tr('Client', 'Cliente'), dataIndex: 'client_name', sorter: true, width: 170 },
  { title: tr('Status', 'Estado'), key: 'status', dataIndex: 'status', sorter: true, width: 100 },
  { title: tr('Leads filter', 'Filtro de leads'), key: 'filters', width: 220 },
  { title: tr('Progress', 'Avance'), key: 'progress', width: 190 },
  { title: tr('Sales', 'Ventas'), key: 'sales', width: 120 },
  { title: tr('Period', 'Periodo'), key: 'period', dataIndex: 'starts_on', sorter: true, width: 170 },
  { title: '', key: 'actions', width: 300 },
]);

function filterText(f) {
  const parts = [];
  if (f.age_min != null || f.age_max != null) parts.push(`${tr('age', 'edad')} ${f.age_min ?? 0}–${f.age_max ?? '…'}`);
  if (f.gender) parts.push(f.gender === 'F' ? tr('women', 'mujeres') : tr('men', 'hombres'));
  ['regions', 'provinces', 'districts', 'sources'].forEach(k => { if (f[k]?.length) parts.push(f[k].join(', ')); });
  (f.extra || []).forEach(c => parts.push(`${c.key} = ${c.value}`));
  return parts.join(' · ') || tr('All the leads', 'Todos los leads');
}

const blank = () => ({
  name: '', client_id: undefined, mode: 'screen', script: '', starts_on: null, ends_on: null, call_from: '09:00', call_to: '20:00',
  max_attempts: 3, retry_minutes: 120, billing_mode: 'none', billing_rate: 0,
  filters: { age_min: null, age_max: null, gender: '', regions: [], provinces: [], districts: [], sources: [], extra: [], exclude_active: false },
});

function openForm(r) {
  const b = blank();
  editing.value = r ? { ...b, ...JSON.parse(JSON.stringify(r)), filters: { ...b.filters, ...(r.filters || {}), extra: [...(r.filters?.extra || [])] } } : b;
  count.value = null;
  countNew.value = null;
  recount();
}

let timer = null;
function recount() {
  clearTimeout(timer);
  timer = setTimeout(async () => {
    if (!editing.value) return;
    counting.value = true;
    try {
      const r = await http.post('callcenter/campaigns/count', { filters: editing.value.filters, campaign_id: editing.value.id || undefined });
      count.value = r.count;
      countNew.value = r.new;
    } catch (e) { /* the count is a help only */ } finally {
      counting.value = false;
    }
  }, 400);
}
watch(() => editing.value && JSON.stringify(editing.value.filters), v => { if (v) recount(); });

async function save() {
  const e = editing.value;
  if (!e.name?.trim()) return message.warning(tr('Write the campaign\'s name.', 'Escribe el nombre de la campaña.'));
  saving.value = true;
  try {
    const body = { ...e, client_id: e.client_id || null };
    const isNew = !e.id;
    const res = isNew ? await http.post('callcenter/campaigns', body) : await http.put(`callcenter/campaigns/${e.id}`, body);
    message.success(tr('Saved.', 'Guardado.'));
    editing.value = null;
    await crud.fetchRows();
    if (isNew && count.value) {
      Modal.confirm({
        title: tr('Load the leads now?', '¿Cargar los leads ahora?'),
        content: tr(`${count.value} leads match the filter.`, `${count.value} leads coinciden con el filtro.`),
        okText: tr('Load', 'Cargar'), cancelText: tr('Later', 'Después'),
        onOk: () => load({ id: res.id }),
      });
    }
  } catch (err) {
    message.error(errText(err, tr('Could not save.', 'No se pudo guardar.')));
  } finally {
    saving.value = false;
  }
}

async function load(r) {
  try {
    const res = await http.post(`callcenter/campaigns/${r.id}/load`);
    message.success(tr(`${res.added} leads added.`, `${res.added} leads agregados.`));
    crud.fetchRows();
  } catch (e) {
    message.error(errText(e, tr('Could not load the leads.', 'No se pudieron cargar los leads.')));
  }
}

async function setStatus(r, s) {
  try {
    await http.post(`callcenter/campaigns/${r.id}/status`, { status: s });
    crud.fetchRows();
  } catch (e) {
    message.error(errText(e, tr('Could not change it.', 'No se pudo cambiar.')));
  }
}

onMounted(async () => {
  crud.fetchRows();
  try {
    [opts.value, leadOpts.value] = await Promise.all([http.get('callcenter/campaigns/options'), http.get('callcenter/leads/options')]);
  } catch (e) { /* the form still opens */ }
});
</script>

<style scoped>
.muted { opacity: 0.65; }
.small { font-size: 12px; }
.mt8 { margin-top: 8px; }
.lbl { font-size: 12px; opacity: 0.75; margin-bottom: 2px; }
.filters :deep(.ant-card-head) { min-height: 38px; }
.count { font-weight: 600; }
.extra-row { display: flex; gap: 6px; align-items: center; margin-bottom: 6px; }
.info { opacity: 0.55; margin-left: 4px; }
.foot { display: flex; justify-content: flex-end; gap: 8px; }
</style>
