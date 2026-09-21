<template>
  <div class="page">
    <PageHeader :title="$t('Users')" :breadcrumb="[$t('User_Management'), $t('Users')]">
      <template #actions>
        <a-button v-if="auth.can('users_add')" type="primary" @click="$router.push('/users/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'avatar'">
          <a-avatar :size="36" :src="avatarUrl(record)">
            {{ initials(record) }}
          </a-avatar>
        </template>
        <template v-else-if="column.key === 'statut'">
          <!-- Activate/deactivate toggle, like the legacy switch. The logged-in
               user cannot deactivate their own account. -->
          <a-tooltip :title="isSelf(record) ? 'You cannot deactivate your own account' : ''">
            <a-switch
              :checked="!!Number(record.statut)"
              :loading="switching === record.id"
              :disabled="isSelf(record)"
              @change="toggleStatus(record)"
            />
          </a-tooltip>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/users/${record.id}/edit`)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <!-- Legacy hides delete for the current user. -->
            <a-tooltip v-if="!isSelf(record)" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Users list: GET users → { users, totalRows, roles, warehouses }.
 * Status toggle: PUT users_switch_activated/{id} { statut, id } (legacy
 * contract sends the NEW value). No bulk delete endpoint for users.
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

const crud = useCrudTable('users', { rowsKey: 'users' });
const switching = ref(null);

// The logged-in user may not deactivate or delete their own account (legacy parity).
const isSelf = record => auth.user && Number(record.id) === Number(auth.user.id);

// Avatars live under public/images/avatar/; the antd Avatar falls back to
// initials when the image is missing or fails to load.
const avatarUrl = record => (record.avatar ? `/images/avatar/${record.avatar}` : undefined);
const initials = record =>
  `${(record.firstname || '')[0] || ''}${(record.lastname || '')[0] || ''}`.toUpperCase()
  || (record.username || '?')[0].toUpperCase();

const columns = computed(() => [
  { title: t('Image'), key: 'avatar', dataIndex: 'avatar', width: 70, align: 'center', exportValue: r => r.avatar || '' },
  { title: t('Firstname'), dataIndex: 'firstname', key: 'firstname', sorter: true },
  { title: t('lastname'), dataIndex: 'lastname', key: 'lastname', sorter: true },
  { title: t('username'), dataIndex: 'username', key: 'username', sorter: true },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('Status'), key: 'statut', dataIndex: 'statut', exportValue: r => (Number(r.statut) ? t('ActivateUser') : t('DisActivateUser')) },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

async function toggleStatus(record) {
  if (isSelf(record)) return;
  switching.value = record.id;
  const next = Number(record.statut) ? 0 : 1;
  try {
    await http.put(`users_switch_activated/${record.id}`, { statut: next, id: record.id });
    record.statut = next;
    message.success(next ? t('ActivateUser') : t('DisActivateUser'));
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    switching.value = null;
  }
}

onMounted(crud.fetchRows);
</script>
