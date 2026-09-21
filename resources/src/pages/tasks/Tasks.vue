<template>
  <div class="page">
    <PageHeader :title="$t('Tasks')" :breadcrumb="[$t('Tasks')]">
      <template #actions>
        <a-button type="primary" @click="$router.push('/tasks/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Stat tiles (global status counts from the list payload) -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="tile in tiles" :key="tile.label" :xs="12" :md="6">
        <a-card size="small">
          <a-spin :spinning="loading">
            <a-statistic :title="tile.label" :value="tile.value" :value-style="tile.style" />
          </a-spin>
        </a-card>
      </a-col>
    </a-row>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'start_date'">{{ record.start_date ? date(record.start_date) : '—' }}</template>
        <template v-else-if="column.key === 'end_date'">{{ record.end_date ? date(record.end_date) : '—' }}</template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="projectStatusColor(record.status)">{{ projectStatusLabel(record.status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/tasks/${record.id}/edit`)">
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
  </div>
</template>

<script setup>
/** Tasks list: GET tasks → {tasks, totalRows, projects, companies}. */
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { projectStatusLabel, projectStatusColor } from '../projects/projectStatus';

const { t } = useI18n();
const { date } = useFormat();
const crud = useCrudTable('tasks', { rowsKey: 'tasks' });

const loading = computed(() => crud.loading.value);

// Global counts by status ship with the list payload (not just the page).
const payload = computed(() => crud.payload.value || {});
const tiles = computed(() => [
  { label: projectStatusLabel('not_started'), value: payload.value.count_not_started || 0 },
  { label: projectStatusLabel('progress'), value: payload.value.count_in_progress || 0, style: { color: '#1677ff' } },
  { label: projectStatusLabel('completed'), value: payload.value.count_completed || 0, style: { color: '#52c41a' } },
  { label: projectStatusLabel('cancelled'), value: payload.value.count_cancelled || 0, style: { color: '#ff4d4f' } },
]);

const columns = computed(() => [
  { title: t('Title'), dataIndex: 'title', key: 'title', sorter: true },
  { title: t('Projects'), dataIndex: 'project_title', key: 'project_title' },
  { title: t('Company'), dataIndex: 'company_name', key: 'company_name' },
  { title: t('start_date'), key: 'start_date', dataIndex: 'start_date', sorter: true, exportValue: r => (r.start_date ? date(r.start_date) : '') },
  { title: t('Finish_Date'), key: 'end_date', dataIndex: 'end_date', sorter: true, exportValue: r => (r.end_date ? date(r.end_date) : '') },
  { title: t('Status'), key: 'status', dataIndex: 'status', exportValue: r => projectStatusLabel(r.status) },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

onMounted(crud.fetchRows);
</script>
