<template>
  <div class="page">
    <PageHeader :title="$t('Warehouses')" :breadcrumb="[$t('Settings'), $t('Warehouses')]">
      <template #actions>
        <a-button type="primary" @click="tab === 'locations' ? openLocationCreate() : openCreate()">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-tabs v-model:activeKey="tab">
      <!-- ================= Warehouses ================= -->
      <a-tab-pane key="warehouses" :tab="$t('Warehouses')">
        <a-alert
          type="info" show-icon style="margin-bottom: 16px"
          :message="$t('Warehouses_Tab_Help')"
        />
        <DataTable :crud="crud" :columns="columns" selectable>
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'actions'">
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
      </a-tab-pane>

      <!-- ================= Rack locations (merged from the old
           settings/warehouse-locations page) ================= -->
      <a-tab-pane v-if="auth.can('warehouse_locations')" key="locations" :tab="$t('Warehouse_Locations')">
        <a-alert
          type="info" show-icon style="margin-bottom: 16px"
          :message="$t('Warehouse_Locations_Tab_Help')"
        />
        <a-card size="small" style="margin-bottom: 16px">
          <a-row :gutter="[16, 8]">
            <a-col :xs="24" :md="10">
              <div class="filter-label">{{ $t('Warehouse') }}</div>
              <a-select
                v-model:value="locFilters.warehouse_id" style="width: 100%" allow-clear show-search
                option-filter-prop="label" :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
                @change="locCrud.reload()"
              />
            </a-col>
          </a-row>
        </a-card>

        <DataTable :crud="locCrud" :columns="locColumns">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'is_active'">
              <a-tag :color="record.is_active ? 'success' : 'default'">
                {{ record.is_active ? $t('Active') : $t('Inactive') }}
              </a-tag>
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-space>
                <a-tooltip :title="$t('Edit')">
                  <a-button type="text" size="small" @click="openLocationEdit(record)">
                    <template #icon><EditOutlined style="color: #52c41a" /></template>
                  </a-button>
                </a-tooltip>
                <a-tooltip :title="$t('Del')">
                  <a-button type="text" size="small" danger @click="locCrud.remove(record)">
                    <template #icon><DeleteOutlined /></template>
                  </a-button>
                </a-tooltip>
              </a-space>
            </template>
          </template>
        </DataTable>
      </a-tab-pane>
    </a-tabs>

    <!-- ================= Warehouse modal ================= -->
    <a-modal
      v-model:open="modalOpen"
      :title="editMode ? $t('Edit') : $t('Add')"
      :confirm-loading="submitting"
      :ok-text="$t('Submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      width="640px"
      @ok="submit"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 8px">
        <a-row :gutter="16">
          <a-col :span="12">
            <a-form-item :label="$t('Name')" name="name">
              <a-input v-model:value="form.name" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Phone')" name="mobile">
              <a-input v-model:value="form.mobile" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Country')" name="country">
              <a-input v-model:value="form.country" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('City')" name="city">
              <a-input v-model:value="form.city" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Email')" name="email">
              <a-input v-model:value="form.email" type="email" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('ZipCode')" name="zip">
              <a-input v-model:value="form.zip" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- ================= Location modal ================= -->
    <a-modal
      v-model:open="locModalOpen"
      :title="locEditing ? $t('Edit') : $t('Add')"
      :confirm-loading="locSaving"
      :ok-text="$t('submit')"
      @ok="saveLocation"
    >
      <a-form ref="locFormRef" :model="locForm" :rules="locFormRules" layout="vertical">
        <a-form-item :label="$t('Warehouse')" name="warehouse_id">
          <a-select
            v-model:value="locForm.warehouse_id" show-search option-filter-prop="label"
            :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
          />
        </a-form-item>
        <a-form-item :label="$t('Rack_Location_Code')" name="code">
          <a-input v-model:value="locForm.code" :placeholder="$t('Enter_Rack_Location_Code')" />
        </a-form-item>
        <a-form-item :label="$t('Location_Name')" name="name">
          <a-input v-model:value="locForm.name" :placeholder="$t('Enter_Location_Name')" />
        </a-form-item>
        <a-form-item>
          <a-checkbox v-model:checked="locForm.is_active">{{ $t('Active') }}</a-checkbox>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Warehouses + rack locations in one page (two tabs). Locations were the
 * standalone settings/warehouse-locations page; its endpoints are unchanged:
 * GET warehouse_locations → {locations, totalRows, warehouses}; POST/PUT
 * {warehouse_id, code, name, is_active}. The locations tab needs the
 * warehouse_locations permission; the old route redirects here.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../components/PageHeader.vue';
