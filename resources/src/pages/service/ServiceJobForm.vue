<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit_Service_Job') : $t('Create_Service_Job')"
      :breadcrumb="[$t('Service_Maintenance'), isEdit ? $t('Edit_Service_Job') : $t('Create_Service_Job')]"
    >
      <template #extra>
        <a-button @click="$router.push('/service/jobs')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Back_to_Service_Jobs') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- Job header summary (edit mode) -->
      <a-card v-if="isEdit && jobMeta" size="small" style="margin-bottom: 16px">
        <a-row :gutter="[16, 12]">
          <a-col :xs="12" :md="4">
            <a-statistic :title="$t('Reference')" :value="jobMeta.Ref || '-'" :value-style="{ fontSize: '16px' }" />
          </a-col>
          <a-col :xs="12" :md="4">
            <div class="stat-label">{{ $t('Status') }}</div>
            <a-tag :color="statusColor(jobMeta.status)">{{ statusLabel(jobMeta.status) }}</a-tag>
          </a-col>
          <a-col :xs="12" :md="4">
            <a-statistic :title="$t('Total')" :value="formatNumber(totals.total_amount)" :prefix="currencySymbol" :value-style="{ fontSize: '16px' }" />
          </a-col>
          <a-col :xs="12" :md="4">
            <a-statistic :title="$t('Paid')" :value="formatNumber(totals.paid_amount)" :prefix="currencySymbol" :value-style="{ fontSize: '16px', color: '#3f8600' }" />
          </a-col>
          <a-col :xs="12" :md="4">
            <a-statistic
              :title="$t('Balance_Due')" :value="formatNumber(totals.balance_due)" :prefix="currencySymbol"
              :value-style="{ fontSize: '16px', color: totals.balance_due > 0 ? '#cf1322' : '#3f8600' }"
            />
          </a-col>
          <a-col :xs="12" :md="4">
            <div class="stat-label">{{ $t('Payment') }}</div>
            <a-tag :color="paymentColor(jobMeta.payment_status)">{{ $t(jobMeta.payment_status || 'unpaid') }}</a-tag>
          </a-col>
        </a-row>
      </a-card>

      <a-card>
        <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
          <a-tabs v-model:activeKey="activeTab">
            <!-- ============== TAB 1: Intake ============== -->
            <a-tab-pane key="intake" :tab="$t('Intake')">
              <a-row :gutter="16">
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('Customer') + ' *'" name="client_id">
                    <a-select
                      v-model:value="form.client_id" :placeholder="$t('Choose_Customer')"
                      :options="clients.map(c => ({ label: c.name, value: c.id }))"
                      show-search option-filter-prop="label" allow-clear
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('Technician')">
                    <a-select
                      v-model:value="form.technician_id" :placeholder="$t('Choose_Technician')"
                      :options="technicians.map(x => ({ label: x.full_name, value: x.id }))"
                      show-search option-filter-prop="label" allow-clear
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('Service_Item') + ' *'" name="service_item">
                    <a-input v-model:value="form.service_item" :placeholder="$t('Service_Item')" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('Job_Type')">
                    <a-input v-model:value="form.job_type" :placeholder="$t('Job_Type')" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('Status')">
                    <a-select v-model:value="form.status" :options="statusOptions" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="4">
                  <a-form-item :label="$t('Scheduled_Date')">
                    <a-input v-model:value="form.scheduled_date" type="datetime-local" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="4">
                  <a-form-item :label="$t('End_Time')">
                    <a-input v-model:value="form.scheduled_end_date" type="datetime-local" />
                  </a-form-item>
                </a-col>
              </a-row>

              <a-divider orientation="left">{{ $t('Device_Information') }}</a-divider>
              <a-row :gutter="16">
                <a-col :xs="12" :md="6">
                  <a-form-item :label="$t('Brand')">
                    <a-input v-model:value="form.device_brand" placeholder="Apple, Samsung..." />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="6">
                  <a-form-item :label="$t('Model')">
                    <a-input v-model:value="form.device_model" placeholder="iPhone 13, Galaxy S22..." />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="6">
                  <a-form-item :label="$t('Color')">
                    <a-input v-model:value="form.device_color" />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="6">
                  <a-form-item :label="$t('Serial_Number')">
                    <a-input v-model:value="form.device_serial" />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="6">
                  <a-form-item :label="$t('IMEI')">
                    <a-input v-model:value="form.device_imei" />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="6">
                  <a-form-item :label="$t('Unlock_Code')">
                    <a-input v-model:value="form.device_password" :placeholder="$t('PIN_pattern_password')" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Accessories_Received')">
                    <a-checkbox-group v-model:value="form.accessories" :options="accessoryOptions" />
                  </a-form-item>
                </a-col>
              </a-row>

              <a-row :gutter="16">
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Condition_On_Arrival')">
                    <a-textarea v-model:value="form.condition_on_arrival" :rows="3" :placeholder="$t('Pre_existing_damage_scratches_etc')" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Reported_Issue')">
                    <a-textarea v-model:value="form.reported_issue" :rows="3" :placeholder="$t('What_the_customer_said')" />
                  </a-form-item>
                </a-col>
                <a-col :span="24">
                  <a-form-item :label="$t('Notes')">
                    <a-textarea v-model:value="form.notes" :rows="2" />
                  </a-form-item>
                </a-col>
              </a-row>
            </a-tab-pane>

            <!-- ============== TAB 2: Diagnostic ============== -->
            <a-tab-pane key="diagnostic" :tab="$t('Diagnostic')">
              <a-row :gutter="16">
                <a-col :xs="24" :md="18">
                  <a-form-item :label="$t('Diagnosis')">
                    <a-textarea v-model:value="form.diagnosis" :rows="4" :placeholder="$t('What_the_technician_found')" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="6">
                  <a-form-item :label="$t('Diagnostic_Fee')">
                    <a-input-number v-model:value="form.diagnostic_fee" :min="0" :step="0.01" style="width: 100%" />
                  </a-form-item>
                </a-col>
              </a-row>

              <a-divider orientation="left">{{ $t('Checklist') }}</a-divider>
              <a-empty v-if="checklistItems.length === 0" :description="$t('No_checklist_items_defined')" />
              <a-row v-else :gutter="[16, 12]">
                <a-col v-for="item in checklistItems" :key="item.id" :xs="24" :md="8">
                  <div class="checklist-row">
                    <span>{{ item.name }}</span>
                    <a-switch :checked="!!checklistState[item.id]" @change="v => (checklistState[item.id] = v)" />
                  </div>
                </a-col>
              </a-row>
            </a-tab-pane>

            <!-- ============== TAB 3: Quote ============== -->
            <a-tab-pane key="quote" :tab="$t('Quote')">
              <a-row :gutter="16">
                <a-col :xs="24" :md="6">
                  <a-form-item :label="$t('Quote_Amount')" :extra="$t('Leave_blank_to_use_line_items')">
                    <a-input-number v-model:value="form.quote_amount" :min="0" :step="0.01" style="width: 100%" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="6">
                  <a-form-item :label="$t('Valid_Until')">
                    <a-input v-model:value="form.quote_valid_until" type="date" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="6">
                  <a-form-item :label="$t('Warranty_Days')">
                    <a-input-number v-model:value="form.warranty_days" :min="0" :max="3650" style="width: 100%" />
                  </a-form-item>
                </a-col>
                <a-col v-if="isEdit && jobMeta && jobMeta.parent_job_id" :xs="24" :md="6">
                  <a-form-item :label="$t('Warranty_Claim_For')">
                    <a-tag color="success">
                      <SafetyCertificateOutlined /> Job #{{ jobMeta.parent_job_id }}
                    </a-tag>
                  </a-form-item>
                </a-col>
              </a-row>

              <a-space v-if="isEdit" wrap style="margin-bottom: 16px">
                <a-button @click="downloadQuotePdf">
                  <template #icon><FileTextOutlined /></template>
                  {{ $t('Download_Quote_PDF') }}
                </a-button>
                <a-button
                  v-if="jobMeta && jobMeta.quotation_id"
                  @click="$router.push(`/quotations/detail/${jobMeta.quotation_id}`)"
                >
                  <template #icon><CheckOutlined /></template>
                  Linked to {{ jobMeta.quotation_ref || ('Quotation #' + jobMeta.quotation_id) }}
                </a-button>
                <a-button v-else :loading="creatingQuotation" @click="sendToQuotations">
                  <template #icon><SendOutlined /></template>
                  Send to Quotations
                </a-button>
              </a-space>

              <template v-if="isEdit && jobMeta">
                <a-alert
                  v-if="jobMeta.quote_approved_at"
                  type="success" show-icon
                  :message="$t('Quote_Approved')"
                  :description="`${formatDate(jobMeta.quote_approved_at)}${jobMeta.quote_approved_by ? ' · ' + jobMeta.quote_approved_by : ''}`"
                />
                <a-alert
                  v-else-if="jobMeta.status === 'declined'"
                  type="error" show-icon :message="$t('Quote_Declined')"
                />
                <a-alert v-else type="warning" show-icon :message="$t('Quote_Awaiting_Approval')">
                  <template #description>
                    <a-space wrap style="margin-top: 8px">
                      <a-input v-model:value="approveBy" :placeholder="$t('Customer_signature_name')" style="width: 240px" />
                      <a-button type="primary" @click="approveQuote">
                        <template #icon><CheckOutlined /></template>
                        {{ $t('Approve_Quote') }}
                      </a-button>
                      <a-button danger @click="declineQuote">
                        <template #icon><CloseOutlined /></template>
                        {{ $t('Decline') }}
                      </a-button>
                    </a-space>
                  </template>
                </a-alert>
              </template>
            </a-tab-pane>

            <!-- ============== TAB 4: Parts & Labor ============== -->
            <a-tab-pane key="items" :tab="$t('Parts_Labor')">
              <a-row :gutter="16" style="margin-bottom: 12px">
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('warehouse')">
                    <a-select
                      v-model:value="selectedWarehouseId" :placeholder="$t('Choose_Warehouse')"
                      :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
                      show-search option-filter-prop="label" allow-clear
                      @change="onWarehouseChange"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Add_Part_From_Stock')">
                    <a-select
                      :value="null" :placeholder="$t('Search_product')"
                      :disabled="!selectedWarehouseId"
                      show-search option-filter-prop="label"
                      :options="warehouseProducts.map(p => ({
                        label: `${p.name} (${p.code} · stock: ${p.qte_sale})`,
                        value: p.product_variant_id ? `${p.id}:${p.product_variant_id}` : String(p.id),
                      }))"
                      @select="onPickProduct"
                    />
                  </a-form-item>
                </a-col>
              </a-row>

              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px">
                <strong>{{ $t('Line_Items') }}</strong>
                <a-space>
                  <a-button size="small" @click="addLaborLine">
                    <template #icon><PlusOutlined /></template>
                    {{ $t('Add_Labor_Line') }}
                  </a-button>
                  <a-button size="small" @click="addOtherLine">
                    <template #icon><PlusOutlined /></template>
                    {{ $t('Add_Other_Line') }}
                  </a-button>
                </a-space>
              </div>

              <a-table
                :columns="itemColumns" :data-source="form.items"
                :pagination="false" size="small" :row-key="(_r, i) => i"
                :scroll="{ x: 'max-content' }"
                :locale="{ emptyText: $t('No_items_added_yet') }"
              >
                <template #bodyCell="{ column, record, index }">
                  <template v-if="column.key === 'type'">
                    <a-tag v-if="record.type === 'part'" color="cyan">{{ $t('Part') }}</a-tag>
                    <a-tag v-else-if="record.type === 'labor'" color="blue">{{ $t('Labor') }}</a-tag>
                    <a-tag v-else>{{ $t('Other') }}</a-tag>
                  </template>
                  <template v-else-if="column.key === 'description'">
                    <a-input v-model:value="record.description" size="small" />
                  </template>
                  <template v-else-if="column.key === 'quantity'">
                    <a-input-number v-model:value="record.quantity" :min="0" :step="0.01" size="small" style="width: 100%" @change="recomputeRow(record)" />
                  </template>
                  <template v-else-if="column.key === 'unit_price'">
                    <a-input-number v-model:value="record.unit_price" :min="0" :step="0.01" size="small" style="width: 100%" @change="recomputeRow(record)" />
                  </template>
                  <template v-else-if="column.key === 'discount'">
                    <a-input-number v-model:value="record.discount" :min="0" :step="0.01" size="small" style="width: 100%" @change="recomputeRow(record)">
                      <template #addonAfter>
                        <a-select v-model:value="record.discount_method" style="width: 52px" size="small" @change="recomputeRow(record)">
                          <a-select-option value="1">$</a-select-option>
                          <a-select-option value="2">%</a-select-option>
                        </a-select>
                      </template>
                    </a-input-number>
                  </template>
                  <template v-else-if="column.key === 'tax_rate'">
                    <a-input-number v-model:value="record.tax_rate" :min="0" :step="0.01" size="small" style="width: 100%" @change="recomputeRow(record)" />
                  </template>
                  <template v-else-if="column.key === 'total'">
                    <strong>{{ currencySymbol }}{{ formatNumber(record.total) }}</strong>
                  </template>
                  <template v-else-if="column.key === 'action'">
                    <a-button size="small" danger @click="removeItem(index)">
                      <template #icon><DeleteOutlined /></template>
                    </a-button>
                  </template>
                </template>
                <template #footer>
                  <div class="items-totals">
                    <div><span>{{ $t('Items_Subtotal') }}</span><strong>{{ currencySymbol }}{{ formatNumber(itemsSubtotal) }}</strong></div>
                    <div v-if="form.diagnostic_fee > 0"><span>{{ $t('Diagnostic_Fee') }}</span><span>{{ currencySymbol }}{{ formatNumber(form.diagnostic_fee) }}</span></div>
                    <div><span>{{ $t('Grand_Total') }}</span><strong style="color: #1677ff">{{ currencySymbol }}{{ formatNumber(grandTotal) }}</strong></div>
                  </div>
                </template>
              </a-table>
            </a-tab-pane>

            <!-- ============== TAB 5: Photos (edit only) ============== -->
            <a-tab-pane key="photos" :tab="$t('Photos')" :disabled="!isEdit">
              <a-row :gutter="16" align="bottom">
                <a-col :xs="24" :md="5">
                  <a-form-item :label="$t('Stage')">
                    <a-select v-model:value="photoStage" :options="photoStageOptions" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="9">
                  <a-form-item :label="$t('Caption')">
                    <a-input v-model:value="photoCaption" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="10">
                  <a-form-item label=" ">
                    <a-space>
                      <a-upload
                        :file-list="photoFileList" :before-upload="() => false" multiple
                        accept="image/*" @change="onPhotoFilesChange"
                      >
                        <a-button>
                          <template #icon><UploadOutlined /></template>
                          {{ $t('Choose_files') }}
                        </a-button>
                      </a-upload>
                      <a-button type="primary" :disabled="!photoFileList.length" :loading="photoUploading" @click="uploadPhotos">
                        {{ $t('Upload') }}
                      </a-button>
                    </a-space>
                  </a-form-item>
                </a-col>
              </a-row>

              <a-empty v-if="photos.length === 0" :description="$t('No_photos_yet')" />
              <div v-else class="photo-grid">
                <div v-for="ph in photos" :key="ph.id" class="photo-tile">
                  <img :src="ph.url" :alt="ph.original_name" @click="previewPhoto = ph" />
                  <div class="photo-meta">
                    <a-tag color="cyan">{{ ph.stage }}</a-tag>
                    <a-button type="text" size="small" danger @click="deletePhoto(ph)">
                      <template #icon><DeleteOutlined /></template>
                    </a-button>
                  </div>
                  <small v-if="ph.caption" style="color: #999; display: block">{{ ph.caption }}</small>
                </div>
              </div>

              <a-modal :open="!!previewPhoto" :footer="null" width="800px" @cancel="previewPhoto = null">
                <img v-if="previewPhoto" :src="previewPhoto.url" style="max-width: 100%; margin-top: 16px" />
              </a-modal>
            </a-tab-pane>

            <!-- ============== TAB 6: Payments (edit only) ============== -->
            <a-tab-pane key="payments" :tab="$t('Payments')" :disabled="!isEdit">
              <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
                <a-col :xs="24" :md="8">
                  <a-card size="small">
                    <a-statistic :title="$t('Total')" :value="formatNumber(totals.total_amount)" :prefix="currencySymbol" />
                  </a-card>
                </a-col>
                <a-col :xs="24" :md="8">
                  <a-card size="small">
                    <a-statistic :title="$t('Paid')" :value="formatNumber(totals.paid_amount)" :prefix="currencySymbol" :value-style="{ color: '#3f8600' }" />
                    <a-progress :percent="paidPercent" size="small" :show-info="true" />
                  </a-card>
                </a-col>
                <a-col :xs="24" :md="8">
                  <a-card size="small">
                    <a-statistic
                      :title="$t('Balance_Due')" :value="formatNumber(totals.balance_due)" :prefix="currencySymbol"
                      :value-style="{ color: totals.balance_due > 0 ? '#cf1322' : '#3f8600' }"
                    />
                  </a-card>
                </a-col>
              </a-row>

              <a-card size="small" :title="$t('Payments') + ` (${payments.length})`">
                <template #extra>
                  <a-button type="primary" size="small" @click="openPaymentModal()">
                    <template #icon><PlusOutlined /></template>
                    {{ $t('Add_Payment') }}
                  </a-button>
                </template>
                <a-table
                  :columns="paymentColumns" :data-source="payments"
                  :pagination="false" size="small" row-key="id"
                  :scroll="{ x: 'max-content' }"
                  :locale="{ emptyText: $t('No_payments_yet') }"
                >
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'payment_kind'">
                      <a-tag :color="kindColor(record.payment_kind)">{{ record.payment_kind || 'payment' }}</a-tag>
                    </template>
                    <template v-else-if="column.key === 'payment_method'">
                      {{ record.payment_method || '—' }}
                    </template>
                    <template v-else-if="column.key === 'montant'">
                      <span :style="{ color: record.payment_kind === 'refund' ? '#cf1322' : undefined, fontWeight: 600 }">
                        {{ record.payment_kind === 'refund' ? '-' : '' }}{{ currencySymbol }}{{ formatNumber(record.montant) }}
                      </span>
                    </template>
                    <template v-else-if="column.key === 'notes'">
                      {{ record.notes || '—' }}
                    </template>
                    <template v-else-if="column.key === 'actions'">
                      <a-space>
                        <a-button size="small" @click="openPaymentModal(record)">
                          <template #icon><EditOutlined /></template>
                        </a-button>
                        <a-button size="small" danger @click="deletePayment(record)">
                          <template #icon><DeleteOutlined /></template>
                        </a-button>
                      </a-space>
                    </template>
                  </template>
                </a-table>
              </a-card>

              <div v-if="canMarkDelivered" style="text-align: right; margin-top: 16px">
                <a-button type="primary" @click="markDelivered">
                  <template #icon><CheckOutlined /></template>
                  {{ $t('Mark_Delivered_Decrement_Stock') }}
                </a-button>
              </div>
              <div v-else-if="jobMeta && jobMeta.status === 'delivered'" style="text-align: right; margin-top: 16px; color: #3f8600">
                <CheckOutlined />
                {{ $t('Delivered_On') }} {{ formatDate(jobMeta.delivered_at) }}
                <span v-if="jobMeta.warranty_expires_at"> · {{ $t('Warranty_until') }} {{ formatDate(jobMeta.warranty_expires_at) }}</span>
              </div>
            </a-tab-pane>
          </a-tabs>

          <a-space style="margin-top: 16px">
            <a-button type="primary" :loading="SubmitProcessing" @click="submit">{{ $t('Save') }}</a-button>
            <a-button @click="$router.back()">{{ $t('Cancel') }}</a-button>
          </a-space>
        </a-form>
      </a-card>

      <!-- Payment modal -->
      <a-modal
        v-model:open="paymentModalShow" :title="paymentModalTitle"
        :confirm-loading="paymentSaving" @ok="submitPayment"
      >
        <a-form layout="vertical" style="margin-top: 12px">
          <a-row :gutter="16">
            <a-col :span="12">
              <a-form-item :label="$t('date') + ' *'">
                <a-input v-model:value="paymentForm.date" type="date" />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item :label="$t('Amount') + ' *'">
                <a-input-number v-model:value="paymentForm.montant" :min="0.01" :step="0.01" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item :label="$t('Kind')">
                <a-select v-model:value="paymentForm.payment_kind" :options="paymentKindOptions" />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item :label="$t('Payment_Method')">
                <a-select
                  v-model:value="paymentForm.payment_method_id" :placeholder="$t('PleaseSelect')"
                  :options="paymentMethods.map(m => ({ label: m.name, value: m.id }))"
                  allow-clear
                />
              </a-form-item>
            </a-col>
            <a-col :span="24">
              <a-form-item :label="$t('Notes')">
                <a-textarea v-model:value="paymentForm.notes" :rows="2" />
              </a-form-item>
            </a-col>
          </a-row>
        </a-form>
      </a-modal>
    </template>
  </div>
