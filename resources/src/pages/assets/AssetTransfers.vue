<template>
  <div class="page">
    <PageHeader
      title="Transfers"
      subtitle="Every move an asset has made between warehouses."
      :breadcrumb="['Asset Management', 'Transfers']"
    >
      <template #actions>
        <a-button type="primary" @click="openForm">
          <template #icon><SwapOutlined /></template>
          Transfer asset
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" placeholder="Search asset, warehouse or reason…"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.asset_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="All assets"
          :options="assetOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.warehouse_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="All warehouses"
          :options="warehouseOptions" @change="crud.reload"
        />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
      </div>
    </a-card>

    <a-alert
      type="info" show-icon banner style="margin-bottom: 12px"
      message="A transfer is a record of something that happened, so it cannot be edited. Deleting the most recent one moves the asset back where it came from."
    />

    <DataTable :crud="crud" :columns="columns" :searchable="false" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'asset'">
          <a class="link" @click="$router.push(`/assets/${record.asset_id}`)">{{ record.asset_name }}</a>
          <div class="sub">{{ record.asset_tag }}</div>
        </template>
        <template v-else-if="column.key === 'route'">
          <span class="route">
            <span class="wh">{{ record.from_warehouse_name || 'Unassigned' }}</span>
            <ArrowRightOutlined class="arrow" />
            <span class="wh wh--to">{{ record.to_warehouse_name }}</span>
          </span>
        </template>
        <template v-else-if="column.key === 'transfer_date'">{{ date(record.transfer_date) }}</template>
        <template v-else-if="column.key === 'reason'">{{ record.reason || '—' }}</template>
        <template v-else-if="column.key === 'actions'">
          <a-tooltip :title="$t('Del')">
            <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.asset_name })">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-tooltip>
        </template>
      </template>
    </DataTable>

    <a-modal
      :open="formOpen" title="Transfer asset" :width="560"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-form-item label="Asset *" name="asset_id">
          <a-select
            v-model:value="form.asset_id" show-search option-filter-prop="label"
            :options="assetOptions" placeholder="Select an asset"
          />
        </a-form-item>
        <a-form-item v-if="currentWarehouse" label="Currently in">
          <a-tag>{{ currentWarehouse }}</a-tag>
        </a-form-item>
        <a-form-item label="Move to *" name="to_warehouse_id">
          <a-select
            v-model:value="form.to_warehouse_id" show-search option-filter-prop="label"
            :options="destinationOptions" placeholder="Select a warehouse"
          />
        </a-form-item>
        <a-form-item label="Date *" name="transfer_date">
          <a-date-picker v-model:value="form.transfer_date" style="width: 100%" value-format="YYYY-MM-DD" />
        </a-form-item>
        <a-form-item label="Reason">
          <a-input v-model:value="form.reason" placeholder="e.g. Branch reallocation" allow-clear />
        </a-form-item>
        <a-form-item :label="$t('Note')" style="margin-bottom: 0">
          <a-textarea v-model:value="form.notes" :rows="2" allow-clear />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * The movement log.
 *
 * The "from" side is never asked for — the API takes it from where the asset
 * actually is, so the chain of moves reads continuously even if this page was
 * left open while someone else moved the same asset. The destination select
 * hides the warehouse the asset is already in, which is the one move the API
 * refuses.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { SwapOutlined, DeleteOutlined, ArrowRightOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { t } from '../../i18n';

const route = useRoute();
const { date } = useFormat();

const filters = reactive({
  asset_id: route.query.asset_id ? Number(route.query.asset_id) : undefined,
  warehouse_id: undefined,
  range: null,
});

const crud = useCrudTable('asset_transfers', {
  rowsKey: 'transfers',
  sortField: 'transfer_date',
  bulkDeleteEndpoint: 'asset_transfers/delete/by_selection',
  params: () => ({
    asset_id: filters.asset_id || '',
    warehouse_id: filters.warehouse_id || '',
    from: filters.range?.[0] || '',
    to: filters.range?.[1] || '',
  }),
});

const columns = computed(() => [
  { title: 'Date', key: 'transfer_date', dataIndex: 'transfer_date', sorter: true, width: 120 },
  { title: 'Asset', key: 'asset', dataIndex: 'asset_name', sorter: true },
  { title: 'Moved', key: 'route', width: 300 },
  { title: 'Reason', key: 'reason', dataIndex: 'reason' },
  { title: '', key: 'actions', width: 70, align: 'center' },
]);

// ---------------- meta ----------------

const meta = ref({ assets: [], warehouses: [] });
const assetOptions = computed(() => (meta.value.assets || []).map(a => ({ value: a.id, label: a.label })));
const warehouseOptions = computed(() => (meta.value.warehouses || []).map(w => ({ value: w.id, label: w.name })));

const selectedAsset = computed(() => (meta.value.assets || []).find(a => a.id === form.asset_id));
const currentWarehouse = computed(() => {
  const wh = (meta.value.warehouses || []).find(w => w.id === selectedAsset.value?.warehouse_id);
  return wh ? wh.name : (selectedAsset.value ? 'Unassigned' : '');
});
/** Everywhere except where it already is — that move is refused server-side. */
const destinationOptions = computed(() => warehouseOptions.value
  .filter(w => w.value !== selectedAsset.value?.warehouse_id));

// ---------------- form ----------------

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const form = reactive({
  asset_id: undefined,
  to_warehouse_id: undefined,
  transfer_date: new Date().toISOString().slice(0, 10),
  reason: '',
  notes: '',
});

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  asset_id: required(),
  to_warehouse_id: required(),
  transfer_date: required(),
}));

function openForm() {
  form.asset_id = filters.asset_id;
  form.to_warehouse_id = undefined;
  form.transfer_date = new Date().toISOString().slice(0, 10);
  form.reason = '';
  form.notes = '';
  formOpen.value = true;
  formRef.value?.clearValidate?.();
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    await http.post('asset_transfers', { ...form });
    message.success(t('Created_in_successfully', 'Transfer recorded'));
    formOpen.value = false;
    crud.fetchRows();
    loadMeta();
  } catch (e) {
    message.error(firstError(e) || t('InvalidData', 'Could not record this transfer'));
  } finally {
    saving.value = false;
  }
}

function firstError(e) {
  const errors = e?.data?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first.length) return first[0];
  }
  return e?.data?.message || '';
}

async function loadMeta() {
  try {
    meta.value = await http.get('assets/workspace/meta');
  } catch (e) { /* the selects stay empty */ }
}

onMounted(() => {
  crud.fetchRows();
  loadMeta();
});
</script>

<style scoped>
.sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.link {
  color: #6d28d9;
  cursor: pointer;
}
.route {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.wh {
  opacity: 0.7;
}
.wh--to {
  opacity: 1;
  font-weight: 500;
}
.arrow {
  font-size: 11px;
  opacity: 0.45;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 220px;
  min-width: 180px;
}
.tb-item {
  width: 170px;
}
.tb-range {
  width: 240px;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-range {
    width: 100%;
  }
}
</style>
