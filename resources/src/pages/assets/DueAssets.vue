<template>
  <div class="page">
    <PageHeader :title="$t('Due_Assets')" :breadcrumb="[$t('Assets_List'), $t('Due_Assets')]">
      <template #extra>
        <a-button :loading="runCheckLoading" @click="runValidationDueNow">
          {{ $t('Run_check_now') || 'Run check now' }}
        </a-button>
      </template>
    </PageHeader>

    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="8">
        <a-card size="small"><a-statistic :title="$t('Total_Due') || 'Total due'" :value="assets.length" /></a-card>
      </a-col>
      <a-col :xs="8">
        <a-card size="small"><a-statistic :title="$t('Overdue') || 'Overdue'" :value="overdueCount" :value-style="{ color: '#ff4d4f' }" /></a-card>
      </a-col>
      <a-col :xs="8">
        <a-card size="small"><a-statistic :title="$t('Due_soon') || 'Due soon'" :value="dueSoonCount" :value-style="{ color: '#faad14' }" /></a-card>
      </a-col>
    </a-row>

    <a-card size="small" style="margin-bottom: 16px" :body-style="{ padding: 0 }">
      <a-table
        :columns="columns" :data-source="assets" :loading="isLoading"
        size="middle" row-key="id" :scroll="{ x: 'max-content' }"
        :locale="{ emptyText: $t('NodataAvailable') }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'next_validation'">
            <a-tag v-if="record.next_validation" :color="isOverdue(record) ? 'error' : 'warning'">
              {{ isOverdue(record) ? ($t('Overdue') || 'Overdue') : ($t('Due_soon') || 'Due soon') }}
            </a-tag>
            {{ record.next_validation || '—' }}
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-button size="small" @click="$router.push(`/assets/${record.id}/edit`)">
              <EditOutlined /> {{ $t('Edit') }}
            </a-button>
          </template>
        </template>
      </a-table>
    </a-card>

    <!-- Scheduler setup -->
    <a-card size="small" :title="$t('Cron_Schedule_Config') || 'Cron / Schedule configuration'">
      <p style="color: #8c8c8c">
        {{ $t('Asset_validation_cron_description') || 'To send automatic notifications when assets are due for validation, the Laravel scheduler must run every minute. Add this line to your server crontab:' }}
      </p>
      <a-input-group compact style="display: flex; max-width: 720px">
        <a-input :value="scheduleInfo.cron_line" readonly style="flex: 1; font-family: monospace" @focus="e => e.target.select()" />
        <a-button @click="copyCronLine"><CopyOutlined /> {{ $t('Copy') || 'Copy' }}</a-button>
      </a-input-group>
      <p style="font-size: 12px; color: #8c8c8c; margin-top: 12px">
        {{ $t('Asset_validation_schedule_detail') || 'The command "assets:check-validation-due" runs daily. It finds assets whose next validation is within 5 working days (or overdue) and sends email and in-app notifications to users with Assets permission.' }}
      </p>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Due assets — GET assets/due → {assets} (due within 5 working days or
 * overdue); GET assets/schedule-info → {cron_line, ...}; POST
 * assets/run-validation-due triggers the notification check on demand.
 * Overdue = next_validation date before today (midnight compare, legacy).
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { EditOutlined, CopyOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const assets = ref([]);
const scheduleInfo = ref({ cron_line: '' });
const runCheckLoading = ref(false);

function isOverdue(record) {
  if (!record.next_validation) return false;
  const d = new Date(record.next_validation);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  d.setHours(0, 0, 0, 0);
  return d < today;
}

const overdueCount = computed(() => assets.value.filter(isOverdue).length);
const dueSoonCount = computed(() => assets.value.length - overdueCount.value);

const columns = computed(() => [
  { title: t('Tag'), dataIndex: 'tag', key: 'tag', width: 110 },
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  { title: t('Category'), dataIndex: 'asset_category_name', key: 'asset_category_name' },
  { title: t('Serial'), dataIndex: 'serial_number', key: 'serial_number' },
  { title: t('Status'), dataIndex: 'status', key: 'status', width: 110 },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Last_Verification'), dataIndex: 'last_verification', key: 'last_verification', width: 140 },
  { title: t('Next_Validation'), key: 'next_validation', width: 200 },
  { title: t('Actions'), key: 'actions', width: 100, align: 'center' },
]);

async function copyCronLine() {
  const line = scheduleInfo.value.cron_line || '';
  if (!line) return;
  try {
    await navigator.clipboard.writeText(line);
    message.success(t('Copied') || 'Copied to clipboard');
  } catch (e) {
    const el = document.createElement('textarea');
    el.value = line;
    document.body.appendChild(el);
    el.select();
    document.execCommand('copy');
    document.body.removeChild(el);
    message.success(t('Copied') || 'Copied to clipboard');
  }
}

async function runValidationDueNow() {
  runCheckLoading.value = true;
  try {
    const data = await http.post('assets/run-validation-due');
    message.success(data.message || 'Check completed.');
  } catch (e) {
    message.error(e?.data?.message || e.message || 'Request failed');
  } finally {
    runCheckLoading.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('assets/due');
    assets.value = data.assets || [];
  } finally {
    isLoading.value = false;
  }
  try {
    scheduleInfo.value = await http.get('assets/schedule-info');
  } catch (e) {
    scheduleInfo.value = { cron_line: '* * * * * cd /path/to/your/project && php artisan schedule:run >> /dev/null 2>&1' };
  }
});
</script>