import DataTable from '../components/DataTable.vue';
import { useCrudTable } from '../composables/useCrudTable';
import { useAuthStore } from '../stores/auth';
import http from '../lib/http';

const { t } = useI18n();
const auth = useAuthStore();
const tab = ref('warehouses');

/* ------------------------------------------------------------ warehouses */
const crud = useCrudTable('warehouses', { rowsKey: 'warehouses' });

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Phone'), dataIndex: 'mobile', key: 'mobile' },
  { title: t('Country'), dataIndex: 'country', key: 'country' },
  { title: t('City'), dataIndex: 'city', key: 'city' },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: t('ZipCode'), dataIndex: 'zip', key: 'zip' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const formRef = ref();
const emptyForm = () => ({ id: null, name: '', mobile: '', email: '', zip: '', country: '', city: '' });
const form = ref(emptyForm());

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
  email: [{ type: 'email', message: t('InvalidData') }],
}));

function openCreate() {
  editMode.value = false;
  form.value = emptyForm();
  modalOpen.value = true;
}

function openEdit(record) {
  editMode.value = true;
  form.value = {
    id: record.id,
    name: record.name || '',
    mobile: record.mobile || '',
    email: record.email || '',
    zip: record.zip || '',
    country: record.country || '',
    city: record.city || '',
  };
  modalOpen.value = true;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  const body = {
    name: form.value.name,
    mobile: form.value.mobile,
    email: form.value.email,
    zip: form.value.zip,
    country: form.value.country,
    city: form.value.city,
  };
  try {
    if (editMode.value) {
      await http.put(`warehouses/${form.value.id}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('warehouses', body);
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    crud.fetchRows();
    // Warehouse names feed the locations tab too.
    if (locLoaded.value) locCrud.fetchRows();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

/* ------------------------------------------------------------- locations */
const locFilters = ref({ warehouse_id: undefined });
const locLoaded = ref(false);

const locCrud = useCrudTable('warehouse_locations', {
  rowsKey: 'locations',
  params: () => ({ warehouse_id: locFilters.value.warehouse_id || '' }),
});

// Fetch lazily, the first time the tab is opened.
watch(tab, v => {
  if (v === 'locations' && !locLoaded.value) {
    locLoaded.value = true;
    locCrud.fetchRows();
  }
});

const warehouseOptions = computed(() =>
  (locCrud.payload.value?.warehouses || crud.rows.value || []).map(w => ({ value: w.id, label: w.name }))
);

const locColumns = computed(() => [
  { title: t('Warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Rack_Location_Code'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('Location_Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Status'), dataIndex: 'is_active', key: 'is_active' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const locModalOpen = ref(false);
const locSaving = ref(false);
const locEditing = ref(null);
const locFormRef = ref();
const locForm = ref({ warehouse_id: undefined, code: '', name: '', is_active: true });

const locFormRules = computed(() => ({
  warehouse_id: [{ required: true, message: t('Field_is_required') }],
  code: [{ required: true, message: t('Field_is_required') }],
  name: [{ required: true, message: t('Field_is_required') }],
}));

function openLocationCreate() {
  locEditing.value = null;
  locForm.value = { warehouse_id: locFilters.value.warehouse_id, code: '', name: '', is_active: true };
  locModalOpen.value = true;
}

function openLocationEdit(record) {
  locEditing.value = record;
  locForm.value = {
    warehouse_id: record.warehouse_id,
    code: record.code,
    name: record.name,
    is_active: !!record.is_active,
  };
  locModalOpen.value = true;
}

async function saveLocation() {
  try {
    await locFormRef.value.validate();
  } catch (e) {
    return;
  }
  locSaving.value = true;
  const payload = {
    warehouse_id: locForm.value.warehouse_id,
    code: locForm.value.code,
    name: locForm.value.name,
    is_active: locForm.value.is_active,
  };
  try {
    if (locEditing.value) {
      await http.put(`warehouse_locations/${locEditing.value.id}`, payload);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('warehouse_locations', payload);
      message.success(t('Successfully_Created'));
    }
    locModalOpen.value = false;
    locCrud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    locSaving.value = false;
  }
}

onMounted(crud.fetchRows);
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
</style>
