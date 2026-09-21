<template>
  <div class="page">
    <PageHeader :title="$t('Reports')" :breadcrumb="[$t('Recruit'), $t('Reports')]">
      <template #extra>
        <a-select v-model:value="period" style="width: 200px" @change="fetchReports">
          <a-select-option value="1month">{{ $t('Last_Month') }}</a-select-option>
          <a-select-option value="3months">{{ $t('Last_3_Months') }}</a-select-option>
          <a-select-option value="6months">{{ $t('Last_6_Months') }}</a-select-option>
          <a-select-option value="1year">{{ $t('Last_Year') }}</a-select-option>
        </a-select>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :md="12">
          <a-card size="small" :title="$t('Hiring_Funnel')" style="height: 100%">
            <div v-for="s in stages" :key="s" style="margin-bottom: 14px">
              <div style="display: flex; justify-content: space-between; margin-bottom: 4px">
                <span style="text-transform: capitalize">{{ formatLabel(s) }}</span>
                <span style="color: #999">{{ funnel[s] || 0 }}</span>
              </div>
              <a-progress
                :percent="Math.round(((funnel[s] || 0) / funnelMax) * 100)"
                :show-info="false" size="small"
              />
            </div>
          </a-card>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-card size="small" :title="$t('Average_Time_To_Hire')" style="height: 100%; text-align: center">
            <div style="padding: 32px 0">
              <div style="font-size: 48px; font-weight: 700; color: #1677ff; line-height: 1.1">
                {{ avgTimeToHire }}
              </div>
              <div style="color: #999">{{ $t('Days') }}</div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :md="12">
          <a-card size="small" :title="$t('Applications_By_Job')" :body-style="{ padding: 0 }" style="height: 100%">
            <a-table
              :columns="byJobColumns" :data-source="byJob"
              :pagination="false" size="small" :row-key="(_r, i) => i"
              :locale="{ emptyText: $t('No_data') }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'job'">{{ record.job ? record.job.title : '-' }}</template>
              </template>
            </a-table>
          </a-card>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-card size="small" :title="$t('Candidates_By_Source')" style="height: 100%">
            <div v-for="(count, src) in bySource" :key="src" style="margin-bottom: 14px">
              <div style="display: flex; justify-content: space-between; margin-bottom: 4px">
                <span style="text-transform: capitalize">{{ formatLabel(src) }}</span>
                <span style="color: #999">{{ count }}</span>
              </div>
              <a-progress
                :percent="Math.round((count / sourceMax) * 100)"
                :show-info="false" size="small" stroke-color="#06b6d4"
              />
            </div>
            <a-empty v-if="Object.keys(bySource).length === 0" :description="$t('No_data')" />
          </a-card>
        </a-col>
      </a-row>

      <a-card size="small" :title="$t('Monthly_Trend')" :body-style="{ padding: 0 }">
        <a-table
          :columns="trendColumns" :data-source="monthlyTrend"
          :pagination="false" size="small" row-key="month"
          :locale="{ emptyText: $t('No_data') }"
        />
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * Recruit reports — GET recruit/reports?period=1month|3months|6months|1year
 * → {funnel{stage: n}, by_job[{job, count}], by_source{src: n},
 * monthly_trend[{month, total, hired, rejected}], avg_time_to_hire}.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const period = ref('6months');
const funnel = ref({});
const byJob = ref([]);
const bySource = ref({});
const monthlyTrend = ref([]);
const avgTimeToHire = ref(0);
const stages = ['applied', 'screening', 'shortlisted', 'interview', 'offered', 'hired', 'rejected'];

const funnelMax = computed(() => {
  const vals = Object.values(funnel.value || {});
  const max = vals.length ? Math.max(...vals) : 0;
  return max > 0 ? max : 1;
});
const sourceMax = computed(() => {
  const vals = Object.values(bySource.value || {});
  const max = vals.length ? Math.max(...vals) : 0;
  return max > 0 ? max : 1;
});

const byJobColumns = computed(() => [
  { title: t('Job'), key: 'job' },
  { title: t('Applications'), dataIndex: 'count', key: 'count', align: 'right' },
]);
const trendColumns = computed(() => [
  { title: t('Month'), dataIndex: 'month', key: 'month' },
  { title: t('Total'), dataIndex: 'total', key: 'total', align: 'right' },
  { title: t('Hired'), dataIndex: 'hired', key: 'hired', align: 'right' },
  { title: t('Rejected'), dataIndex: 'rejected', key: 'rejected', align: 'right' },
]);

function formatLabel(v) { return v ? String(v).replace(/_/g, ' ') : '-'; }

async function fetchReports() {
  isLoading.value = true;
  try {
    const data = await http.get('recruit/reports', { period: period.value });
    funnel.value = data.funnel || {};
    byJob.value = data.by_job || [];
    bySource.value = data.by_source || {};
    monthlyTrend.value = data.monthly_trend || [];
    avgTimeToHire.value = data.avg_time_to_hire || 0;
  } catch (e) { /* report stays empty */ }
  isLoading.value = false;
}

onMounted(fetchReports);
</script>
