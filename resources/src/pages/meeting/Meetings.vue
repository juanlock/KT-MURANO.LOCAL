<template>
  <div class="page">
    <PageHeader :title="$t('Meetings')" :breadcrumb="[$t('Meetings')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-space wrap :size="12">
        <a-select
          v-model:value="statusFilter" style="width: 160px" allow-clear :placeholder="$t('Status')"
          :options="STATUSES.map(s => ({ value: s, label: label(s) }))" @change="crud.reload()" />
        <a-select
          v-model:value="typeFilter" style="width: 160px" allow-clear :placeholder="$t('Meeting_Type')"
          :options="TYPES.map(x => ({ value: x, label: label(x) }))" @change="crud.reload()" />
      </a-space>
    </a-card>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'title'">
          <a style="font-weight: 600" @click="$router.push(`/meeting/details/${record.id}`)">{{ record.title }}</a>
        </template>
        <template v-else-if="column.key === 'datetime'">{{ record.datetime }}</template>
        <template v-else-if="column.key === 'type'">
          <a-tag :color="record.type === 'online' ? 'processing' : 'default'">{{ label(record.type) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="statusColor(record.status)">{{ label(record.status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip v-if="record.type === 'online' && record.meeting_link" :title="$t('Join_Meeting')">
              <a-button type="text" size="small" :href="record.meeting_link" target="_blank" rel="noopener">
                <template #icon><VideoCameraOutlined style="color: #06b6d4" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Details')">
              <a-button type="text" size="small" @click="$router.push(`/meeting/details/${record.id}`)">
                <template #icon><EyeOutlined /></template>
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

    <a-modal
      v-model:open="modalOpen"
      :title="editMode ? $t('Edit') : $t('Add')"
      :confirm-loading="submitting"
      :ok-text="$t('Submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      width="760px"
      @ok="submit"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 8px">
        <a-row :gutter="16">
          <a-col :span="24">
            <a-form-item :label="$t('Title')" name="title">
              <a-input v-model:value="form.title" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('date')" name="meeting_date">
              <a-date-picker v-model:value="form.meeting_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Start_Time')" name="start_time">
              <a-time-picker v-model:value="form.start_time" value-format="HH:mm" format="HH:mm" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('End_Time')" name="end_time">
              <a-time-picker v-model:value="form.end_time" value-format="HH:mm" format="HH:mm" style="width: 100%" />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Meeting_Type')" name="type">
              <a-select v-model:value="form.type" :options="TYPES.map(x => ({ value: x, label: label(x) }))" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Status')" name="status">
              <a-select v-model:value="form.status" :options="STATUSES.map(s => ({ value: s, label: label(s) }))" />
            </a-form-item>
          </a-col>

          <!-- Conditional: physical → location; online → platform + link -->
          <a-col v-if="form.type === 'physical'" :span="24">
            <a-form-item :label="$t('Meeting_Location')" name="location">
              <a-input v-model:value="form.location" />
            </a-form-item>
          </a-col>
          <template v-else>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Platform')" name="platform">
                <a-select v-model:value="form.platform" :options="PLATFORMS.map(p => ({ value: p, label: label(p) }))" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Meeting_Link')" name="meeting_link">
                <a-input v-model:value="form.meeting_link" />
              </a-form-item>
            </a-col>
          </template>

          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Organizer')" name="organizer_id">
              <a-select v-model:value="form.organizer_id" show-search option-filter-prop="label" :options="userOptions" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Participants')" name="participants">
              <a-select v-model:value="form.participants" mode="multiple" option-filter-prop="label" :options="userOptions" />
            </a-form-item>
          </a-col>

          <a-col :span="24">
            <a-form-item :label="$t('Agenda')" name="agenda">
              <a-textarea v-model:value="form.agenda" :rows="2" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Description')" name="description">
              <a-textarea v-model:value="form.description" :rows="2" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Meetings: GET meeting/meetings → {meetings, totalRows}; filters status/type;
 * JSON form posts the whole meeting object (POST meeting/meetings,
 * PUT meeting/meetings/{id}); users from GET users_list_for_select.
 * Type/status/platform labels are humanized snake_case in legacy too — no
 * translation keys exist for them. Dashboard/calendar/reports stay legacy.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, EyeOutlined, VideoCameraOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';

const { t } = useI18n();

const TYPES = ['physical', 'online'];
const STATUSES = ['scheduled', 'ongoing', 'completed', 'cancelled'];
const PLATFORMS = ['zoom', 'google_meet', 'teams', 'other'];

const statusFilter = ref(undefined);
const typeFilter = ref(undefined);

const filterParams = () => ({
  status: statusFilter.value || '',
  type: typeFilter.value || '',
});

const crud = useCrudTable('meeting/meetings', {
  rowsKey: 'meetings',
  sortField: 'id',
  sortType: 'desc',
  params: filterParams,
});

// Humanize snake_case, matching legacy's format_label (no i18n keys exist).
const label = s => String(s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

const columns = computed(() => [
  { title: t('Title'), dataIndex: 'title', key: 'title', sorter: true },
  { title: t('date'), key: 'datetime', dataIndex: 'datetime', sorter: true, exportValue: r => r.datetime },
  { title: t('Meeting_Type'), key: 'type', dataIndex: 'type', exportValue: r => label(r.type) },
  { title: t('Organizer'), dataIndex: 'organizer', key: 'organizer' },
  { title: t('Participants'), dataIndex: 'participants_count', key: 'participants_count', align: 'right' },
  { title: t('Status'), key: 'status', dataIndex: 'status', exportValue: r => label(r.status) },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

function statusColor(s) {
  if (s === 'scheduled') return 'processing';
  if (s === 'ongoing') return 'warning';
  if (s === 'completed') return 'success';
  if (s === 'cancelled') return 'error';
  return 'default';
}

/* ---------------------------------------------------------- users list */
const users = ref([]);
const userOptions = computed(() =>
  users.value.map(u => ({ value: u.id, label: u.username || u.name }))
);
async function loadUsers() {
  try {
    const data = await http.get('users_list_for_select');
    users.value = Array.isArray(data) ? data : data.users || [];
  } catch (e) { /* selects stay empty */ }
}

/* ---------------------------------------------------------- create/edit */
const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const formRef = ref();

const emptyForm = () => ({
  id: null, title: '', meeting_date: null, start_time: null, end_time: null,
  type: 'physical', status: 'scheduled', location: '', platform: 'zoom',
  meeting_link: '', organizer_id: undefined, participants: [],
  agenda: '', description: '', reminder_minutes: 30,
});
const form = ref(emptyForm());

const rules = computed(() => ({
  title: [{ required: true, message: t('Field_is_required') }],
  meeting_date: [{ required: true, message: t('Field_is_required') }],
  start_time: [{ required: true, message: t('Field_is_required') }],
  type: [{ required: true, message: t('Field_is_required') }],
}));

function openCreate() {
  editMode.value = false;
  form.value = emptyForm();
  modalOpen.value = true;
}

async function openEdit(record) {
  // Legacy: fetch the FULL meeting (with participants) before opening the
  // form — the list row has no participants, so editing from it and saving
  // would wipe the participant list.
  try {
    const data = await http.get(`meeting/meetings/${record.id}`);
    const m = data.meeting;
    form.value = {
      ...emptyForm(),
      ...m,
      meeting_date: m.meeting_date ? String(m.meeting_date).substring(0, 10) : null,
      start_time: m.start_time ? String(m.start_time).substring(0, 5) : null,
      end_time: m.end_time ? String(m.end_time).substring(0, 5) : null,
      participants: (m.participants || []).map(p => p.user_id),
    };
    editMode.value = true;
    modalOpen.value = true;
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  try {
    if (editMode.value) {
      await http.put(`meeting/meetings/${form.value.id}`, form.value);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('meeting/meetings', form.value);
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

onMounted(() => {
  crud.fetchRows();
  loadUsers();
});
</script>
