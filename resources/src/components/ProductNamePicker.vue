<template>
  <!-- Group Name + Name of a product / service, as one helper (2026-10-16):
       1. the Group ("Main service") is free typing with suggestions -- what is
          typed is kept (a search box used to drop it on blur);
       2. "What are you adding?": the group's main item, or a new sub-item
          under it (an existing group can always get a new sub-item);
       3. a preview of the name that will be saved, following the group's own
          naming style ("Group (Sub)" like Ruba, or plain names like New Era),
          plus the items already in that group.
       Two-way: v-model:group / v-model:name; the parent's form validates
       `groupField` / `nameField` as before. -->
  <a-row :gutter="16" class="pnp">
    <a-col :xs="24" :md="halfWidth ? 24 : 12">
      <a-form-item :label="groupLabel" :name="groupField" required>
        <a-auto-complete
          :value="group" :options="groupOptions" allow-clear
          :placeholder="es ? `Escriba o elija ${groupLabel.toLowerCase()}` : `Type or pick a ${groupLabel.toLowerCase()}`"
          :filter-option="(input, opt) => String(opt.value).toLowerCase().includes(String(input).toLowerCase())"
          @update:value="v => { touched = true; emit('update:group', v || ''); }"
        />
        <div class="pnp-tags">
          <a-tag v-if="groupTrim && isNewGroup" color="green">{{ $t('New') }} {{ groupLabel.toLowerCase() }}</a-tag>
          <a-tag v-else-if="groupTrim" color="blue">{{ $t('tx_Existing_4ee0d') }} {{ groupItems.length }} {{ nameLabel.toLowerCase() }}{{ groupItems.length === 1 ? '' : 's' }}</a-tag>
        </div>
      </a-form-item>
    </a-col>

    <a-col :span="24">
      <a-form-item :label="$t('tx_What_are_you_adding_6ae6d')">
        <a-radio-group :value="mode" button-style="solid" @update:value="setMode">
          <a-radio-button value="main">{{ groupLabel }} only</a-radio-button>
          <a-radio-button value="sub">{{ $t('New') }} {{ nameLabel.toLowerCase() }} {{ $t('tx_under_it_673ab') }}</a-radio-button>
          <a-radio-button v-if="allowCustom" value="custom">{{ $t('tx_Other_name_c3107') }}</a-radio-button>
        </a-radio-group>
      </a-form-item>
    </a-col>

    <a-col v-if="mode === 'sub'" :span="24">
      <a-form-item :label="es ? nameLabel : `${nameLabel} name`" :extra="es ? `p. ej. Parcial, Mensual, Grande: va debajo de ${groupLabel.toLowerCase()}.` : `e.g. Partial, Monthly, Large — it goes under the ${groupLabel.toLowerCase()}.`">
        <a-auto-complete
          :value="sub" :options="subOptions" allow-clear
          :placeholder="es ? `Escriba ${nameLabel.toLowerCase()} nuevo` : `Type the new ${nameLabel.toLowerCase()}`"
          :filter-option="(input, opt) => String(opt.value).toLowerCase().includes(String(input).toLowerCase())"
          @update:value="v => { touched = true; sub = v || ''; emitName(); }"
        />
      </a-form-item>
    </a-col>
    <a-col v-else-if="mode === 'custom'" :span="24">
      <a-form-item :label="$t('Name')">
        <a-input :value="custom" @update:value="v => { touched = true; custom = v || ''; emitName(); }" />
      </a-form-item>
    </a-col>

    <!-- Preview: the name that will be saved (+ the parent's `name` validation shows here). -->
    <a-col :span="24">
      <a-form-item :name="nameField" class="pnp-preview-item">
        <div class="pnp-preview" :class="{ 'pnp-preview--warn': exists }">
          <div>
            {{ $t('tx_Will_be_saved_as_a9877') }}
            <strong v-if="name">{{ name }}</strong>
            <span v-else class="pnp-muted">{{ es ? (groupTrim ? `escriba ${nameLabel.toLowerCase()}` : `primero elija ${groupLabel.toLowerCase()}`) : (groupTrim ? `type the ${nameLabel.toLowerCase()} name` : `choose the ${groupLabel.toLowerCase()} first`) }}</span>
          </div>
          <div v-if="exists" class="pnp-warn">
            {{ mode === 'main' ? `This ${groupLabel.toLowerCase()} already has its main item` : 'This name already exists in the group' }} {{ $t('tx_pick_it_from_the_product_search_instead_or_change_the_name_6b36a') }}
          </div>
          <div v-if="groupItems.length" class="pnp-items">
            <span class="pnp-muted">{{ $t('tx_Already_in_this_4e959') }} {{ groupLabel.toLowerCase() }}:</span>
            <a-tag v-for="it in groupItems.slice(0, 14)" :key="it.id" class="pnp-chip">{{ it.label }}</a-tag>
            <span v-if="groupItems.length > 14" class="pnp-muted">+{{ groupItems.length - 14 }} more</span>
          </div>
        </div>
      </a-form-item>
    </a-col>
  </a-row>
</template>

<script setup>
import { computed as esComputed } from 'vue';
import { i18n } from '../i18n';
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { sortByLabel } from '../lib/filterOptions';
const es = esComputed(() => i18n.global.locale.value === 'es');

const props = defineProps({
  group: { type: String, default: '' },
  name: { type: String, default: '' },
  groups: { type: Array, default: () => [] },     // [{ id, name }]
  products: { type: Array, default: () => [] },   // [{ id, name, product_group_id }]
  excludeId: { type: Number, default: null },     // the product being edited
  groupField: { type: String, default: 'group_name' },
  nameField: { type: String, default: 'name' },
  halfWidth: { type: Boolean, default: false },   // stack the two top fields (narrow column)
});
const emit = defineEmits(['update:group', 'update:name']);
const { t } = useI18n();

// The company's own labels (Defaults -> Products), e.g. Ruba "Main Service" / "Service".
const groupLabel = computed(() => t('GroupName'));
const nameLabel = computed(() => t('Name_product'));

const groupTrim = computed(() => String(props.group || '').trim());
const groupOptions = computed(() => sortByLabel(props.groups.map(g => ({ value: g.name, label: g.name }))));
const groupRow = computed(() => props.groups.find(g => g.name.toLowerCase() === groupTrim.value.toLowerCase()) || null);
const isNewGroup = computed(() => !!groupTrim.value && !groupRow.value);

// Naming style: "Group (Item)" when most of the group's items (else the company's) are named that way.
const parensOf = (n, g) => n.startsWith(`${g} (`) && n.endsWith(')');
const rawItems = computed(() => (groupRow.value
  ? props.products.filter(p => p.product_group_id === groupRow.value.id && p.id !== props.excludeId)
  : []));
const usesParens = computed(() => {
  const g = groupRow.value?.name;
  if (g && rawItems.value.length) return rawItems.value.filter(p => parensOf(p.name, g)).length * 2 >= rawItems.value.length;
  const grouped = props.products.filter(p => p.product_group_id);
  const nameOf = id => props.groups.find(x => x.id === id)?.name || '';
  return !!grouped.length && grouped.filter(p => parensOf(p.name, nameOf(p.product_group_id))).length * 2 >= grouped.length;
});
// "Group (Partial)" -> "Partial"; "(Main)" -> "Main"; plain names stay.
const itemPart = n => {
  const g = groupRow.value?.name || groupTrim.value;
  return parensOf(n, g) ? n.slice(g.length + 2, -1) : n;
};
const groupItems = computed(() => sortByLabel(rawItems.value.map(p => ({ id: p.id, label: itemPart(p.name) }))));
const subOptions = computed(() => groupItems.value.filter(i => i.label.toLowerCase() !== 'main').map(i => ({ value: i.label, label: i.label })));

// mode: main (the group's own item) | sub (a new item under it) | custom (a name that fits neither)
const mode = ref('sub');
const sub = ref('');
const custom = ref('');
const allowCustom = ref(false);
// Until the user changes something here, the name only comes in (edit load): it is
// read, never rewritten -- a product isn't renamed just by opening it.
const touched = ref(false);

const computedName = () => {
  const g = groupTrim.value;
  if (mode.value === 'custom') return custom.value.trim();
  if (!g) return '';
  if (mode.value === 'main') return usesParens.value ? `${g} (Main)` : g;
  const s = sub.value.trim();
  if (!s) return '';
  return usesParens.value ? `${g} (${s})` : s;
};
let lastEmitted = null;
function emitName() {
  const n = computedName();
  lastEmitted = n;
  if (n !== props.name) emit('update:name', n);
}
function setMode(m) { touched.value = true; mode.value = m; emitName(); }

const exists = computed(() => {
  const n = (props.name || '').trim().toLowerCase();
  return !!n && rawItems.value.some(p => p.name.toLowerCase() === n);
});

// A name coming from outside (edit load, form reset): work out the mode from it.
function parse() {
  const n = String(props.name || '');
  const g = groupTrim.value;
  allowCustom.value = false;
  if (!n) { mode.value = mode.value === 'custom' ? 'sub' : mode.value; sub.value = ''; custom.value = ''; return; }
  if (g && (n === `${g} (Main)` || n === g)) { mode.value = 'main'; return; }
  if (g && parensOf(n, g)) { mode.value = 'sub'; sub.value = n.slice(g.length + 2, -1); return; }
  if (!usesParens.value) { mode.value = 'sub'; sub.value = n; return; }
  allowCustom.value = true;
  mode.value = 'custom';
  custom.value = n;
}
watch(() => props.name, n => { if (n !== lastEmitted) { touched.value = false; parse(); } }, { immediate: true });
// Another group chosen by the user: rebuild the name. Lists loading (group style
// now known) before any change: only re-read the incoming name.
watch([groupTrim, usesParens], () => {
  if (!touched.value) parse();
  else if (mode.value !== 'custom') emitName();
});
</script>

<style scoped>
.pnp-tags { margin-top: 4px; min-height: 22px; }
.pnp-preview-item { margin-bottom: 12px; }
.pnp-preview {
  padding: 10px 12px; border-radius: 6px; font-size: 13px;
  background: rgba(22, 119, 255, 0.06); border-left: 3px solid #1677ff;
}
.pnp-preview--warn { background: rgba(250, 173, 20, 0.08); border-left-color: #faad14; }
.pnp-warn { color: #d48806; margin-top: 4px; }
.pnp-items { margin-top: 6px; display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.pnp-chip { margin: 0; }
.pnp-muted { opacity: 0.6; }
</style>