</template>

<script setup>
/**
 * Service job create/edit — 6 tabs (Intake, Diagnostic, Quote, Parts & Labor,
 * Photos, Payments — the last two edit-only). Legacy contracts verbatim:
 *  - bootstrap GET service_jobs/create → {clients, technicians, warehouses,
 *    payment_methods}; checklist defs from service_checklist/categories+items
 *  - job GET service_jobs/{id} → {job, checklist, items, payments, photos}
 *  - save POST service_jobs / PUT service_jobs/{id} with the flat form +
 *    checklist[] (category/item ids + names + is_completed) + items[]
 *  - parts picker GET get_Products_by_warehouse/{id}?stock=1&is_sale=1&
 *    product_service=1&product_combo=0
 *  - quote: approve_quote {approved_by} / decline_quote / create_quotation /
 *    service_quote_pdf/{id} blob
 *  - photos: GET/POST service_jobs/{id}/photos (multipart photos[]), DELETE
 *  - payments: POST/PUT/DELETE service_jobs/{id}/payments[/{pid}]
 *  - mark_delivered allowed once balance_due <= 0 and not yet delivered
 * Row math is the legacy recomputeRow: (qty*price - discount[$ or %]) * (1 +
 * tax%/100), rounded to 2 decimals.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ArrowLeftOutlined, PlusOutlined, DeleteOutlined, EditOutlined, CheckOutlined,
  CloseOutlined, SendOutlined, FileTextOutlined, UploadOutlined,
  SafetyCertificateOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { useAuthStore } from '../../stores/auth';

const { t } = useI18n();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const isLoading = ref(true);
const isEdit = ref(false);
const SubmitProcessing = ref(false);
const creatingQuotation = ref(false);
const activeTab = ref('intake');
const currencySymbol = computed(() => auth.currency);
const formRef = ref();

const form = ref({
  client_id: null,
  technician_id: null,
  service_item: '',
  job_type: '',
  status: 'pending',
  scheduled_date: '',
  scheduled_end_date: '',
  notes: '',
  device_brand: '',
  device_model: '',
  device_serial: '',
  device_imei: '',
  device_color: '',
  device_password: '',
  accessories: [],
  condition_on_arrival: '',
  reported_issue: '',
  diagnosis: '',
  diagnostic_fee: 0,
  quote_amount: 0,
  quote_valid_until: '',
  warranty_days: 30,
  parent_job_id: null,
  items: [],
});
const jobMeta = ref(null);
const totals = ref({ total_amount: 0, paid_amount: 0, balance_due: 0 });

const clients = ref([]);
const technicians = ref([]);
const warehouses = ref([]);
const paymentMethods = ref([]);

const checklistCategories = ref([]);
const checklistItems = ref([]);
const checklistState = ref({});

const selectedWarehouseId = ref(null);
const warehouseProducts = ref([]);

const photos = ref([]);
const photoFileList = ref([]);
const photoStage = ref('intake');
const photoCaption = ref('');
const photoUploading = ref(false);
const previewPhoto = ref(null);

const payments = ref([]);
const paymentModalShow = ref(false);
const paymentModalTitle = ref('');
const paymentEditingId = ref(null);
const paymentSaving = ref(false);
const paymentForm = ref({
  date: new Date().toISOString().slice(0, 10),
  montant: 0,
  payment_kind: 'payment',
  payment_method_id: null,
  notes: '',
});

const approveBy = ref('');

const statusOptions = [
  { value: 'pending', label: 'Pending' },
  { value: 'intake', label: 'Intake' },
  { value: 'diagnostic', label: 'Diagnostic' },
  { value: 'quoted', label: 'Quoted' },
  { value: 'approved', label: 'Approved' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'ready', label: 'Ready for Pickup' },
  { value: 'delivered', label: 'Delivered' },
  { value: 'declined', label: 'Declined' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: 'completed', label: 'Completed' },
];
const accessoryOptions = ['Charger', 'Cable', 'Case', 'SIM card', 'Memory card', 'Headphones', 'Box'];
const photoStageOptions = [
  { value: 'intake', label: 'Intake' },
  { value: 'before', label: 'Before repair' },
  { value: 'after', label: 'After repair' },
  { value: 'delivery', label: 'Delivery' },
];
const paymentKindOptions = [
  { value: 'deposit', label: 'Deposit' },
  { value: 'payment', label: 'Payment' },
  { value: 'refund', label: 'Refund' },
];

const rules = computed(() => ({
  client_id: [{ required: true, message: t('Field_is_required') }],
  service_item: [{ required: true, message: t('Field_is_required') }],
}));

const itemColumns = computed(() => [
  { title: t('Type'), key: 'type', width: 90 },
  { title: t('Description'), key: 'description', width: 220 },
  { title: t('Qty'), key: 'quantity', width: 110 },
  { title: t('UnitPrice'), key: 'unit_price', width: 130 },
  { title: t('Discount'), key: 'discount', width: 160 },
  { title: t('Tax'), key: 'tax_rate', width: 110 },
  { title: t('Total'), key: 'total', align: 'right', width: 120 },
  { title: '', key: 'action', width: 50 },
]);
const paymentColumns = computed(() => [
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('date'), dataIndex: 'date', key: 'date' },
  { title: t('Kind'), key: 'payment_kind', width: 110 },
  { title: t('Payment_Method'), key: 'payment_method' },
  { title: t('Amount'), key: 'montant', align: 'right' },
  { title: t('Notes'), key: 'notes' },
  { title: t('Actions'), key: 'actions', width: 100 },
]);

const jobId = computed(() => (route.params.id ? Number(route.params.id) : null));
const itemsSubtotal = computed(() => form.value.items.reduce((s, r) => s + Number(r.total || 0), 0));
const grandTotal = computed(() => {
  const fallback = Number(form.value.quote_amount) || 0;
  const items = itemsSubtotal.value;
  const base = items > 0 ? items : fallback;
  return base + (Number(form.value.diagnostic_fee) || 0);
});
// Legacy: delivery allowed when balance settled (covers free/warranty repairs)
// and the job isn't already delivered.
const canMarkDelivered = computed(() => {
  if (!jobMeta.value || jobMeta.value.status === 'delivered') return false;
  return Number(totals.value.balance_due || 0) <= 0.0001;
});
const paidPercent = computed(() => {
  const total = Number(totals.value.total_amount || 0);
  const paid = Number(totals.value.paid_amount || 0);
  if (total <= 0) return paid > 0 ? 100 : 0;
  return Math.min(100, Math.max(0, Math.round((paid / total) * 100)));
});

function statusColor(s) {
  const map = {
    delivered: 'success', ready: 'success', completed: 'success',
    approved: 'processing', in_progress: 'processing',
    quoted: 'cyan', diagnostic: 'cyan', intake: 'cyan',
    pending: 'warning', declined: 'error', cancelled: 'error',
  };
  return map[s] || 'default';
}
function statusLabel(s) { return s ? s.replace('_', ' ') : ''; }
function paymentColor(s) {
  if (s === 'paid') return 'success';
  if (s === 'partial') return 'warning';
  return 'error';
}
function kindColor(kind) {
  if (kind === 'deposit') return 'warning';
  if (kind === 'refund') return 'error';
  return 'success';
}
function formatNumber(n) { return (Number(n) || 0).toFixed(2); }
function formatDate(d) {
  if (!d) return '';
  try { return new Date(d).toLocaleString(); } catch (e) { return d; }
}

/* ---------- Bootstrap ---------- */
async function bootstrap() {
  isLoading.value = true;
  isEdit.value = !!jobId.value;
  if (!isEdit.value) {
    form.value.items = [];
    payments.value = [];
    photos.value = [];
    jobMeta.value = null;
    activeTab.value = 'intake';
  }
  try {
    await loadMeta();
    const catData = await http.get('service_checklist/categories');
    checklistCategories.value = catData.categories || [];
    await loadChecklist();
    await loadJobIfNeeded();
  } catch (e) {
    message.error(t('InvalidData'));
  }
  isLoading.value = false;
}
async function loadMeta() {
  const data = await http.get('service_jobs/create');
  clients.value = (data.clients || []).map(c => ({ id: c.id, name: c.name }));
  technicians.value = (data.technicians || []).map(x => ({ id: x.id, full_name: x.name || `#${x.id}` }));
  warehouses.value = data.warehouses || [];
  paymentMethods.value = data.payment_methods || [];
}
async function loadChecklist() {
  const data = await http.get('service_checklist/items');
  checklistItems.value = (data.items || []).map(item => {
    const cat = checklistCategories.value.find(c => c.id === item.category_id);
    return { id: item.id, name: item.name, category_id: item.category_id, category_name: cat ? cat.name : null };
  });
  if (!isEdit.value) {
    const state = {};
    checklistItems.value.forEach(it => { state[it.id] = false; });
    checklistState.value = state;
  }
}
async function loadJobIfNeeded() {
  if (!jobId.value) return;
  const data = await http.get(`service_jobs/${jobId.value}`);
  const job = data.job || {};
  jobMeta.value = job;

  form.value.client_id = job.client_id || null;
  form.value.technician_id = job.technician_id || null;
  form.value.service_item = job.service_item || '';
  form.value.job_type = job.job_type || '';
  form.value.status = job.status || 'pending';
  form.value.scheduled_date = job.scheduled_date ? String(job.scheduled_date).slice(0, 16).replace(' ', 'T') : '';
  form.value.scheduled_end_date = job.scheduled_end_date ? String(job.scheduled_end_date).slice(0, 16).replace(' ', 'T') : '';
  form.value.notes = job.notes || '';

  form.value.device_brand = job.device_brand || '';
  form.value.device_model = job.device_model || '';
  form.value.device_serial = job.device_serial || '';
  form.value.device_imei = job.device_imei || '';
  form.value.device_color = job.device_color || '';
  form.value.device_password = job.device_password || '';
  form.value.accessories = Array.isArray(job.accessories) ? job.accessories : [];

  form.value.condition_on_arrival = job.condition_on_arrival || '';
  form.value.reported_issue = job.reported_issue || '';
  form.value.diagnosis = job.diagnosis || '';
  form.value.diagnostic_fee = Number(job.diagnostic_fee) || 0;

  form.value.quote_amount = Number(job.quote_amount) || 0;
  form.value.quote_valid_until = job.quote_valid_until || '';
  form.value.warranty_days = Number(job.warranty_days) || 30;

  (data.checklist || []).forEach(row => {
    if (row.item_id) checklistState.value[row.item_id] = !!row.is_completed;
  });

  form.value.items = (data.items || []).map(it => ({
    id: it.id,
    type: it.type || 'part',
    product_id: it.product_id,
    product_variant_id: it.product_variant_id,
    warehouse_id: it.warehouse_id,
    description: it.description,
    quantity: Number(it.quantity) || 1,
    unit_price: Number(it.unit_price) || 0,
    discount: Number(it.discount) || 0,
    discount_method: it.discount_method || '1',
    tax_rate: Number(it.tax_rate) || 0,
    tax_method: it.tax_method || '1',
    total: Number(it.total) || 0,
    notes: it.notes || '',
  }));

  // Job-level warehouse, falling back to the first part line for jobs created
  // before the job carried its own warehouse.
  const firstWithWh = form.value.items.find(i => i.warehouse_id);
  const warehouseId = job.warehouse_id || (firstWithWh ? firstWithWh.warehouse_id : null);
  if (warehouseId) {
    selectedWarehouseId.value = warehouseId;
    await loadWarehouseProducts();
  }

  payments.value = data.payments || [];
  photos.value = data.photos || [];

  totals.value = {
    total_amount: Number(job.total_amount) || 0,
    paid_amount: Number(job.paid_amount) || 0,
    balance_due: Number(job.balance_due) || 0,
  };
}

