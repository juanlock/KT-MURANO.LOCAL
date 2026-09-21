<template>
  <div class="page">
    <PageHeader :title="$t('Sales_Agents')" :breadcrumb="[$t('Commissions'), $t('Sales_Agents')]">
      <template #extra>
        <a-button v-if="auth.can('commissions_add')" type="primary" @click="openModal()">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'user'">
          {{ record.user ? ((record.user.firstname + ' ' + record.user.lastname).trim() || record.user.email) : '—' }}
        </template>
        <template v-else-if="column.key === 'is_active'">
          <a-tag :color="record.is_active ? 'success' : 'default'">
            {{ record.is_active ? $t('Active') : $t('Inactive') }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-button v-if="auth.can('commissions_edit')" size="small" @click="openModal(record)">
              <template #icon><EditOutlined /></template>
            </a-button>
            <a-button v-if="auth.can('commissions_delete')" size="small" danger @click="crud.remove(record)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen" :title="editMode ? $t('Edit') : $t('Add')"
      :confirm-loading="saving" @ok="submit"
    >
      <a-form ref="modalFormRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Name') + ' *'" name="name">
          <a-input v-model:value="form.name" />
        </a-form-item>
        <a-form-item :label="$t('Code')">
          <a-input v-model:value="form.code" />
        </a-form-item>
        <a-form-item :label="$t('email')">
          <a-input v-model:value="form.email" type="email" />
        </a-form-item>
        <a-form-item :label="$t('phone')">
          <a-input v-model:value="form.phone" />
        </a-form-item>
        <a-form-item :label="$t('Link_User')">
          <a-select
            v-model:value="form.user_id" :placeholder="$t('PleaseSelect')"
            :options="usersList.map(u => ({ label: u.label, value: u.id }))"
            show-search option-filter-prop="label" allow-clear
          />
        </a-form-item>
        <a-form-item>
          <a-checkbox v-model:checked="form.is_active">{{ $t('Active') }}</a-checkbox>
        </a-form-item>
        <a-form-item :label="$t('Notes')">
          <a-textarea v-model:value="form.notes" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Sales agents — GET sales_agents → {agents, totalRows} (data.data || data
 * unwrap); users for the Link_User select from users_list_for_select →
 * {users}. Save POST/PUT sales_agents[/{id}] with nullable code/email/phone/
 * user_id/notes, exactly the legacy payload.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const auth = useAuthStore();

const crud = useCrudTable('sales_agents', {
  select: p => {
    const d = p.data || p;
    return { rows: d.agents || [], total: d.totalRows || 0 };
  },
});

const usersList = ref([]);
const modalOpen = ref(false);
const editMode = ref(false);
const saving = ref(false);
const modalFormRef = ref();
const form = ref({ id: null, name: '', code: '', email: '', phone: '', user_id: null, is_active: true, notes: '' });

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
}));

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Code'), dataIndex: 'code', key: 'code' },
  { title: t('email'), dataIndex: 'email', key: 'email' },
  { title: t('User'), key: 'user' },
  { title: t('Active'), key: 'is_active', width: 100 },
  { title: t('Commissions'), dataIndex: 'sale_commissions_count', key: 'sale_commissions_count', align: 'center' },
  { title: t('Action'), key: 'actions', width: 100 },
]);

function openModal(row = null) {
  editMode.value = !!row;
  form.value = row
    ? {
        id: row.id, name: row.name, code: row.code || '', email: row.email || '',
        phone: row.phone || '', user_id: row.user_id || null,
        is_active: !!row.is_active, notes: row.notes || '',
      }
    : { id: null, name: '', code: '', email: '', phone: '', user_id: null, is_active: true, notes: '' };
  modalFormRef.value?.clearValidate();
  modalOpen.value = true;
}
async function submit() {
  try {
    await modalFormRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  const payload = {
    name: form.value.name,
    code: form.value.code || null,
    email: form.value.email || null,
    phone: form.value.phone || null,
    user_id: form.value.user_id || null,
    is_active: form.value.is_active,
    notes: form.value.notes || null,
  };
  try {
    if (editMode.value) await http.put(`sales_agents/${form.value.id}`, payload);
    else await http.post('sales_agents', payload);
    message.success(t('Success'));
    modalOpen.value = false;
    await crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('Error'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  crud.fetchRows();
  try {
    const data = await http.get('users_list_for_select');
    const list = (data && data.users) || [];
    usersList.value = list.map(u => ({
      id: u.id,
      label: ((u.firstname || '') + ' ' + (u.lastname || '')).trim() || u.username || u.email,
    }));
  } catch (e) {
    usersList.value = [];
    message.error(e?.data?.message || t('Error'));
  }
});
</script>
