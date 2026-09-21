<template>
  <div class="page">
    <PageHeader :title="$t('Property_Inquiries')" :breadcrumb="[$t('Real_Estate'), $t('Property_Inquiries')]" />

    <a-card size="small" style="margin-bottom: 16px">
      <div class="filter-label">{{ $t('Status') }}</div>
      <a-select v-model:value="statusFilter" style="width: 240px; max-width: 100%" @change="crud.reload()">
        <a-select-option value="">{{ $t('All') }}</a-select-option>
        <a-select-option value="new">{{ $t('New') }}</a-select-option>
        <a-select-option value="read">{{ $t('Read') }}</a-select-option>
        <a-select-option value="responded">{{ $t('Responded') }}</a-select-option>
        <a-select-option value="closed">{{ $t('Closed') }}</a-select-option>
      </a-select>
    </a-card>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <a style="font-weight: 600" @click="view(record)">{{ record.name }}</a>
        </template>
        <template v-else-if="column.key === 'property'">
          {{ record.property ? record.property.title : '—' }}
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'created_at'">
          {{ shortDt(record.created_at) }}
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-button size="small" @click="view(record)">
              <template #icon><EyeOutlined /></template>
            </a-button>
            <a-button size="small" danger @click="crud.remove(record, { label: record.name })">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal v-model:open="modalOpen" :title="$t('Inquiry_Details')" :footer="null" width="720px">
      <template v-if="current">
        <a-descriptions :column="{ xs: 1, md: 2 }" size="small" bordered style="margin: 12px 0">
          <a-descriptions-item :label="$t('Name')">{{ current.name }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Property')">{{ current.property ? current.property.title : '—' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Email')">
            <a v-if="current.email" :href="'mailto:' + current.email">{{ current.email }}</a>
            <span v-else>—</span>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('Phone')">
            <a v-if="current.phone" :href="'tel:' + current.phone">{{ current.phone }}</a>
            <span v-else>—</span>
          </a-descriptions-item>
        </a-descriptions>
        <strong>{{ $t('Message') }}:</strong>
        <div class="msg-box">{{ current.message }}</div>
        <a-form-item :label="$t('Status')" style="margin-bottom: 0">
          <a-select v-model:value="current.status" style="width: 220px" @change="updateStatus">
            <a-select-option value="new">{{ $t('New') }}</a-select-option>
            <a-select-option value="read">{{ $t('Read') }}</a-select-option>
            <a-select-option value="responded">{{ $t('Responded') }}</a-select-option>
            <a-select-option value="closed">{{ $t('Closed') }}</a-select-option>
          </a-select>
        </a-form-item>
      </template>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Property inquiries — GET realestate/inquiries (+status filter) →
 * {inquiries, totalRows}. Viewing fetches realestate/inquiries/{id} (which
 * auto-marks "read" server-side — the list refreshes after opening like
 * legacy). Status change PUT realestate/inquiries/{id}/status {status}.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { EyeOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';

const { t } = useI18n();

const statusFilter = ref('');
const crud = useCrudTable('realestate/inquiries', {
  rowsKey: 'inquiries',
  params: () => ({ status: statusFilter.value }),
});

const modalOpen = ref(false);
const current = ref(null);

const columns = computed(() => [
  { title: t('Name'), key: 'name' },
  { title: t('Property'), key: 'property' },
  { title: t('Email'), dataIndex: 'email', key: 'email', sorter: true },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('Status'), key: 'status', width: 110 },
  { title: t('date'), key: 'created_at', sorter: true },
  { title: t('Action'), key: 'actions', width: 100 },
]);

function shortDt(d) { return d ? String(d).replace('T', ' ').substring(0, 16) : '-'; }
function statusLabel(s) {
  return { new: t('New'), read: t('Read'), responded: t('Responded'), closed: t('Closed') }[s] || s;
}
function statusColor(s) {
  return { new: 'processing', read: 'cyan', responded: 'success', closed: 'default' }[s] || 'default';
}

async function view(row) {
  try {
    const data = await http.get(`realestate/inquiries/${row.id}`);
    current.value = data.inquiry;
    modalOpen.value = true;
    // refresh list so the server-side auto "read" status shows
    crud.fetchRows();
  } catch (e) {
    message.error(t('InvalidData'));
  }
}
async function updateStatus() {
  try {
    await http.put(`realestate/inquiries/${current.value.id}/status`, { status: current.value.status });
    message.success(t('Updated_in_successfully'));
    crud.fetchRows();
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

onMounted(crud.fetchRows);
</script>

<style scoped>
.filter-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
  margin-bottom: 4px;
}
.msg-box {
  background: rgba(0, 0, 0, 0.03);
  border-radius: 8px;
  padding: 12px;
  margin: 8px 0 16px;
  white-space: pre-line;
}
</style>
