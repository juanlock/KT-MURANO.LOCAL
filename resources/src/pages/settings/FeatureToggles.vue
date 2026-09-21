<template>
  <a-card :loading="loading">
    <a-alert
      type="info"
      show-icon
      :message="$t('Features')"
      description="Turn optional capabilities on or off for the whole system — the same switches as System Settings → Features."
      style="margin-bottom: 16px"
    />

    <div class="ft-toolbar">
      <a-button type="primary" :disabled="!dirty" :loading="saving" @click="save">
        <template #icon><SaveOutlined /></template>
        {{ $t('submit') }}
      </a-button>
    </div>

    <div class="setting-row">
      <div>
        <div class="setting-label">{{ $t('Enable_3_Decimal_Pricing') }}</div>
        <div class="setting-help">{{ $t('Enable_3_Decimal_Pricing_Help') }}</div>
      </div>
      <a-switch v-model:checked="features.enable_3_decimal_pricing" @change="dirty = true" />
    </div>
    <div class="setting-row">
      <div>
        <div class="setting-label">{{ $t('EnableKitchenDisplay') }}</div>
        <div class="setting-help">{{ $t('EnableKitchenDisplay_Help') }}</div>
      </div>
      <a-switch v-model:checked="features.enable_kitchen_display" @change="dirty = true" />
    </div>
    <div v-if="features.enable_kitchen_display" class="setting-row">
      <div>
        <div class="setting-label">{{ $t('KitchenTargetMinutes') }}</div>
        <div class="setting-help">{{ $t('KitchenTargetMinutes_Help') }}</div>
      </div>
      <a-input-number
        v-model:value="features.kitchen_target_minutes"
        :min="1" :max="1440" style="width: 160px"
        @change="dirty = true"
      />
    </div>
    <div v-if="features.enable_kitchen_display" class="setting-row">
      <div>
        <div class="setting-label">{{ $t('KitchenAutoOnlineOrders') }}</div>
        <div class="setting-help">{{ $t('KitchenAutoOnlineOrders_Help') }}</div>
      </div>
      <a-switch v-model:checked="features.kitchen_auto_online_orders" @change="dirty = true" />
    </div>
    <div class="setting-row">
      <div>
        <div class="setting-label">{{ $t('Show_Product_GTIN') }}</div>
        <div class="setting-help">{{ $t('Show_Product_GTIN_Help') }}</div>
      </div>
      <a-switch v-model:checked="features.show_product_gtin" @change="dirty = true" />
    </div>
    <div class="setting-row">
      <div>
        <div class="setting-label">{{ $t('Resize_Product_Images') }}</div>
        <div class="setting-help">{{ $t('Resize_Product_Images_Help') }}</div>
      </div>
      <a-switch v-model:checked="features.product_image_resize" @change="dirty = true" />
    </div>
    <div v-if="features.product_image_resize" class="setting-row">
      <div>
        <div class="setting-label">{{ $t('Product_Image_Max_Size') }}</div>
        <div class="setting-help">{{ $t('Product_Image_Max_Size_Help') }}</div>
      </div>
      <a-input-number
        v-model:value="features.product_image_max_size"
        :min="50" :max="5000" :step="100" addon-after="px" style="width: 160px"
        @change="dirty = true"
      />
    </div>
    <div class="setting-row">
      <div>
        <div class="setting-label">{{ $t('Track_Serial_IMEI') }}</div>
        <div class="setting-help">{{ $t('Track_Serial_IMEI_Hint') }}</div>
      </div>
      <a-switch v-model:checked="features.show_serial_tracking" @change="dirty = true" />
    </div>
    <div class="setting-row">
      <div>
        <div class="setting-label">{{ $t('Enable_Multi_Pack_Selling') }}</div>
        <div class="setting-help">{{ $t('Enable_Multi_Pack_Selling_Hint') }}</div>
      </div>
      <a-switch v-model:checked="features.enable_multi_pack_selling" @change="dirty = true" />
    </div>
    <div class="setting-row">
      <div>
        <div class="setting-label">{{ $t('Enable_Wholesale_Pricing') }}</div>
        <div class="setting-help">{{ $t('Enable_Wholesale_Pricing_Hint') }}</div>
      </div>
      <a-switch v-model:checked="features.enable_wholesale_pricing" @change="dirty = true" />
    </div>
    <div class="setting-row">
      <div>
        <div class="setting-label">{{ $t('Enable_Multi_Currency') }}</div>
        <div class="setting-help">{{ $t('Enable_Multi_Currency_Hint') }}</div>
      </div>
      <a-switch v-model:checked="features.enable_multi_currency" @change="dirty = true" />
    </div>
    <div class="setting-row">
      <div>
        <div class="setting-label">{{ $t('Enable_Pos_Salesperson_Switch') }}</div>
        <div class="setting-help">{{ $t('Enable_Pos_Salesperson_Switch_Hint') }}</div>
      </div>
      <a-switch v-model:checked="features.enable_pos_salesperson_switch" @change="dirty = true" />
    </div>
    <div class="setting-row">
      <div>
        <div class="setting-label">Vehicle Fitment &amp; My Garage</div>
        <div class="setting-help">
          Vehicle selector (Make / Model / Year) with a customer "My Garage" on the
          online store and POS: listings are filtered to compatible parts and
          incompatible parts cannot be purchased. Manage the catalog under
          Products &rarr; Vehicle Fitment.
        </div>
      </div>
      <a-switch v-model:checked="features.vehicle_fitment_enabled" @change="dirty = true" />
    </div>
    <div class="setting-row">
      <div>
        <div class="setting-label">{{ $t('Auto_Journal_Entries') }}</div>
        <div class="setting-help">{{ $t('Auto_Journal_Entries_Hint') }}</div>
      </div>
      <a-switch v-model:checked="features.auto_journal_enabled" @change="dirty = true" />
    </div>
    <div class="setting-row">
      <div>
        <div class="setting-label">Allow overselling</div>
        <div class="setting-help">
          Skip stock checks on all transactions — POS, sales, quotations, transfers,
          adjustments, damages and sales imports — letting quantities exceed the
          available stock (stock can go negative).
        </div>
      </div>
      <a-switch v-model:checked="features.allow_overselling" @change="dirty = true" />
    </div>
  </a-card>
