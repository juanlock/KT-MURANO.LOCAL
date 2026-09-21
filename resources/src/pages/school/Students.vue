<template>
  <div class="page">
    <PageHeader title="Students" :breadcrumb="['School', 'Students']">
      <template #actions>
        <a-button @click="$router.push('/school/enrollments')">
          <template #icon><SwapOutlined /></template>
          Enrolment
        </a-button>
        <a-button v-if="canAdd" type="primary" @click="$router.push('/school/students/create')">
          <template #icon><UserAddOutlined /></template>
          Admit student
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value"
          placeholder="Search name, admission no., guardian…"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.class_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="All classes"
          :options="classOptions" @change="onClassChange"
        />
        <a-select
          v-model:value="filters.section_id" class="tb-item-sm" allow-clear show-search
          option-filter-prop="label" placeholder="Section"
          :options="sectionOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item-sm" allow-clear
          placeholder="Status" :options="STUDENT_STATUSES" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.gender" class="tb-item-sm" allow-clear
          placeholder="Gender" :options="GENDERS" @change="crud.reload"
        />
        <a-tooltip title="Students with no class for the current year">
          <a-checkbox v-model:checked="filters.unassigned" class="tb-check" @change="crud.reload">
            Not placed
          </a-checkbox>
        </a-tooltip>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" :selectable="canDelete">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <button type="button" class="cell-s" @click="open(record)">
            <a-avatar :size="36" :src="record.image_url" class="cell-avatar">
              {{ initials(record.name) }}
            </a-avatar>
            <span class="cell-text">
              <span class="cell-name">{{ record.name }}</span>
              <span class="cell-adm">{{ record.admission_number }}</span>
            </span>
          </button>
        </template>

        <template v-else-if="column.key === 'class'">
          <template v-if="record.class_name">
            <a-tag color="purple">{{ record.class_name }}</a-tag>
            <a-tag v-if="record.section_name">{{ record.section_name }}</a-tag>
          </template>
          <a-tag v-else color="warning">Not placed</a-tag>
        </template>

        <template v-else-if="column.key === 'age'">
          <span v-if="record.age !== null">{{ record.age }}</span>
          <span v-else class="muted">—</span>
        </template>

        <template v-else-if="column.key === 'gender'">{{ labelOf(GENDERS, record.gender) }}</template>

        <template v-else-if="column.key === 'guardian'">
          <div v-if="record.guardian_name">{{ record.guardian_name }}</div>
          <div v-if="record.guardian_phone" class="cell-sub">{{ record.guardian_phone }}</div>
          <span v-if="!record.guardian_name && !record.guardian_phone" class="muted">—</span>
        </template>

        <template v-else-if="column.key === 'status'">
          <a-tag :color="optionOf(STUDENT_STATUSES, record.status).color">
            {{ labelOf(STUDENT_STATUSES, record.status) }}
          </a-tag>
        </template>

        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip title="Open record">
              <a-button type="text" size="small" @click="open(record)">
                <template #icon><EyeOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canEdit" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/school/students/${record.id}/edit`)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canDelete" :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.name })">
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
/**
 * Student roll. Class and section filters run through the ENROLMENT for the
 * current academic year, not a column on the student — which is why "not
 * placed" is a meaningful filter rather than an empty cell.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import {
  UserAddOutlined, EditOutlined, DeleteOutlined, EyeOutlined, SwapOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useAuthStore } from '../../stores/auth';
import { GENDERS, STUDENT_STATUSES, labelOf, optionOf } from './schoolOptions';
import http from '../../lib/http';

const auth = useAuthStore();
const router = useRouter();

const canAdd = computed(() => auth.can('school_students_add'));
const canEdit = computed(() => auth.can('school_students_edit'));
const canDelete = computed(() => auth.can('school_students_delete'));

const filters = reactive({
  class_id: undefined, section_id: undefined, status: undefined,
  gender: undefined, unassigned: false,
});

const crud = useCrudTable('school/students', {
  rowsKey: 'students',
  sortField: 'name',
  sortType: 'asc',
  bulkDeleteEndpoint: 'school/students/delete/by_selection',
  params: () => ({
    class_id: filters.class_id || '',
    section_id: filters.section_id || '',
    status: filters.status || '',
    gender: filters.gender || '',
    unassigned: filters.unassigned ? 1 : '',
  }),
});

const meta = ref({});
const classOptions = computed(() => (meta.value.classes || []).map(c => ({ value: c.id, label: c.name })));
const sectionOptions = computed(() => (meta.value.sections || [])
  .filter(s => !filters.class_id || s.class_id === filters.class_id)
  .map(s => ({ value: s.id, label: s.name })));

function onClassChange() {
  // A section from the previous class would filter everything away.
  filters.section_id = undefined;
  crud.reload();
}

const columns = computed(() => [
  { title: 'Student', key: 'name', dataIndex: 'name', sorter: true },
  { title: 'Class', key: 'class', width: 170 },
  { title: 'Roll', dataIndex: 'roll_number', key: 'roll_number', width: 90 },
  { title: 'Age', key: 'age', dataIndex: 'age', width: 80 },
  { title: 'Gender', key: 'gender', dataIndex: 'gender', sorter: true, width: 110 },
  { title: 'Guardian', key: 'guardian', width: 180 },
  { title: 'Status', key: 'status', dataIndex: 'status', sorter: true, width: 130 },
  { title: '', key: 'actions', width: 120 },
]);

function open(student) {
  router.push(`/school/students/${student.id}`);
}

function initials(name) {
  return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2)
    .map(part => part[0].toUpperCase()).join('');
}

onMounted(async () => {
  crud.fetchRows();
  try {
    meta.value = await http.get('school/meta');
  } catch (e) { /* filters stay empty */ }
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
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
  width: 160px;
}
.tb-item-sm {
  width: 120px;
}
.tb-check {
  white-space: nowrap;
}
.cell-s {
  display: flex;
  align-items: center;
  gap: 10px;
  border: 0;
  background: none;
  padding: 0;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
  min-width: 190px;
}
.cell-avatar {
  flex: none;
  background: rgba(109, 40, 217, 0.15);
  color: #6d28d9;
  font-size: 13px;
}
.cell-name {
  display: block;
  font-weight: 500;
}
.cell-adm {
  display: block;
  font-size: 11.5px;
  opacity: 0.55;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.cell-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.cell-s:hover .cell-name {
  color: #6d28d9;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-item-sm {
    width: 100%;
  }
}
</style>
