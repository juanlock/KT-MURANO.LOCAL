<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('New_Collection')"
      :breadcrumb="[$t('Store'), $t('Collections'), isEdit ? $t('Edit') : $t('New_Collection')]"
    >
      <template #extra>
        <a-space>
          <a-button type="primary" :loading="saving" @click="save(false)">{{ $t('Save') }}</a-button>
          <a-button :loading="saving" @click="save(true)">{{ $t('Save_and_Close') }}</a-button>
          <a-button @click="$router.push('/store/collections')">{{ $t('Cancel') }}</a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-card size="small" :title="$t('Collection_Details')" style="margin-bottom: 16px">
        <a-form layout="vertical">
          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Title') + ' *'">
                <a-input v-model:value="form.title" @input="autoSlug" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item :label="'Slug *'">
                <a-input v-model:value="form.slug" addon-before="/collections/" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="4">
              <a-form-item :label="$t('Limit')">
                <a-input-number v-model:value="form.limit" :min="1" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>
          <a-form-item :label="$t('Description')" style="margin-bottom: 0">
            <a-textarea v-model:value="form.description" :rows="3" />
          </a-form-item>
        </a-form>
      </a-card>

      <a-card size="small">
        <template #title>
          {{ $t('Products_in_Collection') }}
          <a-tag style="margin-left: 8px">{{ selected.length }} {{ $t('selected') }}</a-tag>
        </template>
        <a-row :gutter="[16, 16]">
          <!-- Search column -->
          <a-col :xs="24" :lg="12">
            <div class="panel">
              <a-input-search
                v-model:value="productQuery" :placeholder="$t('Search_products')"
                :loading="searching" allow-clear @change="debouncedSearch" @search="searchProducts"
              />
              <div v-if="!searching && productQuery && !results.length" style="font-size: 12px; color: #999; margin-top: 6px">
                {{ $t('No_results') }}
              </div>
              <div class="results-list">
                <div v-for="p in results" :key="'r-' + p.id" class="result-row">
                  <div style="display: flex; align-items: center; gap: 8px; min-width: 0">
                    <img v-if="productThumb(p)" :src="productThumb(p)" class="thumb" />
                    <div style="min-width: 0">
                      <div style="font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">
                        {{ p.name || p.title || ('#' + p.id) }}
                      </div>
                      <div style="font-size: 12px; color: #999">
                        #{{ p.id }}<span v-if="p.code || p.sku"> • {{ p.code || p.sku }}</span>
                      </div>
                    </div>
                  </div>
                  <a-button size="small" :disabled="hasProduct(p.id)" @click="addProduct(p)">
                    {{ hasProduct(p.id) ? $t('Added') : $t('Add') }}
                  </a-button>
                </div>
                <a-empty v-if="!productQuery && !results.length && !searching" :description="$t('Start_typing_to_search')" />
              </div>
            </div>
          </a-col>

          <!-- Selected column -->
          <a-col :xs="24" :lg="12">
            <div class="panel">
              <a-empty v-if="!selected.length" :description="$t('No_products_in_collection_yet')" />
              <template v-else>
                <a-table
                  :columns="selectedColumns" :data-source="selected"
                  :pagination="false" size="small" :row-key="r => 's-' + r.product_id"
                >
                  <template #bodyCell="{ column, record, index }">
                    <template v-if="column.key === 'order'">
                      <a-tag style="font-family: ui-monospace, Menlo, monospace">{{ index + 1 }}</a-tag>
                    </template>
                    <template v-else-if="column.key === 'product'">
                      <div style="display: flex; align-items: center; gap: 8px">
                        <img v-if="record.thumb" :src="record.thumb" class="thumb" />
                        <div>
                          <div style="font-weight: 600">{{ record.name }}</div>
                          <small style="color: #999">#{{ record.product_id }}<span v-if="record.sku"> • {{ record.sku }}</span></small>
                        </div>
                      </div>
                    </template>
                    <template v-else-if="column.key === 'actions'">
                      <a-space>
                        <a-button size="small" :disabled="index === 0" @click="move(index, -1)">↑</a-button>
                        <a-button size="small" :disabled="index === selected.length - 1" @click="move(index, 1)">↓</a-button>
                        <a-button size="small" danger @click="selected.splice(index, 1)">{{ $t('Remove') }}</a-button>
                      </a-space>
                    </template>
                  </template>
                </a-table>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px">
                  <span style="font-size: 12px; color: #999">{{ $t('Order_determines_display_priority') }}</span>
                  <a-button size="small" danger @click="clearSelected">{{ $t('Clear_all') }}</a-button>
                </div>
              </template>
            </div>
          </a-col>
        </a-row>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * Collection create/edit (legacy CollectionsCreate + CollectionsEdit merged
 * — identical logic). Data: GET admin/store/collections/{id} → collection
 * with products[] (pivot sort_order/pinned drives initial order). Product
 * search GET admin/store/products?q&limit=20 (300ms debounce). Save: POST
 * admin/store/collections (create → {id}) or PUT .../{id}; then products
 * sync POST .../{id}/products {items: [{product_id, sort_order: (i+1)*10,
 * pinned}]} — sync failure only warns (legacy). Title + slug required.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const id = route.params.id || null;
const isEdit = !!id;

