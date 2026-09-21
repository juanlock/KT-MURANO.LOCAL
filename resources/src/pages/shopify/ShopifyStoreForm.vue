<template>
  <a-modal
    :open="open"
    :title="isEdit ? `Edit ${store.name}` : 'Connect a Shopify store'"
    :width="720"
    :confirm-loading="saving"
    :ok-text="isEdit ? $t('submit') : 'Connect'"
    :cancel-text="$t('Cancel')"
    @ok="submit"
    @cancel="close"
  >
    <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-tabs v-model:activeKey="tab">
        <!-- ------------------------------------------------ connection -->
        <a-tab-pane key="connection" tab="Connection">
          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item label="Name *" name="name" extra="How this shop is labelled in the ERP">
                <a-input v-model:value="form.name" placeholder="e.g. Main storefront" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Shop domain *" name="shop_domain" extra="acme.myshopify.com — the admin URL works too">
                <a-input
                  v-model:value="form.shop_domain" placeholder="acme.myshopify.com"
                  :disabled="isEdit && !allowRebind" allow-clear
                />
              </a-form-item>
            </a-col>
            <a-col :span="24">
              <a-form-item
                :label="isEdit ? 'Admin API access token' : 'Admin API access token *'"
                name="access_token"
                :extra="isEdit
                  ? 'Leave blank to keep the token already stored.'
                  : 'From your custom app in Shopify admin → Apps → Develop apps → API credentials.'"
              >
                <a-input-password
                  v-model:value="form.access_token"
                  :placeholder="isEdit && store.has_token ? '•••••••••••••• (unchanged)' : 'shpat_…'"
                  autocomplete="new-password"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="API version" name="api_version">
                <a-input v-model:value="form.api_version" placeholder="2024-10" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item
                label="Webhook signing secret" name="webhook_secret"
                extra="Required before webhooks are accepted — unsigned deliveries are refused."
              >
                <a-input-password
                  v-model:value="form.webhook_secret"
                  :placeholder="store?.has_webhook_secret ? '•••••••••••••• (unchanged)' : 'Optional'"
                  autocomplete="new-password"
                />
              </a-form-item>
            </a-col>

            <a-col v-if="isEdit && !allowRebind" :span="24">
              <a-alert type="info" show-icon banner style="margin-bottom: 12px">
                <template #message>
                  The domain is locked because changing it invalidates every mapping for this shop.
                  <a class="link" @click="allowRebind = true">Change it anyway</a>
                </template>
              </a-alert>
            </a-col>
            <a-col v-else-if="isEdit && allowRebind" :span="24">
              <a-alert
                type="warning" show-icon banner style="margin-bottom: 12px"
                message="Saving a different domain deletes all record mappings for this connection — the stored ids belong to the old shop."
              />
            </a-col>
          </a-row>
        </a-tab-pane>

        <!-- ------------------------------------------------ targets -->
        <a-tab-pane key="targets" tab="Where data lands">
          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item
                label="ERP warehouse" name="warehouse_id"
                extra="Stock levels and imported orders belong to this warehouse."
              >
                <a-select
                  v-model:value="form.warehouse_id" show-search option-filter-prop="label"
                  :options="warehouseOptions" allow-clear placeholder="Select a warehouse"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Shopify location" name="location_id">
                <a-select
                  v-model:value="form.location_id" :options="locationOptions" allow-clear
                  :loading="loadingLocations"
                  :placeholder="isEdit ? 'Load locations from Shopify' : 'Available after connecting'"
                  :disabled="!isEdit"
                />
                <a-button
                  v-if="isEdit" type="link" size="small" style="padding: 0; margin-top: 4px"
                  :loading="loadingLocations" @click="loadLocations"
                >
                  Fetch locations from Shopify
                </a-button>
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Price sent to Shopify" name="price_field">
                <a-select v-model:value="form.price_field" :options="PRICE_FIELDS" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Auto sync every (minutes)" name="sync_interval_minutes">
                <a-input-number
                  v-model:value="form.sync_interval_minutes" :min="5" :max="1440"
                  style="width: 100%" :disabled="!form.auto_sync"
                />
              </a-form-item>
            </a-col>
            <a-col :span="24">
              <a-form-item style="margin-bottom: 8px">
                <a-checkbox v-model:checked="form.auto_sync">
                  Sync automatically on a schedule
                </a-checkbox>
                <div class="hint">Needs Laravel's scheduler running; syncs can always be started by hand.</div>
              </a-form-item>
              <a-form-item style="margin-bottom: 8px">
                <a-checkbox v-model:checked="form.create_missing_products">
                  Create ERP products for Shopify products that do not match a SKU
                </a-checkbox>
              </a-form-item>
              <a-form-item style="margin-bottom: 0">
                <a-checkbox v-model:checked="form.create_missing_customers">
                  Create ERP customers for Shopify customers that do not match an email
                </a-checkbox>
                <div class="hint">With this off, an order from an unknown customer is skipped rather than guessed at.</div>
              </a-form-item>
            </a-col>
          </a-row>
        </a-tab-pane>

        <!-- ------------------------------------------------ entities -->
        <a-tab-pane key="entities" tab="What to sync">
          <p class="lead">
            Only the entities switched on here can be synced — a shop you just pull orders from
            should not have a stray product push rewriting its catalogue.
          </p>
          <div class="ent-grid">
            <label v-for="entity in ENTITIES" :key="entity.value" class="ent-card">
              <a-checkbox v-model:checked="form[`sync_${entity.value}`]" />
              <span class="ent-body">
                <span class="ent-title">
                  <component :is="entity.icon" :size="15" />
                  {{ entity.label }}
                </span>
                <span class="ent-hint">{{ entity.hint }}</span>
                <span class="ent-dirs">
                  <a-tag v-for="d in entity.directions" :key="d" :color="optionOf(DIRECTIONS, d).color" class="dir-tag">
                    {{ labelOf(DIRECTIONS, d) }}
                  </a-tag>
                </span>
              </span>
            </label>
          </div>
        </a-tab-pane>
      </a-tabs>
    </a-form>
  </a-modal>
