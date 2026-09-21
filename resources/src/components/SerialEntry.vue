<template>
  <div>
    <div class="head">
      <span class="label">{{ $t('Enter_Serials') }}</span>
      <a-tag :color="isComplete ? 'green' : 'orange'">
        {{ $t('Serials_Count') }}: {{ serials.length }} / {{ requiredCount }}
      </a-tag>
    </div>

    <a-input-search
      v-model:value="scanInput"
      :placeholder="$t('Scan_Or_Enter_Serial')"
      :enter-button="$t('Add_Serial')"
      @search="addFromScan"
    />

    <a-space style="margin-top: 8px">
      <a-button size="small" @click="showBulk = !showBulk">{{ $t('Bulk_Paste_Serials') }}</a-button>
      <a-button size="small" @click="fileInput?.click()">{{ $t('Import_Serials_CSV') }}</a-button>
    </a-space>

    <div v-if="showBulk" style="margin-top: 8px">
      <a-textarea v-model:value="bulkText" :rows="3" :placeholder="$t('Bulk_Paste_Serials')" />
      <a-button size="small" type="primary" style="margin-top: 4px" @click="applyBulk">
        {{ $t('Add_Serial') }}
      </a-button>
    </div>

    <input ref="fileInput" type="file" accept=".csv,.txt,.xlsx,.xls" hidden @change="onFileChange" />

    <div v-if="serials.length" class="chips">
      <a-tag v-for="(s, i) in serials" :key="s" closable @close.prevent="removeSerial(i)">{{ s }}</a-tag>
    </div>

    <div v-if="error" class="err">{{ error }}</div>
    <div v-else-if="!isComplete" class="muted">
      {{ requiredCount }} {{ $t('Serials_Required_Count') }}
    </div>
  </div>
</template>

<script setup>
/**
 * Enter serials for a serialized (IMEI) line on a PURCHASE — legacy
 * SerialNumbersField in `entry` mode. Purchases CREATE serials rather than
 * consuming them, so there is nothing to pick from stock; serials arrive by
 * scan, bulk paste, or spreadsheet import.
 *
 * Mutates the line in place like BatchAllocator: keeps line.serial_numbers[]
 * and mirrors them into line.imei_number (comma-joined), which is what the
 * backend's resolveImeiString reads. Duplicate detection is case-insensitive
 * and rejects the token with a message, exactly as legacy does. The
 * count-vs-quantity rule is only *reported* here — the form enforces it on
 * submit (received status only), matching legacy.
 */
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
  line: { type: Object, required: true },
});

const scanInput = ref('');
const bulkText = ref('');
const showBulk = ref(false);
const error = ref('');
const fileInput = ref(null);

const serials = computed(() =>
  Array.isArray(props.line.serial_numbers) ? props.line.serial_numbers : []);
const requiredCount = computed(() => Math.round(Number(props.line.quantity) || 0));
const isComplete = computed(() => serials.value.length === requiredCount.value && requiredCount.value > 0);

/** Legacy tokenize: split on newline, comma, semicolon or tab; drop blanks. */
function tokenize(raw) {
  const parts = Array.isArray(raw) ? raw : String(raw ?? '').split(/[\r\n,;\t]+/);
  return parts.map(s => String(s).trim()).filter(Boolean);
}

function commit(list) {
  props.line.serial_numbers = list;
  props.line.imei_number = list.join(',');
}

function addSerials(raw) {
  error.value = '';
  const next = serials.value.slice();
  for (const token of tokenize(raw)) {
    if (next.some(s => s.toLowerCase() === token.toLowerCase())) {
      error.value = `${t('Duplicate_Serial')}: ${token}`;
      continue;
    }
    next.push(token);
  }
  commit(next);
}

function addFromScan() {
  if (!scanInput.value) return;
  addSerials(scanInput.value);
  scanInput.value = '';
}

function applyBulk() {
  if (!bulkText.value) return;
  addSerials(bulkText.value);
  bulkText.value = '';
  showBulk.value = false;
}

function removeSerial(idx) {
  const next = serials.value.slice();
  next.splice(idx, 1);
  commit(next);
}

async function onFileChange(e) {
  const file = e.target.files?.[0];
  if (!file) return;
  try {
    const XLSX = await import('xlsx');
    const wb = XLSX.read(await file.arrayBuffer(), { type: 'array' });
    const tokens = [];
    wb.SheetNames.forEach(name => {
      XLSX.utils.sheet_to_json(wb.Sheets[name], { header: 1, blankrows: false })
        .forEach(row => (row || []).forEach(cell => {
          if (cell !== null && cell !== undefined && String(cell).trim() !== '') {
            tokens.push(String(cell).trim());
          }
        }));
    });
    addSerials(tokens);
  } catch (err) {
    error.value = 'Import failed';
  } finally {
    e.target.value = '';
  }
}
</script>

<style scoped>
.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 6px;
}
.label {
  font-weight: 600;
  font-size: 13px;
}
.chips {
  margin-top: 8px;
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.err {
  margin-top: 4px;
  font-size: 12px;
  color: #ff4d4f;
}
.muted {
  margin-top: 4px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}
</style>