const isLoading = ref(isEdit);
const saving = ref(false);
const searching = ref(false);
const productQuery = ref('');
const results = ref([]);
const selected = ref([]);
let debounceTimer = null;

const form = ref({ title: '', slug: '', description: '', limit: 8, sort_order: 0 });

const selectedColumns = computed(() => [
  { title: '#', key: 'order', width: 60 },
  { title: t('ProductName'), key: 'product' },
  { title: t('Actions'), key: 'actions', width: 190 },
]);

function slugify(v) {
  return String(v || '')
    .toLowerCase().trim()
    .replace(/['"]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}
function autoSlug() {
  if (!form.value.slug) form.value.slug = slugify(form.value.title);
}
function productThumb(p) {
  if (!p) return '';
  return p.thumbnail || p.thumb || p.image_url || (typeof p.image === 'string' ? p.image : '');
}
function hasProduct(pid) {
  return selected.value.some(x => x.product_id === pid);
}
function addProduct(p) {
  if (!p || hasProduct(p.id)) return;
  selected.value.push({
    product_id: p.id,
    name: p.name || p.title || `#${p.id}`,
    sku: p.sku || p.code || '',
    pinned: false,
    thumb: productThumb(p),
  });
}
function move(idx, dir) {
  const j = idx + dir;
  if (j < 0 || j >= selected.value.length) return;
  const row = selected.value.splice(idx, 1)[0];
  selected.value.splice(j, 0, row);
}
function clearSelected() {
  if (!selected.value.length) return;
  Modal.confirm({
    title: t('Confirm_Clear_All'),
    okText: t('Yes'),
    okType: 'danger',
    cancelText: t('No'),
    onOk() { selected.value = []; },
  });
}
function debouncedSearch() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(searchProducts, 300);
}
async function searchProducts() {
  const q = (productQuery.value || '').trim();
  if (!q) {
    results.value = [];
    return;
  }
  searching.value = true;
  try {
    const resp = await http.get('admin/store/products', { q, limit: 20 });
    const payload = Array.isArray(resp?.data) ? resp.data : (Array.isArray(resp) ? resp : []);
    results.value = payload;
  } catch (e) {
    results.value = [];
  } finally {
    searching.value = false;
  }
}
function itemsPayload() {
  return selected.value.map((x, i) => ({
    product_id: x.product_id,
    sort_order: (i + 1) * 10,
    pinned: !!x.pinned,
  }));
}

async function save(close) {
  if (!form.value.title || !form.value.slug) {
    message.error(t('Title_and_Slug_required'));
    return;
  }
  saving.value = true;
  try {
    let collectionId = id;
    if (isEdit) {
      await http.put(`admin/store/collections/${id}`, form.value);
    } else {
      const resp = await http.post('admin/store/collections', form.value);
      collectionId = resp && resp.id;
      if (!collectionId) throw new Error('No ID returned');
    }
    if (selected.value.length) {
      try {
        await http.post(`admin/store/collections/${collectionId}/products`, { items: itemsPayload() });
      } catch (e) {
        message.warning(t('Collection_saved_but_products_not_synced'));
      }
    }
    message.success(isEdit ? t('Successfully_Updated') : t('Successfully_Created'));
    if (close || !isEdit) router.push('/store/collections');
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  if (!isEdit) return;
  try {
    const resp = await http.get(`admin/store/collections/${id}`);
    const c = (resp && resp.data) || resp || {};
    form.value = {
      title: c.title || '',
      slug: c.slug || '',
      description: c.description || '',
      limit: c.limit != null ? c.limit : 8,
      sort_order: c.sort_order != null ? c.sort_order : 0,
    };
    const prods = Array.isArray(c.products) ? c.products.slice() : [];
    const pivotMap = {};
    prods.forEach(pp => {
      if (pp && pp.id && pp.pivot) {
        pivotMap[pp.id] = {
          sort_order: pp.pivot.sort_order != null ? pp.pivot.sort_order : 0,
          pinned: !!pp.pivot.pinned,
        };
      }
    });
    selected.value = prods
      .map(p => ({
        product_id: p.id,
        name: p.name || p.title || `#${p.id}`,
        sku: p.sku || p.code || '',
        pinned: !!(p.pivot && p.pivot.pinned),
        thumb: productThumb(p),
      }))
      .sort((a, b) => {
        const ao = pivotMap[a.product_id] ? pivotMap[a.product_id].sort_order : 0;
        const bo = pivotMap[b.product_id] ? pivotMap[b.product_id].sort_order : 0;
        return ao - bo;
      });
  } catch (e) {
    message.error(t('Failed_to_load'));
  } finally {
    isLoading.value = false;
  }
});
</script>

<style scoped>
.panel {
  border: 1px solid rgba(5, 5, 5, 0.08);
  border-radius: 10px;
  padding: 14px;
  height: 100%;
}
.results-list {
  max-height: 420px;
  overflow: auto;
  margin-top: 10px;
}
.result-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 0;
  border-bottom: 1px dashed rgba(5, 5, 5, 0.1);
}
.result-row:last-child {
  border-bottom: 0;
}
.thumb {
  width: 40px;
  height: 40px;
  border-radius: 6px;
  object-fit: cover;
  background: rgba(0, 0, 0, 0.04);
  border: 1px solid rgba(5, 5, 5, 0.06);
  flex-shrink: 0;
}
</style>
