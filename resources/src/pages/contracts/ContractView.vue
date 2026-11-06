<template>
  <div class="page">
    <PageHeader
      :title="contract ? `${contract.contract_number} — ${contract.subject}` : $t('Contract')"
      :breadcrumb="['Employee Contracts', $t('Contract')]"
    >
      <template #actions>
        <a-button :loading="pdfLoading" @click="previewContractPdf">
          <template #icon><EyeOutlined /></template>
          {{ $t('tx_Preview_PDF_dd07b') }}
        </a-button>
        <a-button type="primary" @click="downloadContractPdf">
          <template #icon><DownloadOutlined /></template>
          {{ $t('DownloadPdf') }}
        </a-button>
        <a-button v-if="canEdit && contract" @click="$router.push(`/contracts/${contract.id}/edit`)">
          <template #icon><EditOutlined /></template>
          {{ $t('Edit_Contract') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-result v-else-if="!contract" status="404" :title="$t('NodataAvailable')">
      <template #extra>
        <a-button type="primary" @click="$router.push('/contracts')">{{ $t('Contracts') }}</a-button>
      </template>
    </a-result>

    <template v-else>
      <a-card class="hero-card" style="margin-bottom: 16px">
        <div class="hero">
          <div class="hero-icon"><FileTextOutlined /></div>

          <div class="hero-body">
            <div class="hero-top">
              <span class="hero-number">{{ contract.contract_number }}</span>
              <a-tag :color="contractStatusColor(contract.status)">{{ contractStatusLabel(contract.status) }}</a-tag>
              <a-tag v-if="contractTypeLabel(contract.type)">{{ contractTypeLabel(contract.type) }}</a-tag>
            </div>
            <h2 class="hero-subject">{{ contract.subject }}</h2>

            <div class="hero-meta">
              <div class="meta-item">
                <span class="meta-label">{{ $t('Employee') }}</span>
                <span class="meta-value">
                  <a-avatar size="small" :style="{ background: avatarColor(partyName) }">{{ initial(partyName) }}</a-avatar>
                  {{ partyName || '-' }}
                </span>
              </div>
              <div v-if="contract.project_name" class="meta-item">
                <span class="meta-label">{{ $t('Project') }}</span>
                <span class="meta-value">{{ contract.project_name }}</span>
              </div>
              <div class="meta-item">
                <span class="meta-label">{{ $t('tx_Start_a6122') }}</span>
                <span class="meta-value">{{ date(contract.start_date) }}</span>
              </div>
              <div class="meta-item">
                <span class="meta-label">{{ $t('tx_End_87557') }}</span>
                <span class="meta-value">{{ date(contract.end_date) }}</span>
              </div>
            </div>
          </div>

          <div class="hero-value">
            <span class="meta-label">{{ $t('Value') }}</span>
            <span class="value-figure">{{ money(contract.value) }}</span>
          </div>
        </div>

        <div v-if="lifecycle" class="lifecycle">
          <a-progress
            :percent="lifecycle.percent" :show-info="false" size="small"
            :stroke-color="lifecycle.tone === 'over' ? '#ff4d4f' : '#7c3aed'"
          />
          <div class="lifecycle-text" :class="{ over: lifecycle.tone === 'over' }">{{ lifecycle.text }}</div>
        </div>

        <a-alert
          v-if="contract.signer_name || contract.signed_at || contract.signed_ip"
          type="success" show-icon style="margin-top: 16px"
        >
          <template #icon><SafetyCertificateOutlined /></template>
          <template #message>{{ $t('tx_Signed_71fed') }}{{ contract.signer_name ? ` by ${contract.signer_name}` : '' }}</template>
          <template #description>
            {{ dateTime(contract.signed_at) }}<span v-if="contract.signed_ip"> — IP {{ contract.signed_ip }}</span>
          </template>
        </a-alert>
      </a-card>

      <a-tabs v-model:activeKey="tab">
        <a-tab-pane key="contract">
          <template #tab><span class="tab"><FileTextOutlined />{{ $t('Contract') }}</span></template>
          <a-card size="small">
            <!-- Sanitised: contract descriptions are authored HTML. -->
            <div v-if="contract.description" class="doc-sheet">
              <div class="rich" v-html="safeDescription"></div>
            </div>
            <a-empty v-else :description="$t('tx_No_description_yet_60b93')" style="padding: 48px 0" />
          </a-card>
        </a-tab-pane>

        <a-tab-pane key="attachments">
          <template #tab>
            <span class="tab"><PaperClipOutlined />{{ $t('Attachments') }}<span v-if="counts.attachments" class="tab-count">{{ counts.attachments }}</span></span>
          </template>
          <a-card size="small">
            <a-upload
              v-if="canEdit" :file-list="[]" :before-upload="uploadAttachment" style="display: block; margin-bottom: 16px"
            >
              <a-button><template #icon><PlusOutlined /></template>{{ $t('Upload_Attachment') }}</a-button>
            </a-upload>
            <div v-if="counts.attachments" class="file-list">
              <div v-for="item in contract.attachments" :key="item.id" class="file-row">
                <span class="file-badge"><component :is="fileIcon(item.file_name)" /></span>
                <span class="file-name">{{ item.file_name }}</span>
                <a-space>
                  <a-button size="small" @click="downloadAttachment(item)">
                    <template #icon><DownloadOutlined /></template>
                    {{ $t('Download') }}
                  </a-button>
                  <a-popconfirm
                    v-if="canEdit" :title="$t('tx_Delete_this_attachment_c0e4f')"
                    @confirm="deleteAttachment(item.id)"
                  >
                    <a-button size="small" danger>{{ $t('Del') }}</a-button>
                  </a-popconfirm>
                </a-space>
              </div>
            </div>
            <a-empty v-else :description="$t('tx_No_attachments_yet_40cde')" style="padding: 32px 0" />
          </a-card>
        </a-tab-pane>

        <a-tab-pane key="comments">
          <template #tab>
            <span class="tab"><MessageOutlined />{{ $t('tx_Comments_8413c') }}<span v-if="counts.comments" class="tab-count">{{ counts.comments }}</span></span>
          </template>
          <a-card size="small">
            <div class="composer">
              <a-textarea v-model:value="newComment" :rows="2" :placeholder="$t('tx_Write_a_comment_bca4c')" />
              <div class="composer-actions">
                <a-button type="primary" :disabled="!newComment.trim()" @click="addComment">{{ $t('tx_Post_comment_5f944') }}</a-button>
              </div>
            </div>
            <div v-if="counts.comments" class="thread">
              <div v-for="item in contract.comments" :key="item.id" class="thread-item">
                <a-avatar :style="{ background: avatarColor(item.user_name) }">{{ initial(item.user_name) }}</a-avatar>
                <div class="thread-body">
                  <div class="thread-head">
                    <strong>{{ item.user_name }}</strong>
                    <span class="muted">{{ dateTime(item.created_at) }}</span>
                    <a-button
                      v-if="canEdit" type="text" size="small" danger class="thread-delete"
                      @click="deleteChild('comments', item.id)"
                    >
                      <template #icon><DeleteOutlined /></template>
                    </a-button>
                  </div>
                  <p class="thread-text">{{ item.body }}</p>
                </div>
              </div>
            </div>
            <a-empty v-else :description="$t('tx_No_comments_yet_ac887')" style="padding: 32px 0" />
          </a-card>
        </a-tab-pane>

        <a-tab-pane key="renewals">
          <template #tab>
            <span class="tab"><HistoryOutlined />{{ $t('tx_Renewal_History_577b1') }}<span v-if="counts.renewals" class="tab-count">{{ counts.renewals }}</span></span>
          </template>
          <a-card size="small">
            <a-space v-if="canEdit" wrap style="margin-bottom: 20px">
              <a-date-picker v-model:value="renewalForm.renewal_date" value-format="YYYY-MM-DD" :placeholder="$t('tx_Renewal_date_d96f0')" />
              <a-date-picker v-model:value="renewalForm.new_end_date" value-format="YYYY-MM-DD" :placeholder="$t('tx_New_end_date_3e734')" />
              <a-input v-model:value="renewalForm.notes" :placeholder="$t('tx_Notes_optional_f7ee7')" style="width: 220px" />
              <a-button type="primary" @click="addRenewal">{{ $t('tx_Add_renewal_3db25') }}</a-button>
            </a-space>
            <a-timeline v-if="counts.renewals" style="margin-top: 8px">
              <a-timeline-item v-for="item in contract.renewals" :key="item.id" color="#7c3aed">
                <strong>{{ date(item.renewal_date) }}</strong>
                <span class="muted" style="font-size: 14px"> {{ $t('tx_extended_to_ee31e') }} </span>
                <strong>{{ date(item.new_end_date) }}</strong>
                <div v-if="item.notes" class="muted" style="margin-top: 2px">{{ item.notes }}</div>
              </a-timeline-item>
            </a-timeline>
            <a-empty v-else :description="$t('tx_No_renewals_yet_1faf9')" style="padding: 32px 0" />
          </a-card>
        </a-tab-pane>

        <a-tab-pane key="tasks">
          <template #tab>
            <span class="tab"><CheckSquareOutlined />{{ $t('Tasks') }}<span v-if="counts.tasks" class="tab-count">{{ counts.tasks }}</span></span>
          </template>
          <a-card size="small">
            <a-space v-if="canEdit" wrap style="margin-bottom: 12px">
              <a-input v-model:value="taskForm.title" :placeholder="$t('tx_Task_title_277dc')" style="width: 260px" />
              <a-date-picker v-model:value="taskForm.due_date" value-format="YYYY-MM-DD" />
              <a-button type="primary" @click="addTask">{{ $t('tx_Add_task_f0a9d') }}</a-button>
            </a-space>
            <a-table
              :columns="taskColumns" :data-source="contract.tasks || []"
              :pagination="false" size="small" :row-key="r => r.id"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'due_date'">
                  <span :class="{ overdue: isOverdue(record) }">{{ date(record.due_date) }}</span>
                </template>
                <template v-else-if="column.key === 'status'">
                  <a-tag :color="record.status === 'completed' ? 'success' : 'default'">{{ record.status }}</a-tag>
                </template>
                <template v-else-if="column.key === 'actions'">
                  <a-button v-if="canEdit" size="small" danger @click="deleteChild('tasks', record.id)">{{ $t('Del') }}</a-button>
                </template>
              </template>
              <template #emptyText><a-empty :description="$t('tx_No_tasks_yet_6c727')" style="padding: 24px 0" /></template>
            </a-table>
          </a-card>
        </a-tab-pane>

        <a-tab-pane key="notes">
          <template #tab>
            <span class="tab"><FormOutlined />{{ $t('notes') }}<span v-if="counts.notes" class="tab-count">{{ counts.notes }}</span></span>
          </template>
          <a-card size="small">
            <div class="composer">
              <a-textarea v-model:value="newNote" :rows="2" :placeholder="$t('tx_Add_a_note_843b0')" />
              <div class="composer-actions">
                <a-button type="primary" :disabled="!newNote.trim()" @click="addNote">{{ $t('Add_Note') }}</a-button>
              </div>
            </div>
            <div v-if="counts.notes" class="thread">
              <div v-for="item in contract.notes" :key="item.id" class="thread-item">
                <a-avatar :style="{ background: avatarColor(item.user_name) }">{{ initial(item.user_name) }}</a-avatar>
                <div class="thread-body">
                  <div class="thread-head">
                    <strong>{{ item.user_name }}</strong>
                    <span class="muted">{{ dateTime(item.created_at) }}</span>
                    <a-button
                      v-if="canEdit" type="text" size="small" danger class="thread-delete"
                      @click="deleteChild('notes', item.id)"
                    >
                      <template #icon><DeleteOutlined /></template>
                    </a-button>
                  </div>
                  <p class="thread-text" style="white-space: pre-line">{{ item.content }}</p>
                </div>
              </div>
            </div>
            <a-empty v-else :description="$t('tx_No_notes_yet_83fdf')" style="padding: 32px 0" />
          </a-card>
        </a-tab-pane>

        <a-tab-pane key="templates">
          <template #tab><span class="tab"><SnippetsOutlined />{{ $t('Templates') }}</span></template>
          <a-card size="small">
            <a-alert
              type="info" style="margin-bottom: 12px"
              message="Use merge fields in templates: {contract_number}, {employee_name}, {start_date}, {end_date}, {contract_value}, etc."
            />
            <a-button type="link" style="padding: 0" @click="loadMergeFields">{{ $t('tx_View_available_merge_fields_e93ed') }}</a-button>
            <div v-if="mergeFields.length" class="merge-list">
              <div v-for="f in mergeFields" :key="f.key" class="muted">{{ f.key }} — {{ f.label }}</div>
            </div>

            <a-button v-if="canEdit" type="primary" size="small" style="margin: 12px 0" @click="newTemplate">
              {{ $t('tx_New_template_a5be3') }}
            </a-button>

            <a-table
              v-if="templates.length" :columns="templateColumns" :data-source="templates"
              :pagination="false" size="small" :row-key="r => r.id"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'actions'">
                  <a-space v-if="canEdit">
                    <a-button type="link" size="small" @click="editTemplate(record)">{{ $t('Edit') }}</a-button>
                    <a-popconfirm :title="$t('tx_Delete_this_template_b25f1')" @confirm="deleteTemplate(record.id)">
                      <a-button type="link" size="small" danger>{{ $t('Del') }}</a-button>
                    </a-popconfirm>
                  </a-space>
                </template>
              </template>
            </a-table>
            <p v-else class="muted">{{ $t('tx_No_templates_yet_Click_2cca7') }} <em>{{ $t('New_Template') }}</em> to create one.</p>

            <a-divider />
            <h4>{{ $t('tx_Preview_against_this_contract_b46ae') }}</h4>
            <a-select
              v-model:value="selectedTemplateId" style="width: 280px" :placeholder="$t('tx_Select_a_template_f45cd')"
              :options="templates.map(t2 => ({ value: t2.id, label: t2.name }))"
              @change="loadTemplatePreview"
            />
            <a-space v-if="selectedTemplateId" style="margin-left: 12px">
              <a-button size="small" :loading="pdfLoading" @click="previewTemplatePdf">
                <template #icon><EyeOutlined /></template>{{ $t('tx_Preview_as_PDF_e49d2') }}
              </a-button>
              <a-button size="small" @click="downloadTemplatePdf">
                <template #icon><DownloadOutlined /></template>{{ $t('DownloadPdf') }}
              </a-button>
            </a-space>
            <!-- Rendered server-side from author-controlled templates; sanitised
                 here because legacy injected it raw. -->
            <div v-if="templatePreview" class="rich preview" v-html="safeTemplatePreview"></div>
          </a-card>
        </a-tab-pane>
      </a-tabs>
    </template>

    <a-modal
      v-model:open="templateModal" :title="templateForm.id ? $t('Edit_Template') : $t('New_Template')"
      width="800px" :ok-text="$t('Save')" @ok="saveTemplate"
    >
      <a-form layout="vertical">
        <a-form-item :label="$t('tx_name_4871e')">
          <a-input v-model:value="templateForm.name" placeholder="e.g. Standard service contract" />
        </a-form-item>
        <a-form-item label="Content (HTML supported, use merge fields like {employee_name})">
          <a-textarea v-model:value="templateForm.content" :rows="12" />
        </a-form-item>
      </a-form>
    </a-modal>

    <a-modal v-model:open="pdfModal" :title="pdfTitle" width="900px" :footer="null" @cancel="closePdf">
      <iframe v-if="pdfUrl" :src="pdfUrl" style="width: 100%; height: 70vh; border: 0" :title="$t('tx_PDF_preview_54fc2')"></iframe>
      <div v-else class="muted" style="padding: 48px; text-align: center">{{ $t('tx_Loading_preview_21f58') }}</div>
    </a-modal>
  </div>
</template>

<script setup>
import { t as tEs } from '../../i18n/index.js';
/**
 * Contract view — legacy View_contract.vue: summary, seven tabs (description,
 * attachments, comments, renewals, tasks, notes, templates) and the PDF
 * generation surface, which is why this page stayed on legacy longest.
 *
 * Legacy is almost entirely un-internationalised — five $t() calls in 465
 * lines — so the English labels are kept verbatim rather than inventing keys
 * that do not exist in the translations table.
 *
 * Two deliberate deviations:
 * - type/status render through contractVocab (labels + Ant colours) instead of
 *   raw database values, matching the rest of the migrated contracts pages.
 * - the rendered template HTML is sanitised. Legacy sanitised the contract
 *   description but injected the template preview raw, which is an XSS hole
 *   given templates are user-authored.
 *
 * Templates are a GLOBAL resource (`contracts-templates`) even though they are
 * edited from inside one contract — editing one affects every contract.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import DOMPurify from 'dompurify';
import dayjs from 'dayjs';
import {
  EyeOutlined, DownloadOutlined, EditOutlined, PlusOutlined, DeleteOutlined,
  FileTextOutlined, PaperClipOutlined, MessageOutlined, HistoryOutlined,
  CheckSquareOutlined, FormOutlined, SnippetsOutlined, EyeInvisibleOutlined,
  SafetyCertificateOutlined, FilePdfOutlined, FileImageOutlined,
  FileWordOutlined, FileExcelOutlined, FileZipOutlined, FileOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useAuthStore } from '../../stores/auth';
import { useFormat } from '../../composables/useFormat';
import { contractTypeLabel, contractStatusLabel, contractStatusColor } from './contractVocab';
import http from '../../lib/http';

const { t } = useI18n();
const { money, date, dateTime } = useFormat();
const auth = useAuthStore();
const route = useRoute();

const loading = ref(true);
const contract = ref(null);
const tab = ref('contract');

const canEdit = computed(() => auth.can('contracts'));
const partyName = computed(() => {
  const c = contract.value || {};
  return c.employee_name || c.party_name;
});
const safeDescription = computed(() => DOMPurify.sanitize(contract.value?.description || ''));

const counts = computed(() => {
  const c = contract.value || {};
  return {
    attachments: (c.attachments || []).length,
    comments: (c.comments || []).length,
    renewals: (c.renewals || []).length,
    tasks: (c.tasks || []).length,
    notes: (c.notes || []).length,
  };
});

/** Elapsed share of the contract term, for the hero progress bar. */
const lifecycle = computed(() => {
  const c = contract.value || {};
  const start = c.start_date ? dayjs(c.start_date) : null;
  const end = c.end_date ? dayjs(c.end_date) : null;
  if (!start?.isValid() || !end?.isValid() || !end.isAfter(start)) return null;
  const now = dayjs();
  const total = end.diff(start, 'day') || 1;
  const percent = Math.min(100, Math.max(0, Math.round((now.diff(start, 'day') / total) * 100)));
  const daysLeft = end.startOf('day').diff(now.startOf('day'), 'day');
  if (daysLeft < 0) return { percent, tone: 'over', text: `Ended ${Math.abs(daysLeft)} day${Math.abs(daysLeft) === 1 ? '' : 's'} ago` };
  if (now.isBefore(start)) {
    const until = start.startOf('day').diff(now.startOf('day'), 'day');
    return { percent, tone: 'ok', text: `Starts in ${until} day${until === 1 ? '' : 's'}` };
  }
  return { percent, tone: 'ok', text: daysLeft === 0 ? 'Ends today' : `${daysLeft} day${daysLeft === 1 ? '' : 's'} remaining` };
});

const AVATAR_COLORS = ['#7c3aed', '#1677ff', '#13c2c2', '#fa8c16', '#eb2f96', '#52c41a'];
function avatarColor(name) {
  const s = String(name || '');
  let h = 0;
  for (let i = 0; i < s.length; i += 1) h = (h + s.charCodeAt(i)) % AVATAR_COLORS.length;
  return AVATAR_COLORS[h];
}
const initial = name => (String(name || '?').trim().charAt(0) || '?').toUpperCase();

const FILE_ICONS = {
  pdf: FilePdfOutlined,
  png: FileImageOutlined, jpg: FileImageOutlined, jpeg: FileImageOutlined,
  gif: FileImageOutlined, webp: FileImageOutlined, svg: FileImageOutlined,
  doc: FileWordOutlined, docx: FileWordOutlined,
  xls: FileExcelOutlined, xlsx: FileExcelOutlined, csv: FileExcelOutlined,
  zip: FileZipOutlined, rar: FileZipOutlined, '7z': FileZipOutlined,
};
const fileIcon = name => FILE_ICONS[String(name || '').split('.').pop().toLowerCase()] || FileOutlined;

const isOverdue = task => task.status !== 'completed' && task.due_date
  && dayjs(task.due_date).isBefore(dayjs(), 'day');

const taskColumns = computed(() => [
  { title: tEs('title', 'Title'), dataIndex: 'title', key: 'title' },
  { title: tEs('Due', 'Due'), dataIndex: 'due_date', key: 'due_date' },
  { title: tEs('Serial_Status', 'Status'), dataIndex: 'status', key: 'status' },
  { title: '', key: 'actions', width: 90 },
]);
const templateColumns = [
  { title: tEs('Name', 'Name'), dataIndex: 'name', key: 'name' },
  { title: '', key: 'actions', width: 140 },
];

async function fetchContract() {
  try {
    const data = await http.get(`contracts/${route.params.id}`);
    contract.value = data?.contract || null;
  } catch (e) {
    contract.value = null;
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

// ---------------- child collections ----------------

const newComment = ref('');
const newNote = ref('');
const renewalForm = ref({ renewal_date: null, new_end_date: null, notes: '' });
const taskForm = ref({ title: '', due_date: null });

const base = () => `contracts/${route.params.id}`;

async function mutate(fn, successMsg) {
  try {
    await fn();
    if (successMsg) message.success(successMsg);
    await fetchContract();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

const addComment = () => mutate(async () => {
  await http.post(`${base()}/comments`, { body: newComment.value });
  newComment.value = '';
});
const addNote = () => mutate(async () => {
  await http.post(`${base()}/notes`, { content: newNote.value });
  newNote.value = '';
});
const addRenewal = () => {
  if (!renewalForm.value.renewal_date || !renewalForm.value.new_end_date) return;
  mutate(async () => {
    await http.post(`${base()}/renewals`, { ...renewalForm.value });
    renewalForm.value = { renewal_date: null, new_end_date: null, notes: '' };
  });
};
const addTask = () => {
  if (!taskForm.value.title.trim()) return;
  mutate(async () => {
    await http.post(`${base()}/tasks`, { ...taskForm.value });
    taskForm.value = { title: '', due_date: null };
  });
};
const deleteChild = (kind, id) => mutate(() => http.delete(`${base()}/${kind}/${id}`));

// ---------------- attachments ----------------

/** Returning false stops a-upload's own request — we post it ourselves. */
function uploadAttachment(file) {
  const fd = new FormData();
  fd.append('file', file);
  mutate(() => http.postForm(`${base()}/attachments`, fd), 'Uploaded');
  return false;
}
async function downloadAttachment(a) {
  try {
    await http.download(`${base()}/attachments/${a.id}/download`, a.file_name);
  } catch (e) {
    message.error(tEs('tx_Download_failed_0b21d', 'Download failed'));
  }
}
const deleteAttachment = id => mutate(() => http.delete(`${base()}/attachments/${id}`));

// ---------------- templates ----------------

const templates = ref([]);
const mergeFields = ref([]);
const selectedTemplateId = ref(null);
const templatePreview = ref('');
const templateModal = ref(false);
const templateForm = ref({ id: null, name: '', content: '' });

const safeTemplatePreview = computed(() => DOMPurify.sanitize(templatePreview.value || ''));

async function fetchTemplates() {
  try {
    const data = await http.get('contracts-templates');
    templates.value = data?.templates || [];
  } catch (e) { /* tab renders its empty state */ }
}
async function loadMergeFields() {
  if (mergeFields.value.length) return;
  try {
    const data = await http.get('contracts/merge-fields');
    mergeFields.value = data?.merge_fields || [];
  } catch (e) { /* link simply does nothing, as in legacy */ }
}
async function loadTemplatePreview() {
  if (!selectedTemplateId.value) return;
  try {
    const data = await http.get(`${base()}/templates/${selectedTemplateId.value}/render`);
    templatePreview.value = data?.content || '';
  } catch (e) {
    templatePreview.value = '';
  }
}
function newTemplate() {
  templateForm.value = { id: null, name: '', content: '' };
  templateModal.value = true;
}
function editTemplate(row) {
  templateForm.value = { ...row };
  templateModal.value = true;
}
async function saveTemplate() {
  if (!templateForm.value.name.trim()) {
    message.warning(tEs('tx_Template_name_is_required_b122d', 'Template name is required.'));
    return;
  }
  const body = { name: templateForm.value.name, content: templateForm.value.content };
  try {
    if (templateForm.value.id) await http.put(`contracts-templates/${templateForm.value.id}`, body);
    else await http.post('contracts-templates', body);
    message.success(tEs('tx_Template_saved_8a381', 'Template saved.'));
    templateModal.value = false;
    await fetchTemplates();
  } catch (e) {
    const errors = e?.data?.errors;
    message.error(e?.data?.message || (errors && Object.values(errors).flat()[0]) || 'Failed to save template.');
  }
}
async function deleteTemplate(id) {
  try {
    await http.delete(`contracts-templates/${id}`);
    message.success(tEs('tx_Template_deleted_6f5d4', 'Template deleted.'));
    if (selectedTemplateId.value === id) {
      selectedTemplateId.value = null;
      templatePreview.value = '';
    }
    await fetchTemplates();
  } catch (e) {
    message.error(tEs('Delete_Failed', 'Delete failed.'));
  }
}

// ---------------- PDF ----------------

const pdfModal = ref(false);
const pdfUrl = ref('');
const pdfTitle = ref('PDF preview');
const pdfLoading = ref(false);

function revokePdf() {
  if (pdfUrl.value) {
    window.URL.revokeObjectURL(pdfUrl.value);
    pdfUrl.value = '';
  }
}
function closePdf() {
  revokePdf();
  pdfModal.value = false;
}

async function openPreview(url, title) {
  pdfLoading.value = true;
  revokePdf();
  pdfTitle.value = title;
  pdfModal.value = true;
  try {
    const b = await http.blob(url);
    pdfUrl.value = window.URL.createObjectURL(b);
  } catch (e) {
    message.error(tEs('tx_Preview_failed_91ed5', 'Preview failed'));
    pdfModal.value = false;
  } finally {
    pdfLoading.value = false;
  }
}

const previewContractPdf = () => openPreview(
  `${base()}/pdf?preview=1`,
  `Contract ${contract.value?.contract_number || ''} — preview`
);
const downloadContractPdf = () => http
  .download(`${base()}/pdf`, `Contract_${contract.value?.contract_number || route.params.id}.pdf`)
  .catch(() => message.error(tEs('tx_Download_failed_0b21d', 'Download failed')));

const previewTemplatePdf = () => {
  const tpl = templates.value.find(x => x.id === selectedTemplateId.value);
  return openPreview(
    `${base()}/templates/${selectedTemplateId.value}/pdf?preview=1`,
    tpl ? `Template: ${tpl.name} — preview` : 'Template preview'
  );
};
const downloadTemplatePdf = () => {
  const tpl = templates.value.find(x => x.id === selectedTemplateId.value);
  const safeName = (tpl?.name || 'template').replace(/[^A-Za-z0-9_-]+/g, '_');
  return http
    .download(
      `${base()}/templates/${selectedTemplateId.value}/pdf`,
      `Template_${safeName}_${contract.value?.contract_number || route.params.id}.pdf`
    )
    .catch(() => message.error(tEs('tx_Download_failed_0b21d', 'Download failed')));
};

// Legacy captured the id once and never refetched; the router reuses this
// component between contracts, so watch it.
watch(() => route.params.id, () => {
  loading.value = true;
  fetchContract();
});

onMounted(() => {
  fetchContract();
  fetchTemplates();
});
onBeforeUnmount(revokePdf);
</script>

<style scoped>
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}

/* ---------------- hero ---------------- */
.hero {
  display: flex;
  align-items: flex-start;
  gap: 20px;
  flex-wrap: wrap;
}
.hero-icon {
  width: 56px;
  height: 56px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 26px;
  border-radius: 14px;
  color: #7c3aed;
  background: rgba(124, 58, 237, 0.1);
}
.hero-body {
  flex: 1 1 320px;
  min-width: 0;
}
.hero-top {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
}
.hero-number {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 13px;
  letter-spacing: 0.03em;
  opacity: 0.65;
  margin-right: 6px;
}
.hero-subject {
  margin: 6px 0 16px;
  font-size: 22px;
  font-weight: 600;
  line-height: 1.3;
}
.hero-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 10px 36px;
}
.meta-item {
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.meta-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  opacity: 0.55;
}
.meta-value {
  font-size: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.hero-value {
  margin-left: auto;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 3px;
}
.value-figure {
  font-size: 26px;
  font-weight: 600;
  line-height: 1.2;
  color: #7c3aed;
  white-space: nowrap;
}
.lifecycle {
  margin-top: 20px;
}
.lifecycle-text {
  margin-top: 2px;
  text-align: right;
  font-size: 12px;
  opacity: 0.7;
}
.lifecycle-text.over {
  color: #ff4d4f;
  opacity: 1;
}

/* ---------------- tabs ---------------- */
.tab {
  display: inline-flex;
  align-items: center;
  gap: 7px;
}
.tab-count {
  font-size: 11px;
  line-height: 18px;
  min-width: 18px;
  padding: 0 6px;
  text-align: center;
  border-radius: 10px;
  background: rgba(128, 128, 128, 0.15);
}

/* ---------------- description as a document sheet ---------------- */
.doc-sheet {
  max-width: 860px;
  margin: 8px auto;
  padding: 36px 44px;
  border: 1px solid rgba(128, 128, 128, 0.18);
  border-radius: 12px;
  box-shadow: 0 1px 8px rgba(0, 0, 0, 0.04);
}
@media (max-width: 640px) {
  .doc-sheet {
    padding: 20px;
  }
}
.rich :deep(p) {
  line-height: 1.75;
  margin-bottom: 0.8em;
}
.rich :deep(h1), .rich :deep(h2), .rich :deep(h3) {
  margin: 1.2em 0 0.5em;
  line-height: 1.35;
}
.rich :deep(ul), .rich :deep(ol) {
  padding-left: 1.4em;
  margin-bottom: 0.8em;
}
.rich :deep(blockquote) {
  margin: 0.8em 0;
  padding: 4px 16px;
  border-left: 3px solid rgba(124, 58, 237, 0.5);
  opacity: 0.85;
}
.rich :deep(img) { max-width: 100%; }
.rich :deep(table) { width: 100%; border-collapse: collapse; }
.rich :deep(td), .rich :deep(th) { border: 1px solid rgba(5, 5, 5, 0.1); padding: 6px; }

/* ---------------- attachments ---------------- */
.file-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.file-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px;
  border: 1px solid rgba(128, 128, 128, 0.16);
  border-radius: 10px;
}
.file-badge {
  font-size: 20px;
  color: #7c3aed;
  display: flex;
}
.file-name {
  flex: 1;
  min-width: 0;
  overflow-wrap: anywhere;
}

/* ---------------- comment / note threads ---------------- */
.composer .composer-actions {
  margin-top: 8px;
  display: flex;
  justify-content: flex-end;
}
.thread {
  display: flex;
  flex-direction: column;
  gap: 18px;
  margin-top: 24px;
}
.thread-item {
  display: flex;
  gap: 12px;
}
.thread-body {
  flex: 1;
  min-width: 0;
}
.thread-head {
  display: flex;
  align-items: center;
  gap: 10px;
}
.thread-delete {
  margin-left: auto;
}
.thread-text {
  margin: 3px 0 0;
  line-height: 1.6;
  overflow-wrap: anywhere;
}

/* ---------------- misc ---------------- */
.overdue {
  color: #ff4d4f;
}
.merge-list {
  margin-top: 8px;
  padding: 8px;
  background: rgba(128, 128, 128, 0.08);
  border-radius: 6px;
}
.preview {
  margin-top: 16px;
  padding: 16px;
  border: 1px solid rgba(5, 5, 5, 0.1);
  border-radius: 8px;
}
</style>
