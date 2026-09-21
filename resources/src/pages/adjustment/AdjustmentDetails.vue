<template>
  <div class="page">
    <PageHeader :title="$t('AdjustmentDetail')" :breadcrumb="[$t('ListAdjustments'), $t('AdjustmentDetail')]">
      <template #actions>
        <a-space wrap>
          <a-button @click="$router.push('/adjustments')">
            <template #icon><ArrowLeftOutlined /></template>
            {{ $t('Back') }}
          </a-button>
          <a-button :loading="downloadingPdf" @click="downloadPdf">
            <template #icon><FilePdfOutlined /></template>
            {{ $t('DownloadPdf') }}
          </a-button>
          <a-button v-if="auth.can('adjustment_edit')" @click="$router.push(`/adjustments/${adjustment.id || $route.params.id}/edit`)">
            <template #icon><EditOutlined /></template>
            {{ $t('Edit') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-card size="small" style="margin-bottom: 16px">
        <a-descriptions :column="{ xs: 1, md: 4 }" size="small">
          <a-descriptions-item :label="$t('Reference')">
            <strong>{{ adjustment.Ref }}</strong>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('date')">{{ dateTime(adjustment.date) }}</a-descriptions-item>
          <a-descriptions-item :label="$t('warehouse')">{{ adjustment.warehouse }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Created_by')">{{ adjustment.created_by }}</a-descriptions-item>
        </a-descriptions>
        <div v-if="adjustment.note" style="margin-top: 8px; color: rgba(0, 0, 0, 0.45)">{{ adjustment.note }}</div>
      </a-card>

      <a-card size="small" :body-style="{ padding: 0 }">
        <a-table
          :columns="itemColumns"
          :data-source="details"
          :pagination="false"
          size="middle"
          :row-key="(r, i) => i"
          :scroll="{ x: 'max-content' }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'product'">
              <div style="font-weight: 500">{{ record.name }}</div>
              <div class="muted">{{ record.code }}</div>
              <div v-if="record.is_batch_tracked && (record.batches || []).length">
                <a-tag v-for="(b, i) in record.batches" :key="i" style="margin-top: 2px">
                  {{ b.batch_number || b.batch_no || b }}
                </a-tag>
              </div>
            </template>
            <template v-else-if="column.key === 'quantity'">{{ record.quantity }} {{ record.unit }}</template>
            <template v-else-if="column.key === 'type'">
              <a-tag :color="record.type === 'add' ? 'success' : 'error'">
                {{ record.type === 'add' ? $t('Addition') : $t('Subtraction') }}
              </a-tag>
            </template>
          </template>
        </a-table>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * GET adjustments/detail/{id} → {adjustment, details}. Lines carry
 * type add|sub rendered as Addition/Subtraction tags, like legacy.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ArrowLeftOutlined, EditOutlined, FilePdfOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { dateTime } = useFormat();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const loading = ref(true);
const adjustment = ref({});
const details = ref([]);

const itemColumns = computed(() => [
  { title: t('ProductName'), key: 'product' },
  { title: t('Quantity'), key: 'quantity', align: 'right' },
  { title: t('Type'), key: 'type', align: 'center' },
]);

const downloadingPdf = ref(false);
async function downloadPdf() {
  downloadingPdf.value = true;
  try {
    await http.download(`adjustment_pdf/${adjustment.value.id || route.params.id}`, `Adjustment_${adjustment.value.Ref || route.params.id}.pdf`);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    downloadingPdf.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get(`adjustments/detail/${route.params.id}`);
    adjustment.value = data.adjustment || {};
    details.value = data.details || [];
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/adjustments');
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
</style>
