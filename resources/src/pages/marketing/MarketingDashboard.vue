<template>
  <div class="page">
    <PageHeader :title="$t('Marketing_Dashboard')" :breadcrumb="[$t('Marketing'), $t('Dashboard')]" />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="card in statCards" :key="card.label" :xs="12" :md="8" :xl="4">
          <a-card size="small">
            <a-statistic :title="$t(card.label)" :value="card.value" :value-style="card.style" />
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="[16, 16]">
        <a-col :xs="24" :lg="16">
          <!-- Last 6 months of sent volume per channel -->
          <ReportChart
            :title="$t('Marketing_Statistics')"
            :data="stats.monthly"
            x-key="label"
            type="bar"
            :fields="[
              { key: 'sms', label: $t('SMS') },
              { key: 'email', label: $t('Email') },
              { key: 'whatsapp', label: $t('WhatsApp') },
            ]"
          />
        </a-col>
        <a-col :xs="24" :lg="8">
          <a-card size="small" :title="$t('Campaigns_By_Status')" style="margin-bottom: 16px">
            <a-empty
              v-if="!statusList.length"
              :description="$t('No_Campaigns')"
              :image="simpleImage"
              style="padding: 24px 0"
            />
            <div v-for="s in statusList" :key="s.key" class="status-row">
              <a-tag :color="campaignStatusColor(s.key)">{{ plainLabel(s.key) }}</a-tag>
              <strong>{{ s.value }}</strong>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-card size="small" :title="$t('Recent_Campaign_Activity')" :body-style="{ padding: 0 }">
        <a-table
          :columns="recentColumns"
          :data-source="stats.recent || []"
          :pagination="false"
          row-key="id"
          size="middle"
          :scroll="{ x: 'max-content' }"
          :custom-row="record => ({ onClick: () => $router.push(`/marketing/campaigns/${record.id}`), style: { cursor: 'pointer' } })"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'title'">
              <a>{{ record.title }}</a>
            </template>
            <template v-else-if="column.key === 'type'">
              <a-tag>{{ channelLabel(record.type) }}</a-tag>
            </template>
            <template v-else-if="column.key === 'status'">
              <a-tag :color="campaignStatusColor(record.status)">{{ plainLabel(record.status) }}</a-tag>
            </template>
          </template>
          <template #emptyText>
            <a-empty :description="$t('No_Campaigns')" style="padding: 32px 0" />
          </template>
        </a-table>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * GET marketing/dashboard → totals, by_status, by_type, monthly (6 buckets of
 * {label, sms, email, whatsapp}) and recent (last 8 campaigns). The stat-label
 * keys (Total_Messages_Sent, …) live only in the translations DB — legacy uses
 * them via $t(card.label), so check-keys.js can't see them; verified directly.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Empty } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { channelLabel, plainLabel, campaignStatusColor } from './marketingVocab';
import http from '../../lib/http';

const { t } = useI18n();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;

const loading = ref(true);
const stats = ref({ by_status: {}, monthly: [], recent: [] });

const statCards = computed(() => [
  { label: 'Total_Campaigns', value: stats.value.total_campaigns || 0 },
  { label: 'Total_Messages_Sent', value: stats.value.total_messages || 0, style: { color: '#52c41a' } },
  { label: 'Total_Emails_Sent', value: stats.value.total_emails || 0, style: { color: '#1677ff' } },
  { label: 'Total_WhatsApp_Sent', value: stats.value.total_whatsapp || 0, style: { color: '#13c2c2' } },
  { label: 'Total_SMS_Sent', value: stats.value.total_sms || 0, style: { color: '#faad14' } },
  { label: 'Failed_Messages', value: stats.value.failed_messages || 0, style: { color: '#ff4d4f' } },
]);

const statusList = computed(() => {
  const o = stats.value.by_status || {};
  return Object.keys(o).map(k => ({ key: k, value: o[k] }));
});

const recentColumns = computed(() => [
  { title: t('Campaign_Title'), dataIndex: 'title', key: 'title' },
  { title: t('Type'), dataIndex: 'type', key: 'type' },
  { title: t('Status'), dataIndex: 'status', key: 'status' },
  { title: t('Total_Recipients'), dataIndex: 'total_recipients', key: 'total_recipients', align: 'right' },
  { title: t('Sent'), dataIndex: 'sent_count', key: 'sent_count', align: 'right' },
  { title: t('Failed'), dataIndex: 'failed_count', key: 'failed_count', align: 'right' },
]);

onMounted(async () => {
  try {
    stats.value = await http.get('marketing/dashboard');
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.status-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 7px 0;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
.status-row:last-child {
  border-bottom: none;
}
</style>
