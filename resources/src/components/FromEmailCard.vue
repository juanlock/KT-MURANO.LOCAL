<template>
  <!-- A document being created from an Email Office email (?email=ID): what
       the email says, its attachments, and that saving records it. -->
  <a-card v-if="email" size="small" class="fe-card">
    <div class="fe-title">
      <MailOutlined /> {{ $t('tx_From_the_email_ac6f1') }}{{ email.subject }}” — {{ email.from_name || email.from_email }}
      <span class="fe-muted" style="font-weight: 400"> · {{ String(email.sent_at || '').slice(0, 10) }}</span>
    </div>
    <div class="fe-muted"><slot>{{ $t('tx_The_email_is_marked_as_recorded_when_you_save_d56f9') }}</slot></div>
    <div v-if="(email.attachments || []).length" class="fe-row">
      <span class="fe-muted">{{ $t('tx_Attachments_dc964') }}</span>
      <a-button v-for="a in email.attachments" :key="a.index" size="small" :disabled="!a.downloadable" @click="openAttachment(a)">
        <template #icon><PaperClipOutlined /></template>
        {{ a.name }}
      </a-button>
    </div>
    <a-collapse ghost size="small" style="margin-top: 4px" :default-active-key="openText ? ['text'] : []">
      <a-collapse-panel key="text" :header="$t('tx_Read_the_email_a5733')">
        <pre class="fe-text">{{ email.body_text || '(empty message)' }}</pre>
      </a-collapse-panel>
    </a-collapse>
  </a-card>
</template>

<script setup>
import { t as tEs } from '../i18n/index.js';
/**
 * Shared "From the email" card for forms opened from Email Office (quotation,
 * service job...). The parent loads the email (GET email-office/emails/{id})
 * and passes it; attachments open in a new tab.
 */
import { message } from 'ant-design-vue';
import { MailOutlined, PaperClipOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';

const props = defineProps({
  email: { type: Object, default: null },
  openText: { type: Boolean, default: true },
});

async function openAttachment(a) {
  try {
    const b = await http.blob(`email-office/emails/${props.email.id}/attachments/${a.index}`, true);
    const typed = /\.pdf$/i.test(a.name) && b.type !== 'application/pdf' ? new Blob([b], { type: 'application/pdf' }) : b;
    window.open(URL.createObjectURL(typed), '_blank');
  } catch (e) {
    message.error(tEs('tx_Could_not_open_the_attachment_195b2', 'Could not open the attachment.'));
  }
}
</script>

<style scoped>
.fe-card {
  margin-bottom: 12px;
}
.fe-title {
  font-weight: 600;
  margin-bottom: 2px;
}
.fe-muted {
  color: #888;
  font-size: 12px;
}
.fe-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
}
.fe-text {
  max-height: 240px;
  overflow: auto;
  white-space: pre-wrap;
  font-size: 12px;
  margin: 0;
}
</style>
