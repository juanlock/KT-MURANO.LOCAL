<template>
  <div class="page">
    <PageHeader
      :title="tf('Vehicle_Fitment', 'Vehicle Fitment')"
      :breadcrumb="[$t('Products'), tf('Vehicle_Fitment', 'Vehicle Fitment')]"
    />

    <a-alert
      v-if="!enabled"
      type="info" show-icon style="margin-bottom: 16px"
      :message="tf('Vehicle_Fitment_Disabled_Hint', 'The Vehicle Fitment feature is currently disabled. Enable it in System Settings → Features to activate the storefront and POS vehicle selector.')"
    />

    <a-row :gutter="[16, 16]">
      <!-- Makes -->
      <a-col :xs="24" :lg="10">
        <a-card size="small" :title="tf('Vehicle_Makes', 'Makes')">
          <template #extra>
            <a-button type="primary" size="small" @click="openMake()">
              <template #icon><PlusOutlined /></template>
              {{ $t('Add') }}
            </a-button>
          </template>
          <a-table
            :data-source="makes" :columns="makeColumns" row-key="id"
            size="small" :pagination="{ pageSize: 10, hideOnSinglePage: true }"
            :row-class-name="r => (r.id === selectedMakeId ? 'row-selected' : '')"
            :custom-row="r => ({ onClick: () => (selectedMakeId = r.id), style: 'cursor:pointer' })"
            :loading="loading"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'name'">
                <strong>{{ record.name }}</strong>
                <a-tag v-if="!record.active" style="margin-inline-start: 8px">{{ tf('Inactive', 'Inactive') }}</a-tag>
              </template>
              <template v-else-if="column.key === 'models'">
                {{ record.models.length }}
              </template>
              <template v-else-if="column.key === 'actions'">
                <a-space>
                  <a-button type="text" size="small" @click.stop="openMake(record)">
                    <template #icon><EditOutlined style="color: #52c41a" /></template>
                  </a-button>
                  <a-button type="text" size="small" danger @click.stop="removeMake(record)">
                    <template #icon><DeleteOutlined /></template>
                  </a-button>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-card>
      </a-col>

      <!-- Models of the selected make -->
      <a-col :xs="24" :lg="14">
        <a-card
          size="small"
          :title="(tf('Vehicle_Models', 'Models')) + (selectedMake ? ` — ${selectedMake.name}` : '')"
        >
          <template #extra>
            <a-button type="primary" size="small" :disabled="!makes.length" @click="openModel()">
              <template #icon><PlusOutlined /></template>
              {{ $t('Add') }}
            </a-button>
          </template>

          <a-empty v-if="!selectedMake" :description="tf('Select_A_Make', 'Select a make on the left')" />
          <a-table
            v-else
            :data-source="selectedMake.models" :columns="modelColumns" row-key="id"
            size="small" :pagination="{ pageSize: 10, hideOnSinglePage: true }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'name'">
                {{ record.name }}
                <a-tag v-if="!record.active" style="margin-inline-start: 8px">{{ tf('Inactive', 'Inactive') }}</a-tag>
              </template>
              <template v-else-if="column.key === 'actions'">
                <a-space>
                  <a-button type="text" size="small" @click="openModel(record)">
                    <template #icon><EditOutlined style="color: #52c41a" /></template>
                  </a-button>
                  <a-button type="text" size="small" danger @click="removeModel(record)">
                    <template #icon><DeleteOutlined /></template>
                  </a-button>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-card>
      </a-col>
    </a-row>

    <!-- Make modal -->
    <a-modal
      v-model:open="makeDlg.open"
      :title="makeDlg.id ? $t('Edit') : $t('Add')"
      :confirm-loading="makeDlg.busy"
      @ok="saveMake"
    >
      <a-form layout="vertical">
        <a-form-item :label="tf('Make', 'Make')" required>
          <a-input v-model:value="makeDlg.name" :maxlength="100" @pressEnter="saveMake" />
        </a-form-item>
        <a-form-item v-if="makeDlg.id" :label="tf('Active', 'Active')">
          <a-switch v-model:checked="makeDlg.active" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Model modal -->
    <a-modal
      v-model:open="modelDlg.open"
      :title="modelDlg.id ? $t('Edit') : $t('Add')"
      :confirm-loading="modelDlg.busy"
      @ok="saveModel"
    >
      <a-form layout="vertical">
        <a-form-item :label="tf('Make', 'Make')" required>
          <a-select
            v-model:value="modelDlg.make_id"
            :options="makeSelectOptions"
            show-search option-filter-prop="label"
            :disabled="!!modelDlg.id"
          />
        </a-form-item>
        <a-form-item :label="tf('Model', 'Model')" required>
          <a-input v-model:value="modelDlg.name" :maxlength="100" @pressEnter="saveModel" />
        </a-form-item>
        <a-form-item v-if="modelDlg.id" :label="tf('Active', 'Active')">
          <a-switch v-model:checked="modelDlg.active" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Vehicle Fitment catalog — GET vehicles/catalog → {makes:[{id,name,active,
 * models:[...]}], enabled}. Make/model CRUD via vehicles/makes + vehicles/
 * models. Deleting a make/model also removes dependent fitment + garage rows
 * (server-side), hence the explicit confirm.
 */
