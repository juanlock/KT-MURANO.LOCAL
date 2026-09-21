<template>
  <div class="page">
    <PageHeader :title="$t('ProductDetails')" :breadcrumb="[$t('Products'), $t('ProductDetails')]">
      <template #actions>
        <a-space wrap>
          <a-button @click="$router.push('/products')">
            <template #icon><ArrowLeftOutlined /></template>
            {{ $t('Back') }}
          </a-button>
          <a-button @click="printSheet">
            <template #icon><PrinterOutlined /></template>
            {{ $t('print') }}
          </a-button>
          <a-button :loading="pdfExporting" @click="exportPdfSheet">
            <template #icon><FilePdfOutlined /></template>
            {{ $t('PDF') }}
          </a-button>
          <a-button v-if="auth.can('products_edit')" type="primary" ghost @click="$router.push(`/products/${product.id}/edit`)">
            <template #icon><EditOutlined /></template>
            {{ $t('Edit') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- ===== Hero ===== -->
      <a-card size="small" style="margin-bottom: 16px">
        <div class="pd-hero">
          <div class="pd-media">
            <a-carousel
              v-if="gallery.length > 1"
              ref="carouselRef" arrows class="pd-carousel"
              :after-change="i => (currentSlide = i)"
            >
              <template #prevArrow>
                <div class="pd-arrow pd-arrow-left"><LeftOutlined /></div>
              </template>
              <template #nextArrow>
                <div class="pd-arrow pd-arrow-right"><RightOutlined /></div>
              </template>
              <div v-for="(img, i) in gallery" :key="i">
                <div class="pd-img-wrap">
                  <img :src="img" :alt="product.name" class="pd-img" @error="onImgError" />
                </div>
              </div>
            </a-carousel>
            <div v-else class="pd-img-wrap">
              <img :src="mainImage" :alt="product.name" class="pd-img" @error="onImgError" />
            </div>
            <div v-if="gallery.length > 1" class="pd-thumbs">
              <img
                v-for="(img, i) in gallery" :key="i" :src="img" alt=""
                class="pd-thumb" :class="{ active: i === currentSlide }"
                @click="goToSlide(i)" @error="onImgError"
              />
            </div>
          </div>

          <div class="pd-info">
            <div class="pd-title">{{ product.name }}</div>
            <div class="pd-code">{{ product.code }}</div>

            <div class="pd-tags">
              <a-tag color="purple">{{ product.type_name || product.type }}</a-tag>
              <a-tag v-if="product.category">{{ product.category }}</a-tag>
              <a-tag v-if="product.brand && product.brand !== 'N/D'">{{ product.brand }}</a-tag>
              <a-tag v-if="product.unit && product.unit !== '----'">{{ product.unit }}</a-tag>
              <a-tag v-if="product.is_batch_tracked" color="warning">{{ $t('Batches') }}</a-tag>
            </div>

            <div class="pd-stats">
              <div class="pd-stat">
                <div class="pd-stat-icon" style="background: rgba(82, 196, 26, 0.12); color: #52c41a"><DollarOutlined /></div>
                <div class="pd-stat-meta">
                  <div class="pd-stat-label">{{ $t('Price') }}</div>
                  <div class="pd-stat-value">{{ money(product.price) }}</div>
                </div>
              </div>
              <div class="pd-stat">
                <div class="pd-stat-icon" style="background: rgba(22, 119, 255, 0.12); color: #1677ff"><TagOutlined /></div>
                <div class="pd-stat-meta">
                  <div class="pd-stat-label">{{ $t('Cost') }}</div>
                  <div class="pd-stat-value">{{ money(product.cost) }}</div>
                </div>
              </div>
              <div v-if="product.wholesale_price" class="pd-stat">
                <div class="pd-stat-icon" style="background: rgba(109, 40, 217, 0.12); color: #6d28d9"><ShoppingOutlined /></div>
                <div class="pd-stat-meta">
                  <div class="pd-stat-label">{{ $t('Wholesale_Price') }}</div>
                  <div class="pd-stat-value">{{ money(product.wholesale_price) }}</div>
                </div>
              </div>
              <div v-if="showStock" class="pd-stat">
                <div class="pd-stat-icon" style="background: rgba(19, 194, 194, 0.12); color: #13c2c2"><InboxOutlined /></div>
                <div class="pd-stat-meta">
                  <div class="pd-stat-label">{{ $t('Stock') }}</div>
                  <div class="pd-stat-value" :style="{ color: lowStock ? '#faad14' : undefined }">{{ totalStock }}</div>
                </div>
              </div>
              <div class="pd-stat">
                <div class="pd-stat-icon" style="background: rgba(250, 173, 20, 0.14); color: #faad14"><WarningOutlined /></div>
                <div class="pd-stat-meta">
                  <div class="pd-stat-label">{{ $t('StockAlert') }}</div>
                  <div class="pd-stat-value">{{ product.stock_alert }}</div>
                </div>
              </div>
            </div>

            <div v-if="product.note" class="pd-note">{{ product.note }}</div>
          </div>
        </div>
      </a-card>

      <a-row :gutter="16">
        <!-- ===== All details ===== -->
        <a-col :xs="24" :lg="16">
          <a-card size="small" :title="$t('Details')" style="margin-bottom: 16px">
            <a-descriptions :column="{ xs: 1, sm: 2 }" size="small" bordered>
              <a-descriptions-item v-for="row in infoRows" :key="row.label" :label="row.label">
                {{ row.value }}
              </a-descriptions-item>
            </a-descriptions>
          </a-card>
        </a-col>

        <!-- ===== Barcode ===== -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" :title="$t('Barcode')" style="margin-bottom: 16px">
            <div class="pd-barcode" ref="barcodeWrap">
              <BarcodeSvg
                :value="product.code"
                :format="product.Type_barcode || 'CODE128'"
                :height="56"
                :fontSize="14"
                textmargin="2"
                width="2"
              />
            </div>
            <div class="pd-barcode-meta">
              <a-tag style="margin: 0">{{ product.Type_barcode || 'CODE128' }}</a-tag>
              <span v-if="product.gtin" class="pd-gtin">{{ $t('Barcode_GTIN') }}: {{ product.gtin }}</span>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <!-- Warehouse stock (single products) -->
      <a-card
        v-if="product.type === 'is_single' && (product.CountQTY || []).length"
        size="small" :title="$t('Warehouse_Stock')" style="margin-bottom: 16px" :body-style="{ padding: 0 }"
      >
        <a-table
          :columns="[
            { title: t('warehouse'), dataIndex: 'mag', key: 'mag' },
            { title: t('Quantity'), dataIndex: 'qte', key: 'qte', align: 'right' },
          ]"
          :data-source="product.CountQTY" :pagination="false" size="middle" :row-key="(r, i) => i"
        />
      </a-card>

      <!-- Warehouse stock per variant -->
      <a-card
        v-if="product.type === 'is_variant' && (product.CountQTY_variants || []).length"
        size="small" :title="$t('Warehouse_Stock')" style="margin-bottom: 16px" :body-style="{ padding: 0 }"
      >
        <a-table
          :columns="[
            { title: t('warehouse'), dataIndex: 'mag', key: 'mag' },
            { title: t('Variant'), dataIndex: 'variant', key: 'variant' },
            { title: t('Quantity'), dataIndex: 'qte', key: 'qte', align: 'right' },
          ]"
          :data-source="product.CountQTY_variants" :pagination="false" size="middle" :row-key="(r, i) => i"
        />
      </a-card>

      <!-- Variants -->
      <a-card
        v-if="(product.products_variants_data || []).length"
        size="small" :title="$t('Variants')" style="margin-bottom: 16px" :body-style="{ padding: 0 }"
      >
        <a-table
          :columns="[
            { title: t('Name'), dataIndex: 'name', key: 'name' },
            { title: t('Code'), dataIndex: 'code', key: 'code' },
            { title: t('Cost'), dataIndex: 'cost', key: 'cost', align: 'right' },
            { title: t('Price'), dataIndex: 'price', key: 'price', align: 'right' },
          ]"
          :data-source="product.products_variants_data" :pagination="false" size="middle" :row-key="(r, i) => i"
        />
      </a-card>

      <!-- Combined products (combos) -->
      <a-card
        v-if="(product.products_combo_data || []).length"
        size="small" :title="$t('Combined_Products')" style="margin-bottom: 16px" :body-style="{ padding: 0 }"
      >
        <a-table
          :columns="[
            { title: t('ProductName'), dataIndex: 'name', key: 'name' },
            { title: t('Quantity'), dataIndex: 'quantity', key: 'quantity', align: 'right' },
          ]"
          :data-source="product.products_combo_data" :pagination="false" size="middle" :row-key="(r, i) => i"
        />
      </a-card>

      <!-- Packs -->
      <a-card
        v-if="(product.packs || []).length"
        size="small" :title="$t('Multi_Pack_Selling')" style="margin-bottom: 16px" :body-style="{ padding: 0 }"
      >
        <a-table
          :columns="[
            ...((product.packs || []).some(p => p.variant_name) ? [{ title: t('Variant'), dataIndex: 'variant_name', key: 'variant_name' }] : []),
            { title: t('Pack_Name'), dataIndex: 'name', key: 'name' },
            { title: t('Quantity_Multiplier'), dataIndex: 'multiplier', key: 'multiplier', align: 'right' },
            { title: t('Selling_Price'), dataIndex: 'price', key: 'price', align: 'right' },
            { title: t('Default'), key: 'is_default', align: 'center' },
          ]"
          :data-source="product.packs" :pagination="false" size="middle" :row-key="(r, i) => i"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'is_default'">
              <a-tag v-if="record.is_default" color="success">{{ $t('Default') }}</a-tag>
            </template>
          </template>
        </a-table>
      </a-card>

      <!-- Wholesale quantity breaks -->
      <a-card
        v-if="(product.price_tiers || []).length"
        size="small" :title="$t('Wholesale_Pricing_By_Quantity')" style="margin-bottom: 16px" :body-style="{ padding: 0 }"
      >
        <a-table
          :columns="[
            { title: t('Min_Quantity'), dataIndex: 'min_qty', key: 'min_qty', align: 'right' },
            { title: t('Max_Quantity'), key: 'max_qty', align: 'right' },
            { title: t('Price_Per_Piece'), dataIndex: 'price', key: 'price', align: 'right' },
          ]"
          :data-source="product.price_tiers" :pagination="false" size="middle" :row-key="(r, i) => i"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'max_qty'">
              {{ record.max_qty === null ? $t('No_maximum') : record.max_qty }}
            </template>
          </template>
        </a-table>
      </a-card>

      <!-- Batches -->
      <a-card
        v-if="product.is_batch_tracked"
        size="small" :title="$t('Batches')" :body-style="{ padding: 0 }"
      >
        <a-table
          :columns="batchColumns" :data-source="batches" :loading="batchesLoading"
          :pagination="false" size="middle" :row-key="r => r.id" :scroll="{ x: 'max-content' }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'expiry'">
              <span :style="{ color: expiryColor(record) }">{{ record.expiry_date || '—' }}</span>
              <div v-if="record.days_to_expiry !== null && record.days_to_expiry !== undefined" class="muted">
                {{ record.days_to_expiry < 0 ? $t('Expired') : `${$t('Expires_in')} ${record.days_to_expiry} ${$t('Days')}` }}
              </div>
            </template>
          </template>
          <template #emptyText>
            <a-empty :description="$t('No_Batches_Available')" style="padding: 24px 0" />
          </template>
        </a-table>
      </a-card>
      <!-- Customer questions ("Ask About This Item") -->
      <a-card size="small" style="margin-top: 16px">
        <template #title>
          {{ $t('Customer_Questions') }}
          <a-tag v-if="unansweredCount" color="warning" style="margin-left: 8px">
            {{ unansweredCount }} {{ $t('Unanswered') }}
          </a-tag>
        </template>

        <a-spin :spinning="questionsLoading">
          <a-empty v-if="!questions.length" :description="$t('No_Questions_Yet')" style="padding: 16px 0" />

          <div v-for="q in questions" :key="q.id" class="pd-q">
            <div class="pd-q-head">
              <span class="pd-q-mark">Q</span>
              <div class="pd-q-body">
                <div>{{ q.question }}</div>
                <div class="muted">{{ q.asker_name }} · {{ q.created_at }}</div>
              </div>
              <a-tag :color="q.answer ? 'success' : 'warning'">
                {{ q.answer ? $t('Answered') : $t('Unanswered') }}
              </a-tag>
            </div>

            <div v-if="q.answer && editingId !== q.id" class="pd-q-answer">
              <span class="pd-q-mark">A</span>
              <div class="pd-q-body">
                <div>{{ q.answer }}</div>
                <div class="muted">
                  <template v-if="q.answered_by">{{ q.answered_by }} · </template>{{ q.answered_at }}
                </div>
              </div>
            </div>

            <div v-if="editingId === q.id" class="pd-q-form">
              <a-textarea v-model:value="answerText" :rows="3" :maxlength="2000"
                          :placeholder="$t('Write_an_answer')" />
              <a-space style="margin-top: 8px">
                <a-button type="primary" size="small" :loading="answering" @click="submitAnswer(q)">
                  {{ $t('Publish_Answer') }}
                </a-button>
                <a-button size="small" @click="editingId = null">{{ $t('Cancel') }}</a-button>
              </a-space>
            </div>

            <a-space v-else style="margin-top: 8px">
              <a-button size="small" @click="startAnswer(q)">
                {{ q.answer ? $t('Edit') : $t('Answer') }}
              </a-button>
              <a-button v-if="q.status !== 'rejected'" size="small" danger @click="setStatus(q, 'rejected')">
                {{ $t('Reject') }}
              </a-button>
              <a-button v-else size="small" @click="setStatus(q, 'pending')">{{ $t('Restore') }}</a-button>
            </a-space>
          </div>
        </a-spin>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * GET get_product_detail_api/{id} → flat product object incl. CountQTY
 * (single) / CountQTY_variants (rows {mag, variant?, qte}),
 * products_variants_data, products_combo_data, packs, warranty/guarantee
 * fields. Batch-tracked products also load GET product_batches
 * ?product_id&limit=200&SortField=expiry_date&SortType=asc → {batches,
 * expiry_warning_days}.
 *
 * Print + PDF render the same data as the page: `infoRows` (the Details
 * card) and `buildSections()` (every table below it). The barcode is the
 * on-screen BarcodeSvg — its rendered <svg> is cloned into the print popup
 * and rasterized to PNG for jsPDF.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ArrowLeftOutlined, EditOutlined, DollarOutlined, TagOutlined,
  ShoppingOutlined, InboxOutlined, WarningOutlined, PrinterOutlined, FilePdfOutlined,
  LeftOutlined, RightOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import BarcodeSvg from '../../components/BarcodeSvg.vue';
