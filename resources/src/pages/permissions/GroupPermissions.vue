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

    <!-- SuperAdmin as AdminSystem: every company's roles, filterable by company. -->
    <a-card v-if="allCompanies" size="small" style="margin-bottom: 12px">
      <a-space wrap>
        <span style="opacity: 0.7">{{ $t('tx_Roles_of_every_company_Company_18445') }}</span>
        <a-select
          v-model:value="roleCompanyId" allow-clear show-search option-filter-prop="label"
          style="width: 260px" :placeholder="$t('All')" :options="companyOptions" @change="crud.reload()"
        />
      </a-space>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'company_name'">
          <a-tag color="purple">{{ record.company_name }}</a-tag>
        </template>
        <template v-else-if="column.key === 'permissions_count'">
          <div class="perm-count">
            <a-progress
              :percent="totalPermissions ? Math.round(((record.permissions_count || 0) / totalPermissions) * 100) : 0"
              :show-info="false" size="small" style="width: 90px"
            />
            <span>{{ record.permissions_count || 0 }} / {{ totalPermissions }}</span>
          </div>
        </template>
        <template v-else-if="column.key === 'users_count'">
          <!-- How many users have this role: opens Users filtered by it. -->
          <PermLink
            v-if="record.users_count"
            :to="{ path: '/users', query: { role: record.id, role_name: record.name } }"
            permission="users_view" class="users-count"
          >
            <TeamOutlined /> {{ record.users_count }}
          </PermLink>
          <span v-else style="opacity: 0.45">0</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <!-- Role #1 is the built-in superadmin: legacy hides its actions. -->
          <a-space v-if="record.id !== 1">
            <a-button v-if="auth.can('permissions_edit')" size="small" @click="$router.push(`/permissions/${record.id}/edit`)">
              <template #icon><EditOutlined /></template>
            </a-button>
            <!-- Duplicate: new role pre-filled with this one's permissions
                 (Owner can't be opened, so it can't be copied either). -->
            <a-tooltip v-if="auth.can('permissions_add') && auth.can('permissions_edit') && !['Owner', 'SuperAdmin'].includes(record.name)" :title="$t('Duplicate')">
              <a-button size="small" @click="$router.push({ path: '/permissions/create', query: { copy: record.id } })">
                <template #icon><CopyOutlined style="color: #722ed1" /></template>
              </a-button>
            </a-tooltip>
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
import { computed, onMounted, ref } from 'vue';
import { sortByLabel } from '../../lib/filterOptions';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, CopyOutlined, TeamOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useAuthStore } from '../../stores/auth';

const { t } = useI18n();
const auth = useAuthStore();

// SuperAdmin working as AdminSystem gets every company's roles (all_companies).
const roleCompanyId = ref(undefined);
const crud = useCrudTable('roles', { rowsKey: 'roles', params: () => ({ role_company_id: roleCompanyId.value || '' }) });
const allCompanies = computed(() => !!crud.payload.value?.all_companies);
const companyOptions = computed(() => sortByLabel((crud.payload.value?.companies || []).map(c => ({ value: c.id, label: c.name }))));

// Total number of permissions in the system, shipped alongside the rows.
const totalPermissions = computed(() => Number(crud.payload.value?.totalPermissions) || 0);

const columns = computed(() => [
  ...(allCompanies.value ? [{ title: t('Company') || 'Company', dataIndex: 'company_name', key: 'company_name', width: 200 }] : []),
  { title: t('RoleName'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Description'), dataIndex: 'description', key: 'description' },
  {
    title: t('Assigned_Permissions'), dataIndex: 'permissions_count', key: 'permissions_count',
    width: 190, align: 'center',
    exportValue: r => `${r.permissions_count || 0} / ${totalPermissions.value}`,
  },
  {
    title: t('Users'), dataIndex: 'users_count', key: 'users_count', width: 100, align: 'center',
    sorter: (a, b) => (a.users_count || 0) - (b.users_count || 0),
    exportValue: r => r.users_count || 0,
  },
  { title: t('Action'), key: 'actions', width: 100 },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
.users-count {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-weight: 600;
}
.perm-count {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
}
</style>
