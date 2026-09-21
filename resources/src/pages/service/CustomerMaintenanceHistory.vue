<template>
  <div class="page">
    <PageHeader :title="$t('Customer_Maintenance_History')" :breadcrumb="[$t('Service_Maintenance'), $t('Customer_Maintenance_History')]" />

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[12, 12]">
        <a-col :xs="24" :md="8">
          <div class="filter-label">{{ $t('Customer') }}</div>
          <a-select
            v-model:value="filters.client_id" :placeholder="$t('Choose_Customer')"
            :options="clients.map(c => ({ label: c.name, value: c.id }))"
            show-search option-filter-prop="label" allow-clear
            style="width: 100%" @change="reload"
          />
        </a-col>
        <a-col :xs="12" :md="5">
          <div class="filter-label">{{ $t('From') }}</div>
          <a-input v-model:value="filters.from" type="date" @change="reload" />
        </a-col>
        <a-col :xs="12" :md="5">
          <div class="filter-label">{{ $t('To') }}</div>
          <a-input v-model:value="filters.to" type="date" @change="reload" />
        </a-col>
      </a-row>
    </a-card>

    <a-card size="small" :body-style="{ padding: 0 }">
      <a-table
        :columns="columns" :data-source="rows" :loading="isLoading"
        :pagination="pagination" size="middle"
        :row-key="(_r, i) => i" :scroll="{ x: 'max-content' }"
        :locale="{ emptyText: $t('NodataAvailable') }"
        @change="onTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'status'">
            <a-tag :color="statusColor(record.status)">{{ record.status }}</a-tag>
          </template>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Customer maintenance history — GET report/customer_maintenance_history
 * (page/limit + client_id/from/to) → {jobs, totalRows, clients}. The client
 * list rides along in the same response, exactly like legacy.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { PAGE_SIZE_OPTIONS, buildPageSizeOptionText } from '../../composables/useCrudTable';

const { t } = useI18n();

const isLoading = ref(true);
const rows = ref([]);
const totalRows = ref(0);
const clients = ref([]);
const page = ref(1);
const limit = ref(10);
const filters = ref({ client_id: null, from: '', to: '' });

const columns = computed(() => [
  { title: t('date'), dataIndex: 'scheduled_date', key: 'scheduled_date' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('Technician'), dataIndex: 'technician_name', key: 'technician_name' },
  { title: t('Service_Item'), dataIndex: 'service_item', key: 'service_item' },
  { title: t('Job_Type'), dataIndex: 'job_type', key: 'job_type' },
  { title: t('Status'), key: 'status' },
]);

const pagination = computed(() => ({
  current: page.value,
  pageSize: limit.value,
  total: totalRows.value,
  showSizeChanger: true,
  pageSizeOptions: PAGE_SIZE_OPTIONS,
  buildOptionText: buildPageSizeOptionText,
}));

function statusColor(s) {
  const map = {
    delivered: 'success', ready: 'success', completed: 'success',
    approved: 'processing', in_progress: 'processing',
    quoted: 'cyan', diagnostic: 'cyan', intake: 'cyan',
    pending: 'warning', declined: 'error', cancelled: 'error',
  };
  return map[s] || 'default';
}

async function fetchHistory() {
  isLoading.value = true;
  try {
    const data = await http.get('report/customer_maintenance_history', {
      page: page.value,
      limit: limit.value,
      client_id: filters.value.client_id,
      from: filters.value.from,
      to: filters.value.to,
    });
    rows.value = data.jobs || [];
    totalRows.value = data.totalRows || 0;
    clients.value = (data.clients || []).map(c => ({ id: c.id, name: c.name }));
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    isLoading.value = false;
  }
}
function reload() {
  page.value = 1;
  fetchHistory();
}
function onTableChange(pag) {
  page.value = pag.current;
  limit.value = pag.pageSize;
  fetchHistory();
}

onMounted(fetchHistory);
</script>

<style scoped>
.filter-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
  margin-bottom: 4px;
}
</style>