import { useAuthStore } from '../../stores/auth';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { money } = useFormat();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const loading = ref(true);
const product = ref({});

// ---- customer questions ("Ask About This Item") ----
const questions = ref([]);
const questionsLoading = ref(false);
const editingId = ref(null);
const answerText = ref('');
const answering = ref(false);
const unansweredCount = computed(() => questions.value.filter(q => !q.answer).length);

async function loadQuestions(productId) {
  questionsLoading.value = true;
  try {
    const r = await http.get('store/questions', { product_id: productId, per_page: 100 });
    questions.value = r.data || [];
  } catch (e) {
    questions.value = [];
  } finally {
    questionsLoading.value = false;
  }
}
function startAnswer(q) {
  editingId.value = q.id;
  answerText.value = q.answer || '';
}
async function submitAnswer(q) {
  if (!(answerText.value || '').trim()) return;
  answering.value = true;
  try {
    await http.post(`store/questions/${q.id}/answer`, { answer: answerText.value.trim(), publish: 1 });
    message.success(t('Successfully_Updated'));
    editingId.value = null;
    await loadQuestions(product.value.id);
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    answering.value = false;
  }
}
async function setStatus(q, status) {
  try {
    await http.post(`store/questions/${q.id}/status`, { status });
    await loadQuestions(product.value.id);
  } catch (e) {
    message.error(t('Failed'));
  }
}
const barcodeWrap = ref(null);
const pdfExporting = ref(false);

