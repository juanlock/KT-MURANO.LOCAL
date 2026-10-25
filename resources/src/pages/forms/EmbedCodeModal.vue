<template>
  <!-- Forms module: Publish window (the list's "Publish" button) -- FormPublish inside a modal. -->
  <a-modal :open="open" :title="form ? `${es ? 'Publicar' : 'Publish'} · ${form.name}` : (es ? 'Publicar' : 'Publish')" :footer="null" :width="760" @cancel="emit('update:open', false)">
    <a-spin :spinning="loading">
      <FormPublish v-if="form" :form="form" />
    </a-spin>
  </a-modal>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import FormPublish from './FormPublish.vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';

const props = defineProps({ open: Boolean, formId: { type: Number, default: null } });
const emit = defineEmits(['update:open']);
const ui = useUiStore();
const es = computed(() => ui.locale === 'es');
const form = ref(null);
const loading = ref(false);

watch(() => [props.open, props.formId], async ([open, id]) => {
  if (!open || !id) return;
  loading.value = true;
  form.value = null;
  try {
    form.value = (await http.get(`forms/${id}`)).form;
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    loading.value = false;
  }
}, { immediate: true });
</script>
