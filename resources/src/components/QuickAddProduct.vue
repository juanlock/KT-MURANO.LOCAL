<template>
  <a-modal
    :open="open"
    :title="t('Quick_Add_Product')"
    :confirm-loading="submitting"
    :ok-text="t('submit')"
    :cancel-text="t('Cancel')"
    width="640px"
    @ok="submit"
    @cancel="close"
  >
    <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-row :gutter="16">
        <a-col :xs="24" :md="12">
          <a-form-item :label="t('Name_product')" name="name">
            <a-input v-model:value="form.name" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-form-item :label="t('GroupName')" name="group_name">
            <a-select
              v-model:value="form.group_name"
              show-search allow-clear
              option-filter-prop="label"
              :placeholder="t('GroupName_Placeholder')"
              :options="groupNameOptions"
              @search="onGroupNameSearch"
            />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-form-item :label="t('Categorie')" name="category_id">
            <a-select
              v-model:value="form.category_id"
              show-search allow-clear
              option-filter-prop="label"
              :placeholder="t('Choose_Category')"
              :options="categoryOptions"
            />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-form-item :label="t('CodeProduct')" name="code">
            <a-input v-model:value="form.code" />
          </a-form-item>
        </a-col>
        <a-col v-if="isPurchase" :xs="24" :md="12">
          <a-form-item :label="t('Unit')" name="unit_id">
            <a-select
              v-model:value="form.unit_id"
              show-search allow-clear
              option-filter-prop="label"
              :options="unitOptions"
            />
          </a-form-item>
        </a-col>
        <a-col v-if="isPurchase" :xs="24" :md="12">
          <a-form-item :label="t('Cost')" name="cost">
            <a-input-number v-model:value="form.cost" style="width: 100%" :min="0" @change="onCostChange" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-form-item :label="t('Price')" name="price">
            <a-input-number v-model:value="form.price" style="width: 100%" :min="0" @change="onPriceChange" />
          </a-form-item>
        </a-col>
      </a-row>
    </a-form>
  </a-modal>
</template>

<script setup>
/**
 * Quick Add Product — opens next to the product search box on the Sale and
 * Purchase forms so a brand-new product can be created without leaving the
 * page. Only Name / Group Name / Price are genuinely required of the user;
 * Category, Code and (on Purchase) Unit are pre-filled with a sensible
 * default and left editable.
 *
 * Sale context creates an `is_service` product (no stock/unit required,
 * always visible in the sale's product list regardless of stock level).
 * Purchase context creates an `is_single` product (stock-managed, so it
 * needs a Unit and a Cost -- fitting, since a purchase is how it gets its
 * first stock).
 *
 * ProductsController@store only ever answers {success:true} (no created-row
 * payload), so this component hands back the generated `code` on `created`;
 * the parent re-fetches its warehouse-scoped product list and matches on
 * that code to find the row to add to its own lines table.
 */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import http from '../lib/http';
import { useAuthStore } from '../stores/auth';

const { t } = useI18n();
const auth = useAuthStore();

const props = defineProps({
  open: { type: Boolean, default: false },
  context: { type: String, default: 'sale' }, // 'sale' | 'purchase'
});
const emit = defineEmits(['update:open', 'created']);

const isPurchase = computed(() => props.context === 'purchase');

const formRef = ref();
const submitting = ref(false);
const loaded = ref(false);
const categories = ref([]);
const units = ref([]);
const productGroups = ref([]);
const priceTouched = ref(false);

function genCode() {
  return `QA${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`.toUpperCase();
}

const blank = () => ({
  name: '',
  code: genCode(),
  category_id: undefined,
  group_name: undefined,
  unit_id: undefined,
  cost: null,
  price: null,
});
const form = ref(blank());

const groupNameSearch = ref('');
function onGroupNameSearch(val) { groupNameSearch.value = val; }
const groupNameOptions = computed(() => {
  const base = productGroups.value.map(g => ({ value: g.name, label: g.name }));
  const search = (groupNameSearch.value || '').trim();
  const exists = search && base.some(o => o.value.toLowerCase() === search.toLowerCase());
  if (search && !exists) {
    base.push({ value: search, label: `${t('Create_new_group')}: "${search}"` });
  }
  return base;
});

const categoryOptions = computed(() => categories.value.map(c => ({ value: c.id, label: c.name })));
const unitOptions = computed(() => units.value.map(u => ({ value: u.id, label: u.ShortName || u.name })));

function onCostChange(v) {
  if (isPurchase.value && !priceTouched.value) form.value.price = v;
}
function onPriceChange() { priceTouched.value = true; }

const rules = computed(() => {
  const r = {
    name: [{ required: true, message: t('Field_is_required') }],
    group_name: [{ required: true, message: t('Field_is_required') }],
    category_id: [{ required: true, message: t('Field_is_required') }],
    code: [{ required: true, message: t('Field_is_required') }],
    price: [{ required: true, type: 'number', message: t('Field_is_required') }],
  };
  if (isPurchase.value) {
    r.unit_id = [{ required: true, message: t('Field_is_required') }];
    r.cost = [{ required: true, type: 'number', message: t('Field_is_required') }];
  }
  return r;
});

async function loadBootstrap() {
  try {
    const [productData, groupData] = await Promise.all([
      http.get('products/create'),
      http.get('product-groups'),
    ]);
    categories.value = productData?.categories || [];
    units.value = productData?.units || [];
    productGroups.value = groupData?.product_groups || [];
    loaded.value = true;
  } catch (e) {
    // leave dropdowns empty -- the user can still type a Group Name / pick nothing
  }
}

watch(() => props.open, async isOpen => {
  if (!isOpen) return;
  priceTouched.value = false;
  form.value = blank();
  groupNameSearch.value = '';
  formRef.value?.clearValidate?.();
  if (!loaded.value) await loadBootstrap();
  if (!form.value.category_id && categories.value.length) form.value.category_id = categories.value[0].id;
  if (isPurchase.value && !form.value.unit_id && units.value.length) form.value.unit_id = units.value[0].id;
});

function close() {
  emit('update:open', false);
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }

  const body = {
    name: form.value.name,
    code: form.value.code,
    category_id: form.value.category_id,
    group_name: form.value.group_name,
    Type_barcode: 'CODE128',
    tax_method: '1',
    discount_method: '1',
    // System Settings -> Defaults -> Default Tax, so a quickly-added
    // product isn't silently 0% until someone edits it later.
    TaxNet: auth.user?.default_tax || 0,
    discount: 0,
    price: form.value.price,
  };

  if (isPurchase.value) {
    body.type = 'is_single';
    body.unit_id = form.value.unit_id;
    body.cost = form.value.cost;
  } else {
    body.type = 'is_service';
  }

  submitting.value = true;
  try {
    await http.post('products', body);
    emit('created', { code: form.value.code, name: form.value.name });
    message.success(t('Successfully_Created'));
    close();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}
</script>
