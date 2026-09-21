<template>
  <div class="page">
    <PageHeader :title="$t('Flash_Sales')" :breadcrumb="[$t('Store'), $t('Flash_Sales')]">
      <template #extra>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: 0 }">
      <div class="toolbar">
        <a-input-search v-model:value="search" :placeholder="$t('Search')" allow-clear style="max-width: 260px" @search="fetch" @change="fetch" />
      </div>
      <a-table
        :columns="columns" :data-source="rows" :loading="isLoading"
        :pagination="{ pageSize: 10, showSizeChanger: true }" size="middle" row-key="id"
        :locale="{ emptyText: $t('No_Flash_Sales') }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'"><strong>{{ record.name }}</strong></template>
          <template v-else-if="column.key === 'starts_at'">{{ record.starts_at || '—' }}</template>
          <template v-else-if="column.key === 'ends_at'">{{ record.ends_at || '—' }}</template>
          <template v-else-if="column.key === 'status'">
            <a-tag v-if="record.is_running" color="success">{{ $t('Running') }}</a-tag>
            <a-tag v-else-if="record.is_active" color="processing">{{ $t('Scheduled') }}</a-tag>
            <a-tag v-else>{{ $t('Inactive') }}</a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-button size="small" @click="openEdit(record.id)">
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

    <a-modal
      v-model:open="modalOpen" :title="form.id ? $t('Edit') : $t('Add')"
      :confirm-loading="saving" width="820px" @ok="submit"
    >
      <a-form layout="vertical" style="margin-top: 12px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Name') + ' *'">
              <a-input v-model:value="form.name" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Status')">
              <a-switch v-model:checked="form.is_active" />
              <span style="margin-left: 8px">{{ form.is_active ? $t('Active') : $t('Inactive') }}</span>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Starts')">
              <a-input v-model:value="form.starts_at" type="datetime-local" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Ends')">
              <a-input v-model:value="form.ends_at" type="datetime-local" />
            </a-form-item>
          </a-col>
        </a-row>

        <template v-if="localeList.length">
          <a-divider orientation="left">{{ $t('Translations') }}</a-divider>
          <div class="i18n-hint">{{ $t('Translations_Fallback_Hint') }}</div>
          <a-row :gutter="16">
            <a-col v-for="l in localeList" :key="l.code" :xs="24" :md="12">
              <a-form-item :label="l.label">
                <a-input v-model:value="form.name_translations[l.code]" :placeholder="form.name" />
              </a-form-item>
            </a-col>
          </a-row>
        </template>

        <a-divider orientation="left">{{ $t('Products') }}</a-divider>
        <a-form-item>
          <a-input v-model:value="prodQuery" :placeholder="$t('Search_products')" @input="searchProducts" />
          <div v-if="results.length" class="results-box">
            <a v-for="p in results" :key="p.id" class="result-row" @click.prevent="addProduct(p)">
              {{ p.name }} <small style="color: #999">({{ p.code }}) — {{ Number(p.price).toFixed(2) }}</small>
            </a>
          </div>
        </a-form-item>

        <a-table
          v-if="form.products.length"
          :columns="productColumns" :data-source="form.products"
          :pagination="false" size="small" :row-key="(_r, i) => i"
        >
          <template #bodyCell="{ column, record, index }">
            <template v-if="column.key === 'product'">
              {{ record.name }}
              <div style="font-size: 12px; color: #999">{{ Number(record.price).toFixed(2) }}</div>
            </template>
            <template v-else-if="column.key === 'discount_type'">
              <a-select v-model:value="record.discount_type" size="small" style="width: 100%">
                <a-select-option value="percent">%</a-select-option>
                <a-select-option value="fixed">{{ $t('Fixed') }}</a-select-option>
              </a-select>
            </template>
            <template v-else-if="column.key === 'discount_value'">
              <a-input-number v-model:value="record.discount_value" :min="0" :step="0.01" size="small" style="width: 100%" />
            </template>
            <template v-else-if="column.key === 'flash_price'">
              <strong>{{ flashPrice(record) }}</strong>
            </template>
            <template v-else-if="column.key === 'action'">
              <a-button size="small" danger @click="form.products.splice(index, 1)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </template>
          </template>
        </a-table>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Flash sales — GET store/flash-sales?search → {flash_sales}; edit GET
 * store/flash-sales/{id} (flat, products normalized); product search GET
 * store/flash-sales/search-products?q (300ms debounce); save POST/PUT with
 * {name, name_translations{locale: name}, is_active 1|0, starts_at|null,
 * ends_at|null, products[{product_id, discount_type percent|fixed,
 * discount_value}]}. Flash price preview = legacy formula. The storefront
 * locales come back from the API as {locale: label}.
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
const rows = ref([]);
const modalOpen = ref(false);
const prodQuery = ref('');
const results = ref([]);
let searchTimer = null;

