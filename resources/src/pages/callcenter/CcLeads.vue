<template>
  <!-- Leads (2026-11-17): the people the campaigns call. Import: Excel or CSV, columns matched on screen, bad rows listed. -->
  <div class="page">
    <PageHeader :title="tr('Leads', 'Leads')" :breadcrumb="[tr('Call center', 'Centro de llamadas'), tr('Leads', 'Leads')]">
      <template #actions>
        <a-button @click="openImport"><UploadOutlined /> {{ tr('Import', 'Importar') }}</a-button>
        <a-button type="primary" @click="openForm()"><PlusOutlined /> {{ tr('New lead', 'Nuevo lead') }}</a-button>
      </template>
    </PageHeader>

    <!-- Demo lock (2026-11-18): only the server's few leads, nothing can be selected, copied or dragged. -->
    <div :class="{ nocopy: demoLock }" @copy="block" @cut="block" @contextmenu="block" @dragstart="block" @selectstart="block">
    <DataTable :crud="crud" :columns="columns" compact-toolbar>
      <template #filters>
        <a-select v-model:value="filters.region" :options="toOpts(opts.regions)" allow-clear show-search :placeholder="tr('Region', 'Departamento')" style="width: 150px" @change="crud.reload" />
        <a-popover trigger="click" placement="bottomLeft">
          <template #content>
            <div class="more">
              <a-radio-group v-model:value="filters.gender" button-style="solid" size="small" @change="crud.reload">
                <a-radio-button value="">{{ tr('Both', 'Ambos') }}</a-radio-button>
                <a-radio-button value="F">{{ tr('Women', 'Mujeres') }}</a-radio-button>
                <a-radio-button value="M">{{ tr('Men', 'Hombres') }}</a-radio-button>
              </a-radio-group>
              <a-space>
                <a-input-number v-model:value="filters.age_min" :min="0" :max="120" :placeholder="tr('Age from', 'Edad desde')" style="width: 110px" @change="crud.reload" />
                <a-input-number v-model:value="filters.age_max" :min="0" :max="120" :placeholder="tr('to', 'hasta')" style="width: 90px" @change="crud.reload" />
              </a-space>
              <a-select v-model:value="filters.district" :options="toOpts(opts.districts)" allow-clear show-search :placeholder="tr('District', 'Distrito')" @change="crud.reload" />
              <a-select v-model:value="filters.source" :options="toOpts(opts.sources)" allow-clear show-search :placeholder="tr('Source / list', 'Fuente / lista')" @change="crud.reload" />
              <a-select v-model:value="filters.import_id" :options="importOptions" allow-clear :placeholder="tr('Import', 'Importación')" @change="crud.reload" />
              <a-select v-model:value="filters.dnc" :options="[{ value: '0', label: tr('Can be called', 'Se pueden llamar') }, { value: '1', label: tr('Do not call', 'No llamar') }]" allow-clear :placeholder="tr('Do not call', 'No llamar')" @change="crud.reload" />
            </div>
          </template>
          <a-badge :count="activeFilters" size="small"><a-button><FilterOutlined /> {{ tr('Filters', 'Filtros') }}</a-button></a-badge>
        </a-popover>
        <span class="muted small">{{ opts.total ?? '' }} leads<span v-if="opts.dnc"> · {{ opts.dnc }} {{ tr('do not call', 'no llamar') }}</span></span>
      </template>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <b v-if="demoLock">{{ record.name || '—' }}</b>
          <a v-else @click="openForm(record)"><b>{{ record.name || '—' }}</b></a>
          <a-tag v-if="record.do_not_call" color="red" class="ml4">{{ tr('Do not call', 'No llamar') }}</a-tag>
          <div class="muted small">{{ record.document || '' }}</div>
        </template>
        <template v-else-if="column.key === 'gender'">{{ record.gender === 'F' ? tr('F', 'F') : record.gender === 'M' ? 'M' : '—' }}</template>
        <template v-else-if="column.key === 'place'">
          <div>{{ record.district || '—' }}</div>
          <div class="muted small">{{ [record.province, record.region].filter(Boolean).join(' · ') }}</div>
        </template>
        <template v-else-if="column.key === 'extra'">
          <div v-for="(v, k) in record.extra" :key="k" class="small"><span class="muted">{{ k }}:</span> {{ v }}</div>
        </template>
        <template v-else-if="column.key === 'last'">
          <template v-if="record.calls">
            <a-tag :color="RESULT_COLORS[record.last_result]">{{ record.last_result_label }}</a-tag>
            <div class="muted small">{{ fmt.dateTime(record.last_call_at) }} · {{ record.calls }} {{ tr('calls', 'llamadas') }}</div>
          </template>
          <span v-else class="muted">{{ tr('Not called', 'Sin llamar') }}</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-button v-if="!demoLock" size="small" danger @click="crud.remove(record, { label: record.name })"><DeleteOutlined /></a-button>
        </template>
      </template>
    </DataTable>
    </div>
    <a-alert v-if="demoLock" type="error" show-icon class="mt12 demo-lock" :message="demoLock.message">
      <template #icon><LockOutlined /></template>
    </a-alert>

    <!-- One lead. -->
    <a-modal :open="!!editing" :title="editing?.id ? tr('Edit lead', 'Editar lead') : tr('New lead', 'Nuevo lead')" width="720px" :confirm-loading="saving" @ok="save" @cancel="editing = null">
      <a-form v-if="editing" layout="vertical" class="lead-form">
        <a-row :gutter="10">
          <a-col :span="12"><a-form-item :label="tr('First name', 'Nombres')"><a-input v-model:value="editing.first_name" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="tr('Last name', 'Apellidos')"><a-input v-model:value="editing.last_name" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="tr('Phone', 'Teléfono')" required><PhoneInput v-model:value="editing.phone" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="tr('Phone 2', 'Teléfono 2')"><PhoneInput v-model:value="editing.phone2" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="tr('ID document', 'DNI')"><a-input v-model:value="editing.document" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="tr('Email', 'Correo')"><a-input v-model:value="editing.email" /></a-form-item></a-col>
          <a-col :span="6"><a-form-item :label="tr('Birth date', 'Nacimiento')"><a-date-picker v-model:value="editing.birth_date" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="6"><a-form-item :label="tr('or age', 'o edad')"><a-input-number v-model:value="editing.age" :min="0" :max="120" :disabled="!!editing.birth_date" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="6">
            <a-form-item :label="tr('Sex', 'Sexo')">
              <a-select v-model:value="editing.gender" allow-clear :options="[{ value: 'F', label: tr('Female', 'Femenino') }, { value: 'M', label: tr('Male', 'Masculino') }]" />
            </a-form-item>
          </a-col>
          <a-col :span="6"><a-form-item :label="tr('Region', 'Departamento')"><a-auto-complete v-model:value="editing.region" :options="toOpts(opts.regions)" /></a-form-item></a-col>
          <a-col :span="6"><a-form-item :label="tr('Province', 'Provincia')"><a-auto-complete v-model:value="editing.province" :options="toOpts(opts.provinces)" /></a-form-item></a-col>
          <a-col :span="6"><a-form-item :label="tr('District', 'Distrito')"><a-auto-complete v-model:value="editing.district" :options="toOpts(opts.districts)" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="tr('Address', 'Dirección')"><a-input v-model:value="editing.address" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="tr('Source / list', 'Fuente / lista')"><a-auto-complete v-model:value="editing.source" :options="toOpts(opts.sources)" /></a-form-item></a-col>
          <a-col :span="24">
            <div class="lbl">{{ tr('Other data', 'Otros datos') }}</div>
            <div v-for="(row, i) in editing.extraRows" :key="i" class="extra-row">
              <a-auto-complete v-model:value="row.key" :options="toOpts(opts.extra_keys)" :placeholder="tr('Field', 'Campo')" style="width: 200px" />
              <a-input v-model:value="row.value" :placeholder="tr('value', 'valor')" style="width: 260px" />
              <a-button size="small" @click="editing.extraRows.splice(i, 1)"><DeleteOutlined /></a-button>
            </div>
            <a-button size="small" @click="editing.extraRows.push({ key: '', value: '' })"><PlusOutlined /> {{ tr('Add', 'Agregar') }}</a-button>
          </a-col>
          <a-col :span="24" class="mt8"><a-form-item :label="tr('Notes', 'Notas')"><a-textarea v-model:value="editing.notes" :rows="2" /></a-form-item></a-col>
          <a-col :span="24"><a-checkbox v-model:checked="editing.do_not_call">{{ tr('Do not call (leaves every campaign)', 'No llamar (sale de todas las campañas)') }}</a-checkbox></a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Import: file -> columns -> result. -->
    <a-modal :open="imp.open" :title="tr('Import leads', 'Importar leads')" width="900px" :footer="null" @cancel="closeImport">
      <a-steps :current="imp.step" size="small" class="mb12">
        <a-step :title="tr('File', 'Archivo')" />
        <a-step :title="tr('Columns', 'Columnas')" />
        <a-step :title="tr('Result', 'Resultado')" />
      </a-steps>

      <div v-if="imp.step === 0">
        <a-upload-dragger :before-upload="upload" :show-upload-list="false" accept=".xlsx,.xls,.csv,.txt" :disabled="imp.busy">
          <p class="ant-upload-drag-icon"><InboxOutlined /></p>
          <p>{{ tr('Drop the Excel or CSV file here, or click to choose it', 'Suelta aquí el archivo Excel o CSV, o haz clic para elegirlo') }}</p>
          <p class="muted small">{{ tr('First row = the column names. Any names: you match them in the next step. Up to 50,000 rows.', 'Primera fila = los nombres de las columnas. Cualquier nombre: los relacionas en el siguiente paso. Hasta 50.000 filas.') }}</p>
        </a-upload-dragger>
        <a-spin v-if="imp.busy" class="mt8" />
        <div class="mt8"><a @click="downloadTemplate"><DownloadOutlined /> {{ tr('Download a template', 'Descargar una plantilla') }}</a></div>
      </div>

      <div v-else-if="imp.step === 1">
        <a-alert type="info" show-icon class="mb12"
          :message="tr(`${imp.preview.rows} rows in ${imp.preview.file_name}. Check what each column is; the ones the lead has no field for are kept as extra data (campaigns can filter by them).`, `${imp.preview.rows} filas en ${imp.preview.file_name}. Revisa qué es cada columna; las que no tienen campo en el lead se guardan como dato extra (las campañas pueden filtrar por ellas).`)" />
        <a-table :data-source="mapRows" :columns="mapColumns" size="small" :pagination="false" row-key="i" :scroll="{ y: 300 }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'field'">
              <a-select v-model:value="imp.mapping[record.i]" :options="fieldOptions" style="width: 100%" />
            </template>
            <template v-else-if="column.key === 'sample'"><span class="small">{{ record.sample }}</span></template>
          </template>
        </a-table>
        <a-row :gutter="12" class="mt8">
          <a-col :span="8">
            <div class="lbl">{{ tr('A phone already in the leads', 'Un teléfono que ya está en los leads') }}</div>
            <a-radio-group v-model:value="imp.duplicates">
              <a-radio value="update">{{ tr('Update that lead', 'Actualizar ese lead') }}</a-radio>
              <a-radio value="skip">{{ tr('Skip the row', 'Saltar la fila') }}</a-radio>
            </a-radio-group>
          </a-col>
          <a-col :span="8">
            <div class="lbl">{{ tr('Source / list name', 'Nombre de la fuente / lista') }}</div>
            <a-input v-model:value="imp.source" :placeholder="imp.preview.file_name" />
          </a-col>
          <a-col :span="8">
            <div class="lbl">{{ tr('Also add them to a campaign (optional)', 'Agregarlos también a una campaña (opcional)') }}</div>
            <a-select v-model:value="imp.campaign_id" :options="campaignOptions" allow-clear style="width: 100%" />
          </a-col>
        </a-row>
        <div class="foot mt12">
          <a-button @click="imp.step = 0">{{ tr('Back', 'Atrás') }}</a-button>
          <a-button type="primary" :loading="imp.busy" :disabled="!hasPhone" @click="runImport">{{ tr(`Import ${imp.preview.rows} rows`, `Importar ${imp.preview.rows} filas`) }}</a-button>
        </div>
      </div>

      <div v-else>
        <a-result status="success" :title="tr('Import done', 'Importación terminada')">
          <template #subTitle>
            <b>{{ imp.result.created }}</b> {{ tr('new', 'nuevos') }} · <b>{{ imp.result.updated }}</b> {{ tr('updated', 'actualizados') }} ·
            <b :class="{ bad: imp.result.skipped }">{{ imp.result.skipped }}</b> {{ tr('skipped', 'saltados') }}
            <span v-if="imp.result.added_to_campaign"> · {{ imp.result.added_to_campaign }} {{ tr('added to the campaign', 'agregados a la campaña') }}</span>
          </template>
        </a-result>
        <a-table v-if="imp.result.errors?.length" :data-source="imp.result.errors" :columns="errColumns" size="small" row-key="row" :pagination="{ pageSize: 8 }" />
        <div class="foot mt12">
          <a-button @click="closeImport">{{ tr('Close', 'Cerrar') }}</a-button>
          <a-button type="primary" @click="showImported">{{ tr('See the imported leads', 'Ver los leads importados') }}</a-button>
        </div>
      </div>
    </a-modal>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined, DeleteOutlined, UploadOutlined, InboxOutlined, DownloadOutlined, FilterOutlined, LockOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useListState } from '../../composables/useListState';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';