/* ---------- Items / parts ---------- */
async function onWarehouseChange() {
  warehouseProducts.value = [];
  if (selectedWarehouseId.value) await loadWarehouseProducts();
}
async function loadWarehouseProducts() {
  try {
    const data = await http.get(`get_Products_by_warehouse/${selectedWarehouseId.value}`, {
      stock: 1, is_sale: 1, product_service: 1, product_combo: 0,
    });
    warehouseProducts.value = (data || []).map(p => ({
      id: p.id,
      product_variant_id: p.product_variant_id,
      name: p.name,
      code: p.code,
      qte_sale: p.qte_sale,
      price: p.Net_price || p.price || 0,
    }));
  } catch (e) {
    warehouseProducts.value = [];
  }
}
function onPickProduct(value) {
  const [pid, vid] = String(value).split(':');
  const p = warehouseProducts.value.find(x =>
    x.id === Number(pid) && (vid ? x.product_variant_id === Number(vid) : true));
  if (!p) return;
  const row = {
    type: 'part',
    product_id: p.id,
    product_variant_id: p.product_variant_id || null,
    warehouse_id: selectedWarehouseId.value,
    description: p.name,
    quantity: 1,
    unit_price: Number(p.price) || 0,
    discount: 0,
    discount_method: '1',
    tax_rate: 0,
    tax_method: '1',
    total: 0,
    notes: '',
  };
  recomputeRow(row);
  form.value.items.push(row);
}
function addLaborLine() {
  form.value.items.push({
    type: 'labor', product_id: null, product_variant_id: null, warehouse_id: null,
    description: 'Labor', quantity: 1, unit_price: 0, discount: 0,
    discount_method: '1', tax_rate: 0, tax_method: '1', total: 0, notes: '',
  });
}
function addOtherLine() {
  form.value.items.push({
    type: 'other', product_id: null, product_variant_id: null, warehouse_id: null,
    description: '', quantity: 1, unit_price: 0, discount: 0,
    discount_method: '1', tax_rate: 0, tax_method: '1', total: 0, notes: '',
  });
}
function removeItem(idx) {
  form.value.items.splice(idx, 1);
}
function recomputeRow(row) {
  const qty = Number(row.quantity) || 0;
  const price = Number(row.unit_price) || 0;
  const disc = Number(row.discount) || 0;
  const tax = Number(row.tax_rate) || 0;
  const subtotal = qty * price;
  const discValue = row.discount_method === '2' ? subtotal * disc / 100 : disc;
  const afterDisc = Math.max(0, subtotal - discValue);
  const taxValue = afterDisc * tax / 100;
  row.total = Number((afterDisc + taxValue).toFixed(2));
}

