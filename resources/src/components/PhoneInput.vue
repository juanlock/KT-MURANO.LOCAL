<template>
  <a-input-group compact class="phone-input" :size="size">
    <a-select
      :value="code" show-search option-filter-prop="label" option-label-prop="short" :options="codeOptions" :dropdown-match-select-width="false"
      class="ph-code" :size="size" :disabled="disabled" @change="onCode" @focus="ensureLoaded"
    >
      <template #option="{ label, flag }"><span class="opt">{{ flag }} {{ label }}</span></template>
    </a-select>
    <a-input
      :value="number" class="ph-num" :size="size" :disabled="disabled" :placeholder="placeholder || tr('Phone number', 'Número de teléfono')"
      inputmode="tel" allow-clear @update:value="onNumber" @blur="$emit('blur')"
    />
  </a-input-group>
</template>

<script setup>
/**
 * Phone with its country calling code (user's rule, 2026-11-21: "el teléfono va con el código del país de la empresa
 * seleccionado por defecto, en todo el sistema"). v-model = one string: "+51 987654321". A number saved without a code
 * shows the company's code (auth.user.company_country) and gets it on the next save; `country` (an English name, e.g.
 * the form's own country field) changes the default code while the number has none of its own.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useCountries } from '../composables/useCountries';
import { useAuthStore } from '../stores/auth';
import { useTr } from '../lib/useTr';

const props = defineProps({
  value: { type: String, default: '' },
  country: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  size: { type: String, default: undefined },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:value', 'change', 'blur']);
const tr = useTr();
const auth = useAuthStore();
const { countries, ensureLoaded } = useCountries();

const flag = iso2 => (iso2 || '').toUpperCase().replace(/./g, ch => String.fromCodePoint(127397 + ch.charCodeAt(0)));
// One option per calling code + country (the US and Canada share +1).
const codeOptions = computed(() => countries.value.filter(c => c.calling_code)
  .map(c => ({ value: `${c.iso2}:${c.calling_code}`, label: `+${c.calling_code} ${c.name}`, short: `+${c.calling_code}`, flag: flag(c.iso2) }))
  .sort((a, b) => a.label.localeCompare(b.label, undefined, { numeric: true })));

const code = ref(undefined); // "PE:51"
const number = ref('');
let touchedCode = false;

const dial = c => (c ? String(c).split(':')[1] : '');
function defaultCode() {
  const byName = name => countries.value.find(c => c.name === name && c.calling_code);
  const c = (props.country && byName(props.country)) || (auth.user?.company_country?.iso2
    && countries.value.find(x => x.iso2 === auth.user.company_country.iso2 && x.calling_code));
  return c ? `${c.iso2}:${c.calling_code}` : undefined;
}

// "+51 987 654 321" -> code + number; the longest calling code that matches wins.
function parse(v) {
  const s = String(v || '').trim();
  if (!s.startsWith('+')) return { code: null, number: s };
  const digits = s.slice(1).replace(/[^\d]/g, '');
  const byLen = countries.value.filter(c => c.calling_code).sort((a, b) => String(b.calling_code).length - String(a.calling_code).length);
  const own = auth.user?.company_country?.iso2;
  for (const c of byLen) {
    const cc = String(c.calling_code).replace(/[^\d]/g, '');
    if (cc && digits.startsWith(cc)) {
      // Shared codes (+1): prefer the company's country, else the first.
      const same = countries.value.filter(x => String(x.calling_code).replace(/[^\d]/g, '') === cc);
      const pick = same.find(x => x.iso2 === own) || same.find(x => x.iso2 === 'US') || c;
      const rest = s.slice(1).replace(/^\s*/, '');
      const stripped = rest.replace(new RegExp('^' + cc.split('').join('[\\s-]?')), '').trim();
      return { code: `${pick.iso2}:${pick.calling_code}`, number: stripped };
    }
  }
  return { code: null, number: s };
}

function load() {
  const p = parse(props.value);
  number.value = p.number;
  code.value = p.code || (touchedCode && code.value) || defaultCode();
}

function out() {
  const n = (number.value || '').trim();
  return n ? (dial(code.value) ? `+${dial(code.value)} ${n.replace(/^\+/, '')}` : n) : '';
}
function push() {
  const v = out();
  if (v !== (props.value || '')) {
    emit('update:value', v);
    emit('change', v);
  }
}
function onCode(v) { touchedCode = true; code.value = v; push(); }
function onNumber(v) {
  // A full number pasted with its "+code": take the code from it.
  if (String(v || '').trim().startsWith('+')) {
    const p = parse(v);
    if (p.code) { code.value = p.code; touchedCode = true; number.value = p.number; push(); return; }
  }
  number.value = v || '';
  push();
}

watch(() => props.value, v => { if (v !== out()) load(); });
watch(() => props.country, () => {
  if (!touchedCode && !String(props.value || '').trim().startsWith('+')) {
    code.value = defaultCode();
    if (number.value) push();
  }
});
watch(countries, load);
onMounted(async () => { await ensureLoaded(); load(); });
</script>

<style scoped>
.phone-input { display: flex !important; width: 100%; }
.ph-code { width: 84px; flex: none; }
.ph-num { flex: 1; min-width: 0; }
.opt { white-space: nowrap; }
</style>