import { useTr, RESULT_COLORS, errText } from '../../lib/callcenter';

const tr = useTr();
const fmt = useFormat();
const filters = ref({ region: undefined, gender: '', age_min: null, age_max: null, district: undefined, source: undefined, import_id: undefined, dnc: undefined });
const crud = useCrudTable('callcenter/leads', {
  rowsKey: 'rows', limit: 50,
  params: () => Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v !== undefined && v !== null && v !== '')),
});
useListState('cc-leads', { filters }, crud);
const opts = ref({ regions: [], provinces: [], districts: [], sources: [], extra_keys: [], fields: [], imports: [] });
const campaigns = ref([]);
const editing = ref(null);
const saving = ref(false);

// Demo lock: the server says so (settings.cc_demo_lead_limit; never for the SuperAdmin).
const demoLock = computed(() => crud.payload.value?.demo_lock || null);
function block(e) {
  if (!demoLock.value) return;
  const t = e.target;
  // the search box and the filters stay usable
  if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA') && e.type !== 'copy' && e.type !== 'cut') return;
  e.preventDefault();
  if (e.type === 'copy' || e.type === 'cut') message.warning(demoLock.value.message);
}

const toOpts = list => sortByLabel((list || []).map(v => ({ value: v, label: v })));
const activeFilters = computed(() => Object.entries(filters.value).filter(([k, v]) => k !== 'region' && v !== undefined && v !== null && v !== '').length);
const importOptions = computed(() => (opts.value.imports || []).map(i => ({ value: i.id, label: `${i.file_name} · ${fmt.date(i.created_at)}` })));
const campaignOptions = computed(() => sortByLabel(campaigns.value.filter(c => c.status !== 'finished').map(c => ({ value: c.id, label: c.name }))));

