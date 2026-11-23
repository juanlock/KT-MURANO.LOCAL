<template>
  <div class="page">
    <PageHeader
      :title="tx('Company status', 'Estado de las compañías')"
      :subtitle="tx('Every company of the install: its status, admin and how much information it holds.', 'Cada compañía del sistema: su estado, su administrador y cuánta información tiene.')"
      :breadcrumb="['SuperAdmin', tx('Company status', 'Estado de las compañías')]"
    >
      <template v-if="summary" #actions>
        <a-tag color="green">{{ tx('Production', 'Producción') }}: {{ summary.live }}</a-tag>
        <a-tag color="blue">Demo: {{ summary.demo }}</a-tag>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns" compact-toolbar>
      <template #filters>
        <a-select v-model:value="filters.status" allow-clear style="width: 190px"
          :placeholder="tx('Active companies', 'Compañías activas')" :options="statusOptions" @change="crud.reload" />
      </template>

      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <div class="cname">{{ record.name }} <span class="muted small">#{{ record.id }}</span></div>
          <!-- Under each company: how much it holds (user, 2026-11-23). -->
          <div class="counts">
            <span v-for="m in MAIN" :key="m.key" class="cnt" :class="{ zero: !record.main[m.key] }">
              {{ tx(m.en, m.es) }} <b>{{ num(record.main[m.key]) }}</b>
            </span>
            <span class="cnt total">{{ tx('Records', 'Registros') }} <b>{{ num(record.total) }}</b>
              <span class="muted">({{ record.tables }} {{ tx('tables', 'tablas') }})</span></span>
            <span v-if="record.demo_rows" class="cnt demo">{{ tx('Demo data', 'Datos demo') }} <b>{{ num(record.demo_rows) }}</b></span>
          </div>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="STATUS[record.status].color">{{ tx(STATUS[record.status].en, STATUS[record.status].es) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'admin'">
          <template v-if="record.admin">
            <a-tooltip v-if="record.admins.length > 1">
              <template #title>
                <div v-for="a in record.admins" :key="a.username">{{ a.username }} · {{ a.email }}<span v-if="a.role"> · {{ a.role }}</span><span v-if="!a.active"> · {{ tx('disabled', 'desactivado') }}</span></div>
              </template>
              <div><b>{{ record.admin.username }}</b> <a-tag class="more">+{{ record.admins.length - 1 }}</a-tag></div>
            </a-tooltip>
            <div v-else><b>{{ record.admin.username }}</b></div>
            <div class="muted small">{{ record.admin.email }}</div>
            <a-tag v-if="!record.admin.active" color="red" class="small-tag">{{ tx('Disabled', 'Desactivado') }}</a-tag>
          </template>
          <span v-else class="muted">{{ tx('No admin', 'Sin administrador') }}</span>
        </template>
        <template v-else-if="['created_at', 'last_change', 'last_login'].includes(column.key)">
          <template v-if="record[column.key]">
            {{ date(record[column.key]) }}
            <div class="muted small">{{ ago(record[column.key]) }}</div>
          </template>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'total'">
          <b>{{ num(record.total) }}</b>
        </template>
      </template>

      <!-- The whole breakdown: every table with rows of this company. -->
      <template #expandedRowRender="{ record }">
        <div class="tables">
          <span v-for="t in record.by_table" :key="t.table" class="tb"><code>{{ t.table }}</code> {{ num(t.n) }}</span>
          <span v-if="!record.by_table.length" class="muted">{{ tx('No records', 'Sin registros') }}</span>
        </div>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * SuperAdmin -> Company status (user, 2026-11-23): every company with its type, status (production / demo), admin
 * login, creation date, last data change and last sign-in, and under each one how many sales, purchases and other
 * records it holds (the full breakdown by table in the expanded row). superadmin/AdminSystem only
 * (CompanyStatusController).
 */
import { computed, ref } from 'vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useListState } from '../../composables/useListState';
import { useFormat } from '../../composables/useFormat';
import { useUiStore } from '../../stores/ui';

const ui = useUiStore();
const tx = (en, es) => (ui.locale === 'es' ? es : en);
const { date } = useFormat();

const MAIN = [
  { key: 'sales', en: 'Sales', es: 'Ventas' },
  { key: 'purchases', en: 'Purchases', es: 'Compras' },
  { key: 'clients', en: 'Customers', es: 'Clientes' },
  { key: 'providers', en: 'Suppliers', es: 'Proveedores' },
  { key: 'products', en: 'Products', es: 'Productos' },
  { key: 'employees', en: 'Employees', es: 'Empleados' },
  { key: 'users', en: 'Logins', es: 'Usuarios' },
  { key: 'form_submissions', en: 'Form answers', es: 'Respuestas de formularios' },
];
const STATUS = {
  live: { en: 'Production', es: 'Producción', color: 'green' },
  demo: { en: 'Demo', es: 'Demo', color: 'blue' },
  deleted: { en: 'Deleted', es: 'Eliminada', color: 'default' },
};

const filters = ref({ status: undefined });
const crud = useCrudTable('company-status', {
  rowsKey: 'companies',
  limit: 50,
  params: () => ({ status: filters.value.status || '' }),
});
useListState('company-status', { filters }, crud);

const summary = computed(() => crud.payload.value?.summary || null);
const statusOptions = computed(() => [
  { value: 'live', label: tx('Production', 'Producción') },
  { value: 'demo', label: 'Demo' },
  { value: 'deleted', label: tx('Deleted', 'Eliminadas') },
]);

const columns = computed(() => [
  { title: tx('Company', 'Compañía'), key: 'name', dataIndex: 'name', sorter: true },
  { title: tx('Type', 'Tipo'), key: 'type', dataIndex: 'type', width: 170, sorter: true },
  { title: tx('Status', 'Estado'), key: 'status', dataIndex: 'status', width: 110, sorter: true },
  { title: tx('Admin login', 'Usuario admin'), key: 'admin', width: 230 },
  { title: tx('Created', 'Creada'), key: 'created_at', dataIndex: 'created_at', width: 115, sorter: true },
  { title: tx('Last update', 'Última actualización'), key: 'last_change', dataIndex: 'last_change', width: 130, sorter: true },
  { title: tx('Last sign-in', 'Último ingreso'), key: 'last_login', dataIndex: 'last_login', width: 120, sorter: true },
  { title: tx('Records', 'Registros'), key: 'total', dataIndex: 'total', width: 100, align: 'right', sorter: true },
]);

const num = n => Number(n || 0).toLocaleString(ui.locale === 'es' ? 'es-PE' : 'en-US');
function ago(v) {
  const d = dayjs().startOf('day').diff(dayjs(v).startOf('day'), 'day');
  if (d <= 0) return tx('today', 'hoy');
  if (d === 1) return tx('yesterday', 'ayer');
  return tx(`${d} days ago`, `hace ${d} días`);
}
</script>

<style scoped>
.cname { font-weight: 600; }
.counts { display: flex; flex-wrap: wrap; gap: 4px 6px; margin-top: 4px; }
.cnt { font-size: 12px; padding: 1px 7px; border-radius: 10px; background: var(--ant-color-fill-tertiary, rgba(0, 0, 0, .05)); white-space: nowrap; }
.cnt b { font-variant-numeric: tabular-nums; }
.cnt.zero { opacity: .45; }
.cnt.total { background: var(--ant-color-primary-bg, #e6f4ff); }
.cnt.demo { background: var(--ant-color-warning-bg, #fffbe6); }
.muted { color: var(--ant-color-text-tertiary, #8c8c8c); }
.small { font-size: 12px; }
.more { margin-left: 4px; font-size: 11px; line-height: 16px; padding: 0 4px; }
.small-tag { margin-top: 2px; font-size: 11px; }
.tables { display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 12px; }
.tb code { font-size: 11px; }
</style>
