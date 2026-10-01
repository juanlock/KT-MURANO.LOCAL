<template>
  <div class="page">
    <PageHeader :title="$t('ListSales')" :breadcrumb="[$t('Sales'), $t('ListSales')]">
      <template #actions>
        <a-space wrap :size="12">
          <!-- Quick date-range presets — filters both the summary cards and
               the table (server-side, via date_from/date_to). The keycard
               shows exactly which days that active range covers. -->
          <a-card size="small" class="range-card">
            <div class="range-row">
              <span class="range-label">{{ $t('start_date') }}</span>
              <b class="range-value">{{ activeRange.from }}</b>
            </div>
            <div class="range-row">
              <span class="range-label">{{ $t('to') }}</span>
              <b class="range-value">{{ activeRange.to }}</b>
            </div>
          </a-card>
          <a-radio-group v-model:value="datePreset" button-style="solid" @change="applyPreset(datePreset)">
            <a-radio-button v-for="p in DATE_PRESETS" :key="p.value" :value="p.value">{{ $t(p.labelKey) }}</a-radio-button>
          </a-radio-group>
          <a-button :loading="exporting === 'pdf'" @click="exportList('pdf')">
            <template #icon><FilePdfOutlined /></template>
            PDF
          </a-button>
          <a-button :loading="exporting === 'xlsx'" @click="exportList('xlsx')">
            <template #icon><FileExcelOutlined /></template>
            EXCEL
          </a-button>
          <a-button v-if="auth.can('Sales_add')" type="primary" @click="$router.push('/sales/create')">
            <template #icon><PlusOutlined /></template>
            {{ $t('AddSale') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <!-- Summary cards — global sums over the whole filtered set (all pages). -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="tile in statTiles" :key="tile.label" :xs="12" :sm="12" :md="6">
        <a-card size="small" class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" :style="{ background: tile.tint, color: tile.color }">
              <component :is="tile.icon" />
            </div>
            <div class="stat-meta">
              <div class="stat-label">{{ tile.label }}</div>
              <div class="stat-value" :style="tile.style">
                <a-spin v-if="crud.loading.value" size="small" />
                <template v-else>{{ tile.value }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <a-card size="small" style="margin-bottom: 16px">
      <!-- Filters + the Customize Filters button share one row (flex: the
           filters split the width, the button sits at the end; wraps only
           on narrow screens). -->
      <div class="filters-line">
        <div v-for="key in visibleFilterKeys" :key="key" class="filters-line__item">
          <template v-if="key === 'date'">
            <div class="filter-label">{{ $t('date') }}</div>
            <a-date-picker v-model:value="filters.date" value-format="YYYY-MM-DD" style="width: 100%" @change="onExactDateChange" />
          </template>
          <template v-else-if="key === 'client_id'">
            <div class="filter-label">{{ $t('Customer') }}</div>
            <a-select
              v-model:value="filters.client_id" style="width: 100%" allow-clear show-search
              option-filter-prop="label" :placeholder="$t('Choose_Customer')" :options="customerOptions"
              @change="onOtherFilterChange"
            />
          </template>
          <template v-else-if="key === 'statut'">
            <div class="filter-label">{{ $t('Status') }}</div>
            <a-select
              v-model:value="filters.statut" style="width: 100%" allow-clear
              :placeholder="$t('Choose_Status')" :options="statusOptions" @change="onOtherFilterChange"
            />
          </template>
          <template v-else-if="key === 'payment_statut'">
            <div class="filter-label">{{ $t('PaymentStatus') }}</div>
            <a-select
              v-model:value="filters.payment_statut" style="width: 100%" allow-clear
              :placeholder="$t('PaymentStatus')" :options="paymentStatusOptions" @change="onOtherFilterChange"
            />
          </template>
          <template v-else-if="key === 'shipping_status'">
            <div class="filter-label">{{ $t('Shipping_status') }}</div>
            <a-select
              v-model:value="filters.shipping_status" style="width: 100%" allow-clear
              :placeholder="$t('Shipping_status')" :options="shippingStatusOptions" @change="onOtherFilterChange"
            />
          </template>
          <template v-else-if="key === 'product_group_id'">
            <div class="filter-label">{{ $t('Group_Name') }}</div>
            <a-select
              v-model:value="filters.product_group_id" style="width: 100%" allow-clear show-search
              option-filter-prop="label" :placeholder="$t('Group_Name')" :options="productGroupOptions"
              @change="onProductGroupFilterChange"
            />
          </template>
          <template v-else-if="key === 'product_id'">
            <div class="filter-label">{{ $t('Name') }}</div>
            <a-select
              v-model:value="filters.product_id" style="width: 100%" allow-clear show-search
              option-filter-prop="label" :placeholder="$t('Name')" :options="productOptions"
              @change="onProductFilterChange"
            />
          </template>
          <template v-else-if="key === 'warehouse_id'">
            <div class="filter-label">{{ $t('warehouse') }}</div>
            <a-select
              v-model:value="filters.warehouse_id" style="width: 100%" allow-clear show-search
              option-filter-prop="label" :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
              @change="onOtherFilterChange"
            />
          </template>
        </div>
        <ResetFiltersButton :dirty="filtersDirty" @reset="resetFilters" />
        <a-tooltip v-if="auth.canCustomizeColumns" title="Customize filters">
          <a-button @click="openCustomize">
            <template #icon><FilterOutlined /></template>
          </a-button>
        </a-tooltip>
      </div>
    </a-card>

    <CustomizeFiltersModal
      v-model:open="customizeOpen" :saving="customizeSaving" :rows="customizeRows"
      @move="moveRow" @save="saveCustomize" @reset="resetCustomize"
    />

    <DataTable :crud="crud" :columns="columns" :selectable="auth.can('Sales_delete')">
      <template #toolbar>
        <a-tag v-if="dueOnly" color="red" closable @close.prevent="clearDueOnly">{{ $t('Due') }}</a-tag>
        <StatusHelpModal doc-type="sale" />
      </template>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'date'">{{ dateTime(record.date) }}</template>
        <template v-else-if="column.key === 'Ref'">
          <!-- Reference opens a small chooser: view details or edit. -->
          <a-dropdown :trigger="['click']">
            <a class="ref-link">{{ record.Ref }} <DownOutlined class="ref-link__caret" /></a>
            <template #overlay>
              <a-menu @click="({ key }) => onAction(key, record)">
                <a-menu-item key="detail">
                  <EyeOutlined style="color: #1677ff" /> {{ $t('SaleDetail') }}
                </a-menu-item>
                <a-menu-item v-if="canEditSale(record)" key="edit">
                  <EditOutlined style="color: #52c41a" /> {{ $t('EditSale') }}
                </a-menu-item>
                <a-menu-item v-else key="edit-locked" disabled :title="editLockedReason(record)">
                  <LockOutlined /> {{ $t('EditSale') }}
                  <div class="ref-link__why">{{ editLockedReason(record) }}</div>
                </a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
          <a-tooltip v-if="record.sale_has_return === 'yes'" :title="$t('Sell_Return')">
            <span style="color: #ff4d4f; margin-left: 4px">↩</span>
          </a-tooltip>
        </template>
        <template v-else-if="column.key === 'client_name'">
          <a v-if="record.client_id" @click="$router.push(`/customers/${record.client_id}/ledger`)">{{ record.client_name }}</a>
          <span v-else>{{ record.client_name }}</span>
        </template>
        <template v-else-if="column.key === 'statut'">
          <a-dropdown v-if="auth.can('Sales_edit') && record.statut === 'pending'" :trigger="['click']">
            <a-tag :color="docStatusColor(record.statut)" style="cursor: pointer">
              {{ statusKey(SALE_STATUSES, record.statut) ? $t(statusKey(SALE_STATUSES, record.statut)) : record.statut }}
            </a-tag>
            <template #overlay>
              <a-menu @click="({ key }) => onStatusMenuClick(key, record)">
                <a-menu-item key="ordered">{{ $t('Ordered') }}</a-menu-item>
                <a-menu-item key="completed">{{ $t('complete') }}</a-menu-item>
                <a-menu-divider />
                <a-menu-item key="cancel" danger>{{ $t('Cancel') }}</a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
          <a-tag v-else :color="docStatusColor(record.statut)">
            {{ statusKey(SALE_STATUSES, record.statut) ? $t(statusKey(SALE_STATUSES, record.statut)) : record.statut }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'currency_code'">
          <a-tag v-if="record.currency_code" color="blue">{{ record.currency_code }}</a-tag>
        </template>
        <template v-else-if="column.key === 'GrandTotal'">{{ money(record.GrandTotal) }}</template>
        <template v-else-if="column.key === 'paid_amount'">{{ money(record.paid_amount) }}</template>
        <template v-else-if="column.key === 'due'">
          <span :style="{ color: Number(record.due) > 0 ? '#ff4d4f' : undefined }">{{ money(record.due) }}</span>
        </template>
        <template v-else-if="column.key === 'other_due'">
          <!-- Blank when this customer has no OTHER invoice still owing --
               deliberately no dash/zero, per the design ask. -->
          <a-popover v-if="record.other_due_count > 0" trigger="click" placement="left">
            <template #content>
              <div style="max-width: 260px">
                <div style="font-weight: 500; margin-bottom: 6px">{{ record.client_name }} — other due invoices</div>
                <a-list size="small" :data-source="record.other_due_sales">
                  <template #renderItem="{ item }">
                    <a-list-item style="padding: 4px 0">
                      <a @click="$router.push(`/sales/${item.id}`)">{{ item.Ref }}</a>
                      <span style="margin-left: auto; color: #ff4d4f">{{ money(item.due) }}</span>
                    </a-list-item>
                  </template>
                </a-list>
              </div>
            </template>
            <a-tag color="warning" style="cursor: pointer">{{ record.other_due_count }}</a-tag>
          </a-popover>
        </template>
        <template v-else-if="column.key === 'payment_status'">
          <!-- Confusing next to Status when the sale is still Pending (same
               'pending' value, same tag color on both) -- and it's not real
               yet anyway, since payment_status is only computed once a sale
               is completed. Hidden while statut is pending. -->
          <a-tag
            v-if="record.statut !== 'pending'"
            :color="payStatusColor(record.payment_status)"
            :style="paymentStatusClickable(record) ? { cursor: 'pointer' } : undefined"
            @click="onPaymentStatusClick(record)"
          >
            {{ statusKey(PAYMENT_STATUSES, record.payment_status) ? $t(statusKey(PAYMENT_STATUSES, record.payment_status)) : record.payment_status }}
          </a-tag>
          <span v-else>—</span>
        </template>
        <template v-else-if="column.key === 'shipping_status'">
          <a-tag v-if="record.shipping_status" :color="shipStatusColor(record.shipping_status)">
            {{ statusKey(SHIPPING_STATUSES, record.shipping_status) ? $t(statusKey(SHIPPING_STATUSES, record.shipping_status)) : record.shipping_status }}
          </a-tag>
          <span v-else>—</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="$t('DownloadPdf')">
              <a-button type="text" size="small" class="row-act row-act--pdf" @click="invoicePdf(record)">
                <template #icon><FilePdfOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('email_notification')">
              <a-button type="text" size="small" class="row-act row-act--mail" @click="openEmailModal(record, 'email')">
                <template #icon><MailOutlined /></template>
              </a-button>
            </a-tooltip>
            <!-- Only while a balance remains -- a paid/cancelled invoice has
                 nothing to remind the customer about. -->
            <a-tooltip v-if="isUnpaid(record)" title="Send payment reminder">
              <a-button type="text" size="small" class="row-act row-act--remind" @click="openEmailModal(record, 'reminder')">
                <template #icon><BellOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('Sales_add')" :title="$t('Duplicate')">
              <a-button type="text" size="small" class="row-act row-act--dup" @click="duplicateSale(record)">
                <template #icon><CopyOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Attach_Documents')">
              <a-button type="text" size="small" class="row-act row-act--docs" @click="openDocuments(record)">
                <template #icon><PaperClipOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-dropdown :trigger="['click']">
              <a-button type="text" size="small">
                <template #icon><MoreOutlined style="font-size: 18px" /></template>
              </a-button>
              <template #overlay>
                <a-menu @click="({ key }) => onAction(key, record)">
                  <a-menu-item key="detail">
                    <EyeOutlined /> {{ $t('SaleDetail') }}
                  </a-menu-item>
                  <a-menu-item v-if="canEditSale(record)" key="edit">
                    <EditOutlined /> {{ $t('EditSale') }}
                  </a-menu-item>
                  <a-menu-item
                    v-if="auth.can('Sale_Returns_add') && record.sale_has_return === 'no' && record.statut === 'completed'"
                    key="return-add"
                  >
                    <RollbackOutlined /> {{ $t('Sell_Return') }}
                  </a-menu-item>
                  <a-menu-item v-if="auth.can('Sale_Returns_add') && record.sale_has_return === 'yes'" key="return-edit">
                    <RollbackOutlined /> {{ $t('Sell_Return') }}
                  </a-menu-item>
                  <!-- Non-destructive alternative to Delete for a stuck Pending
                       invoice: disables it (statut -> cancelled) instead of
                       removing the record. -->
                  <a-menu-item v-if="auth.can('Sales_edit') && record.statut === 'pending'" key="cancel" danger>
                    <StopOutlined /> {{ $t('Cancel') }}
                  </a-menu-item>
                  <!-- Reversing a cancel is SuperAdmin-only: a regular admin's
                       cancel is otherwise permanent. -->
                  <a-menu-item v-if="isSuperAdmin && record.statut === 'cancelled'" key="restore">
                    <RollbackOutlined /> {{ $t('Restore') }}
                  </a-menu-item>
                  <a-menu-item v-if="auth.can('shipment')" key="shipment">
                    <CarOutlined /> {{ $t('Edit_Shipping') }}
                  </a-menu-item>
                  <a-menu-item key="invoice-pos">
                    <FileTextOutlined /> {{ $t('Invoice_POS') }}
                  </a-menu-item>
                  <a-menu-item v-if="whatsappEnabled" key="whatsapp">
                    <WhatsAppOutlined /> WhatsApp Notification
                  </a-menu-item>
                  <a-menu-item v-if="smsEnabled" key="sms">
                    <MessageOutlined /> {{ $t('sms_notification') }}
                  </a-menu-item>
                  <a-menu-divider v-if="auth.can('Sales_delete') && record.sale_has_return === 'no'" />
                  <a-menu-item v-if="auth.can('Sales_delete') && record.sale_has_return === 'no'" key="delete" danger>
                    <DeleteOutlined /> {{ $t('Del') }}
                  </a-menu-item>
                </a-menu>
              </template>
            </a-dropdown>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- ============ Send Email / Reminder recipients modal ============ -->
    <EmailRecipientsModal
      v-model:open="emailModal.open"
      :title="`${emailModal.kind === 'reminder' ? 'Send payment reminder' : $t('email_notification')} — ${emailModal.sale?.Ref || ''}`"
      :contact-label="$t('Customer')"
      contact-noun="customer"
      :contact-name="emailModal.sale?.client_name || ''"
      :main-email="emailModal.sale?.client_email || ''"
      :additional-emails="emailModal.sale?.client_additional_emails || []"
      :sending="emailModal.sending"
      @send="sendEmailModal"
    />

    <!-- ============ Payments list modal ============ -->
    <a-modal v-model:open="paymentsOpen" :title="`${$t('ShowPayment')} — ${activeSale?.Ref || ''}`" :footer="null" width="820px">
      <a-table :columns="paymentColumns" :data-source="payments" :pagination="false" row-key="id" size="small" :scroll="{ x: 'max-content' }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'montant'">{{ money(record.montant) }}</template>
          <template v-else-if="column.key === 'method'">{{ record.payment_method?.name || '---' }}</template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-tooltip :title="$t('DownloadPdf')">
                <a-button type="text" size="small" @click="paymentPdf(record)">
                  <template #icon><FilePdfOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="$t('email_notification')">
                <a-button type="text" size="small" @click="paymentNotify('payment_sale_send_email', record)">
                  <template #icon><MailOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="$t('sms_notification')">
                <a-button type="text" size="small" @click="paymentNotify('payment_sale_send_sms', record)">
                  <template #icon><MessageOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip v-if="auth.can('payment_sales_edit')" :title="$t('Edit')">
                <a-button type="text" size="small" @click="openEditPayment(record)">
                  <template #icon><EditOutlined style="color: #52c41a" /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip v-if="auth.can('payment_sales_delete')" :title="$t('Del')">
                <a-button type="text" size="small" danger @click="removePayment(record)">
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
        {{ $t('Due') }}: <span style="color: #ff4d4f">{{ money(paymentsDue) }}</span>
      </div>
    </a-modal>

    <!-- ============ Add / edit payment modal ============ -->
    <a-modal
      v-model:open="payFormOpen"
      :title="`${payEditing ? $t('Edit') : $t('AddPayment')} — ${activeSale?.Ref || ''}`"
      :confirm-loading="paySaving"
      :ok-text="$t('submit')"
      @ok="submitPayment"
    >
      <a-form layout="vertical">
        <a-alert type="info" show-icon style="margin-bottom: 12px" :message="$t('Payment_Amounts_Help')" />
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('date')">
              <a-date-picker v-model:value="payForm.date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Amount_Paid')">
              <a-input-number v-model:value="payForm.montant" style="width: 100%" :min="0" :max="payMaxDue" />
              <a-typography-text type="secondary" style="font-size: 12px; display: block">
                {{ $t('Amount_Paid_Help') }}
              </a-typography-text>
              <a-typography-text type="secondary" style="font-size: 12px; display: block">
                {{ $t('Maximum_payment') }}: {{ money(payMaxDue) }}
              </a-typography-text>
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Received_Amount')">
              <a-input-number v-model:value="payForm.received_amount" style="width: 100%" :min="0" />
              <a-typography-text type="secondary" style="font-size: 12px; display: block">
                {{ $t('Received_Amount_Help') }}
              </a-typography-text>
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Paymentchoice')">
              <a-select v-model:value="payForm.payment_method_id" :options="paymentMethodOptions" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Account')">
              <a-select v-model:value="payForm.account_id" allow-clear :placeholder="$t('Choose_Account')" :options="accountOptions" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Change')">
              <a-input :value="money(payChange)" disabled />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Due')">
              <a-input :value="money(activeSale?.due)" disabled />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Note')">
              <a-textarea v-model:value="payForm.notes" :rows="2" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- ============ Shipment modal ============ -->
    <a-modal
      v-model:open="shipmentOpen"
      :title="$t('Edit_Shipping')"
      :confirm-loading="shipmentSaving"
      :ok-text="$t('submit')"
      @ok="submitShipment"
    >
      <a-form layout="vertical">
        <a-form-item :label="$t('Status')">
          <a-select v-model:value="shipment.status" :options="shippingStatusOptions" :placeholder="$t('Choose_Status')" />
        </a-form-item>
        <a-form-item :label="$t('delivered_to')">
          <a-input v-model:value="shipment.delivered_to" />
        </a-form-item>
        <a-form-item :label="$t('Adress')">
          <a-input v-model:value="shipment.shipping_address" />
        </a-form-item>
        <a-form-item :label="$t('Please_provide_any_details')">
          <a-textarea v-model:value="shipment.shipping_details" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- ============ Documents modal ============ -->
    <a-modal v-model:open="documentsOpen" :title="`${$t('Attach_Documents')} — ${activeSale?.Ref || ''}`" :footer="null" width="640px">
      <a-alert
        type="info" show-icon style="margin-bottom: 12px"
        message="Documents, images, videos, and .zip/.rar archives only — max 25 MB per file."
        description="Executables and scripts (.exe, .js, .bat, .sh, .msi, .dll, .vbs, .ps1, .jar, etc.) are not allowed."
      />
      <a-upload
        :file-list="docFiles"
        :accept="ALLOWED_DOCUMENT_EXTENSIONS.map(e => '.' + e).join(',')"
        :before-upload="onBeforeUploadDocument"
        multiple
        @remove="f => { docFiles = docFiles.filter(x => x !== f); }"
      >
        <a-button><UploadOutlined /> {{ $t('Attach_Documents') }}</a-button>
      </a-upload>
      <a-button
        type="primary"
        style="margin-top: 8px"
        :loading="docUploading"
        :disabled="!docFiles.length"
        @click="uploadDocuments"
      >
        {{ $t('submit') }}
      </a-button>
      <a-divider style="margin: 16px 0" />
      <a-list :data-source="documents" size="small">
        <template #renderItem="{ item }">
          <a-list-item>
            <span>{{ item.name || item.file_name || item.original_name }}</span>
            <template #actions>
              <a-button type="text" size="small" @click="downloadDocument(item)">
                <template #icon><DownloadOutlined /></template>
              </a-button>
              <a-button type="text" size="small" danger @click="removeDocument(item)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </template>
          </a-list-item>
        </template>
        <template #emptyText>
          <a-empty :description="$t('NodataAvailable')" style="padding: 16px 0" />
        </template>
      </a-list>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Full legacy action surface for the sales list. Contracts:
 * - list GET sales (+filters) → {sales, customers, accounts, warehouses,
 *   payment_methods, totalRows}; bulk delete POST sales_delete_by_selection
 * - list exports refetch with limit=-1, then exporters.js (legacy exported
 *   only the visible page — full set is strictly better)
 * - payments: GET get_payments_by_sale/{id} → {payments, due};
 *   POST payment_sale (payments[] lines — we send one), PUT payment_sale/{id},
 *   DELETE payment_sale/{id} — the payment Ref is assigned server-side,
 *   receipt PDF GET payment_sale_pdf/{id}, notify POST
 *   payment_sale_send_email|payment_sale_send_sms {id}
 * - shipment: GET shipments/{sale_id} → {shipment}; POST shipments (upsert)
 * - invoice: GET sale_pdf/{id} (blob) → Sale-{Ref}.pdf; POS receipt data from
 *   GET sales_print_invoice/{id} rendered into a print window styled by
 *   /css/pos_print.css via lib/posReceiptHtml (all five receipt_layout
 *   variants from Settings → POS Receipt), honoring every pos_settings
 *   show_* toggle, note_customer, logo/paper size, and the ZATCA +
 *   invoice-URL QRs (same flag semantics as the PosPage receipt)
 * - notify: POST sales_send_email|sales_send_sms {id}; WhatsApp POST
 *   sales_send_whatsapp {id} → {phone, message} → web.whatsapp.com link
 * - documents: GET sales/{id}/documents; POST sales/{id}/documents
 *   (documents[] + sale_id); GET sales/documents/{id}/download (blob);
 *   DELETE sales/documents/{id}
 */
import { ref, computed, createVNode } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { message, Modal, Upload } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, MoreOutlined,
  FilePdfOutlined, FileExcelOutlined, FileTextOutlined, MailOutlined,
  MessageOutlined, CarOutlined, RollbackOutlined,
  PaperClipOutlined, UploadOutlined, DownloadOutlined, WhatsAppOutlined,
  ExclamationCircleOutlined, ShoppingCartOutlined, DollarOutlined, CheckCircleOutlined,
  StopOutlined, CopyOutlined, FilterOutlined, BellOutlined, DownOutlined, LockOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import CustomizeFiltersModal from '../../components/CustomizeFiltersModal.vue';
import StatusHelpModal from '../../components/StatusHelpModal.vue';
import EmailRecipientsModal from '../../components/EmailRecipientsModal.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useCustomizableFilters } from '../../composables/useCustomizableFilters';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { docStatusColor, payStatusColor } from '../../lib/statusColors';
import { exportExcel, exportPdf } from '../../lib/exporters';
import {
  SALE_STATUSES, PAYMENT_STATUSES, SHIPPING_STATUSES, statusKey, shipStatusColor,
} from './saleVocab';
import http from '../../lib/http';
import { withAllOption, sortByLabel } from '../../lib/filterOptions';
import { useFilterReset } from '../../composables/useFilterReset';
import ResetFiltersButton from '../../components/ResetFiltersButton.vue';
import { printPosReceipt } from '../../lib/printPosReceipt';

const { t } = useI18n();
const { money, number, dateTime, roundMoney } = useFormat();
const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const filters = ref({
  date: null, client_id: undefined, warehouse_id: undefined,
  statut: undefined, payment_statut: undefined, shipping_status: undefined,
  product_group_id: undefined, product_id: undefined,
  date_from: '', date_to: '',
});

// Reset: every filter back to its default, date range back to Today,
// the Customers-list "Due only" link dropped. Snapshot taken here, before
// the deep link below writes into `filters`.
const { isDirty: filtersDirty, resetFilters } = useFilterReset(filters, () => {
  dueOnly.value = false;
  datePreset.value = 'today';
  applyPreset('today');
}, { ignore: ['date_from', 'date_to'], isDirty: () => datePreset.value !== 'today' || dueOnly.value });

// "Customize Filters" -- which of these filters a company shows, and in
// what order (settings.datatable_filters, same idea as DataTable.vue's
// "Customize Columns"). The date-range presets in the page header are a
// separate control, not part of this filter row.
const {
  visibleFilterKeys, customizeOpen, customizeSaving, customizeRows,
  openCustomize, moveRow, saveCustomize, resetCustomize,
} = useCustomizableFilters('sales_filters', [
  { key: 'date', label: 'Date' },
  { key: 'client_id', label: 'Customer' },
  { key: 'statut', label: 'Status' },
  { key: 'payment_statut', label: 'Payment Status' },
  { key: 'shipping_status', label: 'Shipping Status' },
  { key: 'warehouse_id', label: 'Location' },
  { key: 'product_group_id', label: 'Group Name' },
  { key: 'product_id', label: 'Name' },
]);

// "Only sales with a balance due" -- set by the Customers list Due link.
const dueOnly = ref(false);

// Text in the search box searches ALL dates (reference, customer, product,
// group...): a customer's invoices are rarely all in "This Month", so the
// date range would hide them. Clearing the search restores the range.
function searchActive() {
  try {
    return !!String(crud.search.value || '').trim();
  } catch (e) {
    return false; // crud not created yet (first params() call)
  }
}

const filterParams = () => ({
  due_only: dueOnly.value ? 1 : '',
  Ref: '',
  date: searchActive() ? '' : (filters.value.date || ''),
  date_from: searchActive() ? '' : (filters.value.date_from || ''),
  date_to: searchActive() ? '' : (filters.value.date_to || ''),
  client_id: filters.value.client_id || '',
  warehouse_id: filters.value.warehouse_id || '',
  statut: filters.value.statut || '',
  payment_statut: filters.value.payment_statut || '',
  shipping_status: filters.value.shipping_status || '',
  product_group_id: filters.value.product_group_id || '',
  product_id: filters.value.product_id || '',
});

const crud = useCrudTable('sales', {
  rowsKey: 'sales',
  bulkDeleteEndpoint: 'sales_delete_by_selection',
  params: filterParams,
  limit: 20,
  showQuickJumper: true,
});

// ---------------- quick date-range presets ----------------
// Filters both the summary cards (server-side stats) and the table, via
// date_from/date_to -- independent of the exact-day picker below (only one
// of the two is ever active: picking a preset clears the exact date, and
// picking an exact date clears the active preset).
const DATE_PRESETS = [
  { value: 'all', labelKey: 'All' },
  { value: 'year', labelKey: 'This_Year' },
  { value: 'month', labelKey: 'This_Month' },
  { value: 'today', labelKey: 'Today' },
  { value: 'last_month', labelKey: 'Last_Month' },
  { value: 'last_year', labelKey: 'Last_Year' },
];
// Default: Today (the preset row's middle button).
const datePreset = ref('today');

function presetRange(preset) {
  const now = dayjs();
  switch (preset) {
    case 'year':
      return [now.startOf('year'), now.endOf('year')];
    case 'month':
      return [now.startOf('month'), now.endOf('month')];
    case 'today':
      return [now.startOf('day'), now.endOf('day')];
    case 'last_month': {
      const m = now.subtract(1, 'month');
      return [m.startOf('month'), m.endOf('month')];
    }
    case 'last_year': {
      const y = now.subtract(1, 'year');
      return [y.startOf('year'), y.endOf('year')];
    }
    default:
      return [null, null];
  }
}

function applyPreset(preset) {
  filters.value.date = null;
  const [from, to] = presetRange(preset);
  filters.value.date_from = from ? from.format('YYYY-MM-DD') : '';
  filters.value.date_to = to ? to.format('YYYY-MM-DD') : '';
  // The range combines with the dropdown filters (e.g. Last Year + Unpaid),
  // so the customer/warehouse/status selections are kept, not cleared.
  crud.reload();
}

// Picking an exact day is a separate, more specific filter than the presets
// above -- clear the active preset (visually and in the query) so the two
// don't silently fight each other.
function onExactDateChange() {
  datePreset.value = null;
  filters.value.date_from = '';
  filters.value.date_to = '';
  crud.reload();
}

// Customer/Warehouse/Status/PaymentStatus/Shipping filters are AND-ed with
// the active date range (preset or exact day) -- e.g. Last Year + Unpaid
// shows last year's unpaid invoices. Pick "All" to search every date.
function onOtherFilterChange() {
  crud.reload();
}

// Group Name / Name: sales with a line of a product in that group / of that
// product. Name options narrow to the chosen group; a product outside a
// newly chosen group is cleared, and picking a product fills its group in.
const filterProducts = computed(() => crud.payload.value?.filter_products || []);
function onProductGroupFilterChange(groupId) {
  const current = filterProducts.value.find(p => p.id === filters.value.product_id);
  if (current && groupId && current.product_group_id !== groupId) filters.value.product_id = undefined;
  crud.reload();
}
function onProductFilterChange(productId) {
  const product = filterProducts.value.find(p => p.id === productId);
  if (product?.product_group_id) filters.value.product_group_id = product.product_group_id;
  crud.reload();
}

// Deep links (Customers list: Total Sales / # Sales / Due) arrive as
// ?client_id=&statut=&due_only=1 -- show that customer's matching sales
// across ALL dates instead of the This Month default.
const linkedQuery = route.query;
if (linkedQuery.client_id) {
  datePreset.value = 'all';
  filters.value.date_from = '';
  filters.value.date_to = '';
  filters.value.client_id = Number(linkedQuery.client_id);
  if (linkedQuery.statut) filters.value.statut = String(linkedQuery.statut);
  dueOnly.value = linkedQuery.due_only === '1';
  crud.reload();
} else {
  // Default: Today, applied before the first fetch.
  applyPreset(datePreset.value);
}

function clearDueOnly() {
  dueOnly.value = false;
  crud.reload();
}

// What the keycard shows: the exact day (picker) takes priority, then the
// active preset's range, then '—' when neither is set (the "All" preset).
const activeRange = computed(() => {
  if (String(crud.search.value || '').trim()) return { from: 'All dates', to: '(searching)' };
  if (filters.value.date) return { from: filters.value.date, to: filters.value.date };
  return {
    from: filters.value.date_from || '—',
    to: filters.value.date_to || '—',
  };
});

// Summary cards — `stats` ships with the list payload (sums over ALL pages of
// the current filtered set, not just the visible page).
const statTiles = computed(() => {
  const s = crud.payload.value?.stats || {};
  const n = k => Number(s[k]) || 0;
  return [
    { label: t('Sales'), value: n('count'), icon: ShoppingCartOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { label: t('Total'), value: money(n('total')), icon: DollarOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { label: t('Paid'), value: money(n('paid')), icon: CheckCircleOutlined, color: '#52c41a', tint: 'rgba(82, 196, 26, 0.12)' },
    {
      label: t('Due'), value: money(n('due')), icon: ExclamationCircleOutlined,
      color: '#ff4d4f', tint: 'rgba(255, 77, 79, 0.12)',
      style: n('due') > 0 ? { color: '#ff4d4f' } : undefined,
    },
  ];
});

const customerOptions = computed(() =>
  withAllOption(sortByLabel((crud.payload.value?.customers || []).map(c => ({ value: c.id, label: c.name }))))
);

// Defaults -> Sales -> SMS/WhatsApp toggles -- hide the row-action entirely
// (not just disable it) when the company doesn't use that channel.
const smsEnabled = computed(() => crud.payload.value?.sale_sms_enabled !== false);
const whatsappEnabled = computed(() => crud.payload.value?.sale_whatsapp_enabled !== false);

// Reminder icon: only while a balance remains on a non-cancelled invoice.
function isUnpaid(record) {
  return record.payment_status && record.payment_status !== 'paid' && record.payment_status !== 'Cancelled' && record.statut !== 'cancelled';
}
const productGroupOptions = computed(() =>
  withAllOption(sortByLabel((crud.payload.value?.product_groups || []).map(g => ({ value: g.id, label: g.name }))))
);
const productOptions = computed(() =>
  withAllOption(sortByLabel(filterProducts.value
    .filter(p => !filters.value.product_group_id || p.product_group_id === filters.value.product_group_id)
    .map(p => ({ value: p.id, label: p.name }))))
);
const warehouseOptions = computed(() =>
  withAllOption(sortByLabel((crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))))
);
// paymentMethodOptions/accountOptions are used in the Pay Due modal, not a
// table filter -- no "All" option (there's no "no payment method" concept).
const paymentMethodOptions = computed(() =>
  sortByLabel((crud.payload.value?.payment_methods || []).map(m => ({ value: m.id, label: m.name })))
);
const accountOptions = computed(() =>
  sortByLabel((crud.payload.value?.accounts || []).map(a => ({ value: a.id, label: a.account_name })))
);
const statusOptions = computed(() => withAllOption(sortByLabel(SALE_STATUSES.map(s => ({ value: s.value, label: t(s.key) })))));
// Built from the REAL distinct payment_statut values for this company (not
// the full static vocab) -- a company with no legacy 'pending' rows, for
// example, never sees that option, so it can't collide/near-duplicate with
// a real 'unpaid' entry that IS present.
const paymentStatusOptions = computed(() =>
  withAllOption(sortByLabel(
    (crud.payload.value?.payment_statuses || []).map(v => ({
      value: v,
      label: statusKey(PAYMENT_STATUSES, v) ? t(statusKey(PAYMENT_STATUSES, v)) : v,
    }))
  ))
);
const shippingStatusOptions = computed(() => withAllOption(sortByLabel(SHIPPING_STATUSES.map(s => ({ value: s.value, label: t(s.key) })))));

const columns = computed(() => [
  { title: t('Action'), key: 'actions', width: 130, align: 'center', fixed: 'left' },
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => dateTime(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Seller'), dataIndex: 'seller_name', key: 'seller_name' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name', sorter: true },
  { title: t('Items'), dataIndex: 'items_count', key: 'items_count', align: 'center', width: 80, exportValue: r => r.items_count },
  // Distinct product groups / product names on the sale's lines (comma-joined;
  // long lists are cut with an ellipsis, full text on hover).
  { title: t('Group_Name'), dataIndex: 'product_group_names', key: 'product_group_names', width: 180, ellipsis: { showTitle: true } },
  { title: t('Name'), dataIndex: 'product_names', key: 'product_names', width: 220, ellipsis: { showTitle: true } },
  { title: t('Status'), dataIndex: 'statut', key: 'statut', sorter: true, exportValue: r => r.statut },
  // Multi-Currency: badge with the document's currency code (amounts in the
  // list stay base-currency). Hidden when the module is off.
  ...(auth.multiCurrencyEnabled
    ? [{ title: t('Currency'), dataIndex: 'currency_code', key: 'currency_code', width: 90, align: 'center', exportValue: r => r.currency_code || '' }]
    : []),
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', sorter: true, align: 'right', exportValue: r => money(r.GrandTotal) },
  { title: t('Paid'), dataIndex: 'paid_amount', key: 'paid_amount', sorter: true, align: 'right', exportValue: r => money(r.paid_amount) },
  { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right', exportValue: r => money(r.due) },
  // Other invoices for this same customer still carrying a due balance --
  // blank cell when there are none, per the design ask ("if not exist,
  // don't show nothing"). exportValue mirrors that: '' not '0'.
  { title: 'Other Due', dataIndex: 'other_due_count', key: 'other_due', align: 'center', width: 100, exportValue: r => (r.other_due_count > 0 ? String(r.other_due_count) : '') },
  { title: t('PaymentStatus'), dataIndex: 'payment_status', key: 'payment_status', exportValue: r => r.payment_status },
  { title: t('Shipping_status'), dataIndex: 'shipping_status', key: 'shipping_status', exportValue: r => r.shipping_status || '' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name', sorter: true },
]);

// ---------------- list exports (PDF / Excel) ----------------

const exporting = ref(null);

async function exportList(kind) {
  exporting.value = kind;
  try {
    const data = await http.get('sales', {
      page: 1,
      SortField: crud.sortField.value,
      SortType: crud.sortType.value,
      search: crud.search.value,
      limit: -1,
      ...filterParams(),
    });
    const rows = data.sales || [];
    if (kind === 'xlsx') await exportExcel('sales', columns.value, rows);
    else await exportPdf(t('ListSales'), columns.value, rows);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    exporting.value = null;
  }
}

// ---------------- row action dispatch ----------------

const activeSale = ref(null);

function onAction(key, record) {
  activeSale.value = record;
  const go = {
    detail: () => router.push(`/sales/${record.id}`),
    edit: () => router.push(`/sales/${record.id}/edit`),
    'return-add': () => router.push(`/sale-returns/create/${record.id}`),
    'return-edit': () => router.push(`/sale-returns/${record.salereturn_id}/edit/${record.id}`),
    shipment: () => openShipment(record),
    'invoice-pos': () => invoicePos(record),
    whatsapp: () => sendWhatsApp(record),
    sms: () => notify('sales_send_sms', record.id, 'sms_send_successfully', 'sms_config_invalid'),
    delete: () => crud.remove(record, { label: record.Ref }),
    cancel: () => cancelSale(record),
    restore: () => restoreSale(record),
  };
  go[key]?.();
}

// ---------------- status tag quick actions (pending -> ordered/completed/cancelled) ----------------

const isSuperAdmin = computed(() => auth.user?.type === 'SuperAdmin');

// Who may edit a sale -- shared by the Reference chooser and the kebab menu.
function canEditSale(record) {
  return auth.can('Sales_edit') && record.sale_has_return === 'no' && record.statut !== 'cancelled'
    && (record.statut !== 'completed' || record.payment_status !== 'paid' || isSuperAdmin.value || auth.can('edit_completed_paid_sale'));
}

// Why Edit is unavailable, shown under the disabled item in the chooser.
function editLockedReason(record) {
  if (!auth.can('Sales_edit')) return 'No permission to edit sales';
  if (record.sale_has_return !== 'no') return 'Has a sale return';
  if (record.statut === 'cancelled') return 'Sale is cancelled';
  return 'Completed and paid';
}

function onStatusMenuClick(key, record) {
  if (key === 'cancel') return cancelSale(record);
  if (key === 'ordered') return markOrdered(record);
  if (key === 'completed') return markCompleted(record);
}

function markOrdered(record) {
  Modal.confirm({
    title: t('Ordered'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${t('Confirm_Mark_Ordered')} — ${record.Ref}`,
    okText: t('Ordered'),
    cancelText: t('No'),
    async onOk() {
      try {
        await http.post(`sales/${record.id}/mark-ordered`);
        message.success(t('Successfully_Updated'));
        crud.fetchRows();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

function markCompleted(record) {
  Modal.confirm({
    title: t('complete'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${t('Confirm_Mark_Completed')} — ${record.Ref}`,
    okText: t('complete'),
    cancelText: t('No'),
    async onOk() {
      try {
        await http.post(`sales/${record.id}/mark-completed`);
        message.success(t('Successfully_Updated'));
        crud.fetchRows();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

function cancelSale(record) {
  Modal.confirm({
    title: t('Cancel'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${t('Confirm_Cancel_Sale')} — ${record.Ref}`,
    okText: t('Yes_Cancel_It'),
    okType: 'danger',
    cancelText: t('No'),
    async onOk() {
      try {
        await http.post(`sales/${record.id}/cancel`);
        message.success(t('Successfully_Updated'));
        crud.fetchRows();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

// SuperAdmin-only: reversing a cancel is otherwise permanent for a regular admin.
function restoreSale(record) {
  Modal.confirm({
    title: t('Restore'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${t('Confirm_Restore_Sale')} — ${record.Ref}`,
    okText: t('Restore'),
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.post(`sales/${record.id}/restore`);
        message.success(t('Successfully_Updated'));
        crud.fetchRows();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

// ---------------- notifications (email / SMS / WhatsApp) ----------------

async function notify(endpoint, id, okKey, failKey, extra = {}) {
  try {
    const data = await http.post(endpoint, { id, ...extra });
    message.success(data?.message || t(okKey));
    return true;
  } catch (e) {
    // Surface the backend's actual reason (e.g. "Mail is not configured for
    // this company yet.") instead of a generic translated fallback -- a
    // send that silently "does nothing" is exactly what this replaces.
    const detail = typeof e?.data?.errors === 'string' ? e.data.errors : (e?.data?.errors && JSON.stringify(e.data.errors));
    const summary = e?.data?.message || t(failKey);
    message.error(detail ? `${summary} ${detail}` : summary, 8);
    return false;
  }
}

// Send Email / Reminder go through the shared recipients modal
// (EmailRecipientsModal); a typed new address is saved to the customer
// server-side (ResolvesEmailRecipients).
const emailModal = ref({ open: false, kind: 'email', sale: null, sending: false });

function openEmailModal(record, kind) {
  emailModal.value = { open: true, kind, sale: record, sending: false };
}

async function sendEmailModal(emails, hasNew) {
  const m = emailModal.value;
  const reminder = m.kind === 'reminder';
  m.sending = true;
  const ok = await notify(
    reminder ? 'sales_send_reminder' : 'sales_send_email', m.sale.id,
    reminder ? 'ReminderSent' : 'SendEmail', 'SMTPIncorrect',
    { emails },
  );
  m.sending = false;
  if (ok) {
    m.open = false;
    // New addresses were saved to the customer -- refresh so the next open offers them.
    if (hasNew) crud.fetchRows();
  }
}

async function sendWhatsApp(record) {
  try {
    const data = await http.post('sales_send_whatsapp', { id: record.id });
    const url = `https://web.whatsapp.com/send/?phone=${encodeURIComponent(data.phone)}&text=${encodeURIComponent(data.message)}`;
    window.open(url, '_blank');
  } catch (e) {
    message.error(t('Failed'));
  }
}

// ---------------- invoice PDF + POS receipt ----------------

function invoicePdf(record) {
  http.download(`sale_pdf/${record.id}`, `Sale-${record.Ref}.pdf`)
    .catch(() => message.error(t('InvalidData')));
}

// Opens Sales Create prefilled from this invoice's own line detail (price,
// discount, tax as originally recorded) -- SaleForm.vue's isDuplicate branch
// -- so the user only adjusts what's different instead of re-entering
// everything. Still a plain create underneath: no :id route param, so it
// submits a brand-new sale, never touching the source record.
function duplicateSale(record) {
  router.push({ path: '/sales/create', query: { duplicate: record.id } });
}

// POS receipt reprint -- shared with POS Sales (lib/printPosReceipt.js).
function invoicePos(record) {
  return printPosReceipt(record.id, { t, number, onError: () => message.error(t('InvalidData')) });
}

// ---------------- payments ----------------

const paymentsOpen = ref(false);
const payments = ref([]);
const paymentsDue = ref(0);

const paymentColumns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date' },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('Amount'), dataIndex: 'montant', key: 'montant', align: 'right' },
  { title: t('PayeBy'), key: 'method' },
  { title: t('Action'), key: 'actions', width: 180, align: 'center' },
]);

async function loadPayments(saleId) {
  const data = await http.get(`get_payments_by_sale/${saleId}`);
  payments.value = data.payments || [];
  paymentsDue.value = data.due || 0;
}

// Payment Status tag now doubles as the ShowPayment/AddPayment trigger
// (row menu no longer carries them): paid -> view payments, otherwise ->
// add a payment (only when the sale is actually payable).
function paymentStatusClickable(record) {
  if (record.payment_status === 'paid') return auth.can('payment_sales_view');
  return auth.can('payment_sales_add') && record.statut === 'completed';
}
function onPaymentStatusClick(record) {
  if (!paymentStatusClickable(record)) return;
  activeSale.value = record;
  if (record.payment_status === 'paid') showPayments(record);
  else openAddPayment(record);
}

async function showPayments(record) {
  try {
    await loadPayments(record.id);
    paymentsOpen.value = true;
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

function paymentPdf(payment) {
  http.download(`payment_sale_pdf/${payment.id}`, `Payment-${payment.Ref}.pdf`)
    .catch(() => message.error(t('InvalidData')));
}

async function paymentNotify(endpoint, payment) {
  try {
    await http.post(endpoint, { id: payment.id });
    message.success(t('Success'));
  } catch (e) {
    message.error(t('Failed'));
  }
}

function removePayment(payment) {
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: t('Delete_Text'),
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`payment_sale/${payment.id}`);
        message.success(t('Deleted_in_successfully'));
        await loadPayments(activeSale.value.id);
        crud.fetchRows();
      } catch (e) {
        message.error(t('InvalidData'));
      }
    },
  });
}

const payFormOpen = ref(false);
const payEditing = ref(null);
const paySaving = ref(false);
const payForm = ref({});

const payChange = computed(() =>
  Math.max(0, (Number(payForm.value.received_amount) || 0) - (Number(payForm.value.montant) || 0))
);

// Old-admin rule: a payment cannot exceed what's still owed. When editing, the
// payment's own amount is owed again (it is being replaced), so it re-enters
// the cap. Rounded so the input's clamp never shows a raw float artifact.
const payMaxDue = computed(() => roundMoney(
  payEditing.value
    ? (Number(paymentsDue.value) || 0) + (Number(payEditing.value.montant) || 0)
    : activeSale.value?.due
));

function openAddPayment(record) {
  if (record.payment_status === 'paid') {
    message.warning(t('PaymentComplete'));
    return;
  }
  payEditing.value = null;
  payForm.value = {
    // Defaults to the invoice's own date (record.date is "date time"), not
    // today -- editable in the picker; today only if the date is unusable.
    date: dayjs(String(record.date || '').slice(0, 10)).isValid()
      ? String(record.date).slice(0, 10)
      : dayjs().format('YYYY-MM-DD'),
    montant: roundMoney(record.due),
    received_amount: roundMoney(record.due),
    payment_method_id: 2,
    account_id: undefined,
    notes: '',
  };
  payFormOpen.value = true;
}

function openEditPayment(payment) {
  payEditing.value = payment;
  payForm.value = {
    date: payment.date,
    montant: Number(payment.montant) || 0,
    received_amount: +((Number(payment.montant) || 0) + (Number(payment.change) || 0)).toFixed(2),
    payment_method_id: payment.payment_method_id,
    account_id: payment.account_id || undefined,
    notes: payment.notes || '',
  };
  payFormOpen.value = true;
}

async function submitPayment() {
  const f = payForm.value;
  // Same guards as the pre-migration admin (Verified_paidAmount / Submit_Payment).
  const montant = Number(f.montant) || 0;
  if (montant <= 0 || !f.payment_method_id) {
    message.warning(t('Please_fill_the_form_correctly'));
    return;
  }
  if (montant > (Number(f.received_amount) || 0)) {
    message.warning(t('Paying_amount_is_greater_than_Received_amount'));
    return;
  }
  if (montant > payMaxDue.value) {
    message.warning(t('Paying_amount_is_greater_than_Grand_Total'));
    return;
  }
  paySaving.value = true;
  try {
    if (payEditing.value) {
      await http.put(`payment_sale/${payEditing.value.id}`, {
        sale_id: activeSale.value.id,
        date: f.date,
        montant: (Number(f.montant) || 0).toFixed(2),
        received_amount: (Number(f.received_amount) || 0).toFixed(2),
        change: payChange.value.toFixed(2),
        payment_method_id: f.payment_method_id,
        account_id: f.account_id || null,
        notes: f.notes,
      });
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('payment_sale', {
        sale_id: activeSale.value.id,
        date: f.date,
        payments: [{
          montant: Number(f.montant) || 0,
          payment_method_id: f.payment_method_id,
          account_id: f.account_id || null,
        }],
        montant: (Number(f.montant) || 0).toFixed(2),
        received_amount: (Number(f.received_amount) || 0).toFixed(2),
        change: payChange.value.toFixed(2),
        payment_method_id: f.payment_method_id,
        account_id: f.account_id || null,
        notes: f.notes,
      });
      message.success(t('Successfully_Created'));
    }
    payFormOpen.value = false;
    crud.fetchRows();
    if (paymentsOpen.value) await loadPayments(activeSale.value.id);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    paySaving.value = false;
  }
}

// ---------------- shipment ----------------

const shipmentOpen = ref(false);
const shipmentSaving = ref(false);
const shipment = ref({});

async function openShipment(record) {
  try {
    const data = await http.get(`shipments/${record.id}`);
    shipment.value = data.shipment || {};
    shipmentOpen.value = true;
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

async function submitShipment() {
  shipmentSaving.value = true;
  try {
    await http.post('shipments', {
      Ref: shipment.value.Ref,
      sale_id: shipment.value.sale_id,
      shipping_address: shipment.value.shipping_address,
      delivered_to: shipment.value.delivered_to,
      shipping_details: shipment.value.shipping_details,
      status: shipment.value.status,
    });
    message.success(t('Successfully_Updated'));
    shipmentOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    shipmentSaving.value = false;
  }
}

// ---------------- documents ----------------

const documentsOpen = ref(false);
const documents = ref([]);
const docFiles = ref([]);
const docUploading = ref(false);

// Same allow-list as SalesController::uploadDocuments' `mimes:` rule --
// documents/images/videos/archives, no executables or scripts. Client-side
// check is convenience only; the backend `mimes:`/`max:` rule is the real
// boundary since a user can always bypass a JS check.
const ALLOWED_DOCUMENT_EXTENSIONS = [
  'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf', 'odt', 'ods', 'odp',
  'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg',
  'mp4', 'mov', 'avi', 'mkv', 'webm',
  'zip', 'rar', '7z',
];
const MAX_DOCUMENT_SIZE_MB = 25;

function onBeforeUploadDocument(file) {
  const ext = (file.name.split('.').pop() || '').toLowerCase();
  if (!ALLOWED_DOCUMENT_EXTENSIONS.includes(ext)) {
    message.error(`".${ext}" files are not allowed. Allowed: documents, images, videos, .zip/.rar archives.`);
    return Upload.LIST_IGNORE;
  }
  if (file.size > MAX_DOCUMENT_SIZE_MB * 1024 * 1024) {
    message.error(`"${file.name}" is over the ${MAX_DOCUMENT_SIZE_MB} MB limit.`);
    return Upload.LIST_IGNORE;
  }
  docFiles.value = [...docFiles.value, file];
  return false;
}

async function loadDocuments(saleId) {
  const data = await http.get(`sales/${saleId}/documents`);
  documents.value = data.documents || [];
}

async function openDocuments(record) {
  // Fix: this used to leave `activeSale` at whatever it was (null on a fresh
  // page, or a DIFFERENT row's sale if the "..." dropdown was used earlier),
  // since only onAction() set it -- uploadDocuments() then either threw on
  // `activeSale.value.id` (docUploading stuck true forever, matching the
  // reported "stuck on submit" with no error) or silently uploaded to the
  // wrong sale.
  activeSale.value = record;
  docFiles.value = [];
  try {
    await loadDocuments(record.id);
    documentsOpen.value = true;
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

async function uploadDocuments() {
  if (!docFiles.value.length) {
    message.warning(t('Please_select_files'));
    return;
  }
  if (!activeSale.value?.id) {
    message.error(t('InvalidData'));
    return;
  }
  docUploading.value = true;
  const fd = new FormData();
  docFiles.value.forEach(f => fd.append('documents[]', f.originFileObj || f));
  fd.append('sale_id', activeSale.value.id);
  try {
    await http.postForm(`sales/${activeSale.value.id}/documents`, fd);
    message.success(t('Documents_uploaded_successfully'));
    docFiles.value = [];
    await loadDocuments(activeSale.value.id);
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('Failed_to_upload_documents'));
  } finally {
    docUploading.value = false;
  }
}

function downloadDocument(doc) {
  http.download(`sales/documents/${doc.id}/download`, doc.name || doc.file_name || 'document')
    .catch(() => message.error(t('Failed_to_download_document')));
}

function removeDocument(doc) {
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: t('Delete_Text'),
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`sales/documents/${doc.id}`);
        message.success(t('Deleted_in_successfully'));
        await loadDocuments(activeSale.value.id);
        crud.fetchRows();
      } catch (e) {
        message.error(t('InvalidData'));
      }
    },
  });
}
</script>

<style scoped>
.range-card {
  border-radius: 10px;
}
.range-card :deep(.ant-card-body) {
  padding: 6px 14px;
  display: flex;
  align-items: center;
  gap: 14px;
  height: 100%;
}
.range-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  white-space: nowrap;
}
.range-label {
  opacity: 0.6;
}
.range-value {
  font-size: 13px;
}
.ref-link {
  white-space: nowrap;
}
.ref-link__caret {
  font-size: 10px;
  opacity: 0.55;
  margin-left: 2px;
}
.ref-link__why {
  font-size: 11px;
  opacity: 0.75;
}
/* Row action icons: one color per action, soft tint of it on hover. */
.row-act {
  --act: #595959;
  color: var(--act);
  border-radius: 6px;
}
.row-act:hover,
.row-act:focus-visible {
  color: var(--act) !important;
  background: color-mix(in srgb, var(--act) 14%, transparent) !important;
}
.row-act--pdf { --act: #d4380d; }
.row-act--mail { --act: #1677ff; }
.row-act--remind { --act: #d48806; }
.row-act--dup { --act: #722ed1; }
.row-act--docs { --act: #08979c; }
.filters-line {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 8px 16px;
}
.filters-line__item {
  flex: 1 1 150px;
  min-width: 0;
}
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}

/* Summary cards — same design as the customers/suppliers lists. */
.stat-card {
  border-radius: 10px;
}
.stat-inner {
  display: flex;
  align-items: center;
  gap: 12px;
}
.stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex: none;
}
.stat-meta {
  min-width: 0;
}
.stat-label {
  opacity: 0.65;
  font-size: 13px;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.stat-value {
  font-size: 20px;
  font-weight: 700;
  white-space: nowrap;
}
@media (max-width: 575px) {
  .stat-inner {
    gap: 8px;
  }
  .stat-icon {
    width: 36px;
    height: 36px;
    font-size: 16px;
  }
  .stat-value {
    font-size: 16px;
  }
}
</style>
