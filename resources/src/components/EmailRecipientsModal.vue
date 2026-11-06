<template>
  <a-modal
    :open="open"
    :title="title"
    :ok-text="$t('tx_Send_94966')"
    :confirm-loading="sending"
    :ok-button-props="{ disabled: !emails.length || invalid.length > 0 }"
    @ok="onOk"
    @cancel="$emit('update:open', false)"
  >
    <p style="margin-bottom: 8px">
      {{ contactLabel }}: <strong>{{ contactName }}</strong>
    </p>
    <a-form layout="vertical">
      <a-form-item
        :label="$t('tx_Send_to_c973c')"
        :validate-status="invalid.length ? 'error' : undefined"
        :help="invalid.length ? `Invalid: ${invalid.join(', ')}` : undefined"
        :extra="added.length ? `Will be added to this ${contactNoun}: ${added.join(', ')}` : undefined"
      >
        <a-select
          v-model:value="emails"
          mode="tags"
          :options="known.map(e => ({ value: e, label: e }))"
          :token-separators="[',', ' ', ';']"
          :placeholder="$t('tx_Type_another_email_and_press_Enter_36fd2')"
          style="width: 100%"
        />
      </a-form-item>
    </a-form>
  </a-modal>
</template>

<script setup>
/**
 * Recipients chooser for list-page Send Email / Reminder actions (Sales,
 * Quotations, Purchases). Pre-filled with the contact's main email; the
 * contact's saved additional emails are offered as options; a typed new
 * address is flagged "will be added" -- the backend (ResolvesEmailRecipients)
 * saves it to the customer/supplier. Emits `send` with the trimmed list; the
 * parent posts it and closes the modal on success.
 */
import { ref, computed, watch } from 'vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  contactLabel: { type: String, default: 'Customer' },
  contactNoun: { type: String, default: 'customer' },
  contactName: { type: String, default: '' },
  mainEmail: { type: String, default: '' },
  additionalEmails: { type: Array, default: () => [] },
  sending: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open', 'send']);

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const emails = ref([]);

const known = computed(() => [props.mainEmail, ...(props.additionalEmails || [])].filter(Boolean));

// Reset the selection each time the modal opens for a (new) document.
watch(() => props.open, isOpen => {
  if (isOpen) emails.value = props.mainEmail ? [props.mainEmail] : [];
}, { immediate: true });

const clean = computed(() => emails.value.map(e => String(e).trim()).filter(Boolean));
const invalid = computed(() => clean.value.filter(e => !EMAIL_RE.test(e)));
const added = computed(() => {
  const k = known.value.map(e => e.toLowerCase());
  return clean.value.filter(e => EMAIL_RE.test(e) && !k.includes(e.toLowerCase()));
});

function onOk() {
  if (!clean.value.length || invalid.value.length) return;
  emit('send', clean.value, added.value.length > 0);
}
</script>
