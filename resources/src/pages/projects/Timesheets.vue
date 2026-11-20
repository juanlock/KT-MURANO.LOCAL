<template>
  <div :class="{ page: !projectId }">
    <PageHeader
      v-if="!projectId"
      :title="tr('Timesheets', 'Registro de horas')"
      :subtitle="tr('Hours booked against projects and tasks.', 'Horas registradas en proyectos y tareas.')"
      :breadcrumb="[tr('Projects Management', 'Gestión de proyectos'), tr('Timesheets', 'Registro de horas')]"
    >
      <template #actions>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ tr('Log time', 'Registrar horas') }}
        </a-button>
      </template>
    </PageHeader>

    <div class="summary">
      <div class="sum">
        <span class="sum-label">{{ tr('Hours', 'Horas') }}</span>
        <span class="sum-value">{{ number(totals.hours || 0, 2) }}</span>
      </div>
      <div class="sum">
        <span class="sum-label">{{ tr('Billable value', 'Valor facturable') }}</span>
        <span class="sum-value ok">{{ money(totals.amount || 0) }}</span>
      </div>
      <div class="sum">
        <span class="sum-label">{{ tr('Entries', 'Registros') }}</span>
        <span class="sum-value">{{ crud.total.value }}</span>
      </div>
    </div>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="tr('Search description…', 'Buscar descripción…')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-button v-if="projectId" type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ tr('Log time', 'Registrar horas') }}
        </a-button>
        <a-select
          v-if="!projectId"
          v-model:value="filters.project_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="tr('All projects', 'Todos los proyectos')"
          :options="projectOptions" @change="onProjectChange"
        />
        <a-select
          v-model:value="filters.employee_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="tr('Anyone', 'Cualquiera')"
          :options="employeeOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.billable" class="tb-item-sm" allow-clear :placeholder="tr('All', 'Todos')"
          :options="[{ value: '1', label: tr('Billable', 'Facturable') }, { value: '0', label: tr('Non-billable', 'No facturable') }]"
          @change="crud.reload"
        />
        <PresetRangeFilter v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'log_date'">{{ date(record.log_date) }}</template>
        <template v-else-if="column.key === 'project'">
          <a v-if="!projectId" class="link" @click="$router.push(`/projects/${record.project_id}`)">{{ record.project_title }}</a>
          <div v-if="record.task_title" :class="{ 'cell-sub': !projectId }">{{ record.task_title }}</div>
          <span v-else-if="projectId" class="muted">{{ tr('The project', 'El proyecto') }}</span>
        </template>
        <template v-else-if="column.key === 'hours'">
          <b>{{ number(record.hours, 2) }}</b>
        </template>
        <template v-else-if="column.key === 'billable'">
          <a-tag :color="record.billable ? 'success' : 'default'">
            {{ record.billable ? tr('Billable', 'Facturable') : tr('Internal', 'Interno') }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'hourly_rate'">
          <span v-if="record.hourly_rate !== null">{{ money(record.hourly_rate) }}</span>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'amount'">{{ money(record.amount) }}</template>
        <template v-else-if="column.key === 'employee'">
          <span v-if="record.employee_name">{{ record.employee_name }}</span>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openForm(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.project_title })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      :open="formOpen" :title="editing ? tr('Edit time entry', 'Editar registro de horas') : tr('Log time', 'Registrar horas')" :width="600"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col v-if="!projectId" :xs="24" :md="12">
            <a-form-item :label="tr('Project *', 'Proyecto *')" name="project_id">
              <a-select
                v-model:value="form.project_id" show-search option-filter-prop="label"
                :options="projectOptions" @change="form.task_id = undefined"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="tr('Task', 'Tarea')" :extra="tr('Leave empty to book against the project', 'Déjalo vacío para registrar en el proyecto')">
              <a-select
                v-model:value="form.task_id" allow-clear show-search option-filter-prop="label"
                :options="formTaskOptions" :disabled="!form.project_id" :placeholder="tr('No specific task', 'Sin tarea específica')"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="tr('Who', 'Quién')">
              <a-select
                v-model:value="form.employee_id" allow-clear show-search option-filter-prop="label"
                :options="employeeOptions" :placeholder="tr('Unassigned', 'Sin asignar')"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="tr('Date *', 'Fecha *')" name="log_date">
              <a-date-picker v-model:value="form.log_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="tr('Hours *', 'Horas *')" name="hours">
              <a-input-number v-model:value="form.hours" :min="0.25" :max="24" :step="0.25" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="tr('Billable', 'Facturable')">
              <a-switch v-model:checked="form.billable" :checked-children="tr('Yes', 'Sí')" :un-checked-children="tr('No', 'No')" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="tr('Hourly rate', 'Tarifa por hora')">
              <a-input-number
                v-model:value="form.hourly_rate" :min="0" :disabled="!form.billable"
                style="width: 100%"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="tr('Value', 'Valor')" :extra="tr('Calculated on save', 'Se calcula al guardar')">
              <a-input :value="money(previewAmount)" disabled />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Description')" style="margin-bottom: 0">
              <a-textarea v-model:value="form.description" :rows="2" :placeholder="tr('What was done', 'Qué se hizo')" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
