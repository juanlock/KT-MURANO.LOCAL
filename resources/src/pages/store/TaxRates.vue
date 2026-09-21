<template>
  <div class="page">
    <PageHeader :title="$t('Tax_Rates')" :breadcrumb="[$t('Store'), $t('Tax_Rates')]">
      <template #extra>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: 0 }">
      <div class="toolbar">
        <a-input-search v-model:value="search" :placeholder="$t('Search')" allow-clear style="max-width: 280px" @search="fetch" @change="fetch" />
      </div>
      <a-table
        :columns="columns" :data-source="rates" :loading="isLoading"
        :pagination="{ pageSize: 10, showSizeChanger: true }" size="middle" row-key="id"
        :locale="{ emptyText: $t('No_Tax_Rates') }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'">{{ record.name || '—' }}</template>
          <template v-else-if="column.key === 'country'"><strong>{{ record.country }}</strong></template>
          <template v-else-if="column.key === 'state'">{{ record.state || $t('All') }}</template>
          <template v-else-if="column.key === 'rate'">{{ Number(record.rate).toFixed(3) }}</template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="record.active ? 'success' : 'default'">
              {{ record.active ? $t('Active') : $t('Inactive') }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-button size="small" @click="openEdit(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
              <a-button size="small" danger @click="confirmDelete(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal v-model:open="modalOpen" :title="form.id ? $t('Edit') : $t('Add')" :confirm-loading="saving" @ok="submit">
      <a-form layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Name')">
          <a-input v-model:value="form.name" :placeholder="$t('Optional')" />
        </a-form-item>
        <a-form-item :label="$t('Country') + ' *'">
          <a-select
            v-model:value="form.country" allow-clear show-search option-filter-prop="label"
            :placeholder="$t('Choose_Country')" :options="countryOptions"
            @change="form.state = ''"
          />
        </a-form-item>
        <a-form-item :label="$t('State')">
          <a-select
            v-if="stateOptions.length"
            v-model:value="form.state" allow-clear show-search option-filter-prop="label"
            :placeholder="$t('Leave_empty_whole_country')" :options="stateOptions"
          />
          <a-input v-else v-model:value="form.state" :placeholder="$t('Leave_empty_whole_country')" />
        </a-form-item>
        <a-form-item :label="$t('Rate') + ' (%) *'">
          <a-input-number v-model:value="form.rate" :min="0" :max="100" :step="0.001" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Status')" style="margin-bottom: 0">
          <a-switch v-model:checked="form.active" />
          <span style="margin-left: 8px">{{ form.active ? $t('Active') : $t('Inactive') }}</span>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Tax rates — GET store/tax-rates?search → {rates}; save POST/PUT
 * store/tax-rates[/{id}] {name, country, state, rate, active 1|0}.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const saving = ref(false);
const search = ref('');
const rates = ref([]);
// Canonical country list + subdivisions, from the API.
const countries = ref([]);
const subdivisions = ref({});
const countryOptions = computed(() =>
  countries.value.map(x => ({ label: x.name, value: x.canonical })));
// A state picker only where we ship a list; free text everywhere else.
const stateOptions = computed(() => {
  const hit = countries.value.find(x => x.canonical === form.value.country);
  const rows = (hit && subdivisions.value[hit.code]) || [];
  return rows.map(s => ({ label: s, value: s }));
});
const modalOpen = ref(false);

const emptyForm = () => ({ id: null, name: '', country: '', state: '', rate: 0, active: true });
const form = ref(emptyForm());

const columns = computed(() => [
  { title: t('Name'), key: 'name' },
  { title: t('Country'), key: 'country' },
  { title: t('State'), key: 'state' },
  { title: t('Rate') + ' (%)', key: 'rate', align: 'right' },
  { title: t('Status'), key: 'status', width: 100 },
  { title: t('Actions'), key: 'actions', width: 100 },
]);

async function fetch() {
  try {
    const r = await http.get('store/tax-rates', { search: search.value });
    rates.value = r.rates || [];
    countries.value = r.country_options || [];
    subdivisions.value = r.subdivisions || {};
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
}
function openCreate() {
  form.value = emptyForm();
  modalOpen.value = true;
}
function openEdit(r) {
  form.value = { id: r.id, name: r.name, country: r.country, state: r.state, rate: r.rate, active: !!r.active };
  modalOpen.value = true;
}
async function submit() {
  saving.value = true;
  const payload = {
    name: form.value.name,
    country: form.value.country,
    state: form.value.state,
    rate: form.value.rate,
    active: form.value.active ? 1 : 0,
  };
  try {
    if (form.value.id) await http.put(`store/tax-rates/${form.value.id}`, payload);
    else await http.post('store/tax-rates', payload);
    message.success(t('Successfully_Updated'));
    modalOpen.value = false;
    fetch();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
function confirmDelete(r) {
  Modal.confirm({
    title: t('AreYouSure'),
    okText: t('Yes'),
    okType: 'danger',
    cancelText: t('No'),
    async onOk() {
      try {
        await http.delete(`store/tax-rates/${r.id}`);
        message.success(t('Deleted_in_successfully'));
        fetch();
      } catch (e) {
        message.error(t('Failed'));
      }
    },
  });
}

onMounted(fetch);
</script>

<style scoped>
.toolbar {
  padding: 16px;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
</style>
