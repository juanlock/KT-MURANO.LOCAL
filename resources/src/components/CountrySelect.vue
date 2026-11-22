<template>
  <a-select
    :value="value || undefined" show-search allow-clear option-filter-prop="label" :options="options"
    :placeholder="placeholder || tr('Country', 'País')" :size="size" style="width: 100%"
    @change="v => emitValue(v)" @focus="ensureLoaded"
  />
</template>

<script setup>
/**
 * Country picker (user's rule, 2026-11-21: "el país se elige de una lista, en todo el sistema"). v-model = the
 * country's English name, as every table stores it (companies.country, employees.country...); shown in the screen's
 * language (Intl). Empty on a NEW record = the company's country (auth.user.company_country), unless `no-default`.
 */
import { computed, onMounted, watch } from 'vue';
import { useCountries } from '../composables/useCountries';
import { useAuthStore } from '../stores/auth';
import { useUiStore } from '../stores/ui';
import { useTr } from '../lib/useTr';

const props = defineProps({
  value: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  size: { type: String, default: undefined },
  noDefault: { type: Boolean, default: false },
});
const emit = defineEmits(['update:value', 'change']);
const tr = useTr();
const auth = useAuthStore();
const ui = useUiStore();
const { countries, ensureLoaded } = useCountries();

function localName(c) {
  try {
    return new Intl.DisplayNames([ui.locale || 'en'], { type: 'region' }).of(c.iso2) || c.name;
  } catch (e) {
    return c.name;
  }
}
const options = computed(() => countries.value.map(c => ({ value: c.name, label: localName(c) }))
  .sort((a, b) => a.label.localeCompare(b.label, ui.locale || 'en')));

function emitValue(v) {
  emit('update:value', v || '');
  emit('change', v || '');
}

// A free text saved before the list ("Perú", "USA", an address...): shown as the country it names.
function normalize() {
  if (!props.value || !countries.value.length || countries.value.some(c => c.name === props.value)) return;
  const low = props.value.trim().toLowerCase();
  const hit = countries.value.find(c => c.name.toLowerCase() === low || c.iso2.toLowerCase() === low || localName(c).toLowerCase() === low);
  if (hit) emitValue(hit.name);
}

onMounted(async () => {
  await ensureLoaded();
  normalize();
  const own = auth.user?.company_country?.name;
  if (!props.value && !props.noDefault && own) emitValue(own);
});
watch(() => props.value, normalize);
</script>