/**
 * Timesheets.
 *
 * The value shown while editing is a PREVIEW — the server recomputes it from
 * hours x rate on save, and non-billable time is always worth zero. That figure
 * feeds invoices, so it is never accepted from the client.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { t } from '../../i18n';
import { useTr } from '../../lib/useTr';

// Inside a project (ProjectDetail's "Horas" tab): that project only, no project filter / picker.
const props = defineProps({ projectId: { type: [Number, String], default: null } });
const emit = defineEmits(['changed']);

const tr = useTr();
const { money, number, date } = useFormat();

const filters = reactive({
  project_id: props.projectId ? Number(props.projectId) : undefined, employee_id: undefined, billable: undefined, range: null,
});

const crud = useCrudTable('project_time_logs', {
  rowsKey: 'time_logs',
  sortField: 'log_date',
  params: () => ({
    project_id: filters.project_id || '',
    employee_id: filters.employee_id || '',
    billable: filters.billable || '',
    start_date: filters.range?.[0] || '',
    end_date: filters.range?.[1] || '',
  }),
});

const totals = computed(() => crud.payload.value?.totals || {});

function onProjectChange() {
  crud.reload();
}

const columns = computed(() => [
  { title: tr('Date', 'Fecha'), key: 'log_date', dataIndex: 'log_date', sorter: true, width: 130 },
  { title: props.projectId ? tr('Task', 'Tarea') : tr('Project / task', 'Proyecto / tarea'), key: 'project', dataIndex: 'project_title' },
  { title: tr('Who', 'Quién'), key: 'employee', dataIndex: 'employee_name', width: 160 },
  { title: tr('Hours', 'Horas'), key: 'hours', dataIndex: 'hours', sorter: true, width: 100 },
  { title: tr('Type', 'Tipo'), key: 'billable', dataIndex: 'billable', width: 120 },
  { title: tr('Rate', 'Tarifa'), key: 'hourly_rate', dataIndex: 'hourly_rate', width: 110 },
  { title: tr('Value', 'Valor'), key: 'amount', dataIndex: 'amount', sorter: true, width: 120 },
  { title: tr('Description', 'Descripción'), dataIndex: 'description', key: 'description' },
  { title: '', key: 'actions', width: 90 },
]);

const meta = ref({});
const projectOptions = computed(() => (meta.value.projects || []).map(p => ({ value: p.id, label: p.title })));
const employeeOptions = computed(() => (meta.value.employees || []).map(e => ({ value: e.id, label: e.name })));
const formTaskOptions = computed(() => (meta.value.tasks || [])
  .filter(x => x.project_id === form.value.project_id)
  .map(x => ({ value: x.id, label: x.title })));

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref(empty());

function empty() {
  return {
    project_id: filters.project_id, task_id: undefined, employee_id: undefined,
    log_date: new Date().toISOString().slice(0, 10),
    hours: 1, billable: true, hourly_rate: null, description: '',
  };
}

const previewAmount = computed(() => {
  if (!form.value.billable) return 0;
  return (Number(form.value.hours) || 0) * (Number(form.value.hourly_rate) || 0);
});

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  project_id: required(),
  log_date: required(),
  hours: required(),
}));

function openForm(record) {
  editing.value = record;
  form.value = record
    ? {
        project_id: record.project_id,
        task_id: record.task_id || undefined,
        employee_id: record.employee_id || undefined,
        log_date: record.log_date,
        hours: record.hours,
        billable: !!record.billable,
        hourly_rate: record.hourly_rate,
        description: record.description || '',
      }
    : empty();
  formOpen.value = true;
  formRef.value?.clearValidate?.();
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    if (editing.value) await http.put(`project_time_logs/${editing.value.id}`, form.value);
    else await http.post('project_time_logs', form.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    crud.fetchRows();
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save this entry'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  crud.fetchRows();
  try {
    meta.value = await http.get('projects/meta');
  } catch (e) { /* selects stay empty */ }
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.ok {
  color: #16a34a;
}
.link {
  color: #6d28d9;
  cursor: pointer;
}
.summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.sum {
  display: flex;
  flex-direction: column;
  padding: 12px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 12px;
}
.sum-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  opacity: 0.55;
}
.sum-value {
  font-size: 19px;
  font-weight: 600;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 220px;
  min-width: 180px;
}
.tb-item {
  width: 170px;
}
.tb-item-sm {
  width: 140px;
}
.tb-range {
  width: 230px;
}
.cell-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
</style>
