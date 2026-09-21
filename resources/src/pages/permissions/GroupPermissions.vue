<template>
  <div class="page">
    <PageHeader :title="$t('GroupPermissions')" :breadcrumb="[$t('Users'), $t('GroupPermissions')]">
      <template #extra>
        <a-button v-if="auth.can('permissions_add')" type="primary" @click="$router.push('/permissions/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'permissions_count'">
          <div class="perm-count">
            <a-progress
              :percent="totalPermissions ? Math.round(((record.permissions_count || 0) / totalPermissions) * 100) : 0"
              :show-info="false" size="small" style="width: 90px"
            />
            <span>{{ record.permissions_count || 0 }} / {{ totalPermissions }}</span>
          </div>
        </template>
        <template v-else-if="column.key === 'actions'">
          <!-- Role #1 is the built-in superadmin: legacy hides its actions. -->
          <a-space v-if="record.id !== 1">
            <a-button v-if="auth.can('permissions_edit')" size="small" @click="$router.push(`/permissions/${record.id}/edit`)">
              <template #icon><EditOutlined /></template>
            </a-button>
            <a-button v-if="auth.can('permissions_delete')" size="small" danger @click="crud.remove(record)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-space>
          <span v-else style="color: #999">—</span>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Roles list — GET roles (page/SortField/SortType/search/limit) → {roles,
 * totalRows}; DELETE roles/{id}. Role id 1 is the built-in superadmin and
 * shows no actions (legacy row guard).
 */
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useAuthStore } from '../../stores/auth';

const { t } = useI18n();
const auth = useAuthStore();

const crud = useCrudTable('roles', { rowsKey: 'roles' });

// Total number of permissions in the system, shipped alongside the rows.
const totalPermissions = computed(() => Number(crud.payload.value?.totalPermissions) || 0);

const columns = computed(() => [
  { title: t('RoleName'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Description'), dataIndex: 'description', key: 'description' },
  {
    title: t('Assigned_Permissions'), dataIndex: 'permissions_count', key: 'permissions_count',
    width: 190, align: 'center',
    exportValue: r => `${r.permissions_count || 0} / ${totalPermissions.value}`,
  },
  { title: t('Action'), key: 'actions', width: 100 },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
.perm-count {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
}
</style>
