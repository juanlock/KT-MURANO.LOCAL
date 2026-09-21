<template>
  <div class="page">
    <PageHeader :title="$t('Campaign_Details')" :breadcrumb="[$t('Marketing'), $t('Campaigns'), $t('View_Details')]" />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else-if="campaign">
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :lg="16">
          <a-card>
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 16px">
              <div>
                <h3 style="margin: 0 0 8px">{{ campaign.title }}</h3>
                <a-space>
                  <a-tag>{{ channelLabel(campaign.type) }}</a-tag>
                  <a-tag :color="campaignStatusColor(campaign.status)">{{ plainLabel(campaign.status) }}</a-tag>
                </a-space>
              </div>
              <a-space>
                <a-button v-if="campaignSendable(campaign.status)" type="primary" @click="sendCampaign">
                  <template #icon><SendOutlined /></template>
                  {{ $t('Send_Now') }}
                </a-button>
                <a-button v-if="campaignEditable(campaign.status)" @click="$router.push(`/marketing/campaigns/${campaign.id}/edit`)">
                  <template #icon><EditOutlined /></template>
                  {{ $t('Edit') }}
                </a-button>
              </a-space>
            </div>

            <p v-if="campaign.description" style="color: rgba(0, 0, 0, 0.45)">{{ campaign.description }}</p>

            <a-descriptions :column="1" bordered size="small">
              <a-descriptions-item v-if="campaign.type === 'email' && campaign.subject" :label="$t('Email_Subject')">
                {{ campaign.subject }}
              </a-descriptions-item>
              <a-descriptions-item :label="$t('Message_Content')">
                <pre class="msg-content">{{ campaign.message_content }}</pre>
              </a-descriptions-item>
              <a-descriptions-item v-if="campaign.attachment" :label="$t('Upload_Attachment')">
                <a :href="'/' + campaign.attachment" target="_blank">{{ campaign.attachment }}</a>
              </a-descriptions-item>
              <a-descriptions-item :label="$t('Scheduled_At')">
                {{ campaign.send_immediately ? $t('Send_Immediately') : shortDt(campaign.scheduled_at) }}
              </a-descriptions-item>
              <a-descriptions-item v-if="campaign.segment" :label="$t('Segment')">
                {{ campaign.segment.name }}
              </a-descriptions-item>
            </a-descriptions>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="8">
          <a-card :title="$t('Delivery_Status')" size="small">
            <div class="stat-row"><span>{{ $t('Total_Recipients') }}</span><strong>{{ campaign.total_recipients }}</strong></div>
            <div class="stat-row"><span style="color: #52c41a">{{ $t('Sent') }}</span><strong>{{ campaign.sent_count }}</strong></div>
            <div class="stat-row"><span style="color: #ff4d4f">{{ $t('Failed') }}</span><strong>{{ campaign.failed_count }}</strong></div>
            <div class="stat-row"><span style="color: #faad14">{{ $t('Pending') }}</span><strong>{{ campaign.pending_count }}</strong></div>
          </a-card>
        </a-col>
      </a-row>

      <a-card :title="$t('Recipients')" size="small" :body-style="{ padding: 0 }">
        <a-table
          :columns="recipientColumns"
          :data-source="recipients"
          row-key="id"
          size="middle"
          :scroll="{ x: 'max-content' }"
          :pagination="{ pageSize: 25, showSizeChanger: false }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'status'">
              <a-tag :color="recipientStatusColor(record.status)">{{ plainLabel(record.status) }}</a-tag>
              <div v-if="record.error_message" style="color: #ff4d4f; font-size: 12px">{{ record.error_message }}</div>
            </template>
            <template v-else-if="column.key === 'sent_at'">{{ shortDt(record.sent_at) }}</template>
          </template>
          <template #emptyText>
            <a-empty :description="$t('NodataAvailable')" style="padding: 32px 0" />
          </template>
        </a-table>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * GET marketing/campaigns/{id} → {campaign (with segment/template),
 * recipient_stats, recipients (last 200)}. Send Now re-queues via
 * POST marketing/campaigns/{id}/send.
 */
import { ref, computed, createVNode, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { SendOutlined, EditOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import {
  channelLabel, plainLabel, campaignStatusColor, recipientStatusColor,
  campaignEditable, campaignSendable,
} from './marketingVocab';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const loading = ref(true);
const campaign = ref(null);
const recipients = ref([]);

const recipientColumns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  campaign.value?.type === 'email'
    ? { title: t('Email'), dataIndex: 'email', key: 'email' }
    : { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('Status'), dataIndex: 'status', key: 'status' },
  { title: t('Sent'), dataIndex: 'sent_at', key: 'sent_at' },
]);

function shortDt(d) {
  return d ? String(d).replace('T', ' ').substring(0, 16) : '—';
}

function sendCampaign() {
  Modal.confirm({
    title: t('Send_Campaign'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${t('Send_Now')} — ${campaign.value.title}?`,
    okText: t('Send_Now'),
    async onOk() {
      try {
        await http.post(`marketing/campaigns/${campaign.value.id}/send`);
        message.success(t('Success'));
        load();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

async function load() {
  loading.value = true;
  try {
    const data = await http.get(`marketing/campaigns/${route.params.id}`);
    campaign.value = data.campaign;
    recipients.value = data.recipients || [];
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/marketing/campaigns');
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.msg-content {
  white-space: pre-wrap;
  margin: 0;
  font-family: inherit;
}
.stat-row {
  display: flex;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
.stat-row:last-child {
  border-bottom: none;
}
</style>