</template>

<script setup>
/**
 * Feature toggles — the "Features" tab of the Modules page.
 *
 * Same optional-capability switches as System Settings → Features, but backed
 * by the dedicated GET/PUT feature_settings endpoint: it reads and writes ONLY
 * these fields, so saving here can't clobber the rest of the settings row the
 * way a partial post to settings/{id} would.
 */
import { ref, reactive, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { SaveOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';

const { t } = useI18n();

const loading = ref(true);
const saving = ref(false);
const dirty = ref(false);

const features = reactive({
  enable_3_decimal_pricing: false,
  enable_kitchen_display: false,
  kitchen_target_minutes: null,
  kitchen_auto_online_orders: false,
  show_product_gtin: true,
  product_image_resize: true,
  product_image_max_size: 800,
  show_serial_tracking: false,
  enable_multi_pack_selling: false,
  enable_wholesale_pricing: false,
  enable_multi_currency: false,
  enable_pos_salesperson_switch: false,
  vehicle_fitment_enabled: false,
  auto_journal_enabled: false,
  allow_overselling: false,
});

async function save() {
  saving.value = true;
  try {
    const payload = {};
    for (const key of Object.keys(features)) {
      if (key === 'product_image_max_size') {
        payload[key] = features.product_image_max_size || 800;
      } else if (key === 'kitchen_target_minutes') {
        // Nullable minutes: empty clears the target (no overdue escalation).
        payload[key] = features.kitchen_target_minutes == null ? '' : features.kitchen_target_minutes;
      } else {
        payload[key] = features[key] ? 1 : 0;
      }
    }
    await http.put('feature_settings', payload);
    dirty.value = false;
    message.success(t('Successfully_Updated'));
    // Same post-save side effect as System Settings: price inputs across the
    // app read their decimal count from this cache.
    try {
      localStorage.setItem('app_price_decimals', features.enable_3_decimal_pricing ? '3' : '2');
    } catch (e) { /* storage unavailable */ }
  } catch (e) {
    message.error(e?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('feature_settings');
    Object.assign(features, data?.features || {});
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.ft-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 4px;
}
.setting-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  padding: 14px 0;
  border-top: 1px solid rgba(128, 128, 128, 0.12);
}
.setting-row:first-of-type {
  border-top: none;
}
.setting-label {
  font-weight: 500;
}
.setting-help {
  font-size: 12px;
  color: #8c8c8c;
  max-width: 520px;
}
</style>
