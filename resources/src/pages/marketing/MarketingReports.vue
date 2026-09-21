<template>
  <div class="page">
    <PageHeader :title="$t('Marketing_Reports')" :breadcrumb="[$t('Marketing'), $t('Reports')]">
      <template #actions>
        <a-space>
          <a-button :loading="exporting === 'xlsx'" @click="doExport('xlsx')">
            <template #icon><FileExcelOutlined /></template>
            {{ $t('EXCEL') }}
          </a-button>
          <a-button :loading="exporting === 'pdf'" @click="doExport('pdf')">
            <template #icon><FilePdfOutlined /></template>
            {{ $t('PDF') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-space wrap>
        <div>
          <div class="filter-label">{{ $t('Date_From') }}</div>
          <a-date-picker v-model:value="dateFrom" value-format="YYYY-MM-DD" />
        </div>
        <div>
          <div class="filter-label">{{ $t('Date_To') }}</div>
          <a-date-picker v-model:value="dateTo" value-format="YYYY-MM-DD" />
        </div>
        <a-button type="primary" style="margin-top: 21px" :loading="loading" @click="load">
          <template #icon><SearchOutlined /></template>
          {{ $t('Filter') }}
        </a-button>
      </a-space>
    </a-card>

    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="card in summaryCards" :key="card.label" :xs="12" :md="8" :xl="4">
        <a-card size="small">
          <a-statistic :title="$t(card.label)" :value="card.value" :value-style="card.style" />
        </a-card>
      </a-col>
    </a-row>

    <a-card size="small" :title="$t('Campaigns_By_Type')" :body-style="{ padding: 0 }" style="margin-bottom: 16px">
      <a-table
        :columns="byTypeColumns"
        :data-source="report.by_type || []"
        :pagination="false"
        row-key="type"
        size="middle"
        :scroll="{ x: 'max-content' }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'type'">
            <a-tag>{{ channelLabel(record.type) }}</a-tag>
          </template>
        </template>
        <template #emptyText>
          <a-empty :description="$t('NodataAvailable')" style="padding: 32px 0" />
        </template>
      </a-table>
    </a-card>

    <a-card size="small" :title="$t('Campaign_Performance')" :body-style="{ padding: 0 }">
      <a-table
        :columns="campaignColumns"
        :data-source="campaigns"
        row-key="id"
        size="middle"
        :scroll="{ x: 'max-content' }"
        :pagination="{ pageSize: 25, showSizeChanger: false }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'type'">{{ channelLabel(record.type) }}</template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="campaignStatusColor(record.status)">{{ plainLabel(record.status) }}</a-tag>
          </template>
        </template>
        <template #emptyText>
          <a-empty :description="$t('No_Campaigns')" style="padding: 32px 0" />
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup>
/**
 * GET marketing/reports?date_from&date_to → {summary, by_type, campaigns}.
 * Unfiltered, the backend defaults to the last 3 months. Not paginated —
 * the whole range comes back at once, so exports use the loaded rows.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  SearchOutlined, FileExcelOutlined, FilePdfOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { channelLabel, plainLabel, campaignStatusColor } from './marketingVocab';
import { exportExcel, exportPdf } from '../../lib/exporters';
import http from '../../lib/http';

const { t } = useI18n();

const loading = ref(false);
const exporting = ref(null);
const dateFrom = ref(null);
const dateTo = ref(null);
const report = ref({ summary: {}, by_type: [] });
const campaigns = ref([]);

const summaryCards = computed(() => {
  const s = report.value.summary || {};
  return [
    { label: 'Total_Campaigns', value: s.total_campaigns || 0 },
    { label: 'Total_Recipients', value: s.recipients || 0 },
    { label: 'Sent', value: s.sent || 0, style: { color: '#52c41a' } },
    { label: 'Delivered', value: s.delivered || 0, style: { color: '#1677ff' } },
    { label: 'Failed', value: s.failed || 0, style: { color: '#ff4d4f' } },
    { label: 'Pending', value: s.pending || 0, style: { color: '#faad14' } },
  ];
});

const byTypeColumns = computed(() => [
  { title: t('Type'), dataIndex: 'type', key: 'type' },
  { title: t('Total_Campaigns'), dataIndex: 'campaigns', key: 'campaigns', align: 'right' },
  { title: t('Total_Recipients'), dataIndex: 'recipients', key: 'recipients', align: 'right' },
  { title: t('Sent'), dataIndex: 'sent', key: 'sent', align: 'right' },
  { title: t('Failed'), dataIndex: 'failed', key: 'failed', align: 'right' },
]);

const campaignColumns = computed(() => [
  { title: t('Campaign_Title'), dataIndex: 'title', key: 'title' },
  { title: t('Type'), dataIndex: 'type', key: 'type', exportValue: r => channelLabel(r.type) },
  { title: t('Status'), dataIndex: 'status', key: 'status', exportValue: r => plainLabel(r.status) },
  { title: t('Total_Recipients'), dataIndex: 'total_recipients', key: 'total_recipients', align: 'right' },
  { title: t('Sent'), dataIndex: 'sent_count', key: 'sent_count', align: 'right' },
  { title: t('Failed'), dataIndex: 'failed_count', key: 'failed_count', align: 'right' },
]);

async function load() {
  loading.value = true;
  try {
    const data = await http.get('marketing/reports', {
      date_from: dateFrom.value || '',
      date_to: dateTo.value || '',
    });
    report.value = data;
    campaigns.value = data.campaigns || [];
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

async function doExport(kind) {
  exporting.value = kind;
  try {
    if (kind === 'xlsx') await exportExcel('marketing_report', campaignColumns.value, campaigns.value);
    else await exportPdf(t('Marketing_Reports'), campaignColumns.value, campaigns.value);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    exporting.value = null;
  }
}

onMounted(load);
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
</style>
