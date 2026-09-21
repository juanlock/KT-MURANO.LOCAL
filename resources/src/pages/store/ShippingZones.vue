<template>
  <div class="page">
    <PageHeader :title="$t('Shipping_Zones')" :breadcrumb="[$t('Store'), $t('Shipping_Zones')]">
      <template #extra>
        <a-button type="primary" @click="openZone()">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add_Zone') }}
        </a-button>
      </template>
    </PageHeader>

    <a-alert type="info" show-icon :message="$t('Shipping_Zones_Help')" style="margin-bottom: 16px" />

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-empty v-if="!zones.length" :description="$t('No_Shipping_Zones')" style="padding: 48px 0" />

      <a-card v-for="zone in zones" :key="zone.id" size="small" style="margin-bottom: 16px">
        <template #title>
          <strong>{{ zone.name }}</strong>
          <a-tag v-if="zone.is_catch_all" color="blue" style="margin-left: 8px">{{ $t('Rest_of_the_world') }}</a-tag>
          <span v-else class="zone-locations">{{ locationSummary(zone) }}</span>
        </template>
        <template #extra>
          <a-space>
            <a-button size="small" @click="openRate(zone)">{{ $t('Add_shipping_rule') }}</a-button>
            <a-button size="small" @click="openZone(zone)">
              <template #icon><EditOutlined /></template>
            </a-button>
            <a-button size="small" danger @click="confirmDeleteZone(zone)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-space>
        </template>

        <a-empty v-if="!zone.rates.length" :description="$t('No_shipping_rules')" style="padding: 12px 0" />
        <div v-for="rate in zone.rates" :key="rate.id" class="rate-row">
          <div class="rate-main">
            <div class="rate-name">
              {{ rate.name }}
              <a-tag v-if="!rate.active">{{ $t('Inactive') }}</a-tag>
            </div>
            <div class="rate-cond">{{ conditionText(rate) }}</div>
          </div>
          <div class="rate-price">{{ money(rate.price) }}</div>
          <a-space>
            <a-button size="small" @click="openRate(zone, rate)">
              <template #icon><EditOutlined /></template>
            </a-button>
            <a-button size="small" danger @click="confirmDeleteRate(zone, rate)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-space>
        </div>
      </a-card>
    </template>

    <!-- Zone -->
    <a-modal v-model:open="zoneOpen" :title="zoneForm.id ? $t('Edit') : $t('Add_Zone')"
             :confirm-loading="saving" width="640px" @ok="saveZone">
      <a-form layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Name') + ' *'">
          <a-input v-model:value="zoneForm.name" />
        </a-form-item>

        <a-form-item :label="$t('Countries')" :extra="$t('Shipping_Zone_Locations_Help')">
          <a-checkbox v-model:checked="zoneForm.catchAll">{{ $t('Rest_of_the_world') }}</a-checkbox>
        </a-form-item>

        <template v-if="!zoneForm.catchAll">
          <div v-for="(loc, i) in zoneForm.locations" :key="'loc' + i" class="loc-row">
            <a-select
              v-model:value="loc.country" allow-clear show-search option-filter-prop="label"
              :placeholder="$t('Choose_Country')" :options="countryOptions" style="flex: 1"
              @change="loc.state = ''"
            />
            <a-select
              v-if="statesFor(loc.country).length"
              v-model:value="loc.state" allow-clear show-search option-filter-prop="label"
              :placeholder="$t('Whole_country')" :options="statesFor(loc.country)" style="flex: 1"
            />
            <a-input v-else v-model:value="loc.state" :placeholder="$t('Whole_country')" style="flex: 1" />
            <a-button danger @click="zoneForm.locations.splice(i, 1)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </div>
          <a-button size="small" @click="zoneForm.locations.push({ country: undefined, state: '' })">
            <template #icon><PlusOutlined /></template>
            {{ $t('Add') }}
          </a-button>
        </template>
      </a-form>
    </a-modal>

    <!-- Rate -->
    <a-modal v-model:open="rateOpen" :title="rateForm.id ? $t('Edit') : $t('Add_shipping_rule')"
             :confirm-loading="saving" width="560px" @ok="saveRate">
      <a-form layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Name_of_shipping_rule') + ' *'">
          <a-input v-model:value="rateForm.name" :placeholder="$t('Free_delivery')" />
        </a-form-item>
        <a-form-item :label="$t('Type') + ' *'">
          <a-select v-model:value="rateForm.rate_type" :options="rateTypeOptions" />
        </a-form-item>

        <a-row v-if="rateForm.rate_type !== 'flat'" :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('From')">
              <a-input-number v-model:value="rateForm.min_value" :min="0" style="width: 100%"
                              :placeholder="$t('No_minimum')" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('To')">
              <a-input-number v-model:value="rateForm.max_value" :min="0" style="width: 100%"
                              :placeholder="$t('No_maximum')" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-form-item :label="$t('Shipping_fee') + ' *'">
          <a-input-number v-model:value="rateForm.price" :min="0" :step="0.01" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Status')" style="margin-bottom: 0">
          <a-switch v-model:checked="rateForm.active" />
          <span style="margin-left: 8px">{{ rateForm.active ? $t('Active') : $t('Inactive') }}</span>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Shipping zones — GET store/shipping-zones → {zones, countries, subdivisions,
 * rate_types}. A zone is a set of destinations; its rates are rows of
 * shipping_methods (so a placed order's shipping_method_id stays valid).
 *
 * A rate is either flat, or conditional on the cart's total price or weight.
 * Zones supersede the old flat method list, but only once one exists.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { money } = useFormat();

const isLoading = ref(true);
const saving = ref(false);
const zones = ref([]);
const countries = ref([]);
const subdivisions = ref({});
const rateTypes = ref(['flat', 'price', 'weight']);

const countryOptions = computed(() =>
  countries.value.map(c => ({ label: c.name, value: c.canonical })));

const rateTypeOptions = computed(() => rateTypes.value.map(v => ({
  value: v,
  label: v === 'flat' ? t('Flat_rate')
    : v === 'price' ? t('Based_on_order_total_amount')
      : t('Based_on_order_total_weight'),
})));

function statesFor(country) {
  const hit = countries.value.find(c => c.canonical === country);
  const rows = (hit && subdivisions.value[hit.code]) || [];
  return rows.map(s => ({ label: s, value: s }));
}

function locationSummary(zone) {
  return (zone.locations || [])
    .map(l => (l.state ? `${l.country} — ${l.state}` : l.country))
    .join(', ');
}

function conditionText(rate) {
  if (rate.rate_type === 'flat') return t('Flat_rate');
  const unit = rate.rate_type === 'weight' ? ' g' : '';
  const from = rate.min_value !== null && rate.min_value !== undefined ? rate.min_value + unit : null;
  const to = rate.max_value !== null && rate.max_value !== undefined ? rate.max_value + unit : null;
  if (from && to) return `${from} – ${to}`;
  if (from) return `${t('Greater_than')} ${from}`;
  if (to) return `${t('Up_to')} ${to}`;
  return t('Any_value');
}

async function fetch() {
  try {
    const r = await http.get('store/shipping-zones');
    zones.value = r.zones || [];
    countries.value = r.countries || [];
    subdivisions.value = r.subdivisions || {};
    if (Array.isArray(r.rate_types) && r.rate_types.length) rateTypes.value = r.rate_types;
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
}

/* ------------------------------------------------------------------ zone */
const zoneOpen = ref(false);
const zoneForm = ref({ id: null, name: '', catchAll: false, locations: [] });

function openZone(zone = null) {
  zoneForm.value = zone
    ? {
      id: zone.id,
      name: zone.name,
      catchAll: zone.is_catch_all,
      locations: (zone.locations || [])
        .filter(l => l.country)
        .map(l => ({ country: l.country, state: l.state || '' })),
    }
    : { id: null, name: '', catchAll: false, locations: [{ country: undefined, state: '' }] };
  zoneOpen.value = true;
}

async function saveZone() {
  if (!zoneForm.value.name) { message.warning(`${t('Name')} *`); return; }
  saving.value = true;
  // The catch-all is one blank location row; otherwise send the chosen ones.
  const locations = zoneForm.value.catchAll
    ? [{ country: '', state: '' }]
    : zoneForm.value.locations.filter(l => l.country).map(l => ({ country: l.country, state: l.state || '' }));
  const payload = { name: zoneForm.value.name, locations };
  try {
    if (zoneForm.value.id) await http.put(`store/shipping-zones/${zoneForm.value.id}`, payload);
    else await http.post('store/shipping-zones', payload);
    message.success(t('Successfully_Updated'));
    zoneOpen.value = false;
    fetch();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

function confirmDeleteZone(zone) {
  Modal.confirm({
    title: t('AreYouSure'),
    content: t('Shipping_Zone_Delete_Warning'),
    okText: t('Yes'), okType: 'danger', cancelText: t('No'),
    async onOk() {
      try {
        await http.delete(`store/shipping-zones/${zone.id}`);
        message.success(t('Deleted_in_successfully'));
        fetch();
      } catch (e) { message.error(t('Failed')); }
    },
  });
}

/* ------------------------------------------------------------------ rate */
const rateOpen = ref(false);
const rateZoneId = ref(null);
const rateForm = ref({});
const emptyRate = () => ({
  id: null, name: '', price: 0, rate_type: 'flat',
  min_value: null, max_value: null, active: true,
});

function openRate(zone, rate = null) {
  rateZoneId.value = zone.id;
  rateForm.value = rate ? { ...emptyRate(), ...rate } : emptyRate();
  rateOpen.value = true;
}

async function saveRate() {
  if (!rateForm.value.name) { message.warning(`${t('Name')} *`); return; }
  saving.value = true;
  const f = rateForm.value;
  const payload = {
    name: f.name,
    price: Number(f.price) || 0,
    rate_type: f.rate_type,
    min_value: f.rate_type === 'flat' ? null : f.min_value,
    max_value: f.rate_type === 'flat' ? null : f.max_value,
    active: f.active ? 1 : 0,
  };
  try {
    if (f.id) await http.put(`store/shipping-zones/${rateZoneId.value}/rates/${f.id}`, payload);
    else await http.post(`store/shipping-zones/${rateZoneId.value}/rates`, payload);
    message.success(t('Successfully_Updated'));
    rateOpen.value = false;
    fetch();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

function confirmDeleteRate(zone, rate) {
  Modal.confirm({
    title: t('AreYouSure'),
    content: rate.name,
    okText: t('Yes'), okType: 'danger', cancelText: t('No'),
    async onOk() {
      try {
        await http.delete(`store/shipping-zones/${zone.id}/rates/${rate.id}`);
        message.success(t('Deleted_in_successfully'));
        fetch();
      } catch (e) { message.error(t('Failed')); }
    },
  });
}

onMounted(fetch);
</script>

<style scoped>
.zone-locations {
  margin-left: 10px;
  font-weight: 400;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}
.rate-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
.rate-row:last-child { border-bottom: 0; }
.rate-main { flex: 1; min-width: 0; }
.rate-name { font-weight: 600; }
.rate-cond { font-size: 12px; color: rgba(0, 0, 0, 0.45); }
.rate-price { font-weight: 600; white-space: nowrap; }
.loc-row {
  display: flex;
  gap: 8px;
  margin-bottom: 8px;
}
</style>