/* ---------- Checklist ---------- */
function buildChecklistPayload() {
  return checklistItems.value.map(item => ({
    category_id: item.category_id,
    category_name: item.category_name || '',
    item_id: item.id,
    item_name: item.name,
    is_completed: !!checklistState.value[item.id],
  }));
}

/* ---------- Submit ---------- */
async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  SubmitProcessing.value = true;
  const payload = {
    ...form.value,
    checklist: buildChecklistPayload(),
    warehouse_id: selectedWarehouseId.value || null,
    items: form.value.items.map(it => ({
      // Sending the id lets the server update lines in place. Without it, lines that
      // had already deducted stock were re-created on every save and doubled the total.
      id: it.id || null,
      type: it.type,
      product_id: it.product_id,
      product_variant_id: it.product_variant_id,
      warehouse_id: it.warehouse_id,
      description: it.description,
      quantity: it.quantity,
      unit_price: it.unit_price,
      discount: it.discount,
      discount_method: it.discount_method,
      tax_rate: it.tax_rate,
      tax_method: it.tax_method,
      notes: it.notes,
    })),
  };
  try {
    if (isEdit.value) {
      const data = await http.put(`service_jobs/${jobId.value}`, payload);
      message.success(t('Successfully_Updated'));
      showStockWarnings(data);
      await loadJobIfNeeded();
    } else {
      const data = await http.post('service_jobs', payload);
      message.success(t('Successfully_Created'));
      if (data && data.id) router.replace(`/service/jobs/edit/${data.id}`);
      else router.push('/service/jobs');
    }
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    SubmitProcessing.value = false;
  }
}