const columns = computed(() => [
  { title: tr('Name', 'Nombre'), key: 'name', dataIndex: 'name', sorter: true, width: 210 },
  { title: tr('Phone', 'Teléfono'), dataIndex: 'phone', sorter: true, width: 120 },
  { title: tr('Age', 'Edad'), dataIndex: 'age_now', sorter: true, width: 70 },
  { title: tr('Sex', 'Sexo'), key: 'gender', dataIndex: 'gender', sorter: true, width: 65 },
  { title: tr('Location', 'Ubicación'), key: 'place', dataIndex: 'district', sorter: true, width: 190 },
  { title: tr('Other data', 'Otros datos'), key: 'extra', width: 220 },
  { title: tr('Source', 'Fuente'), dataIndex: 'source', sorter: true, width: 140 },
  { title: tr('Last call', 'Última llamada'), key: 'last', width: 190 },
  { title: '', key: 'actions', width: 60 },
]);

async function loadOptions() {
  try {
    opts.value = await http.get('callcenter/leads/options');
  } catch (e) { /* filters stay empty */ }
}

function openForm(r) {
  const base = { first_name: '', last_name: '', phone: '', phone2: '', email: '', document: '', birth_date: null, age: null, gender: undefined,
    region: '', province: '', district: '', city: '', address: '', source: '', notes: '', do_not_call: false };
  editing.value = { ...base, ...(r ? JSON.parse(JSON.stringify(r)) : {}), extraRows: Object.entries(r?.extra || {}).map(([key, value]) => ({ key, value })) };
}

