<template>
  <!-- Multi-Currency display selector: re-expresses the page's base-currency
       amounts as base × rate (view-only — nothing is stored or refetched).
       Renders nothing when the module is off or only one currency exists. -->
  <a-select
    v-if="visible"
    v-model:value="selectedId"
    :options="options"
    :title="$t('Currency')"
    style="min-width: 92px"
  />
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useAuthStore } from '../stores/auth';
import http from '../lib/http';
import {
  viewCurrencies,
  viewCurrencyId,
  viewBaseCurrencyId,
} from '../lib/viewCurrencyState';

const auth = useAuthStore();

const options = computed(() =>
  viewCurrencies.value.map(c => ({ value: c.id, label: c.code })));

const visible = computed(() => auth.multiCurrencyEnabled && options.value.length > 1);

// The selection is shared module state, so it survives navigation between
// report pages — pick EUR once, every report shows EUR until switched back.
const selectedId = computed({
  get: () => viewCurrencyId.value ?? viewBaseCurrencyId.value,
  set: (v) => { viewCurrencyId.value = v; },
});

let loading = false;
onMounted(async () => {
  if (!auth.multiCurrencyEnabled || viewCurrencies.value.length || loading) return;
  loading = true;
  try {
    const data = await http.get('currencies_list');
    viewCurrencies.value = data?.currencies || [];
    viewBaseCurrencyId.value = data?.default_currency_id ?? auth.defaultCurrencyId ?? null;
  } catch (e) {
    viewCurrencies.value = [];
  } finally {
    loading = false;
  }
});
</script>
