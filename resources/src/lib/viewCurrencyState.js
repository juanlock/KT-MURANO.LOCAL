import { ref, computed } from 'vue';

/**
 * Multi-Currency "display currency" — module-level shared state.
 *
 * Reports/dashboard amounts are computed and delivered in the BASE currency;
 * picking a currency here re-expresses them as base × rate at display time
 * (useFormat.money() applies it globally). Nothing is stored or refetched.
 *
 * Kept dependency-free (no stores/http imports) so useFormat can consume it
 * without a circular import; ViewCurrencySelect.vue does the loading.
 */

export const viewCurrencies = ref([]);
export const viewCurrencyId = ref(null); // null = base (feature effectively off)
export const viewBaseCurrencyId = ref(null); // settings.currency_id, set on load

export const viewActiveCurrency = computed(() =>
    viewCurrencies.value.find(c => Number(c.id) === Number(viewCurrencyId.value)) || null);

export const viewIsBase = computed(() =>
    !viewCurrencyId.value
    || Number(viewCurrencyId.value) === Number(viewBaseCurrencyId.value)
    || !viewActiveCurrency.value);

// rate = units of the view currency per 1 base unit (1 when base)
export const viewRate = computed(() =>
    (viewIsBase.value ? 1 : (Number(viewActiveCurrency.value?.exchange_rate) || 1)));

export const viewSymbol = computed(() =>
    (viewIsBase.value ? null : (viewActiveCurrency.value?.symbol || '')));
