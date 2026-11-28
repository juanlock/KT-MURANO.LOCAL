<template>
  <div class="page">
    <!-- IT Admin -> Locations (2026-11-28, from HTD-IT's locations + offices): where the networks and the devices are. -->
    <DataTable :crud="crud" :columns="columns">
      <template #filters>
        <a-button v-if="can.manage" type="primary" @click="openForm()"><PlusOutlined /> {{ L('Add new', 'Agregar nuevo') }}</a-button>
      </template>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <a @click="openForm(record)"><b>{{ record.name }}</b></a>
          <span v-if="record.code" class="lo-muted"> · {{ record.code }}</span>
          <div class="lo-muted">{{ record.address }}</div>
        </template>
        <template v-else-if="column.key === 'networks'"><router-link :to="`/itadmin/networks?location_id=${record.id}`">{{ record.networks }}</router-link></template>
        <template v-else-if="column.key === 'status'"><a-tag :color="record.status === 'Active' ? 'green' : 'default'">{{ record.status === 'Active' ? L('Active', 'Activa') : L('Inactive', 'Inactiva') }}</a-tag></template>
        <template v-else-if="column.key === 'actions' && can.manage">
          <a-button size="small" danger @click="crud.remove(record, { label: record.name, deleteEndpoint: `itadmin/locations/${record.id}` })"><DeleteOutlined /></a-button>
        </template>
      </template>
    </DataTable>

    <a-modal v-model:open="form.open" :title="form.id ? L('Edit location', 'Editar ubicación') : L('New location', 'Nueva ubicación')" :ok-text="L('Save', 'Guardar')" :confirm-loading="form.saving" @ok="save">
      <a-form layout="vertical">
        <a-row :gutter="10">
          <a-col :span="16"><a-form-item :label="L('Name', 'Nombre')" required><a-input v-model:value="form.d.name" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="L('Short code', 'Código corto')"><a-input v-model:value="form.d.code" :placeholder="L('e.g. Lima (scan files)', 'ej. Lima (archivos de escaneo)')" /></a-form-item></a-col>
          <a-col :span="24"><a-form-item :label="L('Address', 'Dirección')"><a-textarea v-model:value="form.d.address" :rows="2" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="L('Phone', 'Teléfono')"><PhoneInput v-model:value="form.d.phone" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="L('Manager', 'Responsable')"><a-select v-model:value="form.d.manager_employee_id" allow-clear show-search option-filter-prop="label" :options="employeeOptions" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="L('Murano location (optional)', 'Local de Murano (opcional)')"><a-select v-model:value="form.d.warehouse_id" allow-clear :options="warehouseOptions" /></a-form-item></a-col>
          <a-col :span="6"><a-form-item :label="L('Order', 'Orden')"><a-input-number v-model:value="form.d.sort_order" :min="0" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="6"><a-form-item :label="L('Status', 'Estado')"><a-select v-model:value="form.d.status" :options="[{ value: 'Active', label: L('Active', 'Activa') }, { value: 'Inactive', label: L('Inactive', 'Inactiva') }]" /></a-form-item></a-col>
          <a-col :span="24"><a-form-item :label="L('Notes', 'Notas')"><a-textarea v-model:value="form.d.notes" :rows="2" /></a-form-item></a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { message } from 'ant-design-vue';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons-vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useBarTitle } from '../../composables/useBarTitle';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';
import { useTr } from '../../lib/useTr';

const L = useTr();
useBarTitle(() => L('Locations', 'Ubicaciones'));
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };
const crud = useCrudTable('itadmin/locations', { rowsKey: 'locations' });
const opts = ref({});
const can = computed(() => opts.value.can || {});
const columns = computed(() => [
  { title: L('Location', 'Ubicación'), key: 'name', dataIndex: 'name' },
  { title: L('Phone', 'Teléfono'), dataIndex: 'phone', key: 'phone' },
  { title: L('Manager', 'Responsable'), dataIndex: 'manager', key: 'manager' },
  { title: L('Networks', 'Redes'), key: 'networks', align: 'center' },
  { title: L('Devices', 'Equipos'), dataIndex: 'assets', key: 'assets', align: 'center' },
  { title: L('Status', 'Estado'), key: 'status' },
  { title: '', key: 'actions', width: 60 },
]);
const employeeOptions = computed(() => sortByLabel((opts.value.employees || []).map(e => ({ value: e.id, label: e.name }))));
const warehouseOptions = computed(() => sortByLabel((opts.value.warehouses || []).map(w => ({ value: w.id, label: w.name }))));
const blank = () => ({ name: '', code: '', address: '', phone: '', manager_employee_id: null, warehouse_id: null, sort_order: 100, status: 'Active', notes: '' });
const form = reactive({ open: false, id: null, saving: false, d: blank() });
function openForm(r) {
  form.id = r?.id || null;
  form.d = blank();
  if (r) Object.keys(form.d).forEach(k => { if (r[k] !== undefined && r[k] !== null) form.d[k] = r[k]; });
  form.open = true;
}
async function save() {
  form.saving = true;
  try {
    if (form.id) await http.put(`itadmin/locations/${form.id}`, form.d); else await http.post('itadmin/locations', form.d);
    message.success(L('Saved', 'Guardado'));
    form.open = false;
    crud.fetchRows();
  } catch (e) { message.error(errorOf(e)); } finally { form.saving = false; }
}
onMounted(async () => {
  try { opts.value = await http.get('itadmin/options'); } catch (e) { message.error(errorOf(e)); }
  crud.fetchRows();
});
</script>

<style scoped>
.lo-muted { color: #6b7280; font-size: 12px; }
</style>
