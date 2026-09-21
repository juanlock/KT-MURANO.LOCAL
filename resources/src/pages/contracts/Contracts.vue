<template>
  <div class="page">
    <PageHeader :title="$t('Contracts')" :breadcrumb="[$t('Contracts')]">
      <template #actions>
        <a-button type="primary" @click="$router.push('/contracts/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Dashboard counts (separate endpoint, like legacy) -->
    <div class="stats-grid">
      <a-card v-for="tile in tiles" :key="tile.label" size="small" class="stat-card">
        <div class="stat-inner">
          <div class="stat-icon" :style="{ background: tile.bg, color: tile.color }">
            <component :is="tile.icon" />
          </div>
          <div class="stat-meta">
            <div class="stat-value" :style="{ color: tile.color }">
            <a-spin v-if="countsLoading" size="small" />
            <template v-else>{{ tile.value }}</template>
          </div>
            <div class="stat-label">{{ tile.label }}</div>
          </div>
        </div>
      </a-card>
    </div>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'value'">{{ money(record.value) }}</template>
        <template v-else-if="column.key === 'party_type'">
          <a-tag>{{ record.party_type === 'employee' ? $t('Employee') : $t('Customer') }}</a-tag>
        </template>
        <template v-else-if="column.key === 'type'">{{ typeLabel(record.type) }}</template>
        <template v-else-if="column.key === 'start_date'">{{ record.start_date ? date(record.start_date) : '—' }}</template>
        <template v-else-if="column.key === 'end_date'">{{ record.end_date ? date(record.end_date) : '—' }}</template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            
            <a-tooltip title="View">
              <a-button type="text" size="small" @click="$router.push(`/contracts/${record.id}`)">
                <template #icon><EyeOutlined style="color: #1677ff" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/contracts/${record.id}/edit`)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Contracts list: GET contracts → {contracts, totalRows}; counts from
 * GET contracts/dashboard. View page (templates, merge fields, PDF) stays
 * legacy — it's a document-generation surface of its own.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, EyeOutlined,
  CheckCircleOutlined, WarningOutlined, CloseCircleOutlined,
  PlusCircleOutlined, RestOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { CONTRACT_TYPES, CONTRACT_STATUSES, contractTypeLabel, contractStatusLabel, contractStatusColor } from './contractVocab';
import http from '../../lib/http';

const { t } = useI18n();
const { money, date } = useFormat();

const crud = useCrudTable('contracts', { rowsKey: 'contracts' });
const typeLabel = contractTypeLabel;
const statusLabel = contractStatusLabel;
const statusColor = contractStatusColor;

const counts = ref({});
const countsLoading = ref(true);
const tiles = computed(() => [
  { label: t('Active'), value: counts.value.count_active || 0, icon: CheckCircleOutlined, color: '#52c41a', bg: 'rgba(82, 196, 26, 0.14)' },
  { label: t('About_to_Expire'), value: counts.value.count_about_to_expire || 0, icon: WarningOutlined, color: '#faad14', bg: 'rgba(250, 173, 20, 0.14)' },
  { label: t('Expired'), value: counts.value.count_expired || 0, icon: CloseCircleOutlined, color: '#ff4d4f', bg: 'rgba(255, 77, 79, 0.12)' },
  { label: t('Recently_Added'), value: counts.value.count_recently_added || 0, icon: PlusCircleOutlined, color: '#1677ff', bg: 'rgba(22, 119, 255, 0.12)' },
  { label: t('Trash'), value: counts.value.count_trash || 0, icon: RestOutlined, color: '#8c8c8c', bg: 'rgba(140, 140, 140, 0.14)' },
]);

const columns = computed(() => [
  { title: '#', dataIndex: 'contract_number', key: 'contract_number', sorter: true },
  { title: t('Subject'), dataIndex: 'subject', key: 'subject', sorter: true },
  { title: t('Type'), key: 'party_type', dataIndex: 'party_type', exportValue: r => r.party_type },
  { title: t('Name'), dataIndex: 'party_name', key: 'party_name' },
  { title: t('Amount'), key: 'value', dataIndex: 'value', sorter: true, align: 'right', exportValue: r => money(r.value) },
  { title: t('Categorie'), key: 'type', dataIndex: 'type', exportValue: r => typeLabel(r.type) },
  { title: t('start_date'), key: 'start_date', dataIndex: 'start_date', sorter: true, exportValue: r => (r.start_date ? date(r.start_date) : '') },
  { title: t('Finish_Date'), key: 'end_date', dataIndex: 'end_date', sorter: true, exportValue: r => (r.end_date ? date(r.end_date) : '') },
  { title: t('Status'), key: 'status', dataIndex: 'status', exportValue: r => statusLabel(r.status) },
  { title: t('Action'), key: 'actions', width: 130, align: 'center' },
]);

async function loadCounts() {
  try {
    counts.value = await http.get('contracts/dashboard');
  } catch (e) { /* tiles show zeros */ } finally {
    countsLoading.value = false;
  }
}

onMounted(() => {
  crud.fetchRows();
  loadCounts();
});
</script>

<style scoped>
/* Auto-flowing card grid: 5 across on wide screens, wrapping down to 2 on
   phones — no fixed column spans to fight with. */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
  margin-bottom: 16px;
}
.stat-card {
  border-radius: 10px;
}
.stat-inner {
  display: flex;
  align-items: center;
  gap: 12px;
}
.stat-icon {
  width: 46px;
  height: 46px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  flex: none;
}
.stat-meta {
  min-width: 0;
}
.stat-value {
  font-size: 24px;
  font-weight: 800;
  line-height: 1.1;
}
.stat-label {
  opacity: 0.6;
  font-size: 13px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
@media (max-width: 575px) {
  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
  }
  .stat-icon {
    width: 38px;
    height: 38px;
    font-size: 18px;
  }
  .stat-value {
    font-size: 20px;
  }
}
</style>
