<template>
  <div class="page">
    <PageHeader :title="$t('Payment_Methods')" :breadcrumb="[$t('Settings'), $t('Payment_Methods')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <!-- Enable/disable is allowed on every method, defaults included —
             inactive methods disappear from new-payment pickers only. -->
        <template v-if="column.key === 'is_active'">
          <a-switch
            :checked="!!record.is_active"
            :loading="togglingId === record.id"
            size="small"
            @change="v => toggleActive(record, v)"
          />
        </template>
        <template v-else-if="column.key === 'actions'">
          <!-- Methods 1-3 are system defaults — legacy blocks touching them. -->
          <span v-if="[1, 2, 3].includes(record.id)" style="color: #faad14; font-size: 12px">
            {{ $t('You_cant_edit_or_remove_default_payment_choices') }}
          </span>
          <a-space v-else>
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
      :title="editing ? $t('Edit') : $t('Add')"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      @ok="save"
    >
      <a-form ref="formRef" :model="form" :rules="formRules" layout="vertical">
        <a-form-item :label="$t('Name')" name="name">
          <a-input v-model:value="form.name" :placeholder="$t('Enter_Payment_Method')" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET payment_methods → {methods, totalRows}; POST/PUT {name}. Rows with
 * id 1-3 are the built-in defaults and can't be edited or removed (legacy
 * rule, enforced by id) — but CAN be enabled/disabled like any other row via
 * PUT payment_methods/{id}/active {is_active}. Inactive methods are hidden
 * from every new-payment picker (POS, sales/purchases/returns/expenses);
 * reports and historical payments still show them.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';

const { t } = useI18n();

const crud = useCrudTable('payment_methods', { rowsKey: 'methods', sortType: 'asc' });
crud.fetchRows();

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Status'), key: 'is_active', width: 90, align: 'center' },
  { title: t('Action'), key: 'actions', width: 280, align: 'center' },
]);

const togglingId = ref(null);

async function toggleActive(record, checked) {
  togglingId.value = record.id;
  try {
    await http.put(`payment_methods/${record.id}/active`, { is_active: !!checked });
    record.is_active = !!checked;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    togglingId.value = null;
  }
}

const modalOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const formRef = ref();
const form = ref({ name: '' });

const formRules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
}));

function openCreate() {
  editing.value = null;
  form.value = { name: '' };
  modalOpen.value = true;
}

function openEdit(record) {
  editing.value = record;
  form.value = { name: record.name };
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
    if (editing.value) {
      await http.put(`payment_methods/${editing.value.id}`, { name: form.value.name });
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('payment_methods', { name: form.value.name });
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