import { ref, computed, onMounted, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { t as tf } from '../../i18n';

const { t } = useI18n();

const loading = ref(true);
const enabled = ref(true);
const makes = ref([]);
const selectedMakeId = ref(null);

const selectedMake = computed(() => makes.value.find(m => m.id === selectedMakeId.value) || null);

const makeColumns = computed(() => [
  { title: tf('Make', 'Make'), key: 'name' },
  { title: tf('Vehicle_Models', 'Models'), key: 'models', width: 90, align: 'center' },
  { title: t('Action'), key: 'actions', width: 100, align: 'center' },
]);
const modelColumns = computed(() => [
  { title: tf('Model', 'Model'), key: 'name' },
  { title: t('Action'), key: 'actions', width: 100, align: 'center' },
]);

async function fetchCatalog() {
  loading.value = true;
  try {
    const data = await http.get('vehicles/catalog');
    makes.value = Array.isArray(data?.makes) ? data.makes : [];
    enabled.value = !!data?.enabled;
    if (selectedMakeId.value && !makes.value.some(m => m.id === selectedMakeId.value)) {
      selectedMakeId.value = null;
    }
    if (!selectedMakeId.value && makes.value.length) {
      selectedMakeId.value = makes.value[0].id;
    }
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

/* ---------- Makes ---------- */
const makeDlg = ref({ open: false, id: null, name: '', active: true, busy: false });

function openMake(record = null) {
  makeDlg.value = {
    open: true, busy: false,
    id: record?.id ?? null,
    name: record?.name ?? '',
    active: record ? !!record.active : true,
  };
}
async function saveMake() {
  const d = makeDlg.value;
  if (!d.name.trim()) return;
  d.busy = true;
  try {
    if (d.id) await http.put(`vehicles/makes/${d.id}`, { name: d.name.trim(), active: d.active });
    else await http.post('vehicles/makes', { name: d.name.trim() });
    message.success(t('Successfully_Updated'));
    d.open = false;
    await fetchCatalog();
  } catch (e) {
    message.error(firstError(e) || t('InvalidData'));
  } finally {
    d.busy = false;
  }
}
function removeMake(record) {
  Modal.confirm({
    title: t('Confirm'),
    icon: createVNode(ExclamationCircleOutlined),
    content: tf('Delete_Make_Warning', 'Deleting this make also removes its models, all product fitment rows and saved customer vehicles that use it.'),
    okText: t('Del'),
    okType: 'danger',
    async onOk() {
      try {
        await http.delete(`vehicles/makes/${record.id}`);
        message.success(t('Successfully_Deleted'));
        await fetchCatalog();
      } catch (e) {
        message.error(t('InvalidData'));
      }
    },
  });
}

/* ---------- Models ---------- */
const modelDlg = ref({ open: false, id: null, make_id: null, name: '', active: true, busy: false });

const makeSelectOptions = computed(() => makes.value.map(m => ({ value: m.id, label: m.name })));

function openModel(record = null) {
  modelDlg.value = {
    open: true, busy: false,
    id: record?.id ?? null,
    make_id: selectedMakeId.value ?? null,
    name: record?.name ?? '',
    active: record ? !!record.active : true,
  };
}
async function saveModel() {
  const d = modelDlg.value;
  if (!d.name.trim() || !d.make_id) return;
  d.busy = true;
  try {
    if (d.id) await http.put(`vehicles/models/${d.id}`, { name: d.name.trim(), active: d.active });
    else await http.post('vehicles/models', { vehicle_make_id: d.make_id, name: d.name.trim() });
    message.success(t('Successfully_Updated'));
    d.open = false;
    selectedMakeId.value = d.make_id;
    await fetchCatalog();
  } catch (e) {
    message.error(firstError(e) || t('InvalidData'));
  } finally {
    d.busy = false;
  }
}
function removeModel(record) {
  Modal.confirm({
    title: t('Confirm'),
    icon: createVNode(ExclamationCircleOutlined),
    content: tf('Delete_Model_Warning', 'Deleting this model also removes product fitment rows and saved customer vehicles that use it.'),
    okText: t('Del'),
    okType: 'danger',
    async onOk() {
      try {
        await http.delete(`vehicles/models/${record.id}`);
        message.success(t('Successfully_Deleted'));
        await fetchCatalog();
      } catch (e) {
        message.error(t('InvalidData'));
      }
    },
  });
}

function firstError(e) {
  const errs = e?.data?.errors;
  if (errs && typeof errs === 'object') {
    const first = Object.values(errs)[0];
    return Array.isArray(first) ? first[0] : String(first);
  }
  return e?.data?.error || e?.data?.message || null;
}

onMounted(fetchCatalog);
</script>

<style scoped>
:deep(.row-selected) td {
  background: rgba(109, 40, 217, 0.08) !important;
}
</style>
