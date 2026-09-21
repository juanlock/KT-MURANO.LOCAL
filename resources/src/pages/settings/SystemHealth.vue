<template>
  <div class="page">
    <PageHeader :title="$t('System_Health')" :breadcrumb="[$t('Settings'), $t('System_Health')]">
      <template #actions>
        <a-space>
          <a-button :loading="loading" @click="load">
            <template #icon><ReloadOutlined /></template>
            {{ $t('Refresh') }}
          </a-button>
          <a-button :loading="downloading" @click="downloadPdf">
            <template #icon><FilePdfOutlined /></template>
            {{ $t('Download_Report_PDF') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- Overall status hero -------------------------------------------->
      <a-card :bordered="false" class="hero" :class="`hero--${health.level}`">
        <div class="hero__body">
          <div class="hero__left">
            <a-avatar :size="52" :style="{ background: health.color, flex: '0 0 auto' }">
              <template #icon><component :is="health.icon" /></template>
            </a-avatar>
            <div>
              <div class="hero__status" :style="{ color: health.color }">{{ health.label }}</div>
              <div class="hero__summary">{{ health.summary }}</div>
            </div>
          </div>
          <div v-if="generatedAt" class="hero__meta">
            <ClockCircleOutlined />
            <span>{{ $t('Last_updated') }}: {{ generatedAt }}</span>
          </div>
        </div>
      </a-card>

      <!-- Environment ---------------------------------------------------->
      <div class="section-label">{{ $t('Environment') }}</div>
      <a-row :gutter="[16, 16]">
        <a-col v-for="tile in envTiles" :key="tile.label" :xs="24" :sm="12" :xl="8">
          <a-card :bordered="false" class="tile">
            <a-avatar :size="42" :style="{ background: tile.color, flex: '0 0 auto' }">
              <template #icon><component :is="tile.icon" /></template>
            </a-avatar>
            <div class="tile__text">
              <div class="tile__label">{{ tile.label }}</div>
              <div class="tile__value" :style="tile.valueColor ? { color: tile.valueColor } : {}">
                {{ tile.value }}
              </div>
              <div v-if="tile.sub" class="tile__sub">{{ tile.sub }}</div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <!-- Storage & data ------------------------------------------------->
      <div class="section-label">{{ $t('Storage_Usage') }} &amp; {{ $t('Database_Size') }}</div>
      <a-row :gutter="[16, 16]">
        <a-col v-for="tile in storageTiles" :key="tile.label" :xs="24" :sm="12" :xl="8">
          <a-card :bordered="false" class="tile">
            <a-avatar :size="42" :style="{ background: tile.color, flex: '0 0 auto' }">
              <template #icon><component :is="tile.icon" /></template>
            </a-avatar>
            <div class="tile__text">
              <div class="tile__label">{{ tile.label }}</div>
              <div class="tile__value" :style="tile.valueColor ? { color: tile.valueColor } : {}">
                {{ tile.value }}
              </div>
              <div v-if="tile.sub" class="tile__sub" :title="tile.sub">{{ tile.sub }}</div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <!-- Queue ---------------------------------------------------------->
      <div class="section-label">{{ $t('Queue_Status') }}</div>
      <a-row :gutter="[16, 16]">
        <a-col :xs="24" :sm="8">
          <a-card :bordered="false" class="tile">
            <a-avatar :size="42" :style="{ background: '#2f54eb', flex: '0 0 auto' }">
              <template #icon><ThunderboltOutlined /></template>
            </a-avatar>
            <div class="tile__text">
              <div class="tile__label">Driver</div>
              <div class="tile__value">{{ metrics.queue?.driver || '—' }}</div>
            </div>
          </a-card>
        </a-col>
        <a-col :xs="12" :sm="8">
          <a-card :bordered="false" class="tile">
            <a-avatar :size="42" :style="{ background: '#faad14', flex: '0 0 auto' }">
              <template #icon><InboxOutlined /></template>
            </a-avatar>
            <div class="tile__text">
              <div class="tile__label">{{ $t('Pending') }}</div>
              <div class="tile__value">{{ metrics.queue?.pending ?? '—' }}</div>
            </div>
          </a-card>
        </a-col>
        <a-col :xs="12" :sm="8">
          <a-card :bordered="false" class="tile">
            <a-avatar :size="42" :style="{ background: failedCount > 0 ? '#ff4d4f' : '#52c41a', flex: '0 0 auto' }">
              <template #icon><component :is="failedCount > 0 ? CloseCircleOutlined : CheckCircleOutlined" /></template>
            </a-avatar>
            <div class="tile__text">
              <div class="tile__label">{{ $t('Failed') }}</div>
              <div class="tile__value" :style="{ color: failedCount > 0 ? '#ff4d4f' : undefined }">
                {{ metrics.queue?.failed ?? '—' }}
              </div>
            </div>
          </a-card>
        </a-col>
        <a-col v-if="metrics.queue?.error" :span="24">
          <a-alert type="error" show-icon :message="metrics.queue.error" />
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * GET system_health → {success, data: {php_version, laravel_version,
 * environment, database{size_human|size_bytes|connection|error}, storage
 * {size_human|storage_path}, last_backup{human|message|filename|date},
 * queue{driver, pending, failed, error?}}, generated_at}. PDF GET
 * system_health/pdf (blob).
 *
 * The hero derives an overall level (healthy/warning/critical) from the same
 * data the tiles show — a DB/queue error is critical, failed jobs or a missing
 * backup are warnings — so the page reads at a glance.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ReloadOutlined, FilePdfOutlined, ClockCircleOutlined, ThunderboltOutlined, InboxOutlined,
  CheckCircleFilled, WarningFilled, CloseCircleFilled, CheckCircleOutlined, CloseCircleOutlined,
  CodeOutlined, DeploymentUnitOutlined, CloudServerOutlined, DatabaseOutlined, HddOutlined, SaveOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const loading = ref(true);
const downloading = ref(false);
const metrics = ref({});
const generatedAt = ref(null);

const failedCount = computed(() => Number(metrics.value.queue?.failed) || 0);
const isProd = computed(() => /^prod/i.test(metrics.value.environment || ''));

const health = computed(() => {
  const m = metrics.value;
  const critical = !!m.database?.error || !!m.queue?.error;
  const warning = failedCount.value > 0 || !m.last_backup?.date;
  if (critical) {
    return { level: 'critical', color: '#ff4d4f', icon: CloseCircleFilled, label: 'Critical', summary: 'A service reported an error — action required.' };
  }
  if (warning) {
    return { level: 'warning', color: '#faad14', icon: WarningFilled, label: 'Needs attention', summary: 'Some checks need attention (failed jobs or no recent backup).' };
  }
  return { level: 'healthy', color: '#52c41a', icon: CheckCircleFilled, label: 'Healthy', summary: 'All systems operational.' };
});

const envTiles = computed(() => {
  const m = metrics.value;
  return [
    { icon: CodeOutlined, color: '#6E56CF', label: t('PHP_Version'), value: m.php_version || '—' },
    { icon: DeploymentUnitOutlined, color: '#F22F46', label: t('Laravel_Version'), value: m.laravel_version || '—' },
    { icon: CloudServerOutlined, color: isProd.value ? '#52c41a' : '#fa8c16', label: t('Environment'), value: m.environment || '—' },
  ];
});

const storageTiles = computed(() => {
  const db = metrics.value.database || {};
  const st = metrics.value.storage || {};
  const bk = metrics.value.last_backup || {};
  return [
    {
      icon: DatabaseOutlined, color: '#1677ff', label: t('Database_Size'),
      value: db.size_human || db.error || '—', sub: db.connection,
      valueColor: db.error ? '#ff4d4f' : undefined,
    },
    { icon: HddOutlined, color: '#13c2c2', label: t('Storage_Usage'), value: st.size_human || '—', sub: st.storage_path },
    {
      icon: SaveOutlined, color: bk.date ? '#52c41a' : '#faad14', label: t('Last_Backup_Date'),
      value: bk.human || bk.message || '—', sub: bk.filename,
      valueColor: bk.date ? undefined : '#faad14',
    },
  ];
});

async function load() {
  loading.value = true;
  try {
    const data = await http.get('system_health');
    if (data?.success && data.data) {
      metrics.value = data.data;
      generatedAt.value = data.generated_at || null;
    }
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

async function downloadPdf() {
  downloading.value = true;
  try {
    await http.download('system_health/pdf', 'System_Health_Report.pdf');
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    downloading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
/* Hero ------------------------------------------------------------------ */
.hero {
  margin-bottom: 20px;
  border: 1px solid #f0f0f0;
}
.hero--healthy { background: linear-gradient(120deg, rgba(82, 196, 26, 0.10), rgba(82, 196, 26, 0.02)); border-color: rgba(82, 196, 26, 0.25); }
.hero--warning { background: linear-gradient(120deg, rgba(250, 173, 20, 0.12), rgba(250, 173, 20, 0.02)); border-color: rgba(250, 173, 20, 0.30); }
.hero--critical { background: linear-gradient(120deg, rgba(255, 77, 79, 0.12), rgba(255, 77, 79, 0.02)); border-color: rgba(255, 77, 79, 0.30); }
.hero__body {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.hero__left {
  display: flex;
  align-items: center;
  gap: 16px;
}
.hero__status {
  font-size: 20px;
  font-weight: 700;
  line-height: 1.2;
}
.hero__summary {
  color: rgba(0, 0, 0, 0.55);
  margin-top: 2px;
}
.hero__meta {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}

/* Section labels -------------------------------------------------------- */
.section-label {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  color: rgba(0, 0, 0, 0.45);
  margin: 22px 2px 12px;
}

/* Metric tiles ---------------------------------------------------------- */
.tile {
  border: 1px solid #f0f0f0;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
  height: 100%;
}
.tile :deep(.ant-card-body) {
  display: flex;
  align-items: center;
  gap: 14px;
}
.tile__text { min-width: 0; }
.tile__label {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  color: rgba(0, 0, 0, 0.45);
}
.tile__value {
  font-size: 20px;
  font-weight: 600;
  line-height: 1.3;
  word-break: break-word;
}
.tile__sub {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.4);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 220px;
}
</style>
