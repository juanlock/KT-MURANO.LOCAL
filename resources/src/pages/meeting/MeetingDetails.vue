<template>
  <div class="page">
    <PageHeader :title="$t('Meeting_Details')" :breadcrumb="[$t('Meeting_Management'), $t('Meeting_Details')]">
      <template #extra>
        <a-space wrap>
          <a-button
            v-if="meeting && meeting.type === 'online' && meeting.meeting_link"
            type="primary" :href="meeting.meeting_link" target="_blank" rel="noopener"
          >
            <template #icon><VideoCameraOutlined /></template>
            {{ $t('Join_Meeting') }}
          </a-button>
          <a-button v-if="canEdit" :loading="sendingInvites" @click="sendInvitations">
            <template #icon><SendOutlined /></template>
            {{ $t('Send_Invitations') }}
          </a-button>
          <a-dropdown v-if="canEdit">
            <a-button>
              {{ $t('Status') }}
              <DownOutlined />
            </a-button>
            <template #overlay>
              <a-menu @click="({ key }) => changeStatus(key)">
                <a-menu-item v-for="s in STATUSES" :key="s">
                  <span style="text-transform: capitalize">{{ formatLabel(s) }}</span>
                </a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else-if="meeting">
      <!-- Header card -->
      <a-card size="small" style="margin-bottom: 16px">
        <div style="font-size: 18px; font-weight: 600; margin-bottom: 8px">{{ meeting.title }}</div>
        <a-space wrap size="small">
          <span class="meta"><CalendarOutlined /> {{ meeting.meeting_date }}</span>
          <span class="meta">
            <ClockCircleOutlined /> {{ shortTime(meeting.start_time) }}<template v-if="meeting.end_time"> - {{ shortTime(meeting.end_time) }}</template>
          </span>
          <a-tag :color="statusColor(meeting.status)" style="text-transform: capitalize">{{ formatLabel(meeting.status) }}</a-tag>
          <a-tag color="cyan" style="text-transform: capitalize">{{ formatLabel(meeting.type) }}</a-tag>
        </a-space>
        <div v-if="meeting.type === 'physical' && meeting.location" class="meta" style="margin-top: 6px">
          <EnvironmentOutlined /> {{ meeting.location }}
        </div>
        <div v-if="meeting.type === 'online'" class="meta" style="margin-top: 6px">
          <LinkOutlined /> {{ meeting.meeting_link || '-' }}
          <a-tag v-if="meeting.platform" style="margin-left: 6px">{{ platformLabel(meeting.platform) }}</a-tag>
        </div>
        <div class="meta" style="margin-top: 6px">
          <UserOutlined /> {{ $t('Organizer') }}: {{ userName(meeting.organizer) }}
        </div>
        <template v-if="meeting.agenda || meeting.description">
          <a-divider style="margin: 12px 0" />
          <div v-if="meeting.agenda" style="margin-bottom: 8px">
            <strong>{{ $t('Agenda') }}:</strong>
            <div class="meta" style="white-space: pre-line">{{ meeting.agenda }}</div>
          </div>
          <div v-if="meeting.description">
            <strong>{{ $t('Description') }}:</strong>
            <div class="meta" style="white-space: pre-line">{{ meeting.description }}</div>
          </div>
        </template>
      </a-card>

      <a-row :gutter="[16, 16]">
        <a-col :xs="24" :lg="12">
          <!-- Participants & attendance -->
          <a-card size="small" :title="$t('Participants') + ' & ' + $t('Attendance')" style="margin-bottom: 16px">
            <template #extra>
              <a-button v-if="canManageAttendance" type="primary" size="small" :loading="savingAttendance" @click="saveAttendance">
                {{ $t('Save_Attendance') }}
              </a-button>
            </template>
            <a-table
              :columns="participantColumns" :data-source="meeting.participants || []"
              :pagination="false" size="small" row-key="id"
              :locale="{ emptyText: $t('No_data') }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'user'">
                  {{ userName(record.user) }}
                  <a-tooltip v-if="record.is_notified" :title="$t('Invitations_Sent')">
                    <BellOutlined style="color: #52c41a" />
                  </a-tooltip>
                </template>
                <template v-else-if="column.key === 'invitation_status'">
                  <a-tag :color="inviteColor(record.invitation_status)" style="text-transform: capitalize">
                    {{ formatLabel(record.invitation_status) }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'attendance_status'">
                  <a-select
                    v-if="canManageAttendance"
                    :value="record.attendance_status" size="small" style="width: 120px"
                    @change="v => (record.attendance_status = v)"
                  >
                    <a-select-option v-for="a in ATTENDANCES" :key="a" :value="a">
                      <span style="text-transform: capitalize">{{ formatLabel(a) }}</span>
                    </a-select-option>
                  </a-select>
                  <a-tag v-else :color="attendColor(record.attendance_status)" style="text-transform: capitalize">
                    {{ formatLabel(record.attendance_status) }}
                  </a-tag>
                </template>
              </template>
            </a-table>
          </a-card>

          <!-- Attachments -->
          <a-card size="small" :title="$t('Attachments')" style="margin-bottom: 16px">
            <a-space v-if="canEdit" style="margin-bottom: 12px">
              <a-upload :file-list="attachmentList" :before-upload="() => false" :max-count="1" @change="({ fileList }) => (attachmentList = fileList)">
                <a-button size="small">
                  <template #icon><UploadOutlined /></template>
                  {{ $t('Choose_a_file') }}
                </a-button>
              </a-upload>
              <a-button size="small" type="primary" :disabled="!attachmentList.length" :loading="uploadingFile" @click="uploadAttachment">
                {{ $t('Upload_Attachment') }}
              </a-button>
            </a-space>
            <a-list :data-source="meeting.attachments || []" size="small" :locale="{ emptyText: $t('No_data') }">
              <template #renderItem="{ item }">
                <a-list-item>
                  <a :href="'/' + item.file_path" target="_blank" rel="noopener">
                    <PaperClipOutlined /> {{ item.file_name }}
                  </a>
                  <template #actions>
                    <a-button v-if="canEdit" type="text" size="small" danger @click="removeAttachment(item.id)">
                      <template #icon><DeleteOutlined /></template>
                    </a-button>
                  </template>
                </a-list-item>
              </template>
            </a-list>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="12">
          <!-- Notes & minutes -->
          <a-card size="small" :title="$t('Notes_And_Minutes')" style="margin-bottom: 16px">
            <template #extra>
              <a-button v-if="canEdit" type="primary" size="small" @click="newNote">
                <template #icon><PlusOutlined /></template>
                {{ $t('Add_Note') }}
              </a-button>
            </template>
            <a-empty v-if="!meeting.notes || !meeting.notes.length" :description="$t('No_data')" />
            <div v-for="n in meeting.notes" :key="n.id" class="note-item">
              <div style="display: flex; justify-content: space-between; align-items: center">
                <a-tag :color="noteTypeColor(n.type)" style="text-transform: capitalize">{{ formatLabel(n.type) }}</a-tag>
                <a-space v-if="canEdit">
                  <a-button type="text" size="small" @click="editNote(n)">
                    <template #icon><EditOutlined /></template>
                  </a-button>
                  <a-button type="text" size="small" danger @click="removeNote(n.id)">
                    <template #icon><DeleteOutlined /></template>
                  </a-button>
                </a-space>
              </div>
              <div style="margin-top: 4px; white-space: pre-line">{{ n.content }}</div>
              <div v-if="n.type === 'action_item'" class="meta" style="margin-top: 4px; font-size: 12px">
                <span v-if="n.assignee"><UserOutlined /> {{ userName(n.assignee) }}</span>
                <span v-if="n.due_date" style="margin-left: 8px"><CalendarOutlined /> {{ n.due_date }}</span>
                <a-tag :color="noteStatusColor(n.status)" style="margin-left: 8px; text-transform: capitalize">{{ formatLabel(n.status) }}</a-tag>
              </div>
            </div>
          </a-card>

          <!-- Activity log -->
          <a-card size="small" :title="$t('Activity_Log')" style="margin-bottom: 16px">
            <a-empty v-if="!meeting.logs || !meeting.logs.length" :description="$t('No_data')" />
            <a-timeline v-else style="margin-top: 8px">
              <a-timeline-item v-for="l in meeting.logs" :key="l.id">
                <div>{{ l.description || formatLabel(l.action) }}</div>
                <div class="meta" style="font-size: 12px">{{ userName(l.user) }} · {{ formatDatetime(l.created_at) }}</div>
              </a-timeline-item>
            </a-timeline>
          </a-card>
        </a-col>
      </a-row>

      <!-- Note modal -->
      <a-modal
        v-model:open="noteModalOpen" :title="noteEditmode ? $t('Edit') : $t('Add_Note')"
        :confirm-loading="submitting" @ok="submitNote"
      >
        <a-form ref="noteFormRef" :model="note" :rules="noteRules" layout="vertical" style="margin-top: 12px">
          <a-form-item :label="$t('Type')">
            <a-select v-model:value="note.type">
              <a-select-option value="note">{{ $t('Note') }}</a-select-option>
              <a-select-option value="decision">{{ $t('Decision') }}</a-select-option>
              <a-select-option value="action_item">{{ $t('Action_Item') }}</a-select-option>
            </a-select>
          </a-form-item>
          <a-form-item :label="$t('Content') + ' *'" name="content">
            <a-textarea v-model:value="note.content" :rows="3" />
          </a-form-item>
          <template v-if="note.type === 'action_item'">
            <a-form-item :label="$t('Assigned_To')">
              <a-select
                v-model:value="note.assigned_to" :options="participantOptions"
                show-search option-filter-prop="label" allow-clear
              />
            </a-form-item>
            <a-row :gutter="16">
              <a-col :span="12">
                <a-form-item :label="$t('Due_Date')">
                  <a-input v-model:value="note.due_date" type="date" />
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item :label="$t('Status')">
                  <a-select v-model:value="note.status">
                    <a-select-option value="open">{{ $t('Open') }}</a-select-option>
                    <a-select-option value="in_progress">{{ $t('In_Progress') }}</a-select-option>
                    <a-select-option value="done">{{ $t('Done') }}</a-select-option>
                  </a-select>
                </a-form-item>
              </a-col>
            </a-row>
          </template>
        </a-form>
      </a-modal>
    </template>
  </div>
</template>

<script setup>
/**
 * Meeting details — GET meeting/meetings/{id} → {meeting} with participants,
 * attachments, notes, logs. Actions (legacy verbatim):
 *  - status: PUT meeting/meetings/{id}/status {status}
 *  - invitations: POST meeting/meetings/{id}/invitations
 *  - attendance: POST meeting/meetings/{id}/attendance {attendance: [{id,
 *    attendance_status}]}
 *  - attachments: POST meeting/attachments (multipart meeting_id + file),
 *    DELETE meeting/attachments/{id}
 *  - notes: POST/PUT meeting/notes[/{id}], DELETE meeting/notes/{id}
 * canEdit = 'meeting' perm, canManageAttendance = 'meeting_attendance'.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  VideoCameraOutlined, SendOutlined, DownOutlined, CalendarOutlined,
  ClockCircleOutlined, EnvironmentOutlined, LinkOutlined, UserOutlined,
  BellOutlined, UploadOutlined, PaperClipOutlined, PlusOutlined,
  EditOutlined, DeleteOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { useAuthStore } from '../../stores/auth';

const { t } = useI18n();
const route = useRoute();
const auth = useAuthStore();

const STATUSES = ['scheduled', 'ongoing', 'completed', 'cancelled'];
const ATTENDANCES = ['pending', 'present', 'absent', 'late'];

const isLoading = ref(true);
const meeting = ref(null);
const sendingInvites = ref(false);
const savingAttendance = ref(false);
const uploadingFile = ref(false);
const attachmentList = ref([]);
const submitting = ref(false);

const noteModalOpen = ref(false);
const noteEditmode = ref(false);
const noteFormRef = ref();
const emptyNote = () => ({ id: '', meeting_id: '', type: 'note', content: '', assigned_to: null, due_date: '', status: 'open' });
const note = ref(emptyNote());

const canEdit = computed(() => auth.can('meeting'));
const canManageAttendance = computed(() => auth.can('meeting_attendance'));

const participantColumns = computed(() => [
  { title: t('Participant'), key: 'user' },
  { title: t('Invitation_Status'), key: 'invitation_status', width: 130 },
  { title: t('Attendance_Status'), key: 'attendance_status', width: 150 },
]);
const participantOptions = computed(() => {
  if (!meeting.value || !meeting.value.participants) return [];
  return meeting.value.participants.map(p => ({ label: userName(p.user), value: p.user_id }));
});
const noteRules = computed(() => ({
  content: [{ required: true, message: t('Field_is_required') }],
}));

function formatLabel(v) { return v ? String(v).replace(/_/g, ' ') : '-'; }
function formatDatetime(v) { return v ? String(v).replace('T', ' ').substring(0, 16) : '-'; }
function shortTime(x) { return x ? String(x).substring(0, 5) : ''; }
function platformLabel(p) {
  return { zoom: 'Zoom', google_meet: 'Google Meet', teams: 'Microsoft Teams', other: 'Other' }[p] || p;
}
function userName(u) {
  if (!u) return '-';
  const n = `${u.firstname || ''} ${u.lastname || ''}`.trim();
  return n || u.username || '-';
}
function statusColor(s) {
  return { scheduled: 'processing', ongoing: 'warning', completed: 'success', cancelled: 'error' }[s] || 'default';
}
function inviteColor(s) {
  return { invited: 'default', accepted: 'success', declined: 'error', tentative: 'warning' }[s] || 'default';
}
function attendColor(s) {
  return { pending: 'default', present: 'success', absent: 'error', late: 'warning' }[s] || 'default';
}
function noteTypeColor(x) {
  return { note: 'cyan', decision: 'success', action_item: 'warning' }[x] || 'default';
}
function noteStatusColor(s) {
  return { open: 'default', in_progress: 'cyan', done: 'success' }[s] || 'default';
}

async function fetchMeeting() {
  try {
    const data = await http.get(`meeting/meetings/${route.params.id}`);
    meeting.value = data.meeting;
  } catch (e) {
    message.error(t('InvalidData'));
  }
  isLoading.value = false;
}

async function changeStatus(status) {
  try {
    await http.put(`meeting/meetings/${meeting.value.id}/status`, { status });
    message.success(t('Updated_in_successfully'));
    fetchMeeting();
  } catch (e) {
    message.error(t('InvalidData'));
  }
}
async function sendInvitations() {
  sendingInvites.value = true;
  try {
    await http.post(`meeting/meetings/${meeting.value.id}/invitations`);
    message.success(t('Invitations_Sent'));
    fetchMeeting();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    sendingInvites.value = false;
  }
}
async function saveAttendance() {
  if (!meeting.value.participants || meeting.value.participants.length === 0) {
    message.warning(t('No_data'));
    return;
  }
  savingAttendance.value = true;
  const attendance = meeting.value.participants.map(p => ({ id: p.id, attendance_status: p.attendance_status }));
  try {
    await http.post(`meeting/meetings/${meeting.value.id}/attendance`, { attendance });
    message.success(t('Updated_in_successfully'));
    fetchMeeting();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    savingAttendance.value = false;
  }
}

async function uploadAttachment() {
  const f = attachmentList.value[0];
  if (!f) return;
  uploadingFile.value = true;
  try {
    const fd = new FormData();
    fd.append('meeting_id', meeting.value.id);
    fd.append('file', f.originFileObj || f);
    const { status } = await uploadForm('meeting/attachments', fd);
    if (status >= 200 && status < 300) {
      attachmentList.value = [];
      message.success(t('Created_in_successfully'));
      fetchMeeting();
    } else {
      message.error(t('InvalidData'));
    }
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    uploadingFile.value = false;
  }
}
function removeAttachment(id) {
  Modal.confirm({
    title: t('Delete_Title'),
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      await http.delete(`meeting/attachments/${id}`);
      message.success(t('Deleted_in_successfully'));
      fetchMeeting();
    },
  });
}

function newNote() {
  note.value = emptyNote();
  note.value.meeting_id = meeting.value.id;
  noteEditmode.value = false;
  noteFormRef.value?.clearValidate();
  noteModalOpen.value = true;
}
function editNote(n) {
  note.value = { ...emptyNote(), ...n };
  if (note.value.due_date) note.value.due_date = String(note.value.due_date).substring(0, 10);
  noteEditmode.value = true;
  noteFormRef.value?.clearValidate();
  noteModalOpen.value = true;
}
async function submitNote() {
  try {
    await noteFormRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  try {
    if (noteEditmode.value) await http.put(`meeting/notes/${note.value.id}`, note.value);
    else await http.post('meeting/notes', note.value);
    noteModalOpen.value = false;
    message.success(t('Created_in_successfully'));
    fetchMeeting();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}
function removeNote(id) {
  Modal.confirm({
    title: t('Delete_Title'),
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      await http.delete(`meeting/notes/${id}`);
      message.success(t('Deleted_in_successfully'));
      fetchMeeting();
    },
  });
}

onMounted(fetchMeeting);
</script>

<style scoped>
.meta {
  color: rgba(0, 0, 0, 0.55);
}
.note-item {
  border: 1px solid rgba(5, 5, 5, 0.06);
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 10px;
  background: rgba(0, 0, 0, 0.015);
}
</style>