// ---- image gallery ----
// All product images as one carousel: the gallery (API `images`, main image
// first), the legacy comma-joined `image` column as fallback, plus every
// variant's own image — deduped, `no-image.png` excluded.
const NO_IMAGE = '/images/products/no-image.png';
const gallery = computed(() => {
  const p = product.value;
  const files = [];
  const push = f => {
    const c = String(f || '').trim();
    if (c && c !== 'no-image.png' && !files.includes(c)) files.push(c);
  };
  const base = Array.isArray(p.images) && p.images.length
    ? p.images
    : String(p.image || '').split(',');
  base.forEach(push);
  (p.products_variants_data || []).forEach(v => push(v.image));
  return files.length
    ? files.map(f => (/^https?:\/\//i.test(f) ? f : `/images/products/${f}`))
    : [NO_IMAGE];
});
const carouselRef = ref(null);
const currentSlide = ref(0);
// The image currently in view — the print sheet and PDF reuse it.
const mainImage = computed(() => gallery.value[currentSlide.value] || gallery.value[0] || NO_IMAGE);
function goToSlide(i) {
  currentSlide.value = i;
  carouselRef.value?.goTo(i);
}
function onImgError(e) {
  if (e?.target) e.target.src = NO_IMAGE;
}

// ---- stock summary ----
const showStock = computed(() => ['is_single', 'is_variant'].includes(product.value.type));
const totalStock = computed(() => {
  const rows = product.value.type === 'is_variant'
    ? (product.value.CountQTY_variants || [])
    : (product.value.CountQTY || []);
  return rows.reduce((s, r) => s + (Number(r.qte) || 0), 0);
});
const lowStock = computed(() => showStock.value && totalStock.value <= Number(product.value.stock_alert || 0));
const batches = ref([]);
const batchesLoading = ref(false);
const expiryWarningDays = ref(30);

// ---- full details (drives the Details card, the print sheet and the PDF) ----
const infoRows = computed(() => {
  const p = product.value;
  const joinList = (list, single) => {
    const arr = Array.isArray(list)
      ? list.map(x => (x && typeof x === 'object' ? x.name : x)).filter(Boolean)
      : [];
    return arr.length ? arr.join(', ') : (single || '');
  };
  // API sends 'Exclusive'/'Inclusive' as strings; legacy rows may carry 1/2.
  const taxMethod = String(p.tax_method) === '1' ? 'Exclusive'
    : String(p.tax_method) === '2' ? 'Inclusive' : (p.tax_method || '');
  const rows = [
    { label: t('CodeProduct'), value: p.code },
    { label: t('Barcode_GTIN'), value: p.gtin },
    { label: t('Type'), value: p.type_name || p.type },
    { label: t('Brand'), value: p.brand === 'N/D' ? '' : p.brand },
    { label: t('Categories'), value: joinList(p.categories, p.category) },
    { label: t('SubCategorie'), value: joinList(p.subcategories, p.sub_category) },
    { label: t('Unit'), value: p.unit === '----' ? '' : p.unit },
    { label: t('Cost'), value: money(p.cost) },
    { label: t('Price'), value: money(p.price) },
    { label: t('Wholesale_Price'), value: p.wholesale_price ? money(p.wholesale_price) : '' },
    { label: t('Min_Selling_Price'), value: p.min_price ? money(p.min_price) : '' },
    { label: t('Discount'), value: parseFloat(p.discount) ? p.discount : '' },
    { label: t('Tax'), value: p.taxe !== undefined && p.taxe !== null ? `${p.taxe} (${taxMethod})` : '' },
    { label: t('StockAlert'), value: p.stock_alert },
    { label: t('Points'), value: Number(p.points) ? p.points : '' },
    { label: t('Weight'), value: p.weight },
    { label: t('BarcodeSymbology'), value: p.Type_barcode },
    { label: t('Size_Guide'), value: p.size_guide?.name },
    { label: t('Warranty'), value: p.warranty_period ? `${p.warranty_period} ${p.warranty_unit || ''}` : '' },
    { label: t('Guarantee_Period'), value: p.has_guarantee && p.guarantee_period ? `${p.guarantee_period} ${p.guarantee_unit || ''}` : '' },
    { label: t('WarrantyTerms'), value: p.warranty_terms },
    { label: t('Note'), value: p.note },
  ];
  return rows.filter(r => r.value !== undefined && r.value !== null && String(r.value).trim() !== '');
});

// Every table section below the Details card, as plain head/rows — shared by
// the print sheet and the PDF so they always match the page.
function buildSections() {
  const p = product.value;
  const secs = [];
  if (p.type === 'is_single' && (p.CountQTY || []).length) {
    secs.push({
      title: t('Warehouse_Stock'),
      head: [t('warehouse'), t('Quantity')],
      rows: p.CountQTY.map(r => [r.mag, r.qte ?? 0]),
    });
  }
  if (p.type === 'is_variant' && (p.CountQTY_variants || []).length) {
    secs.push({
      title: t('Warehouse_Stock'),
      head: [t('warehouse'), t('Variant'), t('Quantity')],
      rows: p.CountQTY_variants.map(r => [r.mag, r.variant, r.qte ?? 0]),
    });
  }
  if ((p.products_variants_data || []).length) {
    secs.push({
      title: t('Variants'),
      head: [t('Name'), t('Code'), t('Cost'), t('Price')],
      rows: p.products_variants_data.map(v => [v.name, v.code, v.cost, v.price]),
    });
  }
  if ((p.products_combo_data || []).length) {
    secs.push({
      title: t('Combined_Products'),
      head: [t('ProductName'), t('CodeProduct'), t('Quantity')],
      rows: p.products_combo_data.map(c => [c.name, c.code, c.quantity]),
    });
  }
  if ((p.packs || []).length) {
    const withVariant = p.packs.some(x => x.variant_name);
    secs.push({
      title: t('Multi_Pack_Selling'),
      head: [...(withVariant ? [t('Variant')] : []), t('Pack_Name'), t('Quantity_Multiplier'), t('Selling_Price')],
      rows: p.packs.map(x => [...(withVariant ? [x.variant_name || ''] : []), x.name, x.multiplier, x.price]),
    });
  }
  if ((p.price_tiers || []).length) {
    secs.push({
      title: t('Wholesale_Pricing_By_Quantity'),
      head: [t('Min_Quantity'), t('Max_Quantity'), t('Price_Per_Piece')],
      rows: p.price_tiers.map(x => [x.min_qty, x.max_qty === null ? t('No_maximum') : x.max_qty, x.price]),
    });
  }
  if (p.is_batch_tracked && batches.value.length) {
    secs.push({
      title: t('Batches'),
      head: [t('Batch_No'), t('warehouse'), t('Quantity'), t('Mfg_Date'), t('Expiry_Date')],
      rows: batches.value.map(b => [b.batch_no, b.warehouse_name, b.qty, b.mfg_date || '', b.expiry_date || '']),
    });
  }
  return secs;
}

// ---- Print ----
function printSheet() {
  const w = window.open('', '_blank', 'height=700,width=900');
  if (!w) {
    message.warning(t('Popup_blocked_allow_printing'));
    return;
  }
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]
  ));
  const p = product.value;
  const svg = barcodeWrap.value?.querySelector('svg');
  const barcodeHtml = svg ? svg.outerHTML : '';
  const imgHtml = mainImage.value && !mainImage.value.endsWith('no-image.png')
    ? `<img class="photo" src="${/^https?:\/\//i.test(mainImage.value) ? mainImage.value : window.location.origin + mainImage.value}" alt="" />`
    : '';
  const infoHtml = infoRows.value
    .map(r => `<tr><th>${esc(r.label)}</th><td>${esc(r.value)}</td></tr>`)
    .join('');
  const sectionsHtml = buildSections().map(s => `
    <div class="section-title">${esc(s.title)}</div>
    <table class="data">
      <thead><tr>${s.head.map(h => `<th>${esc(h)}</th>`).join('')}</tr></thead>
      <tbody>${s.rows.map(r => `<tr>${r.map(v => `<td>${esc(v)}</td>`).join('')}</tr>`).join('')}</tbody>
    </table>`).join('');

  w.document.write(`<!doctype html><html><head>
    <title>${esc(p.name)}</title>
    <style>
      @page { size: A4 portrait; margin: 12mm; }
      * { box-sizing: border-box; }
      body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 12px; }
      .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;
              border-bottom: 3px solid #6d28d9; padding-bottom: 12px; margin-bottom: 14px; }
      .title { font-size: 22px; font-weight: 700; margin: 0; }
      .code { color: #6b7280; margin-top: 2px; font-size: 13px; }
      .barcode-box { text-align: center; flex: none; }
      .media { display: flex; gap: 16px; align-items: flex-start; margin-bottom: 14px; }
      .photo { width: 110px; height: 110px; object-fit: contain; border: 1px solid #e5e7eb; border-radius: 8px; }
      table.info { border-collapse: collapse; width: 100%; }
      table.info th, table.info td { border: 1px solid #e5e7eb; padding: 5px 8px; text-align: left; }
      table.info th { background: #f5f3ff; width: 32%; font-weight: 600; }
      .section-title { font-size: 13px; font-weight: 700; color: #6d28d9; margin: 16px 0 6px; }
      table.data { border-collapse: collapse; width: 100%; }
      table.data th, table.data td { border: 1px solid #e5e7eb; padding: 5px 8px; text-align: left; }
      table.data th { background: #6d28d9; color: #fff; font-weight: 600; }
      table.data tr:nth-child(even) td { background: #fafafa; }
    </style></head><body>
    <div class="head">
      <div>
        <div class="title">${esc(p.name)}</div>
        <div class="code">${esc(p.code)}${p.gtin ? ' &nbsp;·&nbsp; ' + esc(p.gtin) : ''}</div>
      </div>
      <div class="barcode-box">${barcodeHtml}</div>
    </div>
    <div class="media">
      ${imgHtml}
      <table class="info"><tbody>${infoHtml}</tbody></table>
    </div>
    ${sectionsHtml}
    </body></html>`);
  w.document.close();
  w.focus();
  // Give the product image time to load before the dialog opens.
  setTimeout(() => { w.print(); }, 600);
}

