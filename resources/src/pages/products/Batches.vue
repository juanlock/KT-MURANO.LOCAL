<template>
  <div class="page">
    <PageHeader :title="$t('Batches')" :breadcrumb="[$t('Products'), $t('Batches')]" />

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]" align="bottom">
        <a-col :xs="12" :md="7">
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select
            v-model:value="filters.warehouse_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="7">
          <div class="filter-label">{{ $t('Status') }}</div>
          <a-select
            v-model:value="filters.status" style="width: 100%" :options="statusOptions" @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="7">
          <div class="filter-label">{{ $t('Expiry_Window') }}</div>
          <a-select
            v-model:value="filters.expiry_window" style="width: 100%" :options="expiryOptions" @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="3">
          <div class="muted">{{ $t('Expiry_Warning_Days') }}: {{ expiryWarningDays }}</div>
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'product'">
          <div style="font-weight: 500">{{ record.product_name }}</div>
          <a-tag v-if="record.variant_name" style="margin-top: 2px">{{ record.variant_name }}</a-tag>
        </template>
        <template v-else-if="column.key === 'expiry_date'">
          <span :style="{ color: expiryColor(record) }">{{ record.expiry_date || '—' }}</span>
          <div v-if="Number.isFinite(Number(record.days_to_expiry))" class="muted">
            {{ Number(record.days_to_expiry) < 0 ? $t('Expired') : `${$t('Expires_in')} ${record.days_to_expiry} ${$t('Days')}` }}
          </div>
        </template>
        <template v-else-if="column.key === 'unit_cost'">{{ money(record.unit_cost) }}</template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="batchStatusColor(record.status)">{{ $t(`Batch_Status_${record.status}`) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip v-if="canManage" :title="$t('Edit_Batch')">
              <a-button type="text" size="small" @click="openEdit(record)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canWriteOff && record.status !== 'written_off'" :title="$t('Write_Off')">
              <a-button type="text" size="small" @click="openWriteOff(record)">
                <template #icon><StopOutlined style="color: #faad14" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canManage" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.batch_no })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Edit batch -->
    <a-modal
      v-model:open="editOpen"
      :title="$t('Edit_Batch')"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      @ok="saveEdit"
    >
      <a-form layout="vertical">
        <a-form-item :label="$t('Product')">
          <a-input :value="editing.product_name" disabled />
        </a-form-item>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('Batch_No')">
              <a-input v-model:value="editing.batch_no" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Status')">
              <a-select
                v-model:value="editing.status"
                :options="['active', 'quarantined', 'expired', 'written_off'].map(s => ({ value: s, label: t(`Batch_Status_${s}`) }))"
              />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Mfg_Date')">
              <a-date-picker v-model:value="editing.mfg_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Expiry_Date')">
              <a-date-picker v-model:value="editing.expiry_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Quantity')" :extra="$t('Batch_Qty_Edit_Hint')">
              <a-input-number v-model:value="editing.qty" style="width: 100%" :min="0" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('UnitCost')">
              <a-input-number v-model:value="editing.unit_cost" style="width: 100%" :min="0" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Note')">
              <a-textarea v-model:value="editing.notes" :rows="2" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Write off -->
    <a-modal
      v-model:open="writeOffOpen"
      :title="`${$t('Write_Off')} — ${writingOff.batch_no || ''}`"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      @ok="saveWriteOff"
    >
      <p class="muted">{{ writingOff.product_name }} — {{ $t('Quantity') }}: {{ writingOff.qty }}</p>
      <a-form layout="vertical">
        <a-form-item :label="$t('Reason')">
          <a-textarea v-model:value="writingOff.reason" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET product_batches → {batches, totalRows, warehouses,
 * expiry_warning_days}; filters status (all/active/quarantined/expired/
 * written_off), expiry_window (all/expired/near/valid), warehouse_id (only
 * sent when set). Edit PUT product_batches/{id} {batch_no, expiry_date,
 * mfg_date, qty, unit_cost, status, notes}; write-off POST
 * product_batches/{id}/writeoff {reason}; DELETE product_batches/{id}.
 * Perms (either name works, legacy checks both): manage_batches|batch_manage,
 * writeoff_batches|batch_writeoff.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { EditOutlined, DeleteOutlined, StopOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { money } = useFormat();
const auth = useAuthStore();

const canManage = computed(() => auth.can('manage_batches') || auth.can('batch_manage'));
const canWriteOff = computed(() => auth.can('writeoff_batches') || auth.can('batch_writeoff'));

const filters = ref({ warehouse_id: undefined, status: 'all', expiry_window: 'all' });

const crud = useCrudTable('product_batches', {
  rowsKey: 'batches',
  params: () => ({
    status: filters.value.status,
    expiry_window: filters.value.expiry_window,
    ...(filters.value.warehouse_id ? { warehouse_id: filters.value.warehouse_id } : {}),
  }),
});
crud.fetchRows();

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);
const expiryWarningDays = computed(() => crud.payload.value?.expiry_warning_days || 30);

const statusOptions = computed(() => [
  { value: 'all', label: t('All') },
  { value: 'active', label: t('Batch_Status_active') },
  { value: 'quarantined', label: t('Batch_Status_quarantined') },
  { value: 'expired', label: t('Batch_Status_expired') },
  { value: 'written_off', label: t('Batch_Status_written_off') },
]);
const expiryOptions = computed(() => [
  { value: 'all', label: t('All') },
  { value: 'expired', label: t('Expired') },
  { value: 'near', label: t('Near_Expiry') },
  { value: 'valid', label: t('Valid') },
]);

const columns = computed(() => [
  { title: t('Product'), key: 'product' },
  { title: t('Batch_No'), dataIndex: 'batch_no', key: 'batch_no', sorter: true },
  { title: t('Warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Expiry_Date'), dataIndex: 'expiry_date', key: 'expiry_date', sorter: true },
  { title: t('Quantity'), dataIndex: 'qty', key: 'qty', align: 'right', sorter: true },
  { title: t('UnitCost'), dataIndex: 'unit_cost', key: 'unit_cost', align: 'right' },
  { title: t('Status'), dataIndex: 'status', key: 'status' },
  { title: t('Action'), key: 'actions', width: 140, align: 'center' },
]);

function batchStatusColor(s) {
  return { active: 'success', quarantined: 'warning', expired: 'error', written_off: 'default' }[s] || 'default';
}

function expiryColor(b) {
  const d = Number(b.days_to_expiry);
  if (!Number.isFinite(d)) return undefined;
  if (d < 0) return '#ff4d4f';
  if (d <= expiryWarningDays.value) return '#faad14';
  return undefined;
}

const editOpen = ref(false);
const writeOffOpen = ref(false);
const saving = ref(false);
const editing = ref({});
const writingOff = ref({});

function openEdit(record) {
  editing.value = {
    id: record.id,
    product_name: record.product_name,
    batch_no: record.batch_no,
    expiry_date: record.expiry_date || null,
    mfg_date: record.mfg_date || null,
    qty: Number(record.qty) || 0,
    unit_cost: Number(record.unit_cost) || 0,
    status: record.status,
    notes: record.notes || '',
  };
  editOpen.value = true;
}

async function saveEdit() {
  saving.value = true;
  try {
    await http.put(`product_batches/${editing.value.id}`, {
      batch_no: editing.value.batch_no,
      expiry_date: editing.value.expiry_date || null,
      mfg_date: editing.value.mfg_date || null,
      qty: editing.value.qty,
      unit_cost: editing.value.unit_cost,
      status: editing.value.status,
      notes: editing.value.notes,
    });
    message.success(t('Successfully_Updated'));
    editOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

function openWriteOff(record) {
  writingOff.value = {
    id: record.id,
    batch_no: record.batch_no,
    product_name: record.product_name,
    qty: record.qty,
    reason: '',
  };
  writeOffOpen.value = true;
}

async function saveWriteOff() {
  saving.value = true;
  try {
    await http.post(`product_batches/${writingOff.value.id}/writeoff`, {
      reason: writingOff.value.reason,
    });
    message.success(t('Successfully_Updated'));
    writeOffOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
</style>
