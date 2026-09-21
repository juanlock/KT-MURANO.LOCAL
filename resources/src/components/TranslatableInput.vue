<template>
  <a-form-item :label="label" :extra="extra" :style="itemStyle">
    <div class="ti-row">
      <a-textarea
        v-if="type === 'textarea'"
        :value="modelValue" :rows="rows" :placeholder="placeholder"
        @update:value="v => $emit('update:modelValue', v)"
      />
      <a-input
        v-else
        :value="modelValue" :placeholder="placeholder"
        @update:value="v => $emit('update:modelValue', v)"
      />
      <a-button
        v-if="localeList.length" :type="open ? 'primary' : 'default'"
        :title="$t('Translations')" @click="open = !open"
      >
        <template #icon><GlobalOutlined /></template>
      </a-button>
    </div>

    <!-- Per-language copy; a blank field falls back to the value above. -->
    <div v-if="open" class="ti-i18n">
      <div v-for="l in localeList" :key="l.code" class="ti-i18n-row">
        <span class="ti-i18n-code">{{ l.label }}</span>
        <a-textarea
          v-if="type === 'textarea'"
          :value="translations[l.code] || ''" :rows="rows" :placeholder="modelValue"
          @update:value="v => set(l.code, v)"
        />
        <a-input
          v-else
          :value="translations[l.code] || ''" :placeholder="modelValue"
          @update:value="v => set(l.code, v)"
        />
      </div>
    </div>
  </a-form-item>
</template>

<script setup>
/**
 * A storefront text field plus its per-locale versions, kept behind a toggle so
 * the form stays readable. `translations` is a {locale: text} map owned by the
 * parent; blanks are meaningful (they make that locale fall back), so this
 * component never prunes them — the server does.
 */
import { ref, computed } from 'vue';
import { GlobalOutlined } from '@ant-design/icons-vue';

const props = defineProps({
  modelValue: { type: String, default: '' },
  translations: { type: Object, default: () => ({}) },
  /** {en: 'English', …} as returned by the API. */
  locales: { type: Object, default: () => ({}) },
  label: { type: String, default: '' },
  extra: { type: String, default: '' },
  type: { type: String, default: 'input' },
  rows: { type: Number, default: 2 },
  placeholder: { type: String, default: '' },
  itemStyle: { type: [String, Object], default: '' },
});
const emit = defineEmits(['update:modelValue', 'update:translations']);

const open = ref(false);
const localeList = computed(() =>
  Object.keys(props.locales || {}).map(code => ({ code, label: props.locales[code] })));

function set(code, value) {
  emit('update:translations', { ...(props.translations || {}), [code]: value });
}
</script>

<style scoped>
.ti-row {
  display: flex;
  align-items: flex-start;
  gap: 6px;
}
.ti-i18n {
  display: grid;
  gap: 6px;
  margin-top: 8px;
  padding: 8px 10px;
  background: rgba(0, 0, 0, 0.02);
  border-radius: 6px;
}
.ti-i18n-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}
.ti-i18n-code {
  width: 74px;
  flex: none;
  padding-top: 5px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}
</style>