/* ---------- Quote actions ---------- */
async function approveQuote() {
  try {
    await http.post(`service_jobs/${jobId.value}/approve_quote`, { approved_by: approveBy.value || null });
    message.success(t('Quote_Approved'));
    await loadJobIfNeeded();
  } catch (e) {
    message.error(t('Operation_Failed'));
  }
}
async function declineQuote() {
  try {
    await http.post(`service_jobs/${jobId.value}/decline_quote`);
    message.success(t('Quote_Declined'));
    await loadJobIfNeeded();
  } catch (e) {
    message.error(t('Operation_Failed'));
  }
}
// Parts that could not move stock (no product, no warehouse, or no cost on record)
// used to be skipped in silence. The server now names them.
function showStockWarnings(data) {
  ((data && data.warnings) || []).forEach(msg => message.warning(msg));
}

async function markDelivered() {
  try {
    const data = await http.post(`service_jobs/${jobId.value}/mark_delivered`);
    message.success(t('Successfully_Updated'));
    showStockWarnings(data);
    await loadJobIfNeeded();
  } catch (e) {
    message.error(e?.data?.message || t('Operation_Failed'));
  }
}
async function sendToQuotations() {
  if (!jobId.value) return;
  creatingQuotation.value = true;
  try {
    const data = await http.post(`service_jobs/${jobId.value}/create_quotation`);
    jobMeta.value = { ...(jobMeta.value || {}), quotation_id: data.quotation_id, quotation_ref: data.Ref };
    message.success(data.duplicate
      ? `Already linked to quotation ${data.Ref}.`
      : `Quotation ${data.Ref} created.`);
  } catch (e) {
    message.error(e?.data?.message || 'Could not create quotation.');
  } finally {
    creatingQuotation.value = false;
  }
}
async function downloadQuotePdf() {
  try {
    await http.download(`service_quote_pdf/${jobId.value}`, `Service_Quote_${jobId.value}.pdf`);
  } catch (e) {
    message.error(t('Operation_Failed'));
  }
}

