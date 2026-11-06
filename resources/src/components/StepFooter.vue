<template>
  <!-- Footer of a form in steps (tabs): Cancel · Back · Next · Submit.
       Submit is always there (Edit can save from any step); it is the main
       button on the last step, Next is on the others. -->
  <div class="step-footer">
    <span class="step-count">{{ $t('tx_Step_48c7c') }} {{ index + 1 }} of {{ steps.length }}</span>
    <a-space>
      <a-button @click="$emit('cancel')">{{ $t('Cancel') }}</a-button>
      <a-button v-if="index > 0" @click="go(-1)"><LeftOutlined /> {{ $t('Back') }}</a-button>
      <a-button v-if="index < steps.length - 1" type="primary" ghost @click="go(1)">{{ $t('Next') }} <RightOutlined /></a-button>
      <a-button
        :type="index === steps.length - 1 ? 'primary' : 'default'"
        :loading="busy" :disabled="disabled" @click="$emit('submit')"
      >{{ submitText }}</a-button>
    </a-space>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { LeftOutlined, RightOutlined } from '@ant-design/icons-vue';

const props = defineProps({
  steps: { type: Array, required: true }, // [{ key, title }]
  active: { type: String, required: true },
  busy: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  submitText: { type: String, default: 'Submit' },
});
const emit = defineEmits(['update:active', 'cancel', 'submit']);
const index = computed(() => Math.max(0, props.steps.findIndex(s => s.key === props.active)));
function go(d) {
  const next = props.steps[index.value + d];
  if (next) emit('update:active', next.key);
}
</script>

<style scoped>
.step-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.step-count { font-size: 12px; opacity: 0.6; }
</style>
