<template>
  <div :class="{ page: !projectId }">
    <PageHeader
      v-if="!projectId"
      :title="tr('Milestones', 'Hitos')"
      :subtitle="tr('The plan each project is measured against.', 'El plan con el que se mide cada proyecto.')"
      :breadcrumb="[tr('Projects Management', 'Gestión de proyectos'), tr('Milestones', 'Hitos')]"
    >
      <template #actions>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="tr('Search milestone…', 'Buscar hito…')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-button v-if="projectId" type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ tr('New milestone', 'Nuevo hito') }}
        </a-button>
        <a-select
          v-if="!projectId"
          v-model:value="filters.project_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="tr('All projects', 'Todos los proyectos')"
          :options="projectOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item-sm" allow-clear
          :placeholder="tr('Status', 'Estado')" :options="MILESTONE_STATUSES()" @change="crud.reload"
        />
        <a-checkbox v-model:checked="filters.overdue" class="tb-check" @change="crud.reload">
          {{ tr('Overdue only', 'Solo atrasados') }}
        </a-checkbox>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'title'">
          <div class="cell-title">{{ record.title }}</div>
          <div v-if="record.description" class="cell-sub">{{ record.description }}</div>
        </template>
        <template v-else-if="column.key === 'project'">
          <a class="link" @click="$router.push(`/projects/${record.project_id}`)">{{ record.project_title }}</a>
        </template>
        <template v-else-if="column.key === 'due_date'">
          <div v-if="record.due_date">
            {{ date(record.due_date) }}
            <a-tag v-if="dueLabel(record.days_to_due)" :color="dueLabel(record.days_to_due).color" class="tag-tight">
              {{ dueLabel(record.days_to_due).text }}
            </a-tag>
          </div>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'progress'">
          <a-progress :percent="record.progress" size="small" :stroke-color="progressColor(record.progress)" />
        </template>
        <template v-else-if="column.key === 'status'">
          <a-select
            :value="record.status" size="small" class="status-select"
            :options="MILESTONE_STATUSES()" @change="v => setStatus(record, v)"
          />
        </template>
        <template v-else-if="column.key === 'budget'">
          <span v-if="record.budget !== null">{{ money(record.budget) }}</span>
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
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.title })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      :open="formOpen" :title="editing ? tr('Edit milestone', 'Editar hito') : tr('New milestone', 'Nuevo hito')" :width="600"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-alert
        type="info" show-icon banner
        :message="tr('Marking a milestone complete sets progress to 100% and stamps today as the completion date — that stamp is what the slippage report measures.', 'Marcar un hito como completado pone el avance en 100% y registra hoy como fecha de finalización — esa fecha es la que mide el reporte de retrasos.')"
        style="margin-bottom: 16px"
      />
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col v-if="!projectId" :span="24">
            <a-form-item :label="tr('Project *', 'Proyecto *')" name="project_id">
              <a-select
                v-model:value="form.project_id" show-search option-filter-prop="label"
                :options="projectOptions" :placeholder="tr('Select a project', 'Selecciona un proyecto')"
              />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="tr('Title *', 'Título *')" name="title">
              <a-input v-model:value="form.title" :placeholder="tr('e.g. Beta release', 'p. ej. Versión beta')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="tr('Due date', 'Fecha de vencimiento')">
              <a-date-picker v-model:value="form.due_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="tr('Status *', 'Estado *')" name="status">
              <a-select v-model:value="form.status" :options="MILESTONE_STATUSES()" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="tr('Progress %', 'Avance %')">
              <a-input-number
                v-model:value="form.progress" :min="0" :max="100"
                :disabled="form.status === 'completed'" style="width: 100%"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="tr('Budget', 'Presupuesto')">
              <a-input-number v-model:value="form.budget" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="tr('Order', 'Orden')" :extra="tr('Position in the plan', 'Posición en el plan')">
              <a-input-number v-model:value="form.position" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Description')" style="margin-bottom: 0">
              <a-textarea v-model:value="form.description" :rows="3" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Project milestones. Status is editable inline from the list — moving a
 * milestone to "completed" is the most common single action here, and it should
 * not require opening a form.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { MILESTONE_STATUSES, progressColor, dueLabel } from './workspaceOptions';
import http from '../../lib/http';
import { t } from '../../i18n';
import { useTr } from '../../lib/useTr';

// Inside a project (ProjectDetail's "Hitos" tab): that project only, no project filter / column / picker.
const props = defineProps({ projectId: { type: [Number, String], default: null } });
const emit = defineEmits(['changed']);

const tr = useTr();
const { money, date } = useFormat();

const filters = reactive({ project_id: props.projectId ? Number(props.projectId) : undefined, status: undefined, overdue: false });

const crud = useCrudTable('project_milestones', {
  rowsKey: 'milestones',
  sortField: 'due_date',
  sortType: 'asc',
  params: () => ({
    project_id: filters.project_id || '',
    status: filters.status || '',
    overdue: filters.overdue ? 1 : '',
  }),
});

const projects = ref([]);
const projectOptions = computed(() => projects.value.map(p => ({ value: p.id, label: p.title })));

const columns = computed(() => [
  { title: tr('Milestone', 'Hito'), key: 'title', dataIndex: 'title', sorter: true },
  ...(props.projectId ? [] : [{ title: tr('Project', 'Proyecto'), key: 'project', dataIndex: 'project_title', width: 180 }]),
  { title: tr('Due', 'Vence'), key: 'due_date', dataIndex: 'due_date', sorter: true, width: 200 },
  { title: tr('Progress', 'Avance'), key: 'progress', dataIndex: 'progress', sorter: true, width: 150 },
  { title: tr('Status', 'Estado'), key: 'status', dataIndex: 'status', sorter: true, width: 160 },
  { title: tr('Budget', 'Presupuesto'), key: 'budget', dataIndex: 'budget', width: 120 },
  { title: '', key: 'actions', width: 90 },
]);

async function setStatus(record, status) {
  try {
    await http.post(`project_milestones/${record.id}/status`, { status });
    message.success(tr('Milestone updated.', 'Hito actualizado.'));
    crud.fetchRows();
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not update this milestone'));
  }
}

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref(empty());

function empty() {
  return {
    project_id: filters.project_id, title: '', description: '',
    due_date: null, status: 'pending', progress: 0, budget: null, position: 0,
  };
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  project_id: required(),
  title: required(),
  status: required(),
}));

function openForm(record) {
  editing.value = record;
  form.value = record ? { ...empty(), ...record } : empty();
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
    if (editing.value) await http.put(`project_milestones/${editing.value.id}`, form.value);
    else await http.post('project_milestones', form.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    crud.fetchRows();
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save this milestone'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  crud.fetchRows();
  if (props.projectId) return;
  try {
    const meta = await http.get('projects/meta');
    projects.value = meta?.projects || [];
  } catch (e) { /* the select stays empty */ }
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.link {
  color: #6d28d9;
  cursor: pointer;
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
  width: 180px;
}
.tb-item-sm {
  width: 140px;
}
.tb-check {
  white-space: nowrap;
}
.cell-title {
  font-weight: 500;
}
.cell-sub {
  font-size: 11.5px;
  opacity: 0.55;
  max-width: 320px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.status-select {
  width: 140px;
}
.tag-tight {
  margin-inline-start: 6px;
}
</style>
