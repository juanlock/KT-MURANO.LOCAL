<template>
  <!-- "?" popover listing the invoice-note placeholders and example notes.
       Clicking a placeholder emits insert(token); an example emits use(text).
       Placeholders are filled per invoice by the server
       (SalesContractBilling::renderNote) -- keep this list in sync with
       SalesContractBilling::NOTE_TOKENS. -->
  <a-popover trigger="click" placement="bottomRight" title="Note placeholders">
    <template #content>
      <div class="nph">
        <div class="nph__hint">{{ hint }}</div>
        <template v-for="grp in NOTE_GROUPS" :key="grp.title">
          <div class="nph__group">{{ grp.title }}</div>
          <div v-for="tk in grp.tokens" :key="tk.token" class="nph__row">
            <a-tag color="blue" style="cursor: pointer" @click="emit('insert', tk.token)">{{ tk.token }}</a-tag>
            <span>{{ tk.help }}</span>
          </div>
        </template>
        <a-divider style="margin: 8px 0" />
        <div class="nph__hint">Examples (click to use):</div>
        <div v-for="ex in NOTE_EXAMPLES" :key="ex" class="nph__example" @click="emit('use', ex)">{{ ex }}</div>
      </div>
    </template>
    <slot>
      <a-tooltip title="Placeholders and examples"><QuestionCircleOutlined style="cursor: pointer" /></a-tooltip>
    </slot>
  </a-popover>
</template>

<script setup>
import { QuestionCircleOutlined } from '@ant-design/icons-vue';

const emit = defineEmits(['insert', 'use']);
defineProps({
  // When / with what the placeholders are filled (recurring invoices, one sale, each job...).
  hint: { type: String, default: 'Click to add. Each invoice fills them in with its own values.' },
});

const NOTE_GROUPS = [
  {
    title: 'Period billed',
    tokens: [
      { token: '{{month}}', help: 'Month name -- May' },
      { token: '{{month_short}}', help: 'Short month -- May / Jun' },
      { token: '{{month_number}}', help: 'Month number -- 5' },
      { token: '{{year}}', help: 'Year -- 2026' },
      { token: '{{quarter}}', help: 'Quarter -- Q2' },
      { token: '{{week}}', help: 'Week of the year -- 19' },
      { token: '{{date}}', help: 'Period start -- May 1, 2026' },
      { token: '{{period}}', help: 'Period -- May 1 - May 31, 2026' },
    ],
  },
  {
    title: 'Invoice date',
    tokens: [
      { token: '{{invoice_date}}', help: 'Invoice date -- May 31, 2026' },
      { token: '{{day}}', help: 'Day of the month -- 31' },
      { token: '{{weekday}}', help: 'Day of the week -- Friday' },
      { token: '{{weekday_short}}', help: 'Short day of the week -- Fri' },
      { token: '{{invoice_month}}', help: 'Month of the invoice date -- May' },
    ],
  },
  {
    title: 'Series',
    tokens: [
      { token: '{{occurrence}}', help: 'This invoice\'s number in the series -- 3' },
      { token: '{{number}}', help: 'Same as occurrence -- 3' },
      { token: '{{count}}', help: 'How many invoices in total -- 5' },
    ],
  },
];
const NOTE_EXAMPLES = [
  'Service made on {{month}}',
  'Service made on {{weekday}}, {{invoice_date}}',
  'Note: {{month}} installment services',
  'Installment {{occurrence}} of {{count}} - {{month}} {{year}}',
  'Week {{week}} services ({{period}})',
  '{{quarter}} {{year}} maintenance',
];
</script>

<script>
/** Appends a placeholder to a note (one space before it). */
export function appendToken(note, token) {
  const base = (note || '').replace(/\s+$/, '');
  return `${base}${base ? ' ' : ''}${token}`;
}
</script>

<style scoped>
.nph { max-width: 340px; }
.nph__hint { font-size: 12px; opacity: 0.65; margin-bottom: 6px; }
.nph__group { font-size: 11px; font-weight: 600; text-transform: uppercase; opacity: 0.55; margin: 8px 0 4px; }
.nph__row { display: flex; align-items: center; gap: 6px; font-size: 12px; margin-bottom: 4px; }
.nph__example { font-size: 12px; padding: 3px 6px; border-radius: 4px; cursor: pointer; }
.nph__example:hover { background: rgba(128, 128, 128, 0.12); }
</style>