</template>

<script setup>
/**
 * Connect or edit one shop.
 *
 * The token field is write-only: an existing store shows a masked placeholder
 * and an empty submit keeps whatever is stored. The API never returns the token,
 * so there is nothing here that could leak it back out.
 */
import { ref, reactive, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { ENTITIES, PRICE_FIELDS, DIRECTIONS, labelOf, optionOf } from './shopifyOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const props = defineProps({
  open: { type: Boolean, default: false },
  store: { type: Object, default: null },
  warehouses: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:open', 'saved']);

const formRef = ref();
const tab = ref('connection');
const saving = ref(false);
const allowRebind = ref(false);

const isEdit = computed(() => !!props.store?.id);
const warehouseOptions = computed(() => props.warehouses.map(w => ({ value: w.id, label: w.name })));

const locations = ref([]);
const loadingLocations = ref(false);
const locationOptions = computed(() => locations.value.map(l => ({
  value: l.id,
  label: `${l.name}${l.city ? ' — ' + l.city : ''}${l.active ? '' : ' (inactive)'}`,
})));

const form = reactive(emptyForm());

function emptyForm() {
  return {
    name: '',
    shop_domain: '',
    access_token: '',
    webhook_secret: '',
    api_version: '2024-10',
    warehouse_id: undefined,
    location_id: undefined,
    price_field: 'price',
    auto_sync: false,
    sync_interval_minutes: 60,
    create_missing_products: true,
    create_missing_customers: true,
    sync_products: true,
    sync_inventory: true,
    sync_customers: true,
    sync_orders: true,
    sync_collections: true,
    sync_fulfillments: true,
  };
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  name: required(),
  shop_domain: required(),
  // Only mandatory when connecting: on edit, blank means "keep the stored one".
  access_token: isEdit.value ? [] : required(),
}));

watch(() => props.open, (isOpen) => {
  if (!isOpen) return;

  tab.value = 'connection';
  allowRebind.value = false;
  locations.value = [];
  Object.assign(form, emptyForm());

  if (props.store) {
    Object.keys(form).forEach((key) => {
      if (props.store[key] !== undefined && props.store[key] !== null) form[key] = props.store[key];
    });
    // Never prefill secrets — they are not returned by the API anyway.
    form.access_token = '';
    form.webhook_secret = '';
    if (props.store.location_id) {
      locations.value = [{ id: props.store.location_id, name: `Location ${props.store.location_id}`, active: true }];
    }
  }

  formRef.value?.clearValidate?.();
});

async function loadLocations() {
  if (!isEdit.value) return;
  loadingLocations.value = true;
  try {
    const res = await http.get(`shopify/stores/${props.store.id}/locations`);
    locations.value = res?.locations || [];
    if (!locations.value.length) message.info('That shop reports no locations');
  } catch (e) {
    message.error(e?.data?.error || 'Could not load locations — check the connection first');
  } finally {
    loadingLocations.value = false;
  }
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    tab.value = 'connection';   // the required fields all live there
    return;
  }

  saving.value = true;
  try {
    const payload = { ...form };
    if (isEdit.value && !payload.access_token) delete payload.access_token;
    if (!payload.webhook_secret) delete payload.webhook_secret;

    if (isEdit.value) await http.put(`shopify/stores/${props.store.id}`, payload);
    else await http.post('shopify/stores', payload);

    message.success(isEdit.value ? 'Store updated' : 'Store connected');
    emit('saved');
  } catch (e) {
    message.error(firstError(e) || 'Could not save this store');
  } finally {
    saving.value = false;
  }
}

function firstError(e) {
  const errors = e?.data?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first.length) return first[0];
  }
  return e?.data?.message || '';
}

function close() {
  emit('update:open', false);
}
</script>

<style scoped>
.lead {
  margin-bottom: 14px;
  font-size: 13px;
  opacity: 0.7;
}
.hint {
  font-size: 11.5px;
  opacity: 0.55;
  margin-top: 2px;
}
.link {
  color: #5f9e3f;
  cursor: pointer;
}
.ent-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 10px;
}
.ent-card {
  display: flex;
  gap: 10px;
  padding: 12px 14px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 12px;
  cursor: pointer;
}
.ent-card:hover {
  border-color: rgba(95, 158, 63, 0.5);
}
.ent-body {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}
.ent-title {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 500;
  font-size: 13.5px;
}
.ent-hint {
  font-size: 11.5px;
  opacity: 0.6;
}
.ent-dirs {
  margin-top: 3px;
}
.dir-tag {
  font-size: 10.5px;
  line-height: 17px;
  margin-inline-end: 3px;
}
</style>
