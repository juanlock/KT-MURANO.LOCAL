<template>
  <div class="page">
    <PageHeader :title="$t('Shipping_Methods')" :breadcrumb="[$t('Store'), $t('Shipping_Methods')]">
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
        :columns="columns" :data-source="methods" :loading="isLoading"
        :pagination="{ pageSize: 10, showSizeChanger: true }" size="middle" row-key="id"
        :locale="{ emptyText: $t('No_Shipping_Methods') }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'"><strong>{{ record.name }}</strong></template>
          <template v-else-if="column.key === 'price'">{{ Number(record.price || 0).toFixed(2) }}</template>
          <template v-else-if="column.key === 'regions'">
            <span v-if="!record.countries.length" style="color: #999">{{ $t('All_Regions') }}</span>
            <span v-else>{{ record.countries.join(', ') }}</span>
          </template>
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
        <a-form-item :label="$t('Name') + ' *'">
          <a-input v-model:value="form.name" />
        </a-form-item>
        <a-form-item :label="$t('Price') + ' *'">
          <a-input-number v-model:value="form.price" :min="0" :step="0.01" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Countries')" :extra="$t('Leave_empty_all_regions')">
          <a-select
            v-model:value="form.countries" mode="multiple" allow-clear
            :placeholder="$t('Choose_Countries')"
            :options="countryOptions" show-search option-filter-prop="label" :max-tag-count="6"
          />
        </a-form-item>
        <a-form-item :label="$t('Sort_Order')">
          <a-input-number v-model:value="form.sort_order" :min="0" style="width: 100%" />
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
 * Shipping methods — GET store/shipping-methods?search → {methods}; save
 * POST/PUT store/shipping-methods[/{id}] {name, price, countries[],
 * sort_order, active 1|0}. Countries are picked from the canonical list the
 * region matcher understands (empty = all regions) — free text used to let a
 * spelling drift from the customer's address and read as "no shipping".
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
const methods = ref([]);
// Canonical country list from the API: [{code, name, canonical}].
const countries = ref([]);
const countryOptions = computed(() =>
  countries.value.map(x => ({ label: x.name, value: x.canonical })));
const modalOpen = ref(false);

const emptyForm = () => ({ id: null, name: '', price: 0, countries: [], sort_order: 0, active: true });
const form = ref(emptyForm());

const columns = computed(() => [
  { title: t('Name'), key: 'name' },
  { title: t('Price'), key: 'price', align: 'right' },
  { title: t('Regions'), key: 'regions' },
  { title: t('Status'), key: 'status', width: 100 },
  { title: t('Actions'), key: 'actions', width: 100 },
]);

async function fetch() {
  try {
    const resp = await http.get('store/shipping-methods', { search: search.value });
    methods.value = resp.methods || [];
    countries.value = resp.country_options || [];
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
function openEdit(m) {
  form.value = {
    id: m.id, name: m.name, price: m.price,
    countries: (m.countries || []).slice(), sort_order: m.sort_order, active: !!m.active,
  };
  modalOpen.value = true;
}
async function submit() {
  saving.value = true;
  const payload = {
    name: form.value.name,
    price: form.value.price,
    countries: form.value.countries,
    sort_order: form.value.sort_order,
    active: form.value.active ? 1 : 0,
  };
  try {
    if (form.value.id) await http.put(`store/shipping-methods/${form.value.id}`, payload);
    else await http.post('store/shipping-methods', payload);
    message.success(t('Successfully_Updated'));
    modalOpen.value = false;
    fetch();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
function confirmDelete(m) {
  Modal.confirm({
    title: t('AreYouSure'),
    content: m.name,
    okText: t('Yes'),
    okType: 'danger',
    cancelText: t('No'),
    async onOk() {
      try {
        await http.delete(`store/shipping-methods/${m.id}`);
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
