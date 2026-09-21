<template>
  <div class="page">
    <PageHeader :title="$t('Currencies')" :breadcrumb="[$t('Settings'), $t('Currencies')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'exchange_rate'">
          <a-tooltip v-if="record.id === defaultCurrencyId" :title="$t('Base_Currency_Rate_Locked')">
            <span>1</span>
          </a-tooltip>
          <span v-else>{{ record.exchange_rate ?? 1 }}</span>
        </template>
        <template v-else-if="column.key === 'is_default'">
          <a-tooltip :title="record.id === defaultCurrencyId ? $t('Default') : 'Set as default'">
            <a-switch
              :checked="record.id === defaultCurrencyId"
              :disabled="record.id === defaultCurrencyId || settingDefault !== null"
              :loading="settingDefault === record.id"
              @change="() => makeDefault(record)"
            />
          </a-tooltip>
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
      :title="editMode ? $t('Edit') : $t('Add')"
      :confirm-loading="submitting"
      :ok-text="$t('Submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      @ok="submit"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 8px">
        <a-form-item :label="$t('CurrencyCode')" name="code">
          <a-input v-model:value="form.code" />
        </a-form-item>
        <a-form-item :label="$t('CurrencyName')" name="name">
          <a-input v-model:value="form.name" />
        </a-form-item>
        <a-form-item :label="$t('Symbol')" name="symbol">
          <a-input v-model:value="form.symbol" />
        </a-form-item>
        <a-form-item v-if="auth.multiCurrencyEnabled" :label="$t('Exchange_Rate')" name="exchange_rate">
          <a-input-number
            v-model:value="form.exchange_rate"
            :min="0.000001"
            :precision="6"
            :disabled="editMode && form.id === defaultCurrencyId"
            style="width: 100%"
          />
          <div class="ant-form-item-extra" style="color: var(--text-3, #999); font-size: 12px; margin-top: 4px">
            {{ editMode && form.id === defaultCurrencyId ? $t('Base_Currency_Rate_Locked') : $t('Exchange_Rate_Hint') }}
          </div>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
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
const crud = useCrudTable('currencies', { rowsKey: 'currencies' });

const columns = computed(() => [
  { title: t('CurrencyCode'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('CurrencyName'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Symbol'), dataIndex: 'symbol', key: 'symbol' },
  // Exchange rate only matters when the Multi-Currency module is on.
  ...(auth.multiCurrencyEnabled
    ? [{ title: t('Exchange_Rate'), key: 'exchange_rate', width: 140, align: 'center' }]
    : []),
  { title: t('Default'), key: 'is_default', width: 110, align: 'center' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

// The system default currency (settings.currency_id), returned by the list.
const defaultCurrencyId = computed(() => crud.payload.value?.default_currency_id ?? null);
const settingDefault = ref(null); // id currently being set, for the row spinner

async function makeDefault(record) {
  if (record.id === defaultCurrencyId.value) return;
  settingDefault.value = record.id;
  try {
    await http.post(`currencies/${record.id}/set-default`);
    message.success(t('Successfully_Updated'));
    // Rates are relative to the base currency and are NOT auto-rebased.
    if (auth.multiCurrencyEnabled) message.warning(t('Set_As_Default_Rate_Warning'), 6);
    await crud.fetchRows();
    // Refresh auth so money formatting picks up the new currency app-wide.
    auth.loaded = false;
    await auth.load();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    settingDefault.value = null;
  }
}

const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const formRef = ref();
const emptyForm = () => ({ id: null, name: '', code: '', symbol: '', exchange_rate: 1 });
const form = ref(emptyForm());

const rules = computed(() => ({
  code: [{ required: true, message: t('Field_is_required') }],
  name: [{ required: true, message: t('Field_is_required') }],
  symbol: [{ required: true, message: t('Field_is_required') }],
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
    code: record.code || '',
    symbol: record.symbol || '',
    exchange_rate: record.exchange_rate ?? 1,
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
    code: form.value.code,
    symbol: form.value.symbol,
    exchange_rate: form.value.exchange_rate || 1,
  };
  try {
    if (editMode.value) {
      await http.put(`currencies/${form.value.id}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('currencies', body);
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

onMounted(crud.fetchRows);
</script>
