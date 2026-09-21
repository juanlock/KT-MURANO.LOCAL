<template>
  <div class="page">
    <PageHeader :title="$t('Campaigns')" :breadcrumb="[$t('Marketing'), $t('Campaigns')]">
      <template #actions>
        <a-button type="primary" @click="$router.push('/marketing/campaigns/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('New_Campaign') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]">
        <a-col :xs="24" :md="6">
          <div class="filter-label">{{ $t('Campaign_Type') }}</div>
          <a-select
            v-model:value="typeFilter"
            style="width: 100%"
            :options="typeOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="24" :md="6">
          <div class="filter-label">{{ $t('Status') }}</div>
          <a-select
            v-model:value="statusFilter"
            style="width: 100%"
            :options="statusOptions"
            @change="crud.reload()"
          />
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'title'">
          <a @click="$router.push(`/marketing/campaigns/${record.id}`)">{{ record.title }}</a>
        </template>
        <template v-else-if="column.key === 'type'">
          <a-tag>{{ channelLabel(record.type) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="campaignStatusColor(record.status)">{{ plainLabel(record.status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'progress'">
          {{ record.sent_count }} / {{ record.total_recipients }}
          <span v-if="record.failed_count > 0" style="color: #ff4d4f">
            ({{ record.failed_count }} {{ $t('Failed') }})
          </span>
        </template>
        <template v-else-if="column.key === 'schedule'">
          {{ record.send_immediately ? '—' : shortDt(record.scheduled_at) }}
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('View_Details')">
              <a-button type="text" size="small" @click="$router.push(`/marketing/campaigns/${record.id}`)">
                <template #icon><EyeOutlined style="color: #1677ff" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="campaignSendable(record.status)" :title="$t('Send_Now')">
              <a-button type="text" size="small" @click="sendCampaign(record)">
                <template #icon><SendOutlined style="color: #13c2c2" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="campaignEditable(record.status)" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/marketing/campaigns/${record.id}/edit`)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.title })">
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
 * GET marketing/campaigns → {campaigns, totalRows}; extra filters type +
 * status. Send = POST marketing/campaigns/{id}/send after confirmation; only
 * draft/scheduled/failed can send, and sent/sending campaigns can't be edited
 * (mirrors legacy canEdit/canSend).
 */
import { ref, computed, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, SendOutlined,
  ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import {
  CHANNELS, CAMPAIGN_STATUSES, channelLabel, plainLabel,
  campaignStatusColor, campaignEditable, campaignSendable,
} from './marketingVocab';
import http from '../../lib/http';

const { t } = useI18n();

const typeFilter = ref('');
const statusFilter = ref('');

const crud = useCrudTable('marketing/campaigns', {
  rowsKey: 'campaigns',
  params: () => ({ type: typeFilter.value, status: statusFilter.value }),
});
crud.fetchRows();

const typeOptions = computed(() => [
  { value: '', label: t('All') },
  ...CHANNELS.map(c => ({ value: c, label: channelLabel(c) })),
]);
const statusOptions = computed(() => [
  { value: '', label: t('All') },
  ...CAMPAIGN_STATUSES.map(s => ({ value: s, label: plainLabel(s) })),
]);

const columns = computed(() => [
  { title: t('Campaign_Title'), dataIndex: 'title', key: 'title', sorter: true },
  { title: t('Type'), dataIndex: 'type', key: 'type', sorter: true, exportValue: r => channelLabel(r.type) },
  { title: t('Status'), dataIndex: 'status', key: 'status', sorter: true, exportValue: r => plainLabel(r.status) },
  { title: t('Delivery_Status'), key: 'progress', exportValue: r => `${r.sent_count} / ${r.total_recipients}` },
  { title: t('Scheduled_At'), key: 'schedule', exportValue: r => (r.send_immediately ? '' : shortDt(r.scheduled_at)) },
  { title: t('Action'), key: 'actions', width: 160, align: 'center' },
]);

function shortDt(d) {
  return d ? String(d).replace('T', ' ').substring(0, 16) : '—';
}

function sendCampaign(record) {
  Modal.confirm({
    title: t('Send_Campaign'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${t('Send_Now')} — ${record.title}?`,
    okText: t('Send_Now'),
    async onOk() {
      try {
        await http.post(`marketing/campaigns/${record.id}/send`);
        message.success(t('Success'));
        crud.fetchRows();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
</style>