async function save() {
  const e = editing.value;
  if (!e.phone?.trim()) return message.warning(tr('The phone is required.', 'El teléfono es obligatorio.'));
  saving.value = true;
  try {
    const extra = Object.fromEntries(e.extraRows.filter(x => x.key?.trim()).map(x => [x.key.trim(), x.value]));
    const body = { ...e, extra, extraRows: undefined, gender: e.gender || null, age: e.birth_date ? null : e.age };
    e.id ? await http.put(`callcenter/leads/${e.id}`, body) : await http.post('callcenter/leads', body);
    message.success(tr('Saved.', 'Guardado.'));
    editing.value = null;
    crud.fetchRows();
    loadOptions();
  } catch (err) {
    message.error(errText(err, tr('Could not save.', 'No se pudo guardar.')));
  } finally {
    saving.value = false;
  }
}

// ---------------------------------------------------------------- import
const imp = reactive({ open: false, step: 0, busy: false, preview: null, mapping: {}, duplicates: 'update', source: '', campaign_id: undefined, result: null });
const fieldOptions = computed(() => [
  ...sortByLabel((opts.value.fields || []).map(f => ({ value: f.value, label: f.label }))),
  { value: 'extra', label: tr('Extra data (keep the column)', 'Dato extra (guardar la columna)') },
  { value: 'ignore', label: tr('Ignore', 'Ignorar') },
]);
const mapRows = computed(() => (imp.preview?.headers || []).map((h, i) => ({
  i, header: h || `(${tr('column', 'columna')} ${i + 1})`, sample: (imp.preview.sample || []).slice(0, 3).map(r => r[i]).filter(v => v !== '').join(' · '),
})));
const mapColumns = computed(() => [
  { title: tr('Column in the file', 'Columna del archivo'), dataIndex: 'header', width: 200 },
  { title: tr('Is', 'Es'), key: 'field', width: 260 },
  { title: tr('First values', 'Primeros valores'), key: 'sample' },
]);
const errColumns = computed(() => [
  { title: tr('Row', 'Fila'), dataIndex: 'row', width: 80 },
  { title: tr('Why it was skipped', 'Por qué se saltó'), dataIndex: 'reason' },
]);
const hasPhone = computed(() => Object.values(imp.mapping).includes('phone'));

