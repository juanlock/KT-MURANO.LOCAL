<template>
  <div>
    <a-spin :spinning="loading">
      <a-select
        :value="line.serial_numbers"
        mode="multiple"
        style="width: 100%"
        :placeholder="$t('Select_Serials')"
        :options="available.map(s => ({ value: s.serial_number, label: s.serial_number }))"
        :max-tag-count="6"
        show-search
        @change="onChange"
      />
      <div class="muted" style="margin-top: 4px">
        {{ (line.serial_numbers || []).length }} / {{ Math.round(Number(line.quantity) || 0) }}
        <span v-if="!loading && !available.length" style="color: #faad14; margin-left: 8px">
          {{ $t('No_Serials_Available') }}
        </span>
      </div>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Pick already-issued serials for a serialized (IMEI) line — legacy
 * SerialNumbersField in `select` mode. Keeps line.serial_numbers[] and mirrors
 * them into line.imei_number (comma-joined); the backend's resolveImeiString
 * accepts either. Count-vs-quantity validation stays in the form.
 *
 * Which serials are candidates depends on the document:
 *   sale            → serial_numbers/available   (in stock, the default)
 *   purchase return → serial_numbers/for_purchase (issued by that purchase)
 * so the endpoint and its params are overridable. The candidate list refetches
 * whenever those params change, since the line's product can differ per row.
 */
import { ref, computed, watch, onMounted } from 'vue';
import http from '../lib/http';

const props = defineProps({
  line: { type: Object, required: true },
  warehouseId: { type: [Number, String], default: null },
  fetchUrl: { type: String, default: 'serial_numbers/available' },
  // Explicit params; when omitted they are derived from the line + warehouse.
  fetchParams: { type: Object, default: null },
});

const loading = ref(false);
const available = ref([]);

const params = computed(() => {
  if (props.fetchParams) return props.fetchParams;
  if (!props.line.product_id || !props.warehouseId) return null;
  const p = { product_id: props.line.product_id, warehouse_id: props.warehouseId };
  if (props.line.product_variant_id) p.product_variant_id = props.line.product_variant_id;
  return p;
});

function onChange(values) {
  props.line.serial_numbers = values;
  props.line.imei_number = values.join(',');
}

async function load() {
  if (!params.value) {
    available.value = [];
    return;
  }
  loading.value = true;
  try {
    const data = await http.get(props.fetchUrl, params.value);
    available.value = data?.serials || [];
  } catch (e) {
    available.value = [];
  } finally {
    loading.value = false;
  }
}

watch(params, load, { deep: true });
onMounted(load);
</script>

<style scoped>
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
</style>
