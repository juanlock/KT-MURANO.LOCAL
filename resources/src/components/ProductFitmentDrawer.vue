<template>
  <a-drawer
    :open="open"
    :title="(tf('Vehicle_Fitment', 'Vehicle Fitment')) + (product ? ` — ${product.name}` : '')"
    :width="720"
    @close="$emit('close')"
  >
    <a-alert
      type="info" show-icon style="margin-bottom: 16px"
      :message="tf('Fitment_Universal_Hint', 'A product without fitment rows is universal and fits every vehicle. Add rows to restrict it to specific vehicles.')"
    />

    <a-spin :spinning="loading">
      <a-table
        :data-source="rows" :columns="columns" row-key="_key"
        size="small" :pagination="false" :scroll="{ x: 'max-content' }"
      >
        <template #bodyCell="{ column, record, index }">
          <template v-if="column.key === 'make'">
            <a-select
              v-model:value="record.vehicle_make_id" style="min-width: 140px"
              show-search option-filter-prop="label"
              :options="makeOptions"
              @change="record.vehicle_model_id = null"
            />
          </template>
          <template v-else-if="column.key === 'model'">
            <a-select
              v-model:value="record.vehicle_model_id" style="min-width: 150px"
              show-search option-filter-prop="label" allow-clear
              :placeholder="tf('All_Models', 'All models')"
              :options="modelOptionsFor(record.vehicle_make_id)"
            />
          </template>
          <template v-else-if="column.key === 'years'">
            <a-space>
              <a-input-number v-model:value="record.year_from" :min="1900" :max="2100" :controls="false" style="width: 82px" :placeholder="tf('From', 'From')" />
              <a-input-number v-model:value="record.year_to" :min="1900" :max="2100" :controls="false" style="width: 82px" :placeholder="tf('To', 'To')" />
            </a-space>
          </template>
          <template v-else-if="column.key === 'engine'">
            <a-input v-model:value="record.engine" :maxlength="100" style="width: 110px" placeholder="1.6L" />
          </template>
          <template v-else-if="column.key === 'notes'">
            <a-input v-model:value="record.notes" :maxlength="255" style="min-width: 130px" />
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-button type="text" size="small" danger @click="rows.splice(index, 1)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </template>
        </template>
      </a-table>

      <a-button style="margin-top: 12px" :disabled="!makeOptions.length" @click="addRow">
        <template #icon><PlusOutlined /></template>
        {{ tf('Add_Fitment_Row', 'Add vehicle') }}
      </a-button>
      <a-alert
        v-if="!makeOptions.length && !loading"
        type="warning" show-icon style="margin-top: 12px"
        :message="tf('No_Vehicle_Catalog_Hint', 'No vehicle makes defined yet — create them under Products → Vehicle Fitment first.')"
      />
    </a-spin>

    <template #footer>
      <a-space style="display: flex; justify-content: flex-end">
        <a-button @click="$emit('close')">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" :loading="saving" @click="save">{{ $t('Submit') }}</a-button>
      </a-space>
    </template>
  </a-drawer>
</template>

<script setup>
/**
 * Per-product fitment editor — GET/PUT products/{id}/fitments (replace-all
 * save). Make/model options come from vehicles/lookup; a cleared model means
 * "all models of the make".
 */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { t as tf } from '../i18n';

const props = defineProps({
  open: { type: Boolean, default: false },
  product: { type: Object, default: null }, // {id, name}
});
const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();

const loading = ref(false);
const saving = ref(false);
const rows = ref([]);
const makes = ref([]);
const models = ref([]);
let keySeq = 0;

const makeOptions = computed(() => makes.value.map(m => ({ value: m.id, label: m.name })));
function modelOptionsFor(makeId) {
  return models.value
    .filter(m => Number(m.vehicle_make_id) === Number(makeId))
    .map(m => ({ value: m.id, label: m.name }));
}

const columns = computed(() => [
  { title: tf('Make', 'Make'), key: 'make' },
  { title: tf('Model', 'Model'), key: 'model' },
  { title: tf('Years', 'Years'), key: 'years' },
  { title: tf('Engine', 'Engine'), key: 'engine' },
  { title: tf('Notes', 'Notes'), key: 'notes' },
  { title: '', key: 'actions', width: 50 },
]);

watch(() => props.open, async (isOpen) => {
  if (!isOpen || !props.product) return;
  loading.value = true;
  rows.value = [];
  try {
    const [lookup, data] = await Promise.all([
      http.get('vehicles/lookup'),
      http.get(`products/${props.product.id}/fitments`),
    ]);
    makes.value = Array.isArray(lookup?.makes) ? lookup.makes : [];
    models.value = Array.isArray(lookup?.models) ? lookup.models : [];
    rows.value = (data?.fitments || []).map(f => ({
      _key: ++keySeq,
      vehicle_make_id: f.vehicle_make_id,
      vehicle_model_id: f.vehicle_model_id,
      year_from: f.year_from,
      year_to: f.year_to,
      engine: f.engine,
      notes: f.notes,
    }));
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});

function addRow() {
  rows.value.push({
    _key: ++keySeq,
    vehicle_make_id: makes.value[0]?.id ?? null,
    vehicle_model_id: null,
    year_from: null,
    year_to: null,
    engine: '',
    notes: '',
  });
}

async function save() {
  if (!props.product) return;
  const payload = rows.value
    .filter(r => r.vehicle_make_id)
    .map(r => ({
      vehicle_make_id: r.vehicle_make_id,
      vehicle_model_id: r.vehicle_model_id || null,
      year_from: r.year_from || null,
      year_to: r.year_to || null,
      engine: (r.engine || '').trim() || null,
      notes: (r.notes || '').trim() || null,
    }));

  saving.value = true;
  try {
    await http.put(`products/${props.product.id}/fitments`, { fitments: payload });
    message.success(t('Successfully_Updated'));
    emit('saved');
    emit('close');
  } catch (e) {
    message.error(e?.data?.error || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
</script>
