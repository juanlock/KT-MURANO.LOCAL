<template>
  <!-- Recurring-invoice settings + live preview, shared by the Sales Contract
       form and the sale form's "Recurring" mode. Edits the fields of the
       `value` object in place: billing_frequency, billing_interval,
       billing_day_mode, billing_day, billing_count, next_invoice_date. -->
  <div>
    <a-form-item label="Every">
      <a-input-group compact>
        <a-input-number v-model:value="value.billing_interval" :min="1" :max="24" style="width: 30%" />
        <a-select v-model:value="value.billing_frequency" :options="FREQUENCY_OPTIONS" style="width: 70%" />
      </a-input-group>
    </a-form-item>
    <!-- Which day each invoice falls on. -->
    <a-form-item :label="dayLabel">
      <a-input-group compact>
        <a-select
          v-model:value="dayChoice" :options="dayOptions"
          :style="{ width: dayChoice === 'day' && !isWeekly ? '70%' : '100%' }"
        />
        <a-input-number
          v-if="dayChoice === 'day' && !isWeekly"
          v-model:value="value.billing_day" :min="1" :max="31" placeholder="Day" style="width: 30%"
        />
      </a-input-group>
    </a-form-item>
    <a-form-item :label="`How many ${noun}s`" :extra="value.billing_count ? '' : (endDate ? 'Empty = until the end date.' : 'Empty = no limit (open-ended).')">
      <a-input-number v-model:value="value.billing_count" :min="1" :max="999" style="width: 100%" placeholder="No limit" />
    </a-form-item>
    <a-form-item :label="firstDateLabel" :extra="firstDateHelp">
      <a-date-picker v-model:value="value.next_invoice_date" value-format="YYYY-MM-DD" style="width: 100%" />
    </a-form-item>

    <slot name="summary" />

    <!-- The invoices these settings create (computed by the server code that
         creates them), with each line's note filled in. -->
    <div class="rs-title">
      {{ cap(noun) }}s
      <a-spin v-if="loading" size="small" style="margin-left: 6px" />
    </div>
    <div v-if="rows.length" class="rs">
      <div v-for="inv in rows" :key="inv.number" class="rs-row">
        <span class="rs-num">#{{ inv.number }}</span>
        <span class="rs-date">{{ date(inv.date) }}</span>
        <span v-if="showPeriod" class="rs-period">{{ date(inv.period_start) }} – {{ date(inv.period_end) }}</span>
        <div v-if="inv.invoice_note" class="rs-note"><b>{{ noteLabel }}:</b> {{ inv.invoice_note }}</div>
        <div v-for="(n, i) in inv.notes.filter(Boolean)" :key="i" class="rs-note">{{ n }}</div>
      </div>
      <div v-if="openEnded" class="rs-more">… and so on (no limit / end date)</div>
    </div>
    <div v-else-if="!loading" style="opacity: 0.6; font-size: 13px">No {{ noun }}s with these dates.</div>
  </div>
</template>

<script setup>
/**
 * Preview: POST sales_contracts/schedule (SalesContractController
 * schedulePreview) on every change, debounced. Exposes `rows` so the parent
 * can show "1st invoice: <note>" under a line (firstNotes()).
 */
import { ref, computed, watch } from 'vue';
import http from '../lib/http';
import { useFormat } from '../composables/useFormat';
import { FREQUENCY_OPTIONS } from '../pages/sales_contracts/salesContractVocab';

const props = defineProps({
  value: { type: Object, required: true },
  startDate: { type: String, default: null },
  endDate: { type: String, default: null },
  // Each line's note template, in line order (null for none).
  notes: { type: Array, default: () => [] },
  // The invoice's own note template (sales_contracts.invoice_note).
  invoiceNote: { type: String, default: '' },
  contractId: { type: Number, default: null },
  enabled: { type: Boolean, default: true },
  firstDateLabel: { type: String, default: 'First / next invoice date' },
  firstDateHelp: { type: String, default: 'Empty = the start date, moved to the first matching invoice day.' },
  // Wording, so the same schedule serves invoices and service visits.
  noun: { type: String, default: 'invoice' },
  dayLabel: { type: String, default: 'Invoice day' },
  noteLabel: { type: String, default: 'Note' },
  showPeriod: { type: Boolean, default: true },
});
const cap = s => String(s).charAt(0).toUpperCase() + String(s).slice(1);

const { date } = useFormat();

