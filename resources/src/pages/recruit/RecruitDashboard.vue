<template>
  <div class="page">
    <PageHeader :title="$t('Dashboard')" :breadcrumb="[$t('Recruit'), $t('Dashboard')]" />

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- Stat cards -->
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="card in statCards" :key="card.label" :xs="12" :md="8" :xl="4">
          <a-card size="small">
            <a-statistic :title="$t(card.label)" :value="card.value" :value-style="{ color: card.color }" />
          </a-card>
        </a-col>
      </a-row>

      <!-- Pipeline -->
      <a-card size="small" :title="$t('Pipeline')" style="margin-bottom: 16px">
        <div class="pipeline-bar">
          <a-tooltip v-for="(s, idx) in stages" :key="'seg-' + s" :title="formatLabel(s) + ': ' + (pipeline[s] || 0)">
            <div class="pipeline-seg" :style="{ flex: (pipeline[s] || 0) + 0.02, background: STAGE_COLORS[idx] }"></div>
          </a-tooltip>
        </div>
        <a-space wrap style="margin-top: 14px">
          <span v-for="(s, idx) in stages" :key="'leg-' + s" class="legend-item">
            <span class="legend-dot" :style="{ background: STAGE_COLORS[idx] }"></span>
            <span style="text-transform: capitalize">{{ formatLabel(s) }}</span>
            <strong>{{ pipeline[s] || 0 }}</strong>
          </span>
        </a-space>
      </a-card>

      <a-row :gutter="[16, 16]">
        <!-- Recent applications -->
        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="$t('Recent_Applications')">
            <a-list
              :data-source="recentApplications" size="small"
              :locale="{ emptyText: $t('No_data') }"
            >
              <template #renderItem="{ item }">
                <a-list-item>
                  <a-list-item-meta>
                    <template #avatar>
                      <a-avatar style="background: #4361ee">{{ initials(candidateNameApp(item)) }}</a-avatar>
                    </template>
                    <template #title>{{ candidateNameApp(item) }}</template>
                    <template #description>{{ item.job ? item.job.title : '-' }}</template>
                  </a-list-item-meta>
                  <div style="text-align: right">
                    <a-tag :color="stageColor(item.stage)" style="text-transform: capitalize">{{ formatLabel(item.stage) }}</a-tag>
                    <div style="font-size: 11px; color: #999; margin-top: 4px">{{ item.applied_date }}</div>
                  </div>
                </a-list-item>
              </template>
            </a-list>
          </a-card>
        </a-col>

        <!-- Upcoming interviews -->
        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="$t('Upcoming_Interviews')">
            <a-list
              :data-source="upcomingInterviews" size="small"
              :locale="{ emptyText: $t('No_data') }"
            >
              <template #renderItem="{ item }">
                <a-list-item>
                  <a-list-item-meta>
                    <template #avatar>
                      <a-avatar style="background: #eef1ff; color: #4361ee">
                        <template #icon><CalendarOutlined /></template>
                      </a-avatar>
                    </template>
                    <template #title>{{ interviewCandidate(item) }}</template>
                    <template #description><span style="text-transform: capitalize">{{ formatLabel(item.type) }}</span></template>
                  </a-list-item-meta>
                  <div style="text-align: right">
                    <a-tag color="cyan" style="text-transform: capitalize">{{ formatLabel(item.status) }}</a-tag>
                    <div style="font-size: 11px; color: #999; margin-top: 4px">{{ formatDatetime(item.scheduled_at) }}</div>
                  </div>
                </a-list-item>
              </template>
            </a-list>
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * Recruit dashboard — GET recruit/dashboard → {stats{open_jobs,
 * total_candidates, total_applications, pending_interviews, hired_count,
 * total_jobs}, recent_applications, upcoming_interviews, pipeline{stage:
 * count}}. Pipeline bar keeps the legacy flex trick (count + 0.02 so empty
 * stages still show a sliver).
 */
import { ref, computed, onMounted } from 'vue';
import { CalendarOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const isLoading = ref(true);
const stats = ref({});
const recentApplications = ref([]);
const upcomingInterviews = ref([]);
const pipeline = ref({});
const stages = ['applied', 'screening', 'shortlisted', 'interview', 'offered', 'hired', 'rejected'];
const STAGE_COLORS = ['#4361ee', '#06b6d4', '#8b5cf6', '#f59e0b', '#ec4899', '#22c55e', '#ef4444'];

const statCards = computed(() => [
  { label: 'Open_Jobs', value: stats.value.open_jobs || 0, color: '#4361ee' },
  { label: 'Candidates', value: stats.value.total_candidates || 0, color: '#06b6d4' },
  { label: 'Applications', value: stats.value.total_applications || 0, color: '#8b5cf6' },
  { label: 'Pending_Interviews', value: stats.value.pending_interviews || 0, color: '#f59e0b' },
  { label: 'Hired', value: stats.value.hired_count || 0, color: '#22c55e' },
  { label: 'Total_Jobs', value: stats.value.total_jobs || 0, color: '#334155' },
]);

function formatLabel(v) { return v ? String(v).replace(/_/g, ' ') : '-'; }
function formatDatetime(v) { return v ? String(v).replace('T', ' ').substring(0, 16) : '-'; }
function candidateNameApp(a) {
  return a.candidate ? `${a.candidate.first_name} ${a.candidate.last_name}` : '-';
}
function interviewCandidate(i) {
  const c = i.application && i.application.candidate;
  return c ? `${c.first_name} ${c.last_name}` : '-';
}
function initials(name) {
  if (!name || name === '-') return '?';
  const parts = name.trim().split(/\s+/);
  return ((parts[0] ? parts[0][0] : '') + (parts[1] ? parts[1][0] : '')).toUpperCase();
}
function stageColor(s) {
  const map = {
    applied: 'blue', screening: 'cyan', shortlisted: 'purple',
    interview: 'orange', offered: 'gold', hired: 'success', rejected: 'error',
  };
  return map[s] || 'default';
}

onMounted(async () => {
  try {
    const data = await http.get('recruit/dashboard');
    stats.value = data.stats || {};
    recentApplications.value = data.recent_applications || [];
    upcomingInterviews.value = data.upcoming_interviews || [];
    pipeline.value = data.pipeline || {};
  } catch (e) { /* dashboard stays empty */ }
  isLoading.value = false;
});
</script>

<style scoped>
.pipeline-bar {
  display: flex;
  gap: 4px;
  height: 14px;
}
.pipeline-seg {
  border-radius: 6px;
  min-width: 6px;
  transition: flex 0.4s ease;
}
.legend-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  margin-right: 12px;
}
.legend-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  display: inline-block;
}
</style>