// ---- PDF export ----
function svgToPng(svg) {
  return new Promise((resolve) => {
    try {
      const xml = new XMLSerializer().serializeToString(svg);
      const img = new Image();
      img.onload = () => {
        const wPx = img.width || 300;
        const hPx = img.height || 100;
        const canvas = document.createElement('canvas');
        canvas.width = wPx * 2;
        canvas.height = hPx * 2;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        resolve({ url: canvas.toDataURL('image/png'), w: wPx, h: hPx });
      };
      img.onerror = () => resolve(null);
      img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(xml);
    } catch (e) {
      resolve(null);
    }
  });
}

function imageToPng(src) {
  return new Promise((resolve) => {
    if (!src || src.endsWith('no-image.png')) return resolve(null);
    const img = new Image();
    img.onload = () => {
      try {
        const canvas = document.createElement('canvas');
        canvas.width = img.naturalWidth;
        canvas.height = img.naturalHeight;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0);
        resolve({ url: canvas.toDataURL('image/jpeg', 0.9), w: img.naturalWidth, h: img.naturalHeight });
      } catch (e) {
        resolve(null);
      }
    };
    img.onerror = () => resolve(null);
    img.src = src;
  });
}

async function exportPdfSheet() {
  if (pdfExporting.value) return;
  pdfExporting.value = true;
  try {
    const [{ jsPDF }, autoTableMod] = await Promise.all([
      import('jspdf'),
      import('jspdf-autotable'),
    ]);
    const autoTable = autoTableMod.default || autoTableMod.autoTable;
    const p = product.value;
    const doc = new jsPDF({ orientation: 'portrait' });
    const pageW = doc.internal.pageSize.getWidth();
    const pageH = doc.internal.pageSize.getHeight();
    const PURPLE = [109, 40, 217];

    doc.setFontSize(16);
    doc.text(String(p.name || ''), 14, 18);
    doc.setFontSize(10);
    doc.setTextColor(120);
    doc.text(String(p.code || '') + (p.gtin ? `  ·  ${p.gtin}` : ''), 14, 24);
    doc.setTextColor(0);
    doc.setDrawColor(...PURPLE);
    doc.setLineWidth(0.8);
    doc.line(14, 27, pageW - 14, 27);

    let y = 32;
    const [photo, bc] = await Promise.all([
      imageToPng(mainImage.value),
      barcodeWrap.value?.querySelector('svg') ? svgToPng(barcodeWrap.value.querySelector('svg')) : Promise.resolve(null),
    ]);
    let mediaBottom = y;
    if (photo) {
      const box = 42;
      const ratio = Math.min(box / photo.w, box / photo.h);
      doc.addImage(photo.url, 'JPEG', 14, y, photo.w * ratio, photo.h * ratio);
      mediaBottom = Math.max(mediaBottom, y + photo.h * ratio);
    }
    if (bc) {
      const bw = 70;
      const bh = Math.min(bw * (bc.h / bc.w), 35);
      doc.addImage(bc.url, 'PNG', pageW - 14 - bw, y, bw, bh);
      mediaBottom = Math.max(mediaBottom, y + bh);
    }
    y = (photo || bc) ? mediaBottom + 6 : y;

    autoTable(doc, {
      body: infoRows.value.map(r => [r.label, String(r.value)]),
      startY: y,
      styles: { fontSize: 9 },
      columnStyles: { 0: { fontStyle: 'bold', cellWidth: 60, fillColor: [245, 243, 255] } },
    });

    for (const s of buildSections()) {
      let sy = (doc.lastAutoTable?.finalY || y) + 9;
      if (sy > pageH - 25) {
        doc.addPage();
        sy = 18;
      }
      doc.setFontSize(11);
      doc.setTextColor(...PURPLE);
      doc.text(String(s.title), 14, sy);
      doc.setTextColor(0);
      autoTable(doc, {
        head: [s.head.map(h => String(h))],
        body: s.rows.map(r => r.map(v => String(v ?? ''))),
        startY: sy + 2,
        styles: { fontSize: 8 },
        headStyles: { fillColor: PURPLE },
      });
    }

    doc.save(`${String(p.code || 'product').replace(/[^\w-]+/g, '_')}_details.pdf`);
  } catch (e) {
    message.error(t('Error'));
  } finally {
    pdfExporting.value = false;
  }
}

