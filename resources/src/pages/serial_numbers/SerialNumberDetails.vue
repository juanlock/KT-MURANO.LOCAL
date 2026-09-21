<template>
  <div class="page">
    <PageHeader :title="$t('Serial_Number')" :breadcrumb="[$t('Serial_Numbers'), serial.serial_number || '']">
      <template #actions>
        <a-button @click="$router.push('/serial-numbers')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-card size="small" style="margin-bottom: 16px">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap">
          <a-descriptions :column="{ xs: 1, md: 2 }" size="small">
            <a-descriptions-item :label="$t('Serial_Number')">
              <strong>{{ serial.serial_number }}</strong>
            </a-descriptions-item>
            <a-descriptions-item :label="$t('Serial_Status')">
              <a-tag :color="serialStatusColor(serial.status)">{{ serial.status ? $t(`Status_${serial.status}`) : '—' }}</a-tag>
            </a-descriptions-item>
            <a-descriptions-item :label="$t('Name_product')">
              {{ serial.product_name }}
              <a-tag v-if="serial.variant_name" style="margin-left: 4px">{{ serial.variant_name }}</a-tag>
            </a-descriptions-item>
            <a-descriptions-item :label="$t('warehouse')">{{ serial.warehouse_name }}</a-descriptions-item>
            <a-descriptions-item v-if="serial.provider_name" :label="$t('Supplier')">{{ serial.provider_name }}</a-descriptions-item>
            <a-descriptions-item v-if="serial.client_name" :label="$t('Customer')">{{ serial.client_name }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Registered_Date')">{{ serial.created_at }}</a-descriptions-item>
          </a-descriptions>
          <!-- Manual status overrides, exactly the three legacy offers. -->
          <a-space wrap>
            <a-button v-if="serial.status !== 'available'" size="small" @click="setStatus('available')">
              {{ $t('Status_available') }}
            </a-button>
            <a-button v-if="serial.status !== 'damaged'" size="small" danger @click="setStatus('damaged')">
              {{ $t('Status_damaged') }}
            </a-button>
            <a-button v-if="serial.status !== 'reserved'" size="small" @click="setStatus('reserved')">
              {{ $t('Status_reserved') }}
            </a-button>
          </a-space>
        </div>
      </a-card>

      <a-card size="small" :title="$t('Serial_Movement_Log')" :body-style="{ padding: 0 }">
        <a-table
          :columns="movementColumns" :data-source="movements" :pagination="false"
          size="middle" :row-key="m => m.id" :scroll="{ x: 'max-content' }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'status_change'">
              <span v-if="record.from_status" class="muted">{{ $t(`Status_${record.from_status}`) }} → </span>
              <a-tag :color="serialStatusColor(record.to_status)">
                {{ record.to_status ? $t(`Status_${record.to_status}`) : '—' }}
              </a-tag>
            </template>
            <template v-else-if="column.key === 'reference'">
              {{ record.reference_type || '' }} {{ record.reference_ref || '' }}
            </template>
          </template>
          <template #emptyText>
            <a-empty :description="$t('NodataAvailable')" style="padding: 24px 0" />
          </template>
        </a-table>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * GET serial_numbers/{id} → {serial, movements}. Manual status change =
 * POST serial_numbers/{id}/status {status} — only available/damaged/reserved
 * are user-settable (sold/returned states come from documents); backend
 * rejects invalid transitions with errors.status[0], surfaced verbatim.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeftOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { serialStatusColor } from './serialVocab';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const loading = ref(true);
const serial = ref({});
const movements = ref([]);

const movementColumns = computed(() => [
  { title: t('date'), dataIndex: 'created_at', key: 'created_at' },
  { title: t('Action'), dataIndex: 'action', key: 'action' },
  { title: t('Serial_Status'), key: 'status_change' },
  { title: t('Reference'), key: 'reference' },
  { title: t('User'), dataIndex: 'user_name', key: 'user_name' },
]);

async function load() {
  try {
    const data = await http.get(`serial_numbers/${route.params.id}`);
    serial.value = data.serial || {};
    movements.value = data.movements || [];
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/serial-numbers');
  } finally {
    loading.value = false;
  }
}

async function setStatus(status) {
  try {
    await http.post(`serial_numbers/${serial.value.id}/status`, { status });
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) {
    // Backend explains rejected transitions via errors.status — show it as-is.
    const msg = e?.data?.errors?.status?.[0];
    message.error(msg || t('InvalidData'));
  }
}

onMounted(load);
</script>

<style scoped>
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
</style>