// ---------------- invoice day ----------------
// Monthly / quarterly / yearly: same day as the first invoice, first or last
// day of the month, or a specific day (1-31; 31 = month end). Weekly: same
// weekday as the first invoice, or a weekday. The server stores "same" as
// the fixed day so it can't drift.
const isWeekly = computed(() => props.value.billing_frequency === 'weekly');
// Weekdays in week order (Mon..Sun), not alphabetical.
const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const dayOptions = computed(() => (isWeekly.value
  ? [{ value: 'same', label: `Same weekday as the first ${props.noun}` }, ...WEEKDAYS.map((d, i) => ({ value: `w${i + 1}`, label: `Every ${d}` }))]
  : [
    { value: 'same', label: `Same day as the first ${props.noun}` },
    { value: 'first', label: 'First day of the month' },
    { value: 'last', label: 'Last day of the month' },
    { value: 'day', label: 'Specific day of the month' },
  ]));
const dayChoice = computed({
  get() {
    const v = props.value;
    if (isWeekly.value) return v.billing_day_mode === 'day' && v.billing_day ? `w${v.billing_day}` : 'same';
    return v.billing_day_mode || 'same';
  },
  set(choice) {
    const v = props.value;
    if (String(choice).startsWith('w')) {
      v.billing_day_mode = 'day';
      v.billing_day = Number(String(choice).slice(1));
    } else {
      v.billing_day_mode = choice;
      if (choice !== 'day') v.billing_day = null;
      else if (!v.billing_day) v.billing_day = 1;
    }
  },
});
// Switching between weeks and months: the other kind's day no longer applies.
// Not when the parent swaps in another object (an existing contract loaded).
watch(() => [props.value, isWeekly.value], ([obj, weekly], [prevObj, prevWeekly]) => {
  if (obj === prevObj && weekly !== prevWeekly) {
    obj.billing_day_mode = 'same';
    obj.billing_day = null;
  }
});

// ---------------- preview ----------------
const rows = ref([]);
const openEnded = ref(false);
const loading = ref(false);
let timer = null;
let seq = 0;
function load() {
  clearTimeout(timer);
  const v = props.value;
  if (!props.enabled || !props.startDate) { rows.value = []; return; }
  timer = setTimeout(async () => {
    const mySeq = ++seq;
    loading.value = true;
    try {
      const data = await http.post('sales_contracts/schedule', {
        start_date: props.startDate, end_date: props.endDate || null, next_invoice_date: v.next_invoice_date || null,
        billing_frequency: v.billing_frequency, billing_interval: v.billing_interval || 1,
        billing_count: v.billing_count || null, billing_day_mode: v.billing_day_mode, billing_day: v.billing_day || null,
        notes: props.notes.map(n => n || null), invoice_note: props.invoiceNote || null, contract_id: props.contractId,
      });
      if (mySeq !== seq) return;
      rows.value = data.invoices || [];
      openEnded.value = !!data.open_ended;
    } catch (e) {
      if (mySeq === seq) rows.value = [];
    } finally {
      if (mySeq === seq) loading.value = false;
    }
  }, 350);
}
watch(() => {
  const v = props.value;
  return [props.enabled, props.startDate, props.endDate, v.next_invoice_date, v.billing_frequency, v.billing_interval,
    v.billing_count, v.billing_day_mode, v.billing_day, props.notes.map(n => n || '').join('\u0001'), props.invoiceNote || ''].join('|');
}, load, { immediate: true });

/** Line index -> how its note reads on the first invoice ('' when none). */
function firstNote(index) {
  return rows.value[0]?.notes?.[index] || '';
}
/** How the invoice note reads on the first invoice ('' when none). */
function firstInvoiceNote() {
  return rows.value[0]?.invoice_note || '';
}
defineExpose({ rows, firstNote, firstInvoiceNote });
</script>

<style scoped>
.rs-title { font-weight: 600; margin-bottom: 6px; display: flex; align-items: center; }
.rs { max-height: 320px; overflow-y: auto; border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; }
.rs-row { padding: 6px 10px; border-top: 1px solid rgba(128, 128, 128, 0.12); font-size: 13px; }
.rs-row:first-child { border-top: 0; }
.rs-num { font-weight: 600; margin-right: 8px; }
.rs-date { font-weight: 500; margin-right: 8px; }
.rs-period { opacity: 0.6; font-size: 12px; }
.rs-note { font-size: 12px; font-style: italic; opacity: 0.8; margin-top: 2px; }
.rs-more { padding: 6px 10px; font-size: 12px; opacity: 0.6; }
</style>