const batchColumns = computed(() => [
  { title: t('Batch_No'), dataIndex: 'batch_no', key: 'batch_no' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Quantity'), dataIndex: 'qty', key: 'qty', align: 'right' },
  { title: t('Mfg_Date'), dataIndex: 'mfg_date', key: 'mfg_date' },
  { title: t('Expiry_Date'), key: 'expiry' },
]);

function expiryColor(b) {
  const d = Number(b.days_to_expiry);
  if (!Number.isFinite(d)) return undefined;
  if (d < 0) return '#ff4d4f';
  if (d <= expiryWarningDays.value) return '#faad14';
  return undefined;
}

async function loadBatches() {
  batchesLoading.value = true;
  try {
    const data = await http.get('product_batches', {
      product_id: route.params.id,
      limit: 200,
      SortField: 'expiry_date',
      SortType: 'asc',
    });
    batches.value = data.batches || [];
    if (Number.isFinite(Number(data.expiry_warning_days))) {
      expiryWarningDays.value = Number(data.expiry_warning_days);
    }
  } catch (e) {
    batches.value = [];
  } finally {
    batchesLoading.value = false;
  }
}

onMounted(async () => {
  try {
    product.value = await http.get(`get_product_detail_api/${route.params.id}`);
    if (product.value.is_batch_tracked) loadBatches();
    loadQuestions(product.value.id);
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/products');
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.pd-q {
  padding: 12px 0;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
.pd-q:last-child {
  border-bottom: 0;
}
.pd-q-head,
.pd-q-answer {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}
.pd-q-answer {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px dashed rgba(5, 5, 5, 0.08);
}
.pd-q-mark {
  flex: none;
  width: 18px;
  font-weight: 700;
  color: #6c5ce7;
}
.pd-q-answer .pd-q-mark {
  color: rgba(0, 0, 0, 0.35);
}
.pd-q-body {
  flex: 1;
  min-width: 0;
}
.pd-q-form {
  margin-top: 8px;
  padding-inline-start: 26px;
}
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}

/* ---------------- Hero ---------------- */
.pd-hero {
  display: grid;
  grid-template-columns: 280px 1fr;
  gap: 24px;
}
.pd-media {
  min-width: 0;
}
.pd-img-wrap {
  border: 1px solid rgba(128, 128, 128, 0.18);
  border-radius: 12px;
  background: rgba(128, 128, 128, 0.05);
  aspect-ratio: 1 / 1;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.pd-img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}
/* ---------------- Carousel ---------------- */
.pd-carousel :deep(.slick-slide) {
  pointer-events: auto; /* keep image right-click/drag behavior sane */
}
.pd-carousel :deep(.slick-dots li button) {
  background: #6d28d9;
}
.pd-carousel :deep(.slick-dots-bottom) {
  bottom: 8px;
}
.pd-arrow {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  z-index: 2;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  display: flex !important;
  align-items: center;
  justify-content: center;
  background: rgba(31, 31, 44, 0.45);
  color: #fff !important;
  font-size: 13px;
  cursor: pointer;
  transition: background 0.15s;
}
.pd-arrow:hover {
  background: rgba(31, 31, 44, 0.7);
}
.pd-arrow-left {
  left: 8px;
}
.pd-arrow-right {
  right: 8px;
}
/* Hide slick's default ::before arrow glyphs; our icons replace them. */
.pd-arrow::before {
  content: none;
}

.pd-thumbs {
  display: flex;
  gap: 8px;
  margin-top: 10px;
  flex-wrap: wrap;
}
.pd-thumb {
  width: 52px;
  height: 52px;
  object-fit: cover;
  border-radius: 8px;
  border: 2px solid transparent;
  cursor: pointer;
  opacity: 0.7;
  transition: opacity 0.15s, border-color 0.15s;
}
.pd-thumb:hover {
  opacity: 1;
}
.pd-thumb.active {
  border-color: #6d28d9;
  opacity: 1;
}
.pd-info {
  min-width: 0;
}
.pd-title {
  font-size: 22px;
  font-weight: 700;
  line-height: 1.2;
}
.pd-code {
  opacity: 0.55;
  font-size: 13px;
  margin-top: 2px;
}
.pd-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin: 14px 0 4px;
}

/* ---------------- Stat tiles ---------------- */
.pd-stats {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 12px;
  margin-top: 18px;
}
.pd-stat {
  display: flex;
  align-items: center;
  gap: 10px;
  border: 1px solid rgba(128, 128, 128, 0.18);
  border-radius: 10px;
  padding: 10px 12px;
}
.pd-stat-icon {
  width: 38px;
  height: 38px;
  border-radius: 9px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex: none;
}
.pd-stat-meta {
  min-width: 0;
}
.pd-stat-label {
  opacity: 0.6;
  font-size: 12px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.pd-stat-value {
  font-size: 18px;
  font-weight: 700;
  white-space: nowrap;
}
.pd-note {
  margin-top: 16px;
  padding: 10px 12px;
  border-radius: 8px;
  background: rgba(128, 128, 128, 0.08);
  opacity: 0.85;
  font-size: 13px;
}

/* ---------------- Barcode card ---------------- */
/* The barcode stays on a white chip in both themes — it is a print artifact,
   and dark-mode-inverted bars would not scan. */
.pd-barcode {
  display: flex;
  justify-content: center;
  background: #fff;
  border: 1px solid rgba(128, 128, 128, 0.18);
  border-radius: 10px;
  padding: 14px 10px;
  overflow-x: auto;
}
.pd-barcode-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 10px;
}
.pd-gtin {
  font-size: 12px;
  opacity: 0.65;
}

@media (max-width: 767px) {
  .pd-hero {
    grid-template-columns: 1fr;
    gap: 16px;
  }
  .pd-img-wrap {
    max-width: 260px;
    margin: 0 auto;
  }
  .pd-title {
    font-size: 19px;
  }
}
</style>
