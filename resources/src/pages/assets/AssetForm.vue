<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('Add_Asset')"
      :breadcrumb="[$t('Assets'), isEdit ? $t('Edit') : $t('Add_Asset')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else>
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Tag')" name="tag">
              <a-input v-model:value="form.tag" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Name')" name="name">
              <a-input v-model:value="form.name" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Category')" name="asset_category_id">
              <a-select v-model:value="form.asset_category_id" show-search option-filter-prop="label" :options="categoryOptions" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Warehouse')" name="warehouse_id">
              <a-select v-model:value="form.warehouse_id" show-search option-filter-prop="label" :options="warehouseOptions" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Serial')" name="serial_number">
              <a-input v-model:value="form.serial_number" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Status')" name="status">
              <a-select v-model:value="form.status" :options="statusOptions" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Purchase_Date')" name="purchase_date">
              <a-date-picker v-model:value="form.purchase_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Purchase_Cost')" name="purchase_cost">
              <a-input-number v-model:value="form.purchase_cost" style="width: 100%" :min="0" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Supplier" name="supplier">
              <a-input v-model:value="form.supplier" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Warranty expiry" name="warranty_expiry">
              <a-date-picker v-model:value="form.warranty_expiry" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Last_Verification')" name="last_verification">
              <a-date-picker v-model:value="form.last_verification" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Next_Validation')" name="next_validation">
              <a-date-picker v-model:value="form.next_validation" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>

          <a-col :span="24">
            <a-divider orientation="left" style="margin: 4px 0 12px">Depreciation</a-divider>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="Method" name="depreciation_method" :extra="methodHint">
              <a-select v-model:value="form.depreciation_method" :options="methodOptions" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item
              label="Useful life (months)" name="useful_life_months"
              :extra="depreciates ? 'e.g. 36 for a three-year life' : 'Not used with this method'"
            >
              <a-input-number
                v-model:value="form.useful_life_months" :min="1" :max="1200"
                style="width: 100%" :disabled="!depreciates"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item
              label="Salvage value" name="salvage_value"
              extra="What it is expected to be worth at the end"
            >
              <a-input-number
                v-model:value="form.salvage_value" :min="0" style="width: 100%"
                :disabled="!depreciates"
              />
            </a-form-item>
          </a-col>
          <a-col v-if="depreciates && form.useful_life_months" :span="24">
            <a-alert type="info" show-icon banner style="margin-bottom: 16px" :message="depreciationPreview" />
          </a-col>

          <a-col :span="24">
            <a-form-item :label="$t('Description')" name="description">
              <a-textarea v-model:value="form.description" :rows="3" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-space style="margin-top: 8px">
          <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
          <a-button @click="$router.push('/assets')">{{ $t('Cancel') }}</a-button>
        </a-space>
      </a-form>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Create: bootstrap GET assets/create → {asset_categories, warehouses};
 * POST assets (JSON form). Edit: GET assets/{id}/edit → {asset,
 * asset_categories, warehouses}; PUT assets/{id}. Date fields are plain
 * YYYY-MM-DD strings (value-format keeps them as strings end to end).
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import { DEPRECIATION_METHODS } from './assetOptions';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const submitting = ref(false);
const formRef = ref();
const categories = ref([]);
const warehouses = ref([]);

const form = ref({
  tag: '', name: '', asset_category_id: undefined, warehouse_id: undefined,
  serial_number: '', status: 'in_use', purchase_date: null, purchase_cost: 0,
  supplier: '', warranty_expiry: null,
  depreciation_method: 'none', useful_life_months: null, salvage_value: 0,
  last_verification: null, next_validation: null, description: '',
});

const categoryOptions = computed(() => categories.value.map(c => ({ value: c.id, label: c.name })));
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const statusOptions = computed(() => [
  { value: 'in_use', label: t('In_Use') },
  { value: 'maintenance', label: t('Maintenance') },
  { value: 'retired', label: t('Retired') },
]);

const methodOptions = DEPRECIATION_METHODS.map(m => ({ value: m.value, label: m.label }));
const depreciates = computed(() => form.value.depreciation_method && form.value.depreciation_method !== 'none');
const methodHint = computed(
  () => DEPRECIATION_METHODS.find(m => m.value === form.value.depreciation_method)?.hint || '',
);

/**
 * Show what the numbers mean before saving — an amount per month beats an
 * abstract "useful life" when deciding whether 36 is the right figure.
 */
const depreciationPreview = computed(() => {
  const cost = Number(form.value.purchase_cost) || 0;
  const salvage = Number(form.value.salvage_value) || 0;
  const months = Number(form.value.useful_life_months) || 0;
  if (!cost || !months) return '';
  const base = Math.max(0, cost - salvage);
  const years = (months / 12).toFixed(months % 12 === 0 ? 0 : 1);
  if (form.value.depreciation_method === 'declining_balance') {
    return `Declining balance over ${years} year(s): roughly ${((2 / months) * 100).toFixed(2)}% of the remaining value each month, never below the salvage value.`;
  }
  return `Straight line over ${years} year(s): ${(base / months).toFixed(2)} a month until it reaches its salvage value.`;
});

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
  asset_category_id: [{ required: true, message: t('Field_is_required') }],
  warehouse_id: [{ required: true, message: t('Field_is_required') }],
  salvage_value: [{
    validator: (_rule, value) => (Number(value || 0) > Number(form.value.purchase_cost || 0)
      ? Promise.reject(new Error('Salvage value cannot exceed the purchase cost'))
      : Promise.resolve()),
  }],
}));

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  try {
    if (isEdit.value) {
      await http.put(`assets/${id.value}`, form.value);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('assets', form.value);
      message.success(t('Successfully_Created'));
    }
    router.push('/assets');
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

async function bootstrap() {
  loadingRecord.value = true;
  try {
    const data = await http.get(isEdit.value ? `assets/${id.value}/edit` : 'assets/create');
    categories.value = data.asset_categories || [];
    warehouses.value = data.warehouses || [];
    if (isEdit.value && data.asset) {
      for (const k of Object.keys(form.value)) {
        if (data.asset[k] !== undefined && data.asset[k] !== null) form.value[k] = data.asset[k];
      }
    }
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/assets');
  } finally {
    loadingRecord.value = false;
  }
}

onMounted(bootstrap);
</script>
