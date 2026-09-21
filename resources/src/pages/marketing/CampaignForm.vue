<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit_Campaign') : $t('Create_Campaign')"
      :breadcrumb="[$t('Marketing'), $t('Campaigns'), isEdit ? $t('Edit') : $t('Add')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-row :gutter="16">
        <a-col :xs="24" :lg="16">
          <a-card :title="isEdit ? $t('Edit_Campaign') : $t('Create_Campaign')" style="margin-bottom: 16px">
            <a-form-item :label="$t('Campaign_Title')" name="title">
              <a-input v-model:value="form.title" />
            </a-form-item>

            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Campaign_Type')" name="type">
                  <a-select v-model:value="form.type" :options="typeOptions" @change="onTypeChange" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Use_Template')">
                  <a-select
                    v-model:value="selectedTemplate"
                    :placeholder="$t('Select_Template')"
                    allow-clear
                    :options="templateOptions"
                    @change="applyTemplate"
                  />
                </a-form-item>
              </a-col>
            </a-row>

            <a-form-item :label="$t('Description')" name="description">
              <a-input v-model:value="form.description" />
            </a-form-item>

            <a-form-item v-if="form.type === 'email'" :label="$t('Email_Subject')" name="subject">
              <a-input v-model:value="form.subject" />
            </a-form-item>

            <a-form-item :label="$t('Message_Content')" name="message_content">
              <a-textarea v-model:value="form.message_content" :rows="form.type === 'email' ? 8 : 4" />
            </a-form-item>

            <div style="margin-bottom: 16px">
              <span style="color: rgba(0, 0, 0, 0.45); font-size: 12px">{{ $t('Personalization_Variables') }}:</span>
              <a-button
                v-for="v in PERSONALIZATION_VARIABLES"
                :key="v"
                size="small"
                style="margin: 4px"
                @click="form.message_content = (form.message_content || '') + v"
              >
                {{ v }}
              </a-button>
            </div>

            <a-form-item v-if="form.type === 'email'" :label="$t('Upload_Attachment')">
              <a-upload
                :file-list="fileList"
                :before-upload="onFileSelected"
                :max-count="1"
                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                @remove="fileList = []"
              >
                <a-button><UploadOutlined /> {{ $t('Upload_Attachment') }}</a-button>
              </a-upload>
              <div v-if="form.attachment" style="color: rgba(0, 0, 0, 0.45); font-size: 12px; margin-top: 4px">
                {{ form.attachment }}
              </div>
            </a-form-item>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="8">
          <a-card :title="$t('Recipients')" size="small" style="margin-bottom: 16px">
            <a-radio-group v-model:value="audience" style="display: flex; flex-direction: column; gap: 8px">
              <a-radio value="all">{{ $t('All_Customers') }}</a-radio>
              <a-radio value="segment">{{ $t('Specific_Segment') }}</a-radio>
            </a-radio-group>
            <a-form-item v-if="audience === 'segment'" :label="$t('Select_Segment')" style="margin-top: 12px">
              <a-select
                v-model:value="form.segment_id"
                :placeholder="$t('Select_Segment')"
                show-search
                option-filter-prop="label"
                :options="segmentOptions"
              />
            </a-form-item>
          </a-card>

          <a-card :title="$t('Schedule_Campaign')" size="small" style="margin-bottom: 16px">
            <a-radio-group v-model:value="sendMode" style="display: flex; flex-direction: column; gap: 8px">
              <a-radio value="now">{{ $t('Send_Immediately') }}</a-radio>
              <a-radio value="schedule">{{ $t('Schedule_Campaign') }}</a-radio>
            </a-radio-group>
            <a-form-item v-if="sendMode === 'schedule'" :label="$t('Schedule_Date_Time')" style="margin-top: 12px">
              <a-date-picker
                v-model:value="form.scheduled_at"
                show-time
                value-format="YYYY-MM-DD HH:mm"
                format="YYYY-MM-DD HH:mm"
                style="width: 100%"
              />
            </a-form-item>
          </a-card>

          <a-button type="primary" block :loading="submitting" @click="submit">
            {{ sendMode === 'now' ? $t('Send_Campaign') : $t('submit') }}
          </a-button>
          <a-button block style="margin-top: 8px" @click="$router.push('/marketing/campaigns')">
            {{ $t('Cancel') }}
          </a-button>
        </a-col>
      </a-row>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Create: POST marketing/campaigns as multipart FormData (attachment upload).
 * Edit: POST marketing/campaigns/{id} — the API keeps a POST route for
 * multipart updates, matching legacy; sending/sent campaigns 422 there.
 * Segments from marketing/segments_all, templates from
 * marketing/templates_all?type=X (reloaded when the channel changes).
 * "Send immediately" queues the campaign on save.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { UploadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { CHANNELS, channelLabel, PERSONALIZATION_VARIABLES } from './marketingVocab';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const submitting = ref(false);
const formRef = ref();

const segments = ref([]);
const templates = ref([]);
const selectedTemplate = ref(undefined);
const fileList = ref([]);
const audience = ref('all');
const sendMode = ref('now');

const form = ref({
  title: '', description: '', type: 'sms', subject: '', message_content: '',
  attachment: '', template_id: '', segment_id: undefined, scheduled_at: null,
});

const typeOptions = computed(() => CHANNELS.map(c => ({ value: c, label: channelLabel(c) })));
const templateOptions = computed(() => templates.value.map(x => ({ value: x.id, label: x.name })));
const segmentOptions = computed(() =>
  segments.value.map(s => ({ value: s.id, label: `${s.name} (${s.customers_count})` }))
);

const rules = computed(() => ({
  title: [{ required: true, message: t('Field_is_required') }],
  type: [{ required: true, message: t('Field_is_required') }],
  message_content: [{ required: true, message: t('Field_is_required') }],
}));

function onFileSelected(file) {
  fileList.value = [file];
  return false; // keep it local; uploaded with the form
}

function onTypeChange() {
  selectedTemplate.value = undefined;
  loadTemplates();
}

function applyTemplate(tplId) {
  const tpl = templates.value.find(x => x.id === tplId);
  if (!tpl) return;
  form.value.message_content = tpl.content;
  if (tpl.subject) form.value.subject = tpl.subject;
  form.value.template_id = tpl.id;
}

async function loadSegments() {
  try {
    const data = await http.get('marketing/segments_all');
    segments.value = data.segments || [];
  } catch (e) { /* select stays empty */ }
}

async function loadTemplates() {
  try {
    const data = await http.get('marketing/templates_all', { type: form.value.type });
    templates.value = data.templates || [];
  } catch (e) { /* select stays empty */ }
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;

  const fd = new FormData();
  fd.append('title', form.value.title);
  fd.append('description', form.value.description || '');
  fd.append('type', form.value.type);
  fd.append('subject', form.value.subject || '');
  fd.append('message_content', form.value.message_content);
  fd.append('template_id', form.value.template_id || '');
  fd.append('all_customers', audience.value === 'all' ? 1 : 0);
  fd.append('segment_id', audience.value === 'segment' ? (form.value.segment_id || '') : '');
  fd.append('send_immediately', sendMode.value === 'now' ? 1 : 0);
  if (sendMode.value === 'schedule' && form.value.scheduled_at) {
    fd.append('scheduled_at', form.value.scheduled_at);
  }
  if (fileList.value.length) {
    fd.append('attachment', fileList.value[0].originFileObj || fileList.value[0]);
  }

  try {
    const url = isEdit.value ? `marketing/campaigns/${id.value}` : 'marketing/campaigns';
    await http.postForm(url, fd);
    message.success(t(isEdit.value ? 'Successfully_Updated' : 'Successfully_Created'));
    router.push('/marketing/campaigns');
  } catch (e) {
    const errors = e?.data?.errors;
    if (errors) Object.values(errors).flat().forEach(msg => message.error(String(msg)));
    else message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

async function bootstrap() {
  loadingRecord.value = true;
  loadSegments();
  try {
    if (isEdit.value) {
      const data = await http.get(`marketing/campaigns/${id.value}`);
      const c = data.campaign || {};
      form.value = {
        title: c.title || '', description: c.description || '', type: c.type || 'sms',
        subject: c.subject || '', message_content: c.message_content || '',
        attachment: c.attachment || '', template_id: c.template_id || '',
        segment_id: c.segment_id || undefined,
        scheduled_at: c.scheduled_at ? String(c.scheduled_at).replace('T', ' ').substring(0, 16) : null,
      };
      audience.value = c.all_customers ? 'all' : 'segment';
      sendMode.value = c.send_immediately ? 'now' : 'schedule';
    }
    await loadTemplates();
  } catch (e) {
    if (isEdit.value) {
      message.error(t('InvalidData'));
      router.push('/marketing/campaigns');
      return;
    }
    message.warning(t('InvalidData'));
  } finally {
    loadingRecord.value = false;
  }
}

onMounted(bootstrap);
</script>
