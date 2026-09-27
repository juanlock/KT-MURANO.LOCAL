<template>
  <a-select
    :value="modelValue"
    show-search
    :filter-option="false"
    :options="options"
    :placeholder="placeholder"
    :loading="loading"
    :allow-clear="allowClear"
    :disabled="disabled"
    style="width: 100%"
    @search="onSearch"
    @change="onChange"
    @focus="ensureLoaded"
  >
    <template #notFoundContent>
      <div class="ucp-empty">
        <a-spin v-if="loading" size="small" />
        <span v-else>{{ term ? 'No city matches that.' : 'Type a city name.' }}</span>
      </div>
    </template>
  </a-select>
</template>

<script setup>
/**
 * US city selector, backed by the uscities reference table (31k+ rows --
 * see App\Console\Commands\ImportGeoData). Always searched server-side
 * (GET uscities/search), never loaded as a full dropdown. Selecting a city
 * emits its full row so the caller can also auto-fill State from it.
 *
 * Same shape as pages/hospital/PatientPicker.vue -- mirror that one if this
 * needs the same treatment later.
 */
import { ref, computed, onBeforeUnmount } from 'vue';
import http from '../lib/http';

const props = defineProps({
  modelValue: { type: [Number, String], default: undefined },
  // { id, city, state_id, state_name } for the already-selected city, when editing.
  initialOption: { type: Object, default: null },
  placeholder: { type: String, default: 'Search city' },
  allowClear: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'select']);

const results = ref([]);
const loading = ref(false);
const term = ref('');
const loadedOnce = ref(false);
let timer = null;
// Focus fires an empty-search load and typing fires a debounced one; a
// slower empty-search response landing AFTER a faster typed one would
// otherwise clobber the results the user actually asked for.
let requestSeq = 0;

function labelFor(city) {
  // state_id may be blank for a seeded initialOption built from an existing
  // record's plain text city/state (no real uscities row to look it up from).
  const state = city.state_id || city.state_name || '';
  return state ? `${city.city}, ${state}` : city.city;
}

const options = computed(() => {
  const list = results.value.map(c => ({ value: c.id, label: labelFor(c), city: c }));

  const selected = props.initialOption;
  if (selected?.id && !list.some(o => o.value === selected.id)) {
    list.unshift({ value: selected.id, label: labelFor(selected), city: selected });
  }

  return list;
});

async function fetchCities(q) {
  const seq = ++requestSeq;
  loading.value = true;
  try {
    const data = await http.get('uscities/search', { q });
    if (seq !== requestSeq) return; // a newer request has since started -- discard this one
    results.value = data?.cities || [];
    loadedOnce.value = true;
  } catch (e) {
    if (seq !== requestSeq) return;
    results.value = [];
  } finally {
    if (seq === requestSeq) loading.value = false;
  }
}

function onSearch(value) {
  term.value = value;
  clearTimeout(timer);
  timer = setTimeout(() => fetchCities(value), 300);
}

function ensureLoaded() {
  if (!loadedOnce.value && !loading.value) fetchCities('');
}

function onChange(value, option) {
  emit('update:modelValue', value);
  emit('select', option?.city || null);
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<style scoped>
.ucp-empty {
  padding: 10px 4px;
  text-align: center;
  font-size: 12.5px;
  opacity: 0.6;
}
</style>
