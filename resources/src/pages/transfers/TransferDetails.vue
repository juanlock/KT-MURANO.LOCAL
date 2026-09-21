<template>
  <div class="page">
    <PageHeader :title="$t('TransferDetail')" :breadcrumb="[$t('ListTransfers'), $t('TransferDetail')]">
      <template #actions>
        <a-space wrap>
          <a-button @click="$router.push('/transfers')">
            <template #icon><ArrowLeftOutlined /></template>
            {{ $t('Back') }}
          </a-button>
          <a-button :loading="downloadingPdf" @click="downloadPdf">
            <template #icon><FilePdfOutlined /></template>
            {{ $t('DownloadPdf') }}
          </a-button>
          <a-button v-if="auth.can('transfer_edit')" @click="$router.push(`/transfers/${transfer.id || $route.params.id}/edit`)">
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
        <a-descriptions :column="{ xs: 1, md: 3 }" size="small">
          <a-descriptions-item :label="$t('Reference')">
            <strong>{{ transfer.Ref }}</strong>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('date')">{{ dateTime(transfer.date) }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Items')">{{ transfer.items }}</a-descriptions-item>
          <a-descriptions-item :label="$t('FromWarehouse')">{{ transfer.from_warehouse }}</a-descriptions-item>
          <a-descriptions-item :label="$t('ToWarehouse')">{{ transfer.to_warehouse }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Total')">{{ money(transfer.GrandTotal) }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Status')">
            <a-tag :color="transfer.statut === 'completed' ? 'success' : transfer.statut === 'sent' ? 'warning' : 'error'">
              {{ transfer.statut === 'completed' ? $t('complete') : transfer.statut === 'sent' ? $t('Sent') : $t('Pending') }}
            </a-tag>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('Approval')">
            <a-tag :color="!transfer.approval_status || transfer.approval_status === 'approved' ? 'success' : transfer.approval_status === 'pending' ? 'warning' : 'error'">
              {{
                !transfer.approval_status || transfer.approval_status === 'approved'
                  ? $t('Approved')
                  : transfer.approval_status === 'pending' ? $t('Pending_Approval') : $t('Rejected')
              }}
            </a-tag>
          </a-descriptions-item>
        </a-descriptions>
        <div v-if="transfer.note" style="margin-top: 8px; color: rgba(0, 0, 0, 0.45)">{{ transfer.note }}</div>
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
            <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
          </template>
        </a-table>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * GET transfers/{id} → {transfer, details}. PDF transfer_pdf/{id}.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeftOutlined, EditOutlined, FilePdfOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { money, dateTime } = useFormat();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const loading = ref(true);
const transfer = ref({});
const details = ref([]);

const itemColumns = computed(() => [
  { title: t('ProductName'), key: 'product' },
  { title: t('Quantity'), key: 'quantity', align: 'right' },
  { title: t('Total'), key: 'total', align: 'right' },
]);

const downloadingPdf = ref(false);
async function downloadPdf() {
  downloadingPdf.value = true;
  try {
    await http.download(`transfer_pdf/${transfer.value.id || route.params.id}`, `Transfer_${transfer.value.Ref || route.params.id}.pdf`);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    downloadingPdf.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get(`transfers/${route.params.id}`);
    transfer.value = data.transfer || {};
    details.value = data.details || [];
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/transfers');
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
