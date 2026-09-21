<template>
  <div class="page">
    <PageHeader :title="$t('productsList')" :breadcrumb="[$t('Products'), $t('productsList')]">
      <template #actions>
        <a-space wrap>
          <a-button :loading="exporting === 'pdf'" @click="exportList('pdf')">
            <template #icon><FilePdfOutlined /></template>
            PDF
          </a-button>
          <a-button :loading="exporting === 'xlsx'" @click="exportList('xlsx')">
            <template #icon><FileExcelOutlined /></template>
            EXCEL
          </a-button>
          <a-button v-if="auth.can('product_import')" @click="$router.push('/products/import')">
            <template #icon><UploadOutlined /></template>
            {{ $t('import_products') }}
          </a-button>
          <a-button v-if="auth.can('products_add')" type="primary" @click="$router.push('/products/create')">
            <template #icon><PlusOutlined /></template>
            {{ $t('AddProduct') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]">
        <a-col :xs="12" :md="8" :xl="5">
          <div class="filter-label">{{ $t('CodeProduct') }}</div>
          <a-input v-model:value="filters.code" :placeholder="$t('SearchByCode')" allow-clear @change="crud.reload()" />
        </a-col>
        <a-col :xs="12" :md="8" :xl="5">
          <div class="filter-label">{{ $t('ProductName') }}</div>
          <a-input v-model:value="filters.name" :placeholder="$t('SearchByName')" allow-clear @change="crud.reload()" />
        </a-col>
        <a-col :xs="12" :md="8" :xl="5">
          <div class="filter-label">{{ $t('Categorie') }}</div>
          <a-select
            v-model:value="filters.category_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Category')" :options="categoryOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="8" :xl="4">
          <div class="filter-label">{{ $t('Brand') }}</div>
          <a-select
            v-model:value="filters.brand_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Brand')" :options="brandOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="8" :xl="5">
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select
            v-model:value="filters.warehouse_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
            @change="crud.reload()"
          />
        </a-col>
      </a-row>
      <div class="tb-bar">
        <a-segmented v-model:value="view" :options="VIEW_OPTIONS" class="tb-view">
          <template #label="{ value, label }">
            <span class="seg-label">
              <AppstoreOutlined v-if="value === 'grid'" />
              <UnorderedListOutlined v-else />
              {{ label }}
            </span>
          </template>
        </a-segmented>
      </div>
    </a-card>

    <!-- Card view: products are recognised by photo far faster than by row -->
    <template v-if="view === 'grid'">
      <a-spin :spinning="crud.loading.value">
        <div v-if="crud.rows.value.length" class="grid">
          <article v-for="p in crud.rows.value" :key="p.id" class="pcard">
            <!-- a-image gives click-to-zoom (the antd lightbox) for free; the card
                 still navigates via the title and the view action. -->
            <div class="pcard-media">
              <a-image
                :src="productImg(p.image)"
                :alt="p.name"
                :fallback="'/images/products/no-image.png'"
              />
              <a-tag v-if="p.type" class="pcard-type">{{ p.type }}</a-tag>
              <span class="pcard-qty">{{ p.quantity }}</span>
            </div>

            <div class="pcard-body">
              <h3 class="pcard-name" :title="p.name">
                <a @click="$router.push(`/products/${p.id}`)">{{ p.name }}</a>
              </h3>
              <p class="pcard-sub">{{ subtitleOf(p) }}</p>

              <div class="pcard-codes">
                <span class="chip chip--main" :title="p.code">
                  <QrcodeOutlined />{{ p.code }}
                </span>
                <span
                  v-for="(c, i) in (p.variant_codes || []).slice(0, 2)" :key="i"
                  class="chip" :title="c"
                >
                  <BarcodeOutlined />{{ c }}
                </span>
                <span v-if="(p.variant_codes || []).length > 2" class="chip chip--more">
                  +{{ p.variant_codes.length - 2 }}
                </span>
              </div>

              <dl class="pcard-facts">
                <div>
                  <dt>{{ $t('Price') }}</dt>
                  <dd class="is-price">{{ priceSummary(p.price) }}</dd>
                </div>
                <div>
                  <dt>{{ $t('Wholesale_Price') }}</dt>
                  <dd>{{ priceSummary(p.wholesale_price) }}</dd>
                </div>
                <div>
                  <dt>{{ $t('Min_Selling_Price') }}</dt>
                  <dd>{{ priceSummary(p.min_price) }}</dd>
                </div>
              </dl>
            </div>

            <footer class="pcard-foot">
              <a-tooltip :title="$t('ProductDetails')">
                <a-button type="text" size="small" @click="$router.push(`/products/${p.id}`)">
                  <template #icon><EyeOutlined style="color: #1677ff" /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip v-if="auth.can('products_edit')" :title="$t('Edit')">
                <a-button type="text" size="small" @click="$router.push(`/products/${p.id}/edit`)">
                  <template #icon><EditOutlined style="color: #52c41a" /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip v-if="auth.can('products_add')" :title="$t('Duplicate')">
                <a-button type="text" size="small" @click="duplicate(p)">
                  <template #icon><CopyOutlined style="color: #13c2c2" /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip v-if="auth.can('products_edit') && fitmentEnabled" :title="tf('Vehicle_Fitment', 'Vehicle Fitment')">
                <a-button type="text" size="small" @click="openFitment(p)">
                  <template #icon><CarOutlined style="color: #6d28d9" /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip v-if="auth.can('products_delete')" :title="$t('Del')">
                <a-button type="text" size="small" danger @click="crud.remove(p)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </a-tooltip>
            </footer>
          </article>
        </div>

        <a-card v-else>
          <a-empty :description="$t('NodataAvailable')" style="padding: 32px 0" />
        </a-card>
      </a-spin>

      <div v-if="crud.total.value" class="pager">
        <a-pagination
          :current="crud.page.value" :page-size="crud.limit.value" :total="crud.total.value"
          :page-size-options="PAGE_SIZE_OPTIONS" :build-option-text="buildPageSizeOptionText"
          :show-total="n => `${n}`" show-size-changer @change="onGridPageChange"
        />
      </div>
    </template>

    <DataTable v-else :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'image'">
          <img
            :src="productImg(record.image)"
            alt=""
            style="width: 42px; height: 42px; object-fit: cover; border-radius: 6px"
          />
        </template>
        <template v-else-if="column.key === 'name'">
          <div class="name-cell">
            <a @click="$router.push(`/products/${record.id}`)">{{ record.name }}</a>
            <a-tag v-if="record.type" class="type-tag">{{ record.type }}</a-tag>
          </div>
        </template>
        <template v-else-if="column.key === 'code'">
          <div class="stacked-cell">
            <div class="code-row is-main">
              <QrcodeOutlined />
              <span>{{ record.code }}</span>
            </div>
            <div v-for="(c, i) in (record.variant_codes || [])" :key="i" class="code-row is-variant">
              <BarcodeOutlined />
              <span>{{ c }}</span>
            </div>
          </div>
        </template>
        <!-- Variant products send one value per line ("10.00\n12.00") — split so
             each lands on its own row instead of collapsing into one line. -->
        <template v-else-if="['cost', 'price', 'wholesale_price', 'min_price'].includes(column.key)">
          <div class="stacked-cell stacked-cell--num">
            <div v-for="(v, i) in moneyLines(record[column.key])" :key="i">{{ v }}</div>
          </div>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('ProductDetails')">
              <a-button type="text" size="small" @click="$router.push(`/products/${record.id}`)">
                <template #icon><EyeOutlined style="color: #1677ff" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('products_edit')" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/products/${record.id}/edit`)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('products_add')" :title="$t('Duplicate')">
              <a-button type="text" size="small" @click="duplicate(record)">
                <template #icon><CopyOutlined style="color: #13c2c2" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('products_edit') && fitmentEnabled" :title="tf('Vehicle_Fitment', 'Vehicle Fitment')">
              <a-button type="text" size="small" @click="openFitment(record)">
                <template #icon><CarOutlined style="color: #6d28d9" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('products_delete')" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <ProductFitmentDrawer
      :open="fitmentDrawer.open"
      :product="fitmentDrawer.product"
      @close="fitmentDrawer.open = false"
    />
  </div>
</template>

<script setup>
/** products.image is a filename under /images/products or a pasted absolute URL. */
function productImg(v) {
  const f = String(v || '').trim();
  if (!f) return '/images/products/no-image.png';
  if (/^https?:\/\//i.test(f)) return f;
  return '/images/products/' + f;
}
/**
 * GET products → {products, warehouses, categories, subcategories, brands,
 * totalRows}; filter params code, name, category_id, brand_id, warehouse_id.
 * Duplicate = POST products/{id}/duplicate after a confirm. Bulk delete
 * standard nested. Detail is Vue 3; add/edit/import stay legacy until the
 * product form batch.
 */
import { ref, computed, watch, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, CopyOutlined,
  FilePdfOutlined, FileExcelOutlined, UploadOutlined, ExclamationCircleOutlined,
  CarOutlined, BarcodeOutlined, QrcodeOutlined,
  AppstoreOutlined, UnorderedListOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import ProductFitmentDrawer from '../../components/ProductFitmentDrawer.vue';
import { useCrudTable, PAGE_SIZE_OPTIONS, buildPageSizeOptionText } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { exportExcel, exportPdf } from '../../lib/exporters';
import http from '../../lib/http';
import { t as tf } from '../../i18n';

const { t } = useI18n();
const { money } = useFormat();
const auth = useAuthStore();

const filters = ref({
  code: '', name: '', category_id: undefined, brand_id: undefined, warehouse_id: undefined,
});

const filterParams = () => ({
  code: filters.value.code || '',
  name: filters.value.name || '',
  category_id: filters.value.category_id || '',
  brand_id: filters.value.brand_id || '',
  warehouse_id: filters.value.warehouse_id || '',
});

const crud = useCrudTable('products', {
  rowsKey: 'products',
  params: filterParams,
});
crud.fetchRows();

const categoryOptions = computed(() =>
  (crud.payload.value?.categories || []).map(c => ({ value: c.id, label: c.name }))
);
const brandOptions = computed(() =>
  (crud.payload.value?.brands || []).map(b => ({ value: b.id, label: b.name }))
);
const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

const columns = computed(() => [
  { title: t('image'), key: 'image', width: 70, exportable: false },
  // Type rides along inside the name cell as a tag; unit is already appended to
  // the quantity string by the API, so neither needs a column of its own.
  { title: t('Name_product'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Code'), dataIndex: 'code', key: 'code', sorter: true },
  // Money columns sit next to Code so a variant's codes and its stacked
  // cost/price lines can be read across without scanning past Brand/Category.
  { title: t('Cost'), dataIndex: 'cost', key: 'cost', align: 'right', sorter: true },
  { title: t('Price'), dataIndex: 'price', key: 'price', align: 'right', sorter: true },
  // Off by default — users can enable them from the table's columns dropdown.
  { title: t('Wholesale_Price'), dataIndex: 'wholesale_price', key: 'wholesale_price', align: 'right', sorter: true, defaultHidden: true },
  { title: t('Min_Selling_Price'), dataIndex: 'min_price', key: 'min_price', align: 'right', sorter: true, defaultHidden: true },
  { title: t('Brand'), dataIndex: 'brand', key: 'brand' },
  { title: t('Categorie'), dataIndex: 'category', key: 'category' },
  { title: t('Quantity'), dataIndex: 'quantity', key: 'quantity', align: 'right', sorter: true },
  { title: t('Action'), key: 'actions', width: 160, align: 'center' },
]);

// Variant rows arrive newline-joined from the API; blank/null means "no value".
const moneyLines = v => String(v ?? '').split('\n').filter(s => s !== '');

/* ---------- card / table view (choice persists per device) */
const VIEW_KEY = 'products_view_mode';
const view = ref(localStorage.getItem(VIEW_KEY) === 'grid' ? 'grid' : 'list');
watch(view, v => localStorage.setItem(VIEW_KEY, v));

const VIEW_OPTIONS = computed(() => [
  { value: 'grid', label: t('Grid_View') },
  { value: 'list', label: t('Table_View') },
]);

function onGridPageChange(page, pageSize) {
  crud.page.value = page;
  crud.limit.value = pageSize;
  crud.fetchRows();
}

/**
 * A card has room for one number, not a stacked column: collapse a variant
 * product's per-variant values into a range. The server's own strings are
 * reused for the endpoints so the configured price precision is preserved.
 */
function priceSummary(value) {
  const parts = moneyLines(value);
  if (!parts.length) return '—';
  if (parts.length === 1) return parts[0];

  const nums = parts.map(raw => ({ raw, n: Number(raw) })).filter(x => !Number.isNaN(x.n));
  if (!nums.length) return parts[0];

  const lo = nums.reduce((a, b) => (b.n < a.n ? b : a));
  const hi = nums.reduce((a, b) => (b.n > a.n ? b : a));
  return lo.n === hi.n ? lo.raw : `${lo.raw} – ${hi.raw}`;
}

// Brand is 'N/D' rather than null when unset, so filter it out explicitly.
const subtitleOf = p => [p.brand, p.category].filter(v => v && v !== 'N/D').join(' · ') || '—';

/**
 * What the export writes, mirroring what the table shows.
 *
 * It can't just reuse `columns`: the cells that matter most are slot-rendered,
 * so the raw field either doesn't exist (variant codes) or isn't what's on
 * screen. Type gets its own column rather than riding inside Name as it does
 * visually — same information, but filterable in a spreadsheet. Unit is left
 * out because the API already appends it to Quantity ("12.00 pcs").
 */
const exportColumns = computed(() => [
  { title: t('Name_product'), dataIndex: 'name' },
  { title: t('type'), dataIndex: 'type' },
  {
    title: t('Code'),
    key: 'code',
    // Stacked parent-first like the table. The icons and indent that mark the
    // parent on screen can't survive into a cell, so variants carry a plain
    // "- " marker instead — ASCII on purpose: jsPDF's built-in fonts are
    // cp1252, so box-drawing characters would render as garbage in the PDF.
    exportValue: (r) => {
      const variants = (r.variant_codes || []).filter(Boolean);
      if (!variants.length) return r.code || '';
      return [r.code || '', ...variants.map(c => `- ${c}`)].filter(Boolean).join('\n');
    },
  },
  { title: t('Cost'), dataIndex: 'cost' },
  { title: t('Price'), dataIndex: 'price' },
  { title: t('Wholesale_Price'), dataIndex: 'wholesale_price' },
  { title: t('Min_Selling_Price'), dataIndex: 'min_price' },
  { title: t('Brand'), dataIndex: 'brand' },
  { title: t('Categorie'), dataIndex: 'category' },
  { title: t('Quantity'), dataIndex: 'quantity' },
]);

const exporting = ref(null);

/* ---------- Vehicle Fitment drawer (visible when the feature is on) */
const fitmentEnabled = ref(false);
const fitmentDrawer = ref({ open: false, product: null });
http.get('vehicles/lookup')
  .then(d => { fitmentEnabled.value = !!d?.enabled; })
  .catch(() => {});
function openFitment(record) {
  fitmentDrawer.value = { open: true, product: { id: record.id, name: record.name } };
}

async function exportList(kind) {
  exporting.value = kind;
  try {
    const data = await http.get('products', {
      page: 1,
      SortField: crud.sortField.value,
      SortType: crud.sortType.value,
      search: crud.search.value,
      limit: -1,
      ...filterParams(),
    });
    const rows = data.products || [];
    if (kind === 'xlsx') await exportExcel('products', exportColumns.value, rows);
    else await exportPdf(t('productsList'), exportColumns.value, rows);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    exporting.value = null;
  }
}

function duplicate(record) {
  Modal.confirm({
    title: t('Confirm'),
    icon: createVNode(ExclamationCircleOutlined),
    content: t('Are_you_sure_you_want_to_duplicate_this_product'),
    okText: t('Duplicate'),
    async onOk() {
      try {
        await http.post(`products/${record.id}/duplicate`);
        message.success(t('Successfully_Created'));
        crud.fetchRows();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}
</script>

<style scoped>
/* ---------------- view switch ---------------- */
.tb-bar {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
.seg-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

/* ---------------- card grid ----------------
   Surfaces are left to antd's theme (borders/text use neutral alphas) so the
   cards read correctly in both light and dark mode. */
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 16px;
}
.pcard {
  display: flex;
  flex-direction: column;
  border: 1px solid rgba(128, 128, 128, 0.22);
  border-radius: 16px;
  overflow: hidden;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.12s ease;
}
.pcard:hover {
  border-color: rgba(109, 40, 217, 0.55);
  box-shadow: 0 8px 22px rgba(0, 0, 0, 0.09);
  transform: translateY(-2px);
}
/* Shorter than a hero image and `contain`-fitted: product shots are usually
   packshots on a plain background, so cropping them to fill (as `cover` did)
   cut off the product itself. */
.pcard-media {
  position: relative;
  height: 128px;
  padding: 8px;
  background: rgba(128, 128, 128, 0.08);
}
.pcard-media :deep(.ant-image) {
  width: 100%;
  height: 100%;
}
.pcard-media :deep(.ant-image img) {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
/* The zoom mask covers the whole thumb, so the badges must sit above it and
   stay click-through or they would swallow the preview trigger. */
.pcard-type,
.pcard-qty {
  z-index: 2;
  pointer-events: none;
}
.pcard-type {
  position: absolute;
  inset-block-start: 10px;
  inset-inline-end: 10px;
  margin: 0;
}
.pcard-qty {
  position: absolute;
  inset-block-end: 10px;
  inset-inline-start: 10px;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 600;
  color: #fff;
  background: rgba(0, 0, 0, 0.62);
  backdrop-filter: blur(2px);
}
.pcard-body {
  padding: 12px 14px 6px;
  flex: 1;
}
.pcard-name {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pcard-sub {
  margin: 2px 0 10px;
  font-size: 12.5px;
  opacity: 0.6;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pcard-codes {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  margin-bottom: 10px;
}
.chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  max-width: 100%;
  padding: 1px 7px;
  border-radius: 6px;
  background: rgba(128, 128, 128, 0.16);
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.chip--main {
  font-weight: 600;
}
.chip--more {
  opacity: 0.65;
}
.pcard-facts {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  margin: 0;
}
.pcard-facts dt {
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  opacity: 0.5;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pcard-facts dd {
  margin: 1px 0 0;
  font-size: 13px;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pcard-facts dd.is-price {
  font-weight: 700;
}
.pcard-foot {
  display: flex;
  justify-content: flex-end;
  gap: 2px;
  padding: 6px 10px 8px;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}

/* Name over its type tag, so the tag reads as a subtitle and the column stays
   as narrow as it was when type had its own header. */
.name-cell {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
}
.name-cell .type-tag {
  margin-inline-end: 0;
  font-size: 11px;
  line-height: 16px;
}

/* One row per value: the parent plus each variant underneath. */
.stacked-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
  line-height: 1.5;
  white-space: nowrap;
}
.stacked-cell--num {
  text-align: right;
  font-weight: 600;
}
.code-row {
  display: flex;
  align-items: center;
  gap: 6px;
}
.code-row.is-main {
  font-weight: 600;
}
.code-row.is-variant {
  padding-inline-start: 14px;
  opacity: 0.7;
}
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
</style>
