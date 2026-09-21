<template>
  <a-modal
    :open="open"
    :title="`${$t('ShowPayment')} — ${active?.Ref || ''}`"
    :footer="null"
    width="820px"
    @update:open="v => $emit('update:open', v)"
  >
    <a-table :columns="columns" :data-source="payments" :pagination="false" row-key="id" size="small" :scroll="{ x: 'max-content' }">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'montant'">{{ money(record.montant) }}</template>
        <template v-else-if="column.key === 'method'">{{ record.payment_method?.name || '---' }}</template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('DownloadPdf')">
              <a-button type="text" size="small" @click="downloadPdf(record)">
                <template #icon><FilePdfOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canEdit" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$emit('edit', record)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canDelete" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
      <template #emptyText>
        <a-empty :description="$t('NodataAvailable')" style="padding: 24px 0" />
      </template>
    </a-table>
    <div style="text-align: right; margin-top: 12px; font-weight: 600">
      {{ $t('Due') }}: <span style="color: #ff4d4f">{{ money(due) }}</span>
    </div>
  </a-modal>
</template>

<script setup>
/**
 * Payments list for a return document — shared by sale returns and purchase
 * returns, parameterized by the receipt-PDF and delete endpoints.
 */
import { computed, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  FilePdfOutlined, EditOutlined, DeleteOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import { useFormat } from '../composables/useFormat';
import http from '../lib/http';

const props = defineProps({
  open: { type: Boolean, default: false },
  active: { type: Object, default: null },
  payments: { type: Array, default: () => [] },
  due: { type: [Number, String], default: 0 },
  canEdit: { type: Boolean, default: false },
  canDelete: { type: Boolean, default: false },
  pdfEndpoint: { type: String, required: true },   // e.g. payment_return_sale_pdf
  pdfPrefix: { type: String, required: true },     // download filename prefix
  deleteEndpoint: { type: String, required: true }, // e.g. payment/returns_sale
});

const emit = defineEmits(['update:open', 'edit', 'removed']);

const { t } = useI18n();
const { money } = useFormat();

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date' },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('Amount'), dataIndex: 'montant', key: 'montant', align: 'right' },
  { title: t('PayeBy'), key: 'method' },
  { title: t('Action'), key: 'actions', width: 140, align: 'center' },
]);

function downloadPdf(payment) {
  http.download(`${props.pdfEndpoint}/${payment.id}`, `${props.pdfPrefix}_${payment.Ref}.pdf`)
    .catch(() => message.error(t('InvalidData')));
}

function remove(payment) {
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: t('Delete_Text'),
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`${props.deleteEndpoint}/${payment.id}`);
        message.success(t('Deleted_in_successfully'));
        emit('removed');
      } catch (e) {
        message.error(t('InvalidData'));
      }
    },
  });
}
</script>
