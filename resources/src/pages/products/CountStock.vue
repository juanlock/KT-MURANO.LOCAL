<template>
  <div class="page">
    <PageHeader :title="$t('CountStock')" :breadcrumb="[$t('Products'), $t('CountStock')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Count') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
        <template v-else-if="column.key === 'file_stock'">
          <a :href="'/images/count_stock/' + record.file_stock" target="_blank">
            <DownloadOutlined /> {{ $t('Download') }}
          </a>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen"
      :title="$t('Count')"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      @ok="save"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-form-item :label="$t('date')" name="date">
          <a-date-picker v-model:value="form.date" value-format="YYYY-MM-DD" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('warehouse')" name="warehouse_id">
          <a-select
            v-model:value="form.warehouse_id" show-search option-filter-prop="label"
            :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
          />
        </a-form-item>
        <a-form-item :label="$t('Categorie')" name="category_id">
          <a-select
            v-model:value="form.category_id" allow-clear show-search option-filter-prop="label"
            :placeholder="$t('Choose_Category')" :options="categoryOptions"
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET count_stock → {stocks, warehouses, categories, totalRows}. Generate a
 * count sheet: POST store_count_stock {date, warehouse_id, category_id} →
 * produces a downloadable file under /images/count_stock/{file_stock}.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, DownloadOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { date } = useFormat();

const crud = useCrudTable('count_stock', { rowsKey: 'stocks' });
crud.fetchRows();

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);
const categoryOptions = computed(() =>
  (crud.payload.value?.categories || []).map(c => ({ value: c.id, label: c.name }))
);

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Categorie'), dataIndex: 'category_name', key: 'category_name' },
  { title: t('file'), dataIndex: 'file_stock', key: 'file_stock' },
]);

const modalOpen = ref(false);
const saving = ref(false);
const formRef = ref();
const form = ref({ date: dayjs().format('YYYY-MM-DD'), warehouse_id: undefined, category_id: undefined });

const rules = computed(() => ({
  date: [{ required: true, message: t('Field_is_required') }],
  warehouse_id: [{ required: true, message: t('Field_is_required') }],
}));

function openCreate() {
  form.value = { date: dayjs().format('YYYY-MM-DD'), warehouse_id: undefined, category_id: undefined };
  modalOpen.value = true;
}

async function save() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  try {
    await http.post('store_count_stock', {
      date: form.value.date,
      warehouse_id: form.value.warehouse_id,
      category_id: form.value.category_id || null,
    });
    message.success(t('Successfully_Generated_Count'));
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
</script>
