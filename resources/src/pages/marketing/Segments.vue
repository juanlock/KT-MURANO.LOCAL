<template>
  <div class="page">
    <PageHeader :title="$t('Customer_Segments')" :breadcrumb="[$t('Marketing'), $t('Customer_Segments')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('New_Segment') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'all_customers'">
          <a-tag :color="record.all_customers ? 'green' : 'default'">
            {{ record.all_customers ? $t('All_Customers') : $t('Specific_Segment') }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openEdit(record)">
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

    <a-modal
      v-model:open="modalOpen"
      :title="editing ? $t('Edit_Segment') : $t('New_Segment')"
      :confirm-loading="saving"
      :ok-text="$t('Save_Segment')"
      width="720px"
      @ok="save"
    >
      <a-form ref="formRef" :model="form" :rules="formRules" layout="vertical">
        <a-form-item :label="$t('Segment_Name')" name="name">
          <a-input v-model:value="form.name" />
        </a-form-item>
        <a-form-item :label="$t('Description')" name="description">
          <a-input v-model:value="form.description" />
        </a-form-item>
        <a-form-item>
          <a-checkbox v-model:checked="form.all_customers" @change="previewCount = null">
            {{ $t('All_Customers') }}
          </a-checkbox>
        </a-form-item>

        <template v-if="!form.all_customers">
          <a-divider style="margin: 8px 0 16px" />
          <a-row :gutter="12">
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('City')">
                <a-input v-model:value="form.filters.city" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="6">
              <a-form-item :label="$t('Last_Purchase_From')">
                <a-date-picker v-model:value="form.filters.last_purchase_from" value-format="YYYY-MM-DD" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="6">
              <a-form-item :label="$t('Last_Purchase_To')">
                <a-date-picker v-model:value="form.filters.last_purchase_to" value-format="YYYY-MM-DD" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="6">
              <a-form-item :label="$t('Total_Purchases_Min')">
                <a-input-number v-model:value="form.filters.total_purchases_min" :min="0" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="6">
              <a-form-item :label="$t('Total_Purchases_Max')">
                <a-input-number v-model:value="form.filters.total_purchases_max" :min="0" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="6">
              <a-form-item :label="$t('Total_Spent_Min')">
                <a-input-number v-model:value="form.filters.total_spent_min" :min="0" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="6">
              <a-form-item :label="$t('Total_Spent_Max')">
                <a-input-number v-model:value="form.filters.total_spent_max" :min="0" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>
        </template>

        <a-space style="margin-top: 4px">
          <a-button :loading="previewing" @click="preview">
            <template #icon><SearchOutlined /></template>
            {{ $t('Preview_Segment') }}
          </a-button>
          <strong v-if="previewCount !== null" style="color: #1677ff">
            {{ previewCount }} {{ $t('Matching_Customers') }}
          </strong>
        </a-space>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET marketing/segments → {segments, totalRows}. Save = POST/PUT with
 * {name, description, all_customers (1/0), filters}; the backend recomputes
 * customers_count on every save. Preview = POST marketing/segments/preview
 * with the same audience/filters → {count, sample}.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, SearchOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';

const { t } = useI18n();

const crud = useCrudTable('marketing/segments', { rowsKey: 'segments' });
crud.fetchRows();

const columns = computed(() => [
  { title: t('Segment_Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Type'), dataIndex: 'all_customers', key: 'all_customers' },
  { title: t('Matching_Customers'), dataIndex: 'customers_count', key: 'customers_count', sorter: true, align: 'right' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const modalOpen = ref(false);
const saving = ref(false);
const previewing = ref(false);
const previewCount = ref(null);
const editing = ref(null);
const formRef = ref();

function emptyFilters() {
  return {
    city: '', last_purchase_from: null, last_purchase_to: null,
    total_purchases_min: null, total_purchases_max: null,
    total_spent_min: null, total_spent_max: null,
  };
}

const form = ref({ name: '', description: '', all_customers: false, filters: emptyFilters() });

const formRules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
}));

function openCreate() {
  editing.value = null;
  previewCount.value = null;
  form.value = { name: '', description: '', all_customers: false, filters: emptyFilters() };
  modalOpen.value = true;
}

function openEdit(record) {
  editing.value = record;
  previewCount.value = null;
  form.value = {
    name: record.name,
    description: record.description || '',
    all_customers: !!record.all_customers,
    filters: { ...emptyFilters(), ...(record.filters || {}) },
  };
  modalOpen.value = true;
}

function payload() {
  return {
    name: form.value.name,
    description: form.value.description,
    all_customers: form.value.all_customers ? 1 : 0,
    filters: form.value.filters,
  };
}

async function preview() {
  previewing.value = true;
  try {
    const data = await http.post('marketing/segments/preview', {
      all_customers: form.value.all_customers ? 1 : 0,
      filters: form.value.filters,
    });
    previewCount.value = data.count;
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    previewing.value = false;
  }
}

async function save() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  try {
    if (editing.value) {
      await http.put(`marketing/segments/${editing.value.id}`, payload());
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('marketing/segments', payload());
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
</script>