// Storefront locales, from the API: [{code: 'fr', label: 'Français'}, …].
const localeList = ref([]);
function setLocales(map) {
  if (!map) return;
  localeList.value = Object.keys(map).map(code => ({ code, label: map[code] }));
}

const emptyForm = () => ({ id: null, name: '', name_translations: {}, is_active: true, starts_at: '', ends_at: '', products: [] });
const form = ref(emptyForm());

const columns = computed(() => [
  { title: t('Name'), key: 'name' },
  { title: t('Products'), dataIndex: 'products_count', key: 'products_count', align: 'center' },
  { title: t('Starts'), key: 'starts_at' },
  { title: t('Ends'), key: 'ends_at' },
  { title: t('Status'), key: 'status', width: 110 },
  { title: t('Actions'), key: 'actions', width: 100 },
]);
const productColumns = computed(() => [
  { title: t('ProductName'), key: 'product' },
  { title: t('Discount'), key: 'discount_type', width: 110 },
  { title: t('Value'), key: 'discount_value', width: 120 },
  { title: t('Flash_Price'), key: 'flash_price', width: 110 },
  { title: '', key: 'action', width: 50 },
]);

function flashPrice(it) {
  const price = Number(it.price) || 0;
  const v = Number(it.discount_value) || 0;
  const out = it.discount_type === 'fixed' ? Math.max(0, price - v) : price * (1 - Math.min(100, v) / 100);
  return out.toFixed(2);
}
async function fetch() {
  try {
    const resp = await http.get('store/flash-sales', { search: search.value });
    rows.value = resp.flash_sales || [];
    setLocales(resp.locales);
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
}
function openCreate() {
  form.value = emptyForm();
  results.value = [];
  prodQuery.value = '';
  modalOpen.value = true;
}
async function openEdit(id) {
  try {
    const d = await http.get(`store/flash-sales/${id}`);
    setLocales(d.locales);
    form.value = {
      id: d.id,
      name: d.name,
      name_translations: { ...(d.name_translations || {}) },
      is_active: !!d.is_active,
      starts_at: (d.starts_at || '').replace(' ', 'T').slice(0, 16),
      ends_at: (d.ends_at || '').replace(' ', 'T').slice(0, 16),
      products: (d.products || []).map(p => ({
        product_id: p.product_id, name: p.name, price: p.price,
        discount_type: p.discount_type, discount_value: p.discount_value,
      })),
    };
    results.value = [];
    prodQuery.value = '';
    modalOpen.value = true;
  } catch (e) {
    message.error(t('Failed'));
  }
}
function searchProducts() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    if (!prodQuery.value) {
      results.value = [];
      return;
    }
    try {
      const resp = await http.get('store/flash-sales/search-products', { q: prodQuery.value });
      results.value = resp.products || [];
    } catch (e) {
      results.value = [];
    }
  }, 300);
}
function addProduct(p) {
  if (form.value.products.some(x => x.product_id === p.id)) return;
  form.value.products.push({
    product_id: p.id, name: p.name, price: p.price, discount_type: 'percent', discount_value: 10,
  });
  results.value = [];
  prodQuery.value = '';
}
async function submit() {
  saving.value = true;
  const payload = {
    name: form.value.name,
    name_translations: form.value.name_translations,
    is_active: form.value.is_active ? 1 : 0,
    starts_at: form.value.starts_at || null,
    ends_at: form.value.ends_at || null,
    products: form.value.products.map(p => ({
      product_id: p.product_id, discount_type: p.discount_type, discount_value: p.discount_value,
    })),
  };
  try {
    if (form.value.id) await http.put(`store/flash-sales/${form.value.id}`, payload);
    else await http.post('store/flash-sales', payload);
    message.success(t('Successfully_Updated'));
    modalOpen.value = false;
    fetch();
  } catch (e) {
    message.error(e?.data?.error || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
function confirmDelete(r) {
  Modal.confirm({
    title: t('AreYouSure'),
    content: r.name,
    okText: t('Yes'),
    okType: 'danger',
    cancelText: t('No'),
    async onOk() {
      try {
        await http.delete(`store/flash-sales/${r.id}`);
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
.results-box {
  border: 1px solid rgba(5, 5, 5, 0.12);
  border-radius: 8px;
  margin-top: 4px;
  max-height: 180px;
  overflow: auto;
}
.result-row {
  display: block;
  padding: 6px 10px;
  cursor: pointer;
}
.i18n-hint {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  margin-bottom: 12px;
}
.result-row:hover {
  background: rgba(0, 0, 0, 0.04);
}
</style>