function openImport() {
  Object.assign(imp, { open: true, step: 0, busy: false, preview: null, mapping: {}, duplicates: 'update', source: '', campaign_id: undefined, result: null });
}
function closeImport() {
  imp.open = false;
}

async function upload(file) {
  imp.busy = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    imp.preview = await http.postForm('callcenter/leads/import/preview', fd);
    imp.mapping = { ...imp.preview.mapping };
    imp.step = 1;
  } catch (e) {
    message.error(errText(e, tr('Could not read the file.', 'No se pudo leer el archivo.')));
  } finally {
    imp.busy = false;
  }
  return false;
}

async function runImport() {
  imp.busy = true;
  try {
    imp.result = await http.post(`callcenter/leads/import/${imp.preview.import_id}/run`, {
      mapping: imp.mapping, duplicates: imp.duplicates, source: imp.source || null, campaign_id: imp.campaign_id || null,
    });
    imp.step = 2;
    crud.fetchRows();
    loadOptions();
  } catch (e) {
    message.error(errText(e, tr('Could not import.', 'No se pudo importar.')));
  } finally {
    imp.busy = false;
  }
}

function showImported() {
  Object.keys(filters.value).forEach(k => { filters.value[k] = k === 'gender' ? '' : undefined; });
  filters.value.import_id = imp.preview.import_id;
  imp.open = false;
  crud.reload();
}

/** The template is written here, in the screen's language (Excel opens it with its accents thanks to the BOM). */
function downloadTemplate() {
  const es = tr('en', 'es') === 'es';
  const head = es
    ? ['Nombres', 'Apellidos', 'Teléfono', 'Teléfono 2', 'Correo', 'DNI', 'Fecha de nacimiento', 'Edad', 'Sexo', 'Departamento', 'Provincia', 'Distrito', 'Ciudad', 'Dirección', 'Fuente', 'Notas', 'Seguro actual']
    : ['First name', 'Last name', 'Phone', 'Phone 2', 'Email', 'Document', 'Birth date', 'Age', 'Sex', 'Region', 'Province', 'District', 'City', 'Address', 'Source', 'Notes', 'Current insurance'];
  const row = ['María', 'Quispe Rojas', '987654321', '', 'maria@example.com', '45678912', '12/04/1985', '', 'F', 'Lima', 'Lima', 'Miraflores', 'Lima', 'Av. Larco 123', 'Feria 2026', '', 'Ninguno'];
  const csv = '﻿' + [head, row].map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(';')).join('\r\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  a.download = es ? 'plantilla_leads.csv' : 'leads_template.csv';
  a.click();
  URL.revokeObjectURL(a.href);
}

onMounted(async () => {
  crud.fetchRows();
  loadOptions();
  try {
    campaigns.value = (await http.get('callcenter/campaigns/options')).campaigns || [];
  } catch (e) { /* only for the import's campaign picker */ }
});
</script>

<style scoped>
.muted { opacity: 0.65; }
.small { font-size: 12px; }
.ml4 { margin-left: 4px; }
.mt8 { margin-top: 8px; }
.mt12 { margin-top: 12px; }
.mb12 { margin-bottom: 12px; }
.more { display: grid; gap: 8px; width: 250px; }
.lbl { font-size: 12px; opacity: 0.75; margin-bottom: 2px; }
.extra-row { display: flex; gap: 6px; align-items: center; margin-bottom: 6px; }
.lead-form :deep(.ant-form-item) { margin-bottom: 10px; }
.foot { display: flex; justify-content: flex-end; gap: 8px; }
.bad { color: #cf1322; }
.nocopy :deep(.ant-table) { user-select: none; -webkit-user-select: none; }
.demo-lock { font-weight: 600; }
@media print { .nocopy { display: none; } }
</style>
