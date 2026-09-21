<template>
  <div class="page">
    <PageHeader :title="$t('ListQuotations')" :breadcrumb="[$t('Quotations'), $t('ListQuotations')]">
      <template #actions>
        <a-space wrap>
          <a-button :loading="exporting === 'pdf'" @click="exportList('pdf')">
            <template #icon><FilePdfOutlined /></template>
            PDF
          </a-button>
          <a-button :loading="exporting === 'xlsx'" @click="exportList('xlsx')">
            <template #icon><FileExcelOutlined /></template>
            EXCEL
          </a-button>
          <a-button v-if="auth.can('Quotations_add')" type="primary" @click="$router.push('/quotations/create')">
            <template #icon><PlusOutlined /></template>
            {{ $t('AddQuote') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <!-- Summary cards — global sums over the whole filtered set (all pages). -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="tile in statTiles" :key="tile.label" :xs="12" :sm="12" :md="6">
        <a-card size="small" class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" :style="{ background: tile.tint, color: tile.color }">
              <component :is="tile.icon" />
            </div>
            <div class="stat-meta">
              <div class="stat-label">{{ tile.label }}</div>
              <div class="stat-value">
                <a-spin v-if="crud.loading.value" size="small" />
                <template v-else>{{ tile.value }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]">
        <a-col :xs="12" :md="8" :xl="6">
          <div class="filter-label">{{ $t('date') }}</div>
          <a-date-picker v-model:value="filters.date" value-format="YYYY-MM-DD" style="width: 100%" @change="crud.reload()" />
        </a-col>
        <a-col :xs="12" :md="8" :xl="6">
          <div class="filter-label">{{ $t('Customer') }}</div>
          <a-select
            v-model:value="filters.client_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Customer')" :options="customerOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="8" :xl="6">
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select
            v-model:value="filters.warehouse_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="8" :xl="6">
          <div class="filter-label">{{ $t('Status') }}</div>
          <a-select
            v-model:value="filters.statut" style="width: 100%" allow-clear
            :placeholder="$t('Choose_Status')" :options="statusOptions" @change="crud.reload()"
          />
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :selectable="auth.can('Quotations_delete')">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'date'">{{ dateTime(record.date) }}</template>
        <template v-else-if="column.key === 'Ref'">
          <a @click="$router.push(`/quotations/${record.id}`)">{{ record.Ref }}</a>
          <!-- Converted marker — like the sales list's return arrow; click opens the sale. -->
          <a-tooltip
            v-if="record.converted_sale_id"
            :title="`${$t('Already_Converted')} — ${record.converted_sale_ref || ''}`"
          >
            <span
              style="color: #52c41a; margin-left: 4px; cursor: pointer"
              @click="$router.push(`/sales/${record.converted_sale_id}`)"
            >⇄</span>
          </a-tooltip>
        </template>
        <template v-else-if="column.key === 'statut'">
          <a-tag :color="record.statut === 'sent' ? 'success' : 'processing'">
            {{ record.statut === 'sent' ? $t('Sent') : $t('Pending') }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'GrandTotal'">{{ money(record.GrandTotal) }}</template>
        <template v-else-if="column.key === 'actions'">
          <a-dropdown :trigger="['click']">
            <a-button type="text" size="small">
              <template #icon><MoreOutlined style="font-size: 18px" /></template>
            </a-button>
            <template #overlay>
              <a-menu @click="({ key }) => onAction(key, record)">
                <a-menu-item key="detail">
                  <EyeOutlined /> {{ $t('DetailQuote') }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('Quotations_edit')" key="edit">
                  <EditOutlined /> {{ $t('EditQuote') }}
                </a-menu-item>
                <!-- Already-converted quotations can't be converted a second time. -->
                <a-menu-item v-if="auth.can('Quotations_edit') && !record.converted_sale_id" key="convert">
                  <SwapOutlined /> {{ $t('Convert_to_Invoice') }}
                </a-menu-item>
                <a-menu-item key="pdf">
                  <FilePdfOutlined /> {{ $t('DownloadPdf') }}
                </a-menu-item>
                <a-menu-item key="whatsapp">
                  <WhatsAppOutlined /> WhatsApp Notification
                </a-menu-item>
                <a-menu-item key="email">
                  <MailOutlined /> {{ $t('email_notification') }}
                </a-menu-item>
                <a-menu-item key="sms">
                  <MessageOutlined /> {{ $t('sms_notification') }}
                </a-menu-item>
                <a-menu-divider v-if="auth.can('Quotations_delete')" />
                <a-menu-item v-if="auth.can('Quotations_delete')" key="delete" danger>
                  <DeleteOutlined /> {{ $t('DeleteQuote') }}
                </a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * GET quotations → {quotations, customers, warehouses, totalRows}; filter
 * params date, client_id, warehouse_id, statut (sent|pending) + the standard
 * five and legacy's always-sent empty Ref. Bulk delete flat POST
 * quotations_delete_by_selection. PDF GET quote_pdf/{id} →
 * Quotation_{Ref}.pdf. Notify quotations_send_email|quotations_send_sms;
 * WhatsApp quotation_send_whatsapp (singular!). Convert to Invoice deep-links
 * to the legacy sale-from-quote form until the sale form is migrated.
 */
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, MoreOutlined,
  FilePdfOutlined, FileExcelOutlined, MailOutlined, MessageOutlined,
  WhatsAppOutlined, SwapOutlined, FileTextOutlined, DollarOutlined,
  SendOutlined, ClockCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { exportExcel, exportPdf } from '../../lib/exporters';
import http from '../../lib/http';

const { t } = useI18n();
const { money, dateTime } = useFormat();
const auth = useAuthStore();
const router = useRouter();

const filters = ref({
  date: null, client_id: undefined, warehouse_id: undefined, statut: undefined,
});

const filterParams = () => ({
  Ref: '',
  date: filters.value.date || '',
  client_id: filters.value.client_id || '',
  warehouse_id: filters.value.warehouse_id || '',
  statut: filters.value.statut || '',
});

const crud = useCrudTable('quotations', {
  rowsKey: 'quotations',
  bulkDeleteEndpoint: 'quotations_delete_by_selection',
  params: filterParams,
});
crud.fetchRows();

// Summary cards — `stats` ships with the list payload (sums over ALL pages of
// the current filtered set, not just the visible page).
const statTiles = computed(() => {
  const s = crud.payload.value?.stats || {};
  const n = k => Number(s[k]) || 0;
  return [
    { label: t('Quotations'), value: n('count'), icon: FileTextOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { label: t('Total'), value: money(n('total')), icon: DollarOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { label: t('Sent'), value: n('sent'), icon: SendOutlined, color: '#52c41a', tint: 'rgba(82, 196, 26, 0.12)' },
    { label: t('Pending'), value: n('pending'), icon: ClockCircleOutlined, color: '#faad14', tint: 'rgba(250, 173, 20, 0.14)' },
  ];
});

const customerOptions = computed(() =>
  (crud.payload.value?.customers || []).map(c => ({ value: c.id, label: c.name }))
);
const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);
const statusOptions = computed(() => [
  { value: 'sent', label: t('Sent') },
  { value: 'pending', label: t('Pending') },
]);

const columns = computed(() => [
  { title: t('Action'), key: 'actions', width: 70, align: 'center', fixed: 'left' },
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => dateTime(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name', sorter: true },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name', sorter: true },
  { title: t('Status'), dataIndex: 'statut', key: 'statut', sorter: true, exportValue: r => r.statut },
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', sorter: true, align: 'right', exportValue: r => money(r.GrandTotal) },
]);

const exporting = ref(null);

async function exportList(kind) {
  exporting.value = kind;
  try {
    const data = await http.get('quotations', {
      page: 1,
      SortField: crud.sortField.value,
      SortType: crud.sortType.value,
      search: crud.search.value,
      limit: -1,
      ...filterParams(),
    });
    const rows = data.quotations || [];
    if (kind === 'xlsx') await exportExcel('quotations', columns.value, rows);
    else await exportPdf(t('ListQuotations'), columns.value, rows);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    exporting.value = null;
  }
}

function onAction(key, record) {
  const go = {
    detail: () => router.push(`/quotations/${record.id}`),
    edit: () => router.push(`/quotations/${record.id}/edit`),
    convert: () => router.push(`/sales/from-quotation/${record.id}`),
    pdf: () =>
      http.download(`quote_pdf/${record.id}`, `Quotation_${record.Ref}.pdf`)
        .catch(() => message.error(t('InvalidData'))),
    whatsapp: () => sendWhatsApp(record),
    email: () => notify('quotations_send_email', record.id, 'SendEmail', 'SMTPIncorrect'),
    sms: () => notify('quotations_send_sms', record.id, 'sms_send_successfully', 'sms_config_invalid'),
    delete: () => crud.remove(record, { label: record.Ref }),
  };
  go[key]?.();
}

async function notify(endpoint, id, okKey, failKey) {
  try {
    await http.post(endpoint, { id });
    message.success(t(okKey));
  } catch (e) {
    message.error(t(failKey));
  }
}

async function sendWhatsApp(record) {
  try {
    const data = await http.post('quotation_send_whatsapp', { id: record.id });
    const url = `https://web.whatsapp.com/send/?phone=${encodeURIComponent(data.phone)}&text=${encodeURIComponent(data.message)}`;
    window.open(url, '_blank');
  } catch (e) {
    message.error(t('Failed'));
  }
}
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}

/* Summary cards — same design as the sales/purchases lists. */
.stat-card {
  border-radius: 10px;
}
.stat-inner {
  display: flex;
  align-items: center;
  gap: 12px;
}
.stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex: none;
}
.stat-meta {
  min-width: 0;
}
.stat-label {
  opacity: 0.65;
  font-size: 13px;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.stat-value {
  font-size: 20px;
  font-weight: 700;
  white-space: nowrap;
}
@media (max-width: 575px) {
  .stat-inner {
    gap: 8px;
  }
  .stat-icon {
    width: 36px;
    height: 36px;
    font-size: 16px;
  }
  .stat-value {
    font-size: 16px;
  }
}
</style>