/* ---------- Photos ---------- */
function onPhotoFilesChange({ fileList }) {
  photoFileList.value = fileList;
}
async function uploadPhotos() {
  if (!photoFileList.value.length) return;
  photoUploading.value = true;
  try {
    const fd = new FormData();
    fd.append('stage', photoStage.value);
    if (photoCaption.value) fd.append('caption', photoCaption.value);
    photoFileList.value.forEach(f => fd.append('photos[]', f.originFileObj || f));
    const { status } = await uploadForm(`service_jobs/${jobId.value}/photos`, fd);
    if (status >= 200 && status < 300) {
      photoFileList.value = [];
      photoCaption.value = '';
      await refreshPhotos();
      message.success(t('Successfully_Created'));
    } else {
      message.error(t('Upload_failed'));
    }
  } catch (e) {
    message.error(t('Upload_failed'));
  } finally {
    photoUploading.value = false;
  }
}
async function refreshPhotos() {
  const data = await http.get(`service_jobs/${jobId.value}/photos`);
  photos.value = data.photos || [];
}
function deletePhoto(ph) {
  Modal.confirm({
    title: t('Delete_Title'),
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      await http.delete(`service_jobs/${jobId.value}/photos/${ph.id}`);
      await refreshPhotos();
    },
  });
}

