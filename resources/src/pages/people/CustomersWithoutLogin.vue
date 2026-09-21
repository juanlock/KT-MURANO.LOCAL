<template>
  <div class="page">
    <PageHeader
      :title="$t('Customers_without_Login') || 'Customers without Login'"
      :breadcrumb="[$t('Store'), $t('Customers_without_Login')]"
    />

    <a-alert
      type="info" show-icon style="margin-bottom: 16px"
      :message="$t('Online_Store_Accounts')"
      :description="$t('Customers_without_login_alert')"
    />

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'actions'">
          <a-button type="primary" size="small" @click="openRegister(record)">
            Register Account
          </a-button>
        </template>
      </template>
    </DataTable>

    <!-- Register store account -->
    <a-modal
      v-model:open="modalOpen"
      :title="'Register Account'"
      :confirm-loading="submitting"
      :ok-text="$t('submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      @ok="submit"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 8px">
        <a-form-item :label="$t('Email') + ' *'" name="email"
          :validate-status="emailExist ? 'error' : undefined" :help="emailExist || undefined">
          <a-input v-model:value="form.email" :placeholder="$t('Email')" @change="emailExist = ''" />
        </a-form-item>
        <a-form-item :label="$t('password') + ' *'" name="password">
          <a-input-password v-model:value="form.password" :placeholder="$t('password')" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Customers without a store login — GET clients_without_ecommerce →
 * {clients, totalRows}. One action: register a store account, POST
 * clients_without_ecommerce {client_id, email, password}. Legacy rules:
 * email required; password required 6–14 chars; server email-taken error
 * (errors.email[0]) shown inline under the email field.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';

const { t } = useI18n();

const crud = useCrudTable('clients_without_ecommerce', { rowsKey: 'clients' });
crud.fetchRows();

const columns = computed(() => [
  { title: t('CustomerCode'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('CustomerName'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: t('Action'), key: 'actions', width: 170, align: 'center' },
]);

const modalOpen = ref(false);
const submitting = ref(false);
const formRef = ref();
const form = ref({ client_id: null, email: '', password: '' });
const emailExist = ref('');

const rules = computed(() => ({
  email: [{ required: true, message: t('Field_is_required') }],
  password: [
    { required: true, message: t('Field_is_required') },
    { min: 6, max: 14, message: '6 – 14' },
  ],
}));

function openRegister(record) {
  form.value = { client_id: record.id, email: record.email || '', password: '' };
  emailExist.value = '';
  modalOpen.value = true;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  try {
    await http.post('clients_without_ecommerce', {
      client_id: form.value.client_id,
      email: form.value.email,
      password: form.value.password,
    });
    message.success('Account Registred Successfully');
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    const emailErr = e?.data?.errors?.email;
    if (Array.isArray(emailErr) && emailErr.length) emailExist.value = String(emailErr[0]);
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}
</script>
