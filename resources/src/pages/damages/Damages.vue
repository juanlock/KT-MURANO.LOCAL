<template>
  <div class="page">
    <PageHeader :title="$t('Damages')" :breadcrumb="[$t('Damages')]">
      <template #actions>
        <a-button v-if="auth.can('damage_view')" type="primary" @click="$router.push('/damages/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Create_Damage') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]">
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('date') }}</div>
          <a-date-picker v-model:value="filters.date" value-format="YYYY-MM-DD" style="width: 100%" @change="crud.reload()" />
        </a-col>
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('Reference') }}</div>
          <a-input v-model:value="filters.Ref" :placeholder="$t('Reference')" allow-clear @change="crud.reload()" />
        </a-col>
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select
            v-model:value="filters.warehouse_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
            @change="crud.reload()"
          />
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'date'">{{ dateTime(record.date) }}</template>
        <template v-else-if="column.key === 'Ref'">
          <a @click="showDetails(record)">{{ record.Ref }}</a>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Download_PDF')">
              <a-button type="text" size="small" @click="downloadPdf(record)">
                <template #icon><FilePdfOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('View')">
              <a-button type="text" size="small" @click="showDetails(record)">
                <template #icon><EyeOutlined style="color: #1677ff" /></template>
              </a-button>
            </a-tooltip>
            <!-- Legacy gates damage edit/delete with the ADJUSTMENT permissions. -->
            <a-tooltip v-if="auth.can('adjustment_edit')" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/damages/${record.id}/edit`)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('adjustment_delete')" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.Ref })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Details modal (legacy shows details in a modal, not a page) -->
    <a-modal v-model:open="detailsOpen" :title="$t('Damage_Detail')" :footer="null" width="820px">
      <a-spin :spinning="detailsLoading">
        <a-descriptions :column="3" size="small" bordered style="margin-bottom: 16px">
          <a-descriptions-item :label="$t('date')">{{ detail.damage.date }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Reference')">{{ detail.damage.Ref }}</a-descriptions-item>
          <a-descriptions-item :label="$t('warehouse')">{{ detail.damage.warehouse }}</a-descriptions-item>
        </a-descriptions>
        <a-table
          :columns="detailColumns" :data-source="detail.details"
          size="small" :pagination="false" :row-key="(r, i) => i"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'quantity'">{{ number(record.quantity, 2) }} {{ record.unit }}</template>
          </template>
        </a-table>
        <p v-if="detail.damage.note" style="margin-top: 16px">{{ detail.damage.note }}</p>
      </a-spin>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET damages → {damages, warehouses, totalRows}; filter params Ref,
 * warehouse_id, date (always sent, legacy contract). Details are a MODAL
 * (GET damages/detail/{id} → {damage, details}), not a page. PDF per row
 * damage_pdf/{id} → "Damage-{Ref}.pdf". Legacy gates edit/delete with the
 * adjustment_* permissions (add button with damage_view) — kept as-is.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, FilePdfOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { dateTime, number } = useFormat();
const auth = useAuthStore();

const filters = ref({ date: null, Ref: '', warehouse_id: undefined });

const crud = useCrudTable('damages', {
  rowsKey: 'damages',
  params: () => ({
    Ref: filters.value.Ref || '',
    warehouse_id: filters.value.warehouse_id || '',
    date: filters.value.date || '',
  }),
});
crud.fetchRows();

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => dateTime(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name', sorter: true },
  { title: t('TotalProducts'), dataIndex: 'items', key: 'items', align: 'right' },
  { title: t('Action'), key: 'actions', width: 160, align: 'center' },
]);

function downloadPdf(record) {
  http.download(`damage_pdf/${record.id}`, `Damage-${record.Ref}.pdf`)
    .catch(() => message.error(t('InvalidData')));
}

/* ------------------------------------------------------------ details modal */
const detailsOpen = ref(false);
const detailsLoading = ref(false);
const detail = ref({ damage: {}, details: [] });

const detailColumns = computed(() => [
  { title: t('ProductName'), dataIndex: 'name', key: 'name' },
  { title: t('CodeProduct'), dataIndex: 'code', key: 'code' },
  { title: t('Quantity'), key: 'quantity', align: 'right' },
]);

async function showDetails(record) {
  detailsOpen.value = true;
  detailsLoading.value = true;
  try {
    const data = await http.get(`damages/detail/${record.id}`);
    detail.value = { damage: data.damage || {}, details: data.details || [] };
  } catch (e) {
    message.error(t('InvalidData'));
    detailsOpen.value = false;
  } finally {
    detailsLoading.value = false;
  }
}
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
</style>
