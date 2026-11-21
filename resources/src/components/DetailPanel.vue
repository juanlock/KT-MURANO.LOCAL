<template>
  <!-- Phone: the record opens in a modal over the list (the list takes the whole width). -->
  <a-modal
    v-if="isPhone"
    :open="open"
    :title="title"
    :footer="null"
    width="100%"
    wrap-class-name="detail-panel-modal"
    destroy-on-close
    @cancel="$emit('close')"
  >
    <slot />
  </a-modal>
  <!-- Computer / tablet: the right-hand column, as the page lays it out (attrs: span, xl, class...). -->
  <a-col v-else v-bind="$attrs">
    <slot />
  </a-col>
</template>

<script setup>
/**
 * List on the left, record on the right (user's rule, 2026-11-21): on a phone only, the right side becomes a modal and
 * the list fits the screen. Use it for the right column of any list + detail screen:
 *   <a-row><a-col class="col-list">...list...</a-col>
 *     <DetailPanel :open="!!sel" :title="sel?.name" class="col-form" @close="sel = null">...form...</DetailPanel></a-row>
 * The list's column must be full width on a phone (span 24 / xs 24). On a phone the page's own "click a row" empty
 * state is never shown (the modal opens when there is a record). A panel that is always showing a "new" form on a
 * computer opens on the phone only from a button (useIsPhone() in the page, a "Nuevo" button shown on the phone only).
 */
import { useIsPhone } from '../lib/useIsPhone';

defineOptions({ inheritAttrs: false });
defineProps({ open: { type: Boolean, default: false }, title: { type: String, default: '' } });
defineEmits(['close']);

const isPhone = useIsPhone();
</script>

<style>
/* Unscoped: the modal is teleported to <body>. Full screen on the phone, its body scrolls. */
.detail-panel-modal .ant-modal { top: 0; max-width: 100vw; margin: 0; padding: 0; }
.detail-panel-modal .ant-modal-content { min-height: 100vh; border-radius: 0; padding: 40px 12px 14px; }
/* The panel brings its own heading: the modal's title is only for a panel without one. */
.detail-panel-modal .ant-modal-header { margin: -26px 0 10px; }
.detail-panel-modal .ant-modal-body { max-height: calc(100vh - 70px); overflow-y: auto; }
.detail-panel-modal .ant-modal-body .ant-card { box-shadow: none; border: 0; }
.detail-panel-modal .ant-modal-body .ant-card-body { padding: 0; }
.detail-panel-modal .ant-modal-body .ant-card { min-height: 0 !important; }
</style>
