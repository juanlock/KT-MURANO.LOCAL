<template>
  <div class="page">
    <PageHeader :title="$t('Commission_Rules')" :breadcrumb="[$t('Commissions'), $t('Commission_Rules')]">
      <template #extra>
        <a-button v-if="auth.can('commissions_add')" type="primary" @click="openModal()">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-select
        v-model:value="filterProgramId" :placeholder="$t('Filter_by_Program')"
        :options="programsList.map(p => ({ label: p.name, value: p.id }))"
        show-search option-filter-prop="label" allow-clear
        style="width: 280px; max-width: 100%" @change="crud.reload()"
      />
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'type'">
          {{ record.type }} ({{ record.value }}{{ record.type === 'percentage' ? '%' : '' }})
        </template>
        <template v-else-if="column.key === 'source'">
          {{ record.source === 'sale_total' ? $t('Sale_Total') : $t('Paid_Amount') }}
        </template>
        <template v-else-if="column.key === 'program'">
          {{ record.commission_program ? record.commission_program.name : '—' }}
        </template>
        <template v-else-if="column.key === 'agent'">
          {{ record.sales_agent ? record.sales_agent.name : '—' }}
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
      :confirm-loading="saving" width="720px" @ok="submit"
    >
      <a-form ref="modalFormRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Commission_Program') + ' *'" name="commission_program_id">
          <a-select
            v-model:value="form.commission_program_id" :placeholder="$t('PleaseSelect')"
            :options="programsList.map(p => ({ label: p.name, value: p.id }))"
            show-search option-filter-prop="label"
          />
        </a-form-item>
        <a-form-item :label="$t('Name') + ' *'" name="name">
          <a-input v-model:value="form.name" :maxlength="192" />
        </a-form-item>
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Type')">
              <a-select v-model:value="form.type">
                <a-select-option value="percentage">{{ $t('Percentage') }}</a-select-option>
                <a-select-option value="fixed">{{ $t('Fixed') }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Source')">
              <a-select v-model:value="form.source">
                <a-select-option value="sale_total">{{ $t('Sale_Total') }}</a-select-option>
                <a-select-option value="paid_amount">{{ $t('Paid_Amount') }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Value') + ' *'" name="value">
              <a-input-number v-model:value="form.value" :min="0" :step="0.01" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Min_Threshold')">
              <a-input-number v-model:value="form.min_threshold" :min="0" :step="0.01" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Max_Cap')">
              <a-input-number v-model:value="form.max_cap" :min="0" :step="0.01" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Priority')">
              <a-input-number v-model:value="form.priority" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Applies_To')">
          <a-select v-model:value="form.applies_to">
            <a-select-option value="all_agents">{{ $t('All_Agents') }}</a-select-option>
            <a-select-option value="specific_agent">{{ $t('Specific_Agent') }}</a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item v-if="form.applies_to === 'specific_agent'" :label="$t('Sales_Agent')">
          <a-select
            v-model:value="form.sales_agent_id" :placeholder="$t('PleaseSelect')"
            :options="agentsList.map(a => ({ label: a.name, value: a.id }))"
            show-search option-filter-prop="label" allow-clear
          />
        </a-form-item>
        <a-form-item>
          <a-checkbox v-model:checked="form.is_active">{{ $t('Active') }}</a-checkbox>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Commission rules — GET commission_rules (+ optional commission_program_id
 * filter) → {rules, totalRows}; programs from commission_programs?limit=-1,
 * agents from sales_agents_list_for_select. Save POST/PUT commission_rules
 * with the whole form; sales_agent_id nulled unless applies_to is
 * specific_agent (legacy payload). Default sort priority desc.
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

const filterProgramId = ref(null);

const crud = useCrudTable('commission_rules', {
  sortField: 'priority',
  sortType: 'desc',
  params: () => (filterProgramId.value ? { commission_program_id: filterProgramId.value } : {}),
  select: p => {
    const d = p.data || p;
    return { rows: d.rules || [], total: d.totalRows || 0 };
  },
});

const programsList = ref([]);
const agentsList = ref([]);
const modalOpen = ref(false);
const editMode = ref(false);
const saving = ref(false);
const modalFormRef = ref();

const emptyForm = () => ({
  id: null,
  commission_program_id: filterProgramId.value || null,
  name: '',
  type: 'percentage',
  source: 'sale_total',
  value: 0,
  min_threshold: null,
  max_cap: null,
  applies_to: 'all_agents',
  sales_agent_id: null,
  priority: 0,
  is_active: true,
});
const form = ref(emptyForm());

const rules = computed(() => ({
  commission_program_id: [{ required: true, message: t('Field_is_required') }],
  name: [{ required: true, message: t('Field_is_required') }],
  value: [{ required: true, message: t('Field_is_required') }],
}));

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Program'), key: 'program' },
  { title: t('Type'), key: 'type' },
  { title: t('Source'), key: 'source' },
  { title: t('Agent'), key: 'agent' },
  { title: t('Active'), key: 'is_active', width: 100 },
  { title: t('Priority'), dataIndex: 'priority', key: 'priority', sorter: true, align: 'center' },
  { title: t('Action'), key: 'actions', width: 100 },
]);

function openModal(row = null) {
  editMode.value = !!row;
  form.value = row
    ? {
        id: row.id,
        commission_program_id: row.commission_program_id,
        name: row.name,
        type: row.type,
        source: row.source,
        value: parseFloat(row.value),
        min_threshold: row.min_threshold || null,
        max_cap: row.max_cap || null,
        applies_to: row.applies_to,
        sales_agent_id: row.sales_agent_id || null,
        priority: row.priority || 0,
        is_active: !!row.is_active,
      }
    : emptyForm();
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
    ...form.value,
    min_threshold: form.value.min_threshold || null,
    max_cap: form.value.max_cap || null,
    sales_agent_id: form.value.applies_to === 'specific_agent' ? form.value.sales_agent_id : null,
  };
  delete payload.id;
  try {
    if (editMode.value) await http.put(`commission_rules/${form.value.id}`, payload);
    else await http.post('commission_rules', payload);
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
    const data = await http.get('commission_programs', { limit: '-1' });
    const d = data.data || data;
    programsList.value = (d.programs || []).map(p => ({ id: p.id, name: p.name }));
  } catch (e) { /* filter stays empty */ }
  try {
    const data = await http.get('sales_agents_list_for_select');
    const d = data.data || data;
    agentsList.value = Array.isArray(d) ? d : (d.agents || []);
  } catch (e) { /* select stays empty */ }
});
</script>