/* ---------- Payments ---------- */
function openPaymentModal(p = null) {
  if (p) {
    paymentModalTitle.value = t('Edit_Payment');
    paymentEditingId.value = p.id;
    paymentForm.value = {
      date: p.date || new Date().toISOString().slice(0, 10),
      montant: Number(p.montant) || 0,
      payment_kind: p.payment_kind || 'payment',
      payment_method_id: p.payment_method_id || null,
      notes: p.notes || '',
    };
  } else {
    paymentModalTitle.value = t('Add_Payment');
    paymentEditingId.value = null;
    paymentForm.value = {
      date: new Date().toISOString().slice(0, 10),
      montant: totals.value.balance_due > 0 ? totals.value.balance_due : 0,
      payment_kind: 'payment',
      payment_method_id: null,
      notes: '',
    };
  }
  paymentModalShow.value = true;
}
async function submitPayment() {
  paymentSaving.value = true;
  try {
    if (paymentEditingId.value) {
      await http.put(`service_jobs/${jobId.value}/payments/${paymentEditingId.value}`, paymentForm.value);
    } else {
      await http.post(`service_jobs/${jobId.value}/payments`, paymentForm.value);
    }
    paymentModalShow.value = false;
    await loadJobIfNeeded();
    message.success(t('Successfully_Created'));
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    paymentSaving.value = false;
  }
}
function deletePayment(p) {
  Modal.confirm({
    title: t('Delete_Title'),
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      await http.delete(`service_jobs/${jobId.value}/payments/${p.id}`);
      await loadJobIfNeeded();
    },
  });
}

// Same component serves /create and /edit/:id — after creating we redirect to
// the edit URL, so reload on route change (legacy watch on $route).
watch(() => route.params.id, (to, from) => {
  if (to !== from) bootstrap();
});

onMounted(bootstrap);
</script>

<style scoped>
.stat-label {
  font-size: 14px;
  color: rgba(0, 0, 0, 0.45);
  margin-bottom: 6px;
}
.checklist-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 12px;
  border: 1px solid rgba(5, 5, 5, 0.06);
  border-radius: 8px;
}
.items-totals {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
}
.items-totals > div {
  display: flex;
  gap: 24px;
}
.photo-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 12px;
}
.photo-tile {
  border: 1px solid rgba(5, 5, 5, 0.1);
  border-radius: 8px;
  padding: 6px;
}
.photo-tile img {
  width: 100%;
  height: 140px;
  object-fit: cover;
  cursor: zoom-in;
  border-radius: 6px;
}
.photo-meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 6px;
}
</style>
