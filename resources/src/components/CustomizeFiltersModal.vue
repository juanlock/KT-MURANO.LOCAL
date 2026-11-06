<template>
  <a-modal
    :open="open" @update:open="v => emit('update:open', v)"
    :title="$t('tx_Customize_filters_d68b2')"
    :width="480"
    :confirm-loading="saving"
    @ok="emit('save')"
  >
    <div class="cf-hint">{{ $t('tx_Show_hide_or_reorder_this_list_s_filters_for_everyone_in_the_2e0db') }}</div>
    <div class="cf-head">
      <span class="cf-move"></span>
      <span class="cf-vis">{{ $t('tx_Show_498f7') }}</span>
      <span class="cf-label">{{ $t('Filter') }}</span>
    </div>
    <div v-for="(row, i) in rows" :key="row.key" class="cf-row">
      <span class="cf-move">
        <a-button size="small" type="text" :disabled="i === 0" @click="emit('move', i, -1)"><template #icon><ArrowUpOutlined /></template></a-button>
        <a-button size="small" type="text" :disabled="i === rows.length - 1" @click="emit('move', i, 1)"><template #icon><ArrowDownOutlined /></template></a-button>
      </span>
      <span class="cf-vis"><a-checkbox v-model:checked="row.visible" /></span>
      <span class="cf-label">{{ row.label }}</span>
    </div>
    <template #footer>
      <a-button danger :loading="saving" @click="emit('reset')">{{ $t('Reset') }}</a-button>
      <a-button @click="emit('update:open', false)">{{ $t('Cancel') }}</a-button>
      <a-button type="primary" :loading="saving" @click="emit('save')">{{ $t('Save') }}</a-button>
    </template>
  </a-modal>
</template>

<script setup>
/**
 * Shared "Customize Filters" modal for Products/Sales/Purchases (and any
 * future list) -- pairs with useCustomizableFilters(). Rows are owned by the
 * caller (v-model-like: mutated in place via `rows`, moved/saved/reset via
 * emitted events) so the composable stays the single source of truth.
 */
import { ArrowUpOutlined, ArrowDownOutlined } from '@ant-design/icons-vue';

defineProps({
  open: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  rows: { type: Array, required: true },
});

const emit = defineEmits(['update:open', 'move', 'save', 'reset']);
</script>

<style scoped>
.cf-hint { font-size: 12px; opacity: 0.65; margin-bottom: 12px; }
.cf-head, .cf-row { display: flex; align-items: center; gap: 8px; }
.cf-head { font-size: 12px; font-weight: 600; opacity: 0.7; margin-bottom: 6px; }
.cf-row { padding: 4px 0; border-top: 1px solid rgba(128, 128, 128, 0.12); }
.cf-move { width: 64px; flex: none; display: flex; }
.cf-vis { width: 44px; flex: none; text-align: center; }
.cf-label { flex: 1; min-width: 0; }
</style>
