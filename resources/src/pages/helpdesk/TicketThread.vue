<template>
  <div class="tt">
    <div v-for="m in messages" :key="m.id" class="tt-msg" :class="[m.author_type, { internal: m.is_internal }]">
      <div class="tt-head">
        <b>{{ m.author_name || (m.author_type === 'agent' ? 'Support' : 'Requester') }}</b>
        <a-tag v-if="m.is_internal" color="gold">Internal note</a-tag>
        <a-tag v-else-if="m.author_type === 'agent'" color="blue">Support</a-tag>
        <span class="tt-meta">{{ m.created_at }} · {{ SOURCE[m.via] || m.via }}</span>
      </div>
      <div class="tt-body">{{ m.body }}</div>
      <div v-if="m.attachments && m.attachments.length" class="tt-files">
        <a-button v-for="a in m.attachments" :key="a.index" size="small" @click="download(m, a)">
          <template #icon><PaperClipOutlined /></template>{{ a.name }}<span v-if="a.size" class="tt-size">&nbsp;({{ kb(a.size) }})</span>
        </a-button>
      </div>
    </div>

    <div v-if="canReply" class="tt-reply">
      <a-textarea v-model:value="body" :auto-size="{ minRows: 4, maxRows: 14 }" :placeholder="agent && internal ? 'Internal note (the requester does not see it)…' : 'Write your reply…'" />
      <div class="tt-actions">
        <a-space wrap>
          <a-upload :file-list="files" :before-upload="addFile" multiple :show-upload-list="{ showRemoveIcon: true }" @remove="f => files = files.filter(x => x.uid !== f.uid)">
            <a-button size="small"><template #icon><PaperClipOutlined /></template>Attach</a-button>
          </a-upload>
          <a-checkbox v-if="agent" v-model:checked="internal">Internal note</a-checkbox>
        </a-space>
        <a-space wrap>
          <a-select v-if="agent && !internal" v-model:value="afterStatus" size="small" style="width: 220px" :options="statusAfter" />
          <a-button type="primary" :loading="sending" :disabled="!body.trim()" @click="send">
            <template #icon><SendOutlined /></template>{{ agent && internal ? 'Add note' : 'Send' }}
          </a-button>
        </a-space>
      </div>
    </div>
    <a-alert v-else-if="closedNote" type="info" show-icon :message="closedNote" />
  </div>
</template>

<script setup>
/**
 * A ticket's conversation + reply box. `base` is the API prefix for replies
 * and downloads: 'helpdesk' (agents: helpdesk/tickets/{id}/reply,
 * helpdesk/messages/{m}/attachments/{i}) or 'helpdesk/my' (own tickets).
 * Emits 'updated' with the API's refreshed ticket payload.
 */
import { ref } from 'vue';
import { message } from 'ant-design-vue';
import { PaperClipOutlined, SendOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { getActingCompanyId } from '../../lib/actingCompany';
import { SOURCE } from './helpdeskLabels';

const props = defineProps({
  ticketId: { type: [Number, String], required: true },
  messages: { type: Array, default: () => [] },
  base: { type: String, default: 'helpdesk' },
  agent: { type: Boolean, default: false },
  canReply: { type: Boolean, default: true },
  closedNote: { type: String, default: '' },
});
const emit = defineEmits(['updated']);

const body = ref('');
const files = ref([]);
const internal = ref(false);
const sending = ref(false);
const afterStatus = ref('');
const statusAfter = [
  { value: '', label: 'Send (waiting on requester)' },
  { value: 'resolved', label: 'Send and mark Resolved' },
  { value: 'closed', label: 'Send and Close' },
];

const kb = n => (n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);
function addFile(f) { files.value = [...files.value, f]; return false; }

async function download(m, a) {
  const replyBase = props.base === 'helpdesk' ? 'helpdesk/messages' : 'helpdesk/my/messages';
  const co = getActingCompanyId();
  try {
    await http.download(`${replyBase}/${m.id}/attachments/${a.index}${co ? `?company_id=${co}` : ''}`, a.name);
  } catch (e) { message.error('Could not download the file.'); }
}

async function send() {
  sending.value = true;
  try {
    const url = props.base === 'helpdesk' ? `helpdesk/tickets/${props.ticketId}/reply` : `helpdesk/my/${props.ticketId}/reply`;
    const fd = new FormData();
    fd.append('body', body.value);
    if (props.agent) {
      fd.append('is_internal', internal.value ? '1' : '0');
      if (!internal.value && afterStatus.value) fd.append('status', afterStatus.value);
    }
    files.value.forEach(f => fd.append('files[]', f));
    const { status, data } = await uploadForm(url, fd);
    if (status >= 200 && status < 300) {
      body.value = ''; files.value = []; afterStatus.value = '';
      message.success(internal.value ? 'Note added.' : 'Reply sent.');
      emit('updated', data);
    } else {
      message.error((data?.errors && Object.values(data.errors).flat().join(' ')) || data?.message || 'Could not send.');
    }
  } finally {
    sending.value = false;
  }
}
</script>

<style scoped>
.tt-msg { border: 1px solid rgba(5, 5, 5, 0.08); border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; background: var(--ant-color-bg-container, #fff); }
.tt-msg.agent { border-left: 3px solid #1677ff; }
.tt-msg.requester { border-left: 3px solid #52c41a; }
.tt-msg.internal { background: #fffbe6; border-left-color: #faad14; }
.tt-head { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 6px; }
.tt-meta { font-size: 12px; opacity: 0.6; }
.tt-body { white-space: pre-wrap; word-break: break-word; line-height: 1.5; }
.tt-files { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.tt-size { opacity: 0.6; font-size: 11px; }
.tt-reply { border: 1px solid rgba(5, 5, 5, 0.1); border-radius: 8px; padding: 10px; margin-top: 12px; }
.tt-actions { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; margin-top: 8px; }
</style>
