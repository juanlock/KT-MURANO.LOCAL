<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('EditSale') : $t('AddSale')"
      :breadcrumb="[$t('Sales'), isEdit ? $t('EditSale') : $t('AddSale')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <a-card size="small" style="margin-bottom: 16px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('date')" required>
              <a-date-picker v-model:value="sale.date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Customer')" required>
              <div style="display: flex; gap: 8px">
                <a-select
                  v-model:value="sale.client_id"
                  show-search option-filter-prop="label"
                  :placeholder="$t('Choose_Customer')"
                  :options="clientOptions"
                  style="flex: 1"
                  @change="onClientChange"
                />
                <a-tooltip :title="$t('Quick_Add_Customer')">
                  <a-button @click="quickAddClientOpen = true">
                    <template #icon><PlusOutlined /></template>
                  </a-button>
                </a-tooltip>
              </div>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('warehouse')" required>
              <div style="display: flex; gap: 8px">
                <a-select
                  v-model:value="sale.warehouse_id"
                  show-search option-filter-prop="label"
                  :placeholder="$t('Choose_Warehouse')"
                  :options="warehouseOptions"
                  :disabled="isEdit"
                  style="flex: 1"
                  @change="onWarehouseChange"
                />
                <a-button type="primary" :disabled="!sale.warehouse_id" @click="quickAddProductOpen = true">
                  <template #icon><PlusOutlined /></template>
                  {{ $t('Add_Product') }}
                </a-button>
              </div>
            </a-form-item>
          </a-col>
          <!-- Multi-Currency: entered/displayed amounts are in this currency;
               the model (and the payload) stays in the base currency. -->
          <a-col v-if="mcEnabled" :xs="24" :md="8">
            <a-form-item :label="$t('Currency')">
              <a-select
                v-model:value="docCurrencyId"
                show-search option-filter-prop="label"
                :options="currencyOptions"
              />
            </a-form-item>
          </a-col>
          <!-- One invoice (a normal sale) or Recurring: the same lines billed
               on a schedule -- saved as an active Sales Contract that creates
               each invoice (SalesContractBilling). -->
          <a-col v-if="canRecurring" :xs="24" :md="8">
            <a-form-item label="Invoice">
              <a-radio-group v-model:value="invoiceMode" button-style="solid">
                <a-radio-button value="single">Only 1 invoice</a-radio-button>
                <a-radio-button value="recurring"><SyncOutlined /> Recurring</a-radio-button>
              </a-radio-group>
            </a-form-item>
          </a-col>
        </a-row>
      </a-card>

      <!-- Product search + lines -->
      <a-card size="small" style="margin-bottom: 16px">
        <div style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap">
          <a-select
            v-model:value="searchValue"
            show-search
            :filter-option="filterProduct"
            :placeholder="$t('Scan_Search_Product_by_Code_Name')"
            style="flex: 2; min-width: 220px"
            :options="productOptions"
            :disabled="!sale.warehouse_id"
            @select="onProductPicked"
          />
          <!-- Browse by Group: pick an existing Group Name, then a Name within
               it, to insert that product without typing in the search box. -->
          <a-select
            v-model:value="browseGroupId"
            show-search allow-clear
            option-filter-prop="label"
            :placeholder="$t('GroupName')"
            style="min-width: 180px; flex: 1"
            :options="browseGroupOptions"
            :disabled="!sale.warehouse_id"
            @change="browseProductIdx = undefined"
          />
          <a-select
            v-model:value="browseProductIdx"
            show-search allow-clear
            option-filter-prop="label"
            :placeholder="$t('Name_product')"
            style="min-width: 180px; flex: 1"
            :options="browseProductOptions"
            :disabled="!browseGroupId"
            @select="onBrowseProductPicked"
          />
          <ProductScanModal type="primary" :disabled="!sale.warehouse_id" @scan="onScan" />
        </div>

        <QuickAddProduct v-model:open="quickAddProductOpen" context="sale" @created="onProductCreated" />

        <a-table
          :columns="lineColumns"
          :data-source="lines"
          :pagination="false"
          size="middle"
          :row-key="(r, i) => r.detail_id"
          :scroll="{ x: 'max-content' }"
        >
          <template #bodyCell="{ column, record, index }">
            <template v-if="column.key === 'product'">
              <div style="font-weight: 500">{{ productDisplayName(record.name) }}</div>
              <div v-if="record.note" style="font-size: 10pt; color: #0252f3; white-space: pre-wrap; text-align: left">Note: {{ record.note }}</div>
              <a-tag v-if="record.is_batch_tracked" color="warning" style="margin-top: 2px">Batch</a-tag>
              <a-select
                v-if="showPriceTypeDropdown"
                :value="record.price_type"
                size="small"
                style="width: 100%; min-width: 150px; margin-top: 4px"
                :options="[
                  { value: 'retail', label: $t('Retail Price') },
                  { value: 'wholesale', label: $t('Wholesale Price') },
                ]"
                @change="v => onChangePriceType(record, v)"
              />
              <a-select
                v-if="record.packs && record.packs.length"
                :value="record.product_pack_id"
                size="small"
                style="width: 100%; min-width: 150px; margin-top: 4px"
                :placeholder="$t('Pack')"
                :options="packOptions(record)"
                @change="v => onChangePack(record, v)"
              />
              <div
                v-if="(Number(record.min_price) || 0) > 0 && Number(record.Net_price) < Number(record.min_price)"
                class="form-warn"
              >
                {{ $t('Price_below_min_not_allowed') }}
              </div>
            </template>
            <template v-else-if="column.key === 'net_price'">{{ docMoney(record.Net_price) }}</template>
            <template v-else-if="column.key === 'stock'">
              {{ record.stock }} {{ record.unitSale }}
            </template>
            <template v-else-if="column.key === 'quantity'">
              <!-- min 0 like legacy: a zero qty is caught at submit (AddQuantity) -->
              <a-input-number
                :value="record.quantity"
                :min="0"
                style="width: 110px"
                @update:value="v => setQty(record, v)"
              />
            </template>
            <template v-else-if="column.key === 'discount'">{{ docMoney(record.DiscountNet * record.quantity) }}</template>
            <template v-else-if="column.key === 'tax'">{{ docMoney(record.taxe * record.quantity) }}</template>
            <template v-else-if="column.key === 'subtotal'">
              <strong>{{ docMoney(record.subtotal) }}</strong>
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-space>
                <a-tooltip :title="$t('Edit')">
                  <a-button type="text" size="small" @click="openLineEdit(record)">
                    <template #icon><EditOutlined style="color: #52c41a" /></template>
                  </a-button>
                </a-tooltip>
                <a-tooltip :title="$t('Del')">
                  <a-button type="text" size="small" danger @click="removeLine(index)">
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
      </a-card>

      <a-row :gutter="16">
        <a-col :xs="24" :lg="14">
          <!-- Recurring: the schedule (same settings + preview as Sales Contracts). -->
          <a-card v-if="isRecurring" size="small" title="Recurring invoices" style="margin-bottom: 16px">
            <a-row :gutter="12">
              <a-col :xs="24" :md="16">
                <a-form-item label="Subject" required extra="Name of the recurring billing (Sales Contract).">
                  <a-input v-model:value="recurring.subject" :placeholder="recurringSubjectDefault" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="End date" extra="Optional.">
                  <a-date-picker v-model:value="recurring.end_date" value-format="YYYY-MM-DD" style="width: 100%" />
                </a-form-item>
              </a-col>
            </a-row>
            <!-- Notes on each invoice: static text or placeholders filled per
                 invoice ({{month}}, {{weekday}}, {{occurrence}}...). The
                 invoice note goes on each invoice; a line note on that line. -->
            <div class="rec-notes">
              <div class="rec-notes__title">Notes on each invoice</div>
              <div class="rec-notes__help">
                Fixed text, or placeholders that change on every invoice: month, day, weekday, invoice date,
                occurrence number… Click <QuestionCircleOutlined /> to add them; the list below shows each invoice's result.
              </div>
              <a-form-item label="Invoice note" style="margin-bottom: 8px">
                <a-input
                  v-model:value="recurring.invoice_note" allow-clear
                  placeholder="e.g. Service made on {{month}} — empty = automatic text"
                >
                  <template #addonAfter>
                    <NotePlaceholderHelp
                      @insert="tk => (recurring.invoice_note = appendToken(recurring.invoice_note, tk))"
                      @use="ex => (recurring.invoice_note = ex)"
                    />
                  </template>
                </a-input>
                <div v-if="recurring.invoice_note && scheduleRef?.firstInvoiceNote()" class="rec-notes__preview">
                  1st invoice: <i>{{ scheduleRef.firstInvoiceNote() }}</i>
                </div>
              </a-form-item>
              <div v-if="!lines.length" class="rec-notes__empty">Add products to write a note per line.</div>
              <a-form-item v-for="(l, i) in lines" :key="l.detail_id" :label="`Line note — ${productDisplayName(l.name)}`" style="margin-bottom: 8px">
                <a-input v-model:value="l.note" allow-clear placeholder="e.g. Note: {{month}} installment services">
                  <template #addonAfter>
                    <NotePlaceholderHelp @insert="tk => (l.note = appendToken(l.note, tk))" @use="ex => (l.note = ex)" />
                  </template>
                </a-input>
                <div v-if="l.note && scheduleRef?.firstNote(i)" class="rec-notes__preview">
                  1st invoice: <i>{{ scheduleRef.firstNote(i) }}</i>
                </div>
              </a-form-item>
            </div>

            <RecurringSchedule
              ref="scheduleRef"
              :value="recurring" :start-date="sale.date" :end-date="recurring.end_date"
              :notes="lines.map(l => l.note)" :invoice-note="recurring.invoice_note"
              first-date-label="First invoice date"
              first-date-help="Empty = the sale date, moved to the first matching invoice day."
            >
              <template #summary>
                <a-alert
                  type="info" show-icon style="margin-bottom: 12px"
                  message="Each invoice repeats these lines (their prices, discounts and taxes) and the order tax %, and is created unpaid. Invoices dated today or earlier are created now; later ones are created automatically on their date."
                />
              </template>
            </RecurringSchedule>
          </a-card>

          <!-- Service job: one job with this sale (One invoice), or recurring
               visits on their own schedule (Recurring). Service module only. -->
          <a-card v-if="serviceJobAvailable" size="small" :title="isRecurring ? $t('Contract_Service_Jobs') : $t('Service_Jobs')" style="margin-bottom: 16px">
            <!-- Edit: the jobs already made with this sale. -->
            <div v-if="saleServiceJobs.length" class="sale-jobs">
              <div v-for="j in saleServiceJobs" :key="j.id" class="sale-jobs__item">
                <div class="sale-jobs__row">
                  <router-link :to="`/service/jobs/details/${j.id}`">{{ j.ref }}</router-link>
                  <span>{{ j.title }}</span>
                  <span class="muted">{{ j.scheduled_date || '' }}{{ j.technician ? ` · ${j.technician}` : '' }}</span>
                  <!-- Pending -> In progress -> Completed, saved with the sale. -->
                  <a-select
                    v-if="jobEdits[j.id] && JOB_EDITABLE.includes(j.status)"
                    v-model:value="jobEdits[j.id].status" size="small" style="width: 140px" :options="JOB_STATUS_OPTIONS"
                  />
                  <a-tag v-else style="margin: 0">{{ j.status }}</a-tag>
                </div>
                <template v-if="jobEdits[j.id]">
                  <div v-if="j.notes" class="sale-jobs__notes muted">{{ j.notes }}</div>
                  <a-input v-model:value="jobEdits[j.id].note" size="small" allow-clear placeholder="Add a note to this job (saved with the sale)" />
                </template>
              </div>
            </div>
            <!-- The customer's existing jobs this invoice bills (service_job_sales). -->
            <a-form-item v-if="!isRecurring && sale.client_id" label="Link existing service jobs" style="margin-bottom: 8px">
              <a-select
                v-model:value="linkedJobIds" mode="multiple" allow-clear show-search option-filter-prop="label"
                :options="linkJobOptions" :loading="clientJobsLoading"
                :placeholder="linkJobOptions.length ? 'Choose this customer\'s jobs' : 'This customer has no service jobs'"
              />
            </a-form-item>
            <a-form-item style="margin-bottom: 8px">
              <a-switch v-model:checked="serviceJob.enabled" />
              <span style="margin-left: 8px">{{ isRecurring ? 'Create a service job for each visit' : (saleServiceJobs.length ? 'Add another service job' : 'Create a service job for this sale') }}</span>
            </a-form-item>
            <template v-if="serviceJob.enabled">
              <a-row :gutter="12">
                <a-col :xs="24" :md="isRecurring ? 24 : 12">
                  <a-form-item :label="isRecurring ? 'Visit title' : 'Job title'">
                    <a-input v-model:value="serviceJob.title" allow-clear :placeholder="isRecurring ? 'e.g. Lawn mowing - {{weekday}} {{date}}' : `${serviceJobTitleDefault} -- or e.g. Visit {{number}} of {{count}} - {{weekday}}`">
                      <!-- Placeholders filled per job: its own date, its number in the series. -->
                      <template #addonAfter>
                        <NotePlaceholderHelp
                          :hint="isRecurring ? 'Click to add. Each visit fills them in with its own date.' : 'Click to add. Each job fills them in with its own date ({{number}} / {{count}} with Repeat or Specific dates).'"
                          @insert="tk => (serviceJob.title = appendToken(serviceJob.title, tk))" @use="ex => (serviceJob.title = ex)"
                        />
                      </template>
                    </a-input>
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="isRecurring ? 12 : 12">
                  <a-form-item label="Technician / crew lead">
                    <a-select v-model:value="serviceJob.technician_id" allow-clear :options="technicianOptions" placeholder="Assign later" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item label="Job type">
                    <a-input v-model:value="serviceJob.job_type" placeholder="e.g. Maintenance" />
                  </a-form-item>
                </a-col>
                <!-- One invoice: when -- one day, several days, or specific
                     dates (one job each); no time = all-day task. -->
                <a-col v-if="!isRecurring" :span="24">
                  <ServiceJobWhen :value="serviceJobWhen" allow-dates />
                </a-col>
                <!-- Recurring: visit time + how early each job is created. -->
                <template v-else>
                  <a-col :xs="12">
                    <a-form-item label="Visit time" extra="Empty = all-day task on the visit day.">
                      <a-time-picker v-model:value="serviceJob.time" value-format="HH:mm" format="HH:mm" :minute-step="15" placeholder="All day" style="width: 100%" />
                    </a-form-item>
                  </a-col>
                  <a-col :xs="12">
                    <a-form-item label="Create ahead" extra="Days before each visit.">
                      <a-input-number v-model:value="serviceJob.lead_days" :min="0" :max="60" addon-after="days" style="width: 100%" />
                    </a-form-item>
                  </a-col>
                </template>
              </a-row>
              <RecurringSchedule
                v-if="isRecurring"
                :value="jobSchedule" :start-date="sale.date" :end-date="recurring.end_date"
                :invoice-note="serviceJob.title || ''"
                noun="visit" day-label="Visit day" note-label="Title" :show-period="false"
                first-date-label="First visit date" first-date-help="Empty = the sale date, moved to the first matching visit day."
              >
                <template #summary>
                  <a-alert type="info" show-icon style="margin-bottom: 12px" message="Each visit becomes a Service Job (Pending, no price: the invoices bill the work), on its own schedule -- e.g. weekly visits, monthly invoices." />
                </template>
              </RecurringSchedule>
              <a-alert v-else type="info" show-icon message="The job is created for this customer and location when the sale is saved (status Pending, no price: the sale bills the work), and shows on the sale under Service Jobs." />
            </template>
          </a-card>

          <a-card size="small" style="margin-bottom: 16px">
            <a-row :gutter="12">
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('OrderTax')">
                  <a-input-number
                    v-model:value="taxInput" style="width: 100%" :min="0"
                    :max="sale.tax_Method === '1' ? 100 : undefined"
                    :addon-after="sale.tax_Method === '1' ? '%' : undefined"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Tax_Method') || 'Tax Method'">
                  <a-select
                    v-model:value="sale.tax_Method"
                    :options="[
                      { value: '1', label: $t('Percentage') },
                      { value: '2', label: $t('Fixed') },
                    ]"
                  />
                </a-form-item>
              </a-col>
              <a-col v-if="!isRecurring" :xs="12" :md="8">
                <a-form-item :label="$t('Discount')">
                  <a-input-number v-model:value="discountInput" style="width: 100%" :min="0" />
                </a-form-item>
              </a-col>
              <a-col v-if="!isRecurring" :xs="12" :md="8">
                <a-form-item :label="$t('Discount_Method') || 'Discount Method'">
                  <a-select
                    v-model:value="sale.discount_Method"
                    :options="[
                      { value: '2', label: $t('Fixed') },
                      { value: '1', label: $t('Percentage') },
                    ]"
                  />
                </a-form-item>
              </a-col>
              <a-col v-if="shippingEnabled && !isRecurring" :xs="12" :md="8">
                <a-form-item :label="$t('Shipping')">
                  <a-input-number v-model:value="shippingInput" style="width: 100%" :min="0" />
                </a-form-item>
              </a-col>
              <a-col v-if="!isRecurring" :xs="12" :md="8">
                <a-form-item :label="$t('Status')">
                  <a-select
                    v-model:value="sale.statut"
                    :options="[
                      { value: 'completed', label: $t('complete') },
                      { value: 'pending', label: $t('Pending') },
                      { value: 'ordered', label: $t('Ordered') },
                    ]"
                  />
                </a-form-item>
              </a-col>
              <!-- Sales agents belong to the Commissions module; hide the field
                   when the module is toggled off (Settings → Modules). -->
              <a-col v-if="auth.moduleEnabled('commissions') && salesAgentEnabled && !isRecurring" :xs="24" :md="8">
                <a-form-item :label="$t('Sales_Agent') || 'Sales Agent'">
                  <a-select
                    v-model:value="sale.sales_agent_id" allow-clear show-search
                    option-filter-prop="label" :options="agentOptions"
                  />
                </a-form-item>
              </a-col>
              <!-- Change Salesperson (same opt-in feature as the POS picker) -->
              <a-col v-if="salesSwitchEnabled && !isRecurring" :xs="24" :md="8">
                <a-form-item :label="$t('Seller')">
                  <a-select
                    v-model:value="sale.seller_id" allow-clear show-search
                    option-filter-prop="label" :options="salespersonOptions"
                    :placeholder="$t('Select_Salesperson')"
                  />
                </a-form-item>
              </a-col>
              <!-- Loyalty points -> discount. Legacy gates this on the customer
                   being royalty-eligible AND the tax/discount edit permission. -->
              <!-- Also hidden when the company switched loyalty off (Settings -> customer_loyalty_enabled). -->
              <a-col v-if="!isRecurring && auth.customerLoyaltyEnabled && loyaltyPointsEnabled && clientIsEligible && auth.can('edit_tax_discount_shipping_sale')" :span="24">
                <a-form-item label="Points to convert">
                  <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center">
                    <a-input-number
                      :value="points_to_convert"
                      :min="0"
                      :max="selectedClientPoints"
                      :step="1"
                      :disabled="selectedClientPoints === 0 || pointsConverted"
                      placeholder="e.g., 200"
                      style="width: 160px"
                      @update:value="onPointsToConvertInput"
                    />
                    <a-button
                      :type="pointsConverted ? 'default' : 'primary'"
                      :disabled="!pointsConverted && (selectedClientPoints === 0 || !pointsInputValid)"
                      @click="convertPointsToDiscount"
                    >
                      {{ pointsConverted ? 'Unconverted' : 'Convert' }}
                    </a-button>
                    <span class="muted">Total available: <strong>{{ selectedClientPoints }}</strong> pts</span>
                  </div>
                  <div v-if="!pointsConverted && points_to_convert && !pointsInputValid" class="form-warn">
                    Enter a value from 1 to your available points.
                  </div>
                  <div v-if="pointsState.discount_from_points > 0" style="margin-top: 4px; color: #52c41a">
                    Discount of <strong>{{ docMoney(pointsState.discount_from_points) }}</strong> will be applied
                  </div>
                </a-form-item>
              </a-col>
              <a-col :span="24">
                <!-- Recurring: this becomes the contract's internal description;
                     the invoices' own note is "Notes on each invoice" above. -->
                <a-form-item :label="isRecurring ? 'Contract description (internal, not printed on the invoices)' : $t('Note')">
                  <!-- One invoice: placeholders are filled with the sale's own date when saved. -->
                  <template v-if="!isRecurring" #extra>
                    <span>Variables like <code v-text="'{{month}}'" /> or <code v-text="'{{invoice_date}}'" /> are filled with this sale's date when you save.</span>
                    <NotePlaceholderHelp
                      hint="Click to add. Filled with this sale's date when you save."
                      @insert="tk => (sale.notes = appendToken(sale.notes, tk))" @use="ex => (sale.notes = ex)"
                    >
                      <a-button type="link" size="small">Variables &amp; examples</a-button>
                    </NotePlaceholderHelp>
                  </template>
                  <a-textarea v-model:value="sale.notes" :rows="2" :placeholder="$t('Afewwords')" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <!-- Payment (create + completed only, like legacy) -->
          <a-card v-if="!isEdit && !isRecurring && sale.statut === 'completed'" size="small" :title="$t('Payment')" style="margin-bottom: 16px">
            <a-row :gutter="12">
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('PaymentStatus')">
                  <a-select
                    v-model:value="payment.status"
                    :options="[
                      { value: 'paid', label: $t('Paid') },
                      { value: 'partial', label: $t('partial') },
                      { value: 'pending', label: $t('Pending') },
                    ]"
                    @change="onPaymentStatusChange"
                  />
                </a-form-item>
              </a-col>
              <!-- One sale may be split across several methods (legacy
                   payment_lines); the first line is prefilled with the total. -->
              <a-col v-if="payment.status !== 'pending'" :span="24">
                <a-form-item :label="$t('Paymentchoice')">
                  <div v-for="(line, idx) in payment_lines" :key="'pay-' + idx" class="pay-line">
                    <a-select
                      v-model:value="line.payment_method_id"
                      :placeholder="$t('PleaseSelect')"
                      :options="paymentMethodOptions"
                      style="flex: 1 1 160px"
                    />
                    <a-input-number
                      :value="toDocAmt(line.amount)"
                      :min="0"
                      :placeholder="$t('Paying_Amount')"
                      style="flex: 1 1 140px"
                      @update:value="v => onPaymentLineInput(line, v)"
                    />
                    <a-select
                      v-model:value="line.account_id"
                      allow-clear
                      :placeholder="$t('Choose_Account')"
                      :options="accountOptions"
                      style="flex: 1 1 160px"
                    />
                    <a-button
                      v-if="payment_lines.length > 1"
                      danger type="text" :title="$t('Delete')"
                      @click="removePaymentLine(idx)"
                    >
                      <template #icon><DeleteOutlined /></template>
                    </a-button>
                  </div>

                  <a-button size="small" style="margin-top: 4px" @click="addPaymentLine">
                    <template #icon><PlusOutlined /></template>
                    {{ $t('Add_Payment_Method') }}
                  </a-button>

                  <div class="pay-summary">
                    <span><strong>{{ $t('Paying') }}:</strong> {{ docMoney(totalPaidLines) }}</span>
                    <span><strong>{{ $t('Balance') }}:</strong> {{ docMoney(paymentDue) }}</span>
                    <span><strong>{{ $t('Change') }}:</strong> {{ docMoney(paymentChange) }}</span>
                  </div>
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="10">
          <a-card size="small">
            <div class="sum-row"><span>{{ $t('Total') }}</span><span>{{ docMoney(totals.total) }}</span></div>
            <div class="sum-row"><span>{{ $t('OrderTax') }}</span><span>{{ docMoney(totals.TaxNet) }}<template v-if="sale.tax_Method === '1'"> ({{ Number(sale.tax_rate) || 0 }}%)</template></span></div>
            <div class="sum-row">
              <span>{{ $t('Discount') }}</span>
              <span style="color: #ff4d4f">- {{ docMoney(totals.discountAmount) }}</span>
            </div>
            <div v-if="shippingEnabled" class="sum-row"><span>{{ $t('Shipping') }}</span><span>{{ docMoney(sale.shipping) }}</span></div>
            <div class="sum-row grand"><span>{{ $t('Total') }}</span><span>{{ docMoney(totals.GrandTotal) }}</span></div>
            <!-- When a foreign currency is active, keep the base total visible -->
            <div v-if="!dcIsBase" class="sum-row" style="border-bottom: none; font-size: 12px; color: rgba(128,128,128,.85)">
              <span>{{ $t('Base_Currency_Equivalent') }}</span><span>{{ moneyBase(totals.GrandTotal) }}</span>
            </div>
          </a-card>

          <!-- Disabled conditions mirror each legacy form: create also gates
               on min price; edit only on batch errors. -->
          <a-button
            type="primary" block size="large" style="margin-top: 16px"
            :loading="submitting"
            :disabled="(!isEdit && hasMinPriceViolation) || (sale.statut === 'completed' && hasBatchValidationErrors)"
            @click="submit(false)"
          >
            {{ isRecurring ? 'Create recurring invoices' : (isDraft ? 'Finalize (no longer a draft)' : $t('submit')) }}
          </a-button>
          <!-- Draft: saved as Pending (no stock out, no payment), labelled Draft;
               finalize it later. Not for Recurring (those are contracts). -->
          <a-button
            v-if="!isRecurring && (!isEdit || isDraft || sale.statut === 'pending')"
            block style="margin-top: 8px" :loading="submitting" @click="submit(true)"
          >
            <template #icon><FileTextOutlined /></template>
            {{ isDraft ? 'Save draft' : 'Save as draft' }}
          </a-button>
          <div v-if="!isEdit && hasMinPriceViolation" class="form-warn">{{ $t('Price_below_min_not_allowed') }}</div>
          <div v-else-if="sale.statut === 'completed' && hasBatchValidationErrors" class="form-warn">
            {{ firstBatchSelectError(lines, $t) }}
          </div>
          <a-button block style="margin-top: 8px" @click="$router.push('/sales')">{{ $t('Cancel') }}</a-button>
        </a-col>
      </a-row>
    </a-form>

    <!-- Line edit modal -->
    <a-modal
      v-model:open="lineEditOpen"
      :title="editingLine ? productDisplayName(editingLine.name) : ''"
      :ok-text="$t('submit')"
      :ok-button-props="{ disabled: lineEditLoading }"
      @ok="applyLineEdit"
    >
      <!-- The unit list is fetched as the modal opens, so show a spinner until
           it lands rather than letting the unit field pop in afterwards. -->
      <div v-if="lineEditLoading" class="modal-loading"><a-spin /></div>
      <a-form v-else-if="editingLine" layout="vertical">
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('ProductPrice')">
              <a-input-number v-model:value="lineDraft.Unit_price" style="width: 100%" :min="0" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('TaxMethod')">
              <a-select
                v-model:value="lineDraft.tax_method"
                :options="[
                  { value: '1', label: 'Exclusive' },
                  { value: '2', label: 'Inclusive' },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Tax')">
              <a-input-number v-model:value="lineDraft.tax_percent" style="width: 100%" :min="0" :max="100" addon-after="%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Discount_Method') || 'Discount Method'">
              <a-select
                v-model:value="lineDraft.discount_Method"
                :options="[
                  { value: '2', label: $t('Fixed') },
                  { value: '1', label: $t('Percentage') },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Discount')">
              <a-input-number v-model:value="lineDraft.discount" style="width: 100%" :min="0" />
            </a-form-item>
          </a-col>
          <a-col v-if="units.length" :span="12">
            <a-form-item :label="$t('UnitSale')">
              <a-select v-model:value="lineDraft.sale_unit_id" :options="units.map(u => ({ value: u.id, label: u.name }))" />
            </a-form-item>
          </a-col>
          <!-- Per-line note (ported from billing's sale_details.note) --
               shown in the items table in place of the product code. -->
          <a-col :span="24">
            <a-form-item :label="$t('Description')">
              <!-- Placeholders: filled per invoice (Recurring) or with this
                   sale's date when saved (one invoice). -->
              <template #extra>
                <span v-if="isRecurring">Recurring: use placeholders that change per invoice, e.g. <code v-text="'Note: {{month}} installment services'" />.</span>
                <span v-else>Variables like <code v-text="'{{month}}'" /> are filled with this sale's date when you save, e.g. <code v-text="'Service made on {{month}}'" />.</span>
                <NotePlaceholderHelp
                  :hint="isRecurring ? undefined : 'Click to add. Filled with this sale\'s date when you save.'"
                  @insert="tk => (lineDraft.note = appendToken(lineDraft.note, tk))"
                  @use="ex => (lineDraft.note = ex)"
                >
                  <a-button type="link" size="small">Placeholders &amp; examples</a-button>
                </NotePlaceholderHelp>
              </template>
              <a-textarea v-model:value="lineDraft.note" :rows="3" />
            </a-form-item>
          </a-col>
          <a-col v-if="editingLine.is_imei" :span="24">
            <a-form-item :label="$t('IMEI_SN')">
              <SerialPicker :line="editingLine" :warehouse-id="sale.warehouse_id" />
            </a-form-item>
          </a-col>
          <a-col v-if="editingLine.is_batch_tracked" :span="24">
            <a-form-item :label="$t('Batches')">
              <BatchAllocator :line="editingLine" :warehouse-id="sale.warehouse_id" endpoint="batches_for_sale" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <QuickAddParty v-model:open="quickAddClientOpen" type="client" @created="onClientCreated" />
  </div>
</template>

<script setup>
/**
 * Sale create/edit — v1 of the multi-line document form.
 * Contracts (all verified against SalesController + legacy form):
 * - bootstrap create: GET sales/create → clients/warehouses/sales_agents/
 *   accounts/payment_methods; edit: GET sales/{id}/edit → + sale/details
 * - products for a warehouse: GET get_Products_by_warehouse/{id}
 *   ?stock=1&is_sale=1&product_service=1&product_combo=1 (client-side filter)
 * - line data: GET show_product_data/{pid}/{vid}/{wid} — line stock is
 *   qte_sale, '---' for services; unit list GET get_units?id={product_id}
 * - math in lib/lineCalc.js, copied from legacy to the formula
 * - POST sales / PUT sales/{id} — details carry legacy field names verbatim;
 *   payment only on create when statut=completed (one payments[] line)
 *
 * v2: batch allocation (BatchAllocator ← batches_for_sale) and the serial
 * picker (SerialPicker ← serial_numbers/available) live in the line-edit
 * modal; validation matches legacy's hasBatchValidationErrors /
 * serialCountMismatch exactly (completed submits only).
 *
 * v3 closes the remaining parity gaps, all copied from legacy:
 * - retail/wholesale toggle + multi-pack selling per line (a non-default pack
 *   pins its own price and multiplies the stock a unit consumes)
 * - loyalty points → discount (GET get_points_client/{id}, rate from
 *   sales/create), kept separate from the manual discount
 * - credit-limit check at submit (GET clients/{id}/brief)
 * - split payment across several methods (payments[]), with the sale's amount
 *   summed from the lines and the surplus reported as change
 * - Quick Add Customer without leaving the page (QuickAddParty)
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { EditOutlined, DeleteOutlined, PlusOutlined, SyncOutlined, QuestionCircleOutlined, FileTextOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import QuickAddParty from '../../components/QuickAddParty.vue';
import QuickAddProduct from '../../components/QuickAddProduct.vue';
import SerialPicker from '../../components/SerialPicker.vue';
import BatchAllocator from '../../components/BatchAllocator.vue';
import ProductScanModal from '../../components/ProductScanModal.vue';
import RecurringSchedule from '../../components/RecurringSchedule.vue';
import ServiceJobWhen, { emptyWhen, whenToSchedule } from '../../components/ServiceJobWhen.vue';
import NotePlaceholderHelp, { appendToken } from '../../components/NotePlaceholderHelp.vue';
import { useFormat } from '../../composables/useFormat';
import { useDocCurrency } from '../../composables/useDocCurrency';
import { useAuthStore } from '../../stores/auth';
import { productDisplayName, productSearchLabel } from '../../lib/productDisplayName';
import { recomputeLine, computeTotals } from '../../lib/lineCalc';
import { hasBatchSelectErrors, firstBatchSelectError } from '../../lib/batchValidation';
import { resolveScan } from '../../lib/scanMatch';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
// moneyBase: forms always show base-currency values as base, ignoring the
// report pages' display-currency selector.
const { moneyBase } = useFormat();
const auth = useAuthStore();

// Multi-Currency: display/input conversion only — the model stays base.
const {
  enabled: mcEnabled,
  currencyId: docCurrencyId,
  options: currencyOptions,
  isBase: dcIsBase,
  toDoc: toDocAmt,
  toBase: toBaseAmt,
  docMoney,
  loadCurrencies,
  setFromDocument: setDocCurrency,
  payloadFields: currencyPayload,
} = useDocCurrency();

const id = computed(() => route.params.id);
/**
 * Three modes. `convert` turns a quotation into a NEW sale, so it carries a
 * route :id like edit does but must submit down the create path — reading the
 * mode from route meta rather than inferring it from the presence of :id is
 * what keeps those two apart.
 */
const mode = computed(() => route.meta?.mode || (route.params.id ? 'edit' : 'create'));
const isEdit = computed(() => mode.value === 'edit');
const isConvert = computed(() => mode.value === 'convert');
// Duplicate Invoice ("All Sales" list -> Duplicate icon): a plain create
// route (`/sales/create?duplicate={id}`, no :id param) prefilled from an
// existing sale's own line detail (not a quotation), so it still submits
// down the create path exactly like a from-scratch new sale.
const duplicateFromId = computed(() => route.query.duplicate);
const isDuplicate = computed(() => !isEdit.value && !isConvert.value && !!duplicateFromId.value);

const loadingRecord = ref(true);
const submitting = ref(false);

// ---------------- One invoice / Recurring ----------------
// Recurring = the same lines billed on a schedule, saved as an active Sales
// Contract (POST sales_contracts, generate_due) whose billing creates each
// invoice -- same invoice-day / count / note-placeholder features as the
// Sales Contract form. New sales only, and only for users who may create
// Sales Contracts.
const canRecurring = computed(() => !isEdit.value
  && (auth.user?.type === 'SuperAdmin' || auth.can('sales_contracts_add')));
const invoiceMode = ref('single');
const isRecurring = computed(() => canRecurring.value && invoiceMode.value === 'recurring');
const recurring = ref({
  subject: '', end_date: null, invoice_note: '',
  billing_frequency: 'monthly', billing_interval: 1, billing_day_mode: 'same', billing_day: null,
  billing_count: null, next_invoice_date: null,
});
const scheduleRef = ref(null);
// Switching to Recurring: the order discount / shipping fields are hidden
// (generated invoices don't repeat them), so clear what was typed there.
watch(isRecurring, on => {
  if (!on) return;
  if ((Number(sale.value.discount) || 0) || (Number(sale.value.shipping) || 0)) {
    sale.value.discount = 0;
    sale.value.shipping = 0;
    message.info('Order discount and shipping don\'t apply to recurring invoices: cleared.');
  }
});

// ---------------- Service job (Create Sale) ----------------
// One invoice: one Service Job created with the sale (sales.store ->
// service_job, linked by sale_id). Recurring: visits on their own schedule,
// created by the Sales Contract (jobs_* fields, see SalesContractJobs).
// Only for new sales of a company with the Service module.
const serviceJobAvailable = ref(false);
const serviceTechnicians = ref([]);
const serviceJob = ref({ enabled: false, title: '', technician_id: undefined, job_type: '', time: null, lead_days: 7 });
// One invoice: when the job(s) happen (components/ServiceJobWhen.vue); empty date = the sale date.
const serviceJobWhen = ref(emptyWhen());
const jobSchedule = ref({
  billing_frequency: 'weekly', billing_interval: 1, billing_count: null,
  billing_day_mode: 'same', billing_day: null, next_invoice_date: null,
});
const technicianOptions = computed(() => sortByLabel(serviceTechnicians.value.map(t2 => ({ value: t2.id, label: t2.name }))));
// Edit Sale: the jobs already made with this sale (listed in the card).
const saleServiceJobs = ref([]);
// Editing a draft (Save as draft / a recurring invoice to review).
const isDraft = ref(false);
// The customer's existing jobs linked to this invoice (service_job_ids ->
// service_job_sales); the list follows the chosen customer.
const linkedJobIds = ref([]);
const clientJobs = ref([]);
const clientJobsLoading = ref(false);
const linkJobOptions = computed(() => {
  const opts = clientJobs.value.map(j => ({ value: j.id, label: j.label }));
  // Linked jobs no longer in the list (e.g. cancelled) stay selectable.
  saleServiceJobs.value.forEach(j => { if (!opts.some(o => o.value === j.id)) opts.push({ value: j.id, label: `${j.ref} · ${j.title || ''} · ${j.status}` }); });
  return opts;
});
async function loadClientJobs() {
  clientJobs.value = [];
  if (!serviceJobAvailable.value || !sale.value.client_id) return;
  clientJobsLoading.value = true;
  try {
    clientJobs.value = (await http.get(`sales_client_service_jobs/${sale.value.client_id}`)).jobs || [];
  } catch (e) { clientJobs.value = []; }
  clientJobsLoading.value = false;
}
// Edit Sale: status + a note per linked job (service_job_updates on PUT).
// Delivered / cancelled / declined are handled on the job page (stock, payments).
const JOB_EDITABLE = ['pending', 'intake', 'approved', 'in_progress', 'completed'];
const JOB_STATUS_OPTIONS = [
  { value: 'pending', label: 'Pending' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'completed', label: 'Completed' },
];
const jobEdits = ref({});
function serviceJobUpdatesPayload() {
  const updates = saleServiceJobs.value
    .filter(j => jobEdits.value[j.id])
    .map(j => ({ id: j.id, status: jobEdits.value[j.id].status, note: (jobEdits.value[j.id].note || '').trim() }))
    .filter(u => u.note || u.status !== saleServiceJobs.value.find(j => j.id === u.id).status);
  return updates.length ? { service_job_updates: updates } : {};
}
function loadServiceJobOptions(data) {
  serviceJobAvailable.value = data?.service_job_enabled === true;
  serviceTechnicians.value = data?.service_technicians || [];
  saleServiceJobs.value = data?.sale_service_jobs || [];
  jobEdits.value = Object.fromEntries(saleServiceJobs.value.map(j => [j.id, { status: j.status, note: '' }]));
  linkedJobIds.value = saleServiceJobs.value.map(j => j.id);
  loadClientJobs();
}
/** The one-off service job + the linked jobs for the sales POST / PUT. */
function serviceJobPayload() {
  const sj = serviceJob.value;
  const links = serviceJobAvailable.value && !isRecurring.value ? { service_job_ids: linkedJobIds.value } : {};
  if (!serviceJobAvailable.value || !sj.enabled || isRecurring.value) return links;
  const w = serviceJobWhen.value;
  // No date picked: the sale's date.
  const when = whenToSchedule(w.mode !== 'dates' && !w.date ? { ...w, date: sale.value.date } : w);
  return {
    ...links,
    service_job: {
      enabled: 1,
      title: (sj.title || '').trim() || serviceJobTitleDefault.value,
      technician_id: sj.technician_id || null,
      job_type: sj.job_type || null,
      ...when,
    },
  };
}
function serviceJobMessage(res) {
  if (res?.service_job?.ref) message.info(res.service_job.count > 1 ? `${res.service_job.count} service jobs created: ${res.service_job.ref}.` : `Service job ${res.service_job.ref} created.`, 6);
  else if (res?.service_job?.warning) message.warning(res.service_job.warning, 6);
}
// Default title: the first line's product (e.g. "Lawn mowing"), else the customer.
const serviceJobTitleDefault = computed(() => {
  const first = lines.value[0];
  if (first?.name) return productDisplayName(first.name);
  const c = clients.value.find(x => Number(x.id) === Number(sale.value.client_id));
  return c ? `Service for ${c.name}` : 'Service';
});

const clients = ref([]);
const warehouses = ref([]);
const agents = ref([]);
// Change Salesperson (same opt-in feature as the POS picker): attribution only,
// the sale's owner stays the logged-in user.
const salespeople = ref([]);
const salesSwitchEnabled = ref(false);
// Settings -> Defaults -> Default - Sales: show/hide the Retail/Wholesale
// price dropdown on each line when adding a product (default on).
const showPriceTypeDropdown = ref(true);
// Settings -> Defaults -> Default - Sales: quantity a new line starts at
// when a product is added (default 1).
const defaultQuantity = ref(1);
// Settings -> Defaults -> Default - Sales: master on/off for the Loyalty
// Points widget and the Shipping field, for companies that don't use them
// (e.g. service-only businesses that don't ship products) (both default on).
const loyaltyPointsEnabled = ref(true);
const shippingEnabled = ref(true);
// Settings -> Defaults -> Default - Sales: show/hide the Sales Agent field
// (like Shipping, default on; it also needs the Commissions module).
const salesAgentEnabled = ref(true);

// New sales start credited to the authenticated user (matches the POS
// default); edit mode keeps whatever the sale already stores.
function defaultSellerToSelf() {
  if (!salesSwitchEnabled.value || sale.value.seller_id) return;
  const uid = Number(auth.user?.id);
  if (uid && salespeople.value.some(s => Number(s.id) === uid)) {
    sale.value.seller_id = uid;
  }
}
const accounts = ref([]);
const paymentMethods = ref([]);
const products = ref([]);
const lines = ref([]);
// `null`, never `undefined`: antd's Select falls back to its own internal
// selection whenever the bound value is undefined, so clearing it that way
// leaves the last picked product on screen instead of the placeholder.
const searchValue = ref(null);
let nextDetailId = 1;

// Overselling Control (get_pos_Settings.allow_overselling): when ON, every
// stock check on this page is bypassed — same switch legacy honours.
const posSettings = ref({});
const isOversellingAllowed = computed(() => !!posSettings.value?.allow_overselling);

const serialsOf = line =>
  Array.isArray(line.serial_numbers) && line.serial_numbers.length
    ? line.serial_numbers
    : String(line.imei_number || '').split(/[\r\n,;\t]+/).map(s => s.trim()).filter(Boolean);

const serialCountMismatch = line =>
  !!line.is_imei && serialsOf(line).length !== Math.round(Number(line.quantity) || 0);

// Legacy disables submit on these two, in addition to blocking in verifiedForm.
const hasMinPriceViolation = computed(() =>
  lines.value.some(l => (Number(l.min_price) || 0) > 0 && Number(l.Net_price) < Number(l.min_price))
);
// Full legacy rule set: allocation present, every row picked, qty > 0 and
// within availability, no duplicate batches, totals matching the line qty.
const hasBatchValidationErrors = computed(() => hasBatchSelectErrors(lines.value));

const sale = ref({
  date: dayjs().format('YYYY-MM-DD'),
  client_id: undefined,
  warehouse_id: undefined,
  sales_agent_id: undefined,
  seller_id: undefined,
  statut: 'completed',
  notes: '',
  tax_rate: 0,
  tax_Method: '1',
  discount: 0,
  discount_Method: '2',
  shipping: 0,
});

// Monetary inputs are typed in the document currency but stored in base.
// Percentage discounts are unit-less and pass through untouched.
const discountInput = computed({
  get: () => (String(sale.value.discount_Method) === '2'
    ? toDocAmt(sale.value.discount)
    : Number(sale.value.discount) || 0),
  set: (v) => {
    sale.value.discount = String(sale.value.discount_Method) === '2'
      ? toBaseAmt(v)
      : (Number(v) || 0);
  },
});
// Order Tax: percent is unit-less; a fixed amount is typed in the document
// currency but stored in base, same convention as discount/shipping.
const taxInput = computed({
  get: () => (String(sale.value.tax_Method) === '2'
    ? toDocAmt(sale.value.tax_rate)
    : Number(sale.value.tax_rate) || 0),
  set: (v) => {
    sale.value.tax_rate = String(sale.value.tax_Method) === '2'
      ? toBaseAmt(v)
      : (Number(v) || 0);
  },
});
const shippingInput = computed({
  get: () => toDocAmt(sale.value.shipping),
  set: (v) => { sale.value.shipping = toBaseAmt(v); },
});

// Preserved verbatim on edit — zeroing them would strip an existing
// loyalty-points discount from the sale.
const pointsState = ref({ discount_from_points: 0, used_points: 0 });

// Loyalty points: `point_to_amount_rate` comes from sales/create and converts
// points to currency. Available points drop as soon as a conversion is applied
// (display only — the backend deducts them on save), and `initialClientPoints`
// is what an "Unconverted" click restores.
const point_to_amount_rate = ref(0);
const selectedClientPoints = ref(0);
const initialClientPoints = ref(0);
const points_to_convert = ref(0);
const pointsConverted = ref(false);
const clientIsEligible = ref(false);

// Credit limit of the selected customer (0 = unlimited) and what they already
// owe — checked at submit when the sale is not paid in full.
const selectedClientCreditLimit = ref(0);
const selectedClientNetBalance = ref(0);

// Split payment: one row per method. Empty while the status is pending.
const payment_lines = ref([]);

const payment = ref({
  // Unpaid until the cashier explicitly records money — a new sale must not
  // default to fully paid.
  status: 'pending',
  payment_method_id: 2,
  amount: 0,
  received_amount: 0,
  account_id: undefined,
});

const pointsInputValid = computed(() => {
  const val = Number(points_to_convert.value);
  return Number.isInteger(val) && val >= 1 && val <= (Number(selectedClientPoints.value) || 0);
});

const totalPaidLines = computed(() =>
  payment_lines.value.reduce((sum, l) => sum + (Number(l.amount) || 0), 0));
const paymentDue = computed(() => {
  const due = Number(totals.value.GrandTotal || 0) - totalPaidLines.value;
  return due > 0 ? due : 0;
});
const paymentChange = computed(() => {
  const over = totalPaidLines.value - Number(totals.value.GrandTotal || 0);
  return over > 0 ? over : 0;
});

const clientOptions = computed(() => sortByLabel(clients.value.map(c => ({ value: c.id, label: c.name }))));

// Quick Add Customer — legacy appends the new client, selects it, then runs
// the same selection side effects as picking an existing one.
const quickAddClientOpen = ref(false);
function onClientCreated(client) {
  clients.value.push(client);
  sale.value.client_id = client.id;
  onClientChange(client.id);
}

/**
 * Legacy Selected_customer: every customer change resets any points already
 * converted AND the manual discount (legacy zeroes sale.discount and resets
 * the method to fixed), then reloads the new customer's points and credit.
 */
async function onClientChange(clientId) {
  pointsState.value = { discount_from_points: 0, used_points: 0 };
  selectedClientPoints.value = 0;
  initialClientPoints.value = 0;
  points_to_convert.value = 0;
  pointsConverted.value = false;
  clientIsEligible.value = false;
  sale.value.discount = 0;
  sale.value.discount_Method = '2';
  selectedClientCreditLimit.value = 0;
  selectedClientNetBalance.value = 0;
  if (!clientId) return;

  // Company-level loyalty switch off -> don't even fetch the customer's points.
  if (loyaltyPointsEnabled.value && auth.customerLoyaltyEnabled) {
    try {
      const data = await http.get(`get_points_client/${clientId}`);
      if (data?.is_royalty_eligible) {
        selectedClientPoints.value = Number(data.points) || 0;
        initialClientPoints.value = Number(data.points) || 0;
        clientIsEligible.value = true;
      }
    } catch (e) { /* points stay at zero */ }
  }

  try {
    const brief = await http.get(`clients/${clientId}/brief`);
    selectedClientCreditLimit.value = parseFloat(brief?.credit_limit || 0);
    selectedClientNetBalance.value = parseFloat(brief?.netBalance || 0);
  } catch (e) { /* treated as no limit */ }
}

/** Legacy clamps the input to whole points within the available balance. */
function onPointsToConvertInput(value) {
  const max = Number(selectedClientPoints.value) || 0;
  let val = Number(value);
  if (!Number.isFinite(val) || val < 0) val = 0;
  val = Math.floor(val);
  if (val > max) {
    message.warning(t('Entered_points_exceed_available'));
    val = max;
  }
  points_to_convert.value = val;
}

/**
 * Toggle the points discount. Converting keeps the discount SEPARATE from
 * sale.discount (the manual field stays what the user typed) — computeTotals
 * applies both. Un-converting restores the displayed balance.
 */
function convertPointsToDiscount() {
  if (pointsConverted.value) {
    pointsState.value = { discount_from_points: 0, used_points: 0 };
    points_to_convert.value = 0;
    pointsConverted.value = false;
    if (Number.isFinite(initialClientPoints.value)) {
      selectedClientPoints.value = Number(initialClientPoints.value) || 0;
    }
    return;
  }

  const maxPoints = Number(selectedClientPoints.value) || 0;
  let pts = Number(points_to_convert.value);
  if (!Number.isFinite(pts) || pts <= 0) {
    message.warning(t('Please_enter_points_to_convert'));
    return;
  }
  if (pts > maxPoints) {
    message.warning(t('Entered_points_exceed_available'));
    pts = maxPoints;
  }
  points_to_convert.value = pts;
  pointsState.value = {
    discount_from_points: parseFloat((pts * point_to_amount_rate.value).toFixed(2)),
    used_points: pts,
  };
  pointsConverted.value = true;
  const baseAvail = Number(initialClientPoints.value || selectedClientPoints.value) || 0;
  selectedClientPoints.value = Math.max(0, baseAvail - pts);
}
const warehouseOptions = computed(() => sortByLabel(warehouses.value.map(w => ({ value: w.id, label: w.name }))));
const agentOptions = computed(() => sortByLabel(agents.value.map(a => ({ value: a.id, label: a.name }))));
const salespersonOptions = computed(() =>
  sortByLabel(salespeople.value.map(s => ({ value: s.id, label: s.name || s.username })))
);
const accountOptions = computed(() => sortByLabel(accounts.value.map(a => ({ value: a.id, label: a.account_name }))));
const paymentMethodOptions = computed(() => sortByLabel(paymentMethods.value.map(m => ({ value: m.id, label: m.name }))));

const productOptions = computed(() =>
  sortByLabel(products.value.map((p, i) => ({
    value: i,
    // Group Name once + the item ("Landscape Maintenance Services — Monthly"),
    // not "Group — Group (Item)"; stock only for stocked products (services
    // report '---').
    label: `${productSearchLabel(p.name, p.group_name)}${p.qte !== undefined && p.qte !== '---' ? ` (${p.qte})` : ''}`,
  })))
);

// Matches Group Name / Name only -- Code is de-prioritized here (there's a
// dedicated Code column/search elsewhere); the scan button and barcode
// input still match on code directly, this box is name/group driven.
function filterProduct(input, option) {
  const p = products.value[option.value];
  return `${p?.group_name || ''} ${p?.name || ''}`
    .toLowerCase()
    .includes(String(input).toLowerCase());
}

const totals = computed(() =>
  computeTotals(lines.value, {
    discount: sale.value.discount,
    discountMethod: sale.value.discount_Method,
    taxRate: sale.value.tax_rate,
    taxMethod: sale.value.tax_Method,
    shipping: sale.value.shipping,
    discountFromPoints: pointsState.value.discount_from_points,
  })
);

// Keep "paid in full" aligned with the grand total, like legacy's GrandTotal
// watcher: a single line tracks the total, a manual split is left alone. A
// negative total forces the sale to pending, exactly as legacy does.
watch([totals, () => payment.value.status], () => {
  if (Number(totals.value.GrandTotal) < 0) {
    payment.value.status = 'pending';
    return;
  }
  if (payment.value.status === 'paid') {
    payment.value.amount = totals.value.GrandTotal;
    payment.value.received_amount = totals.value.GrandTotal;
    if (payment_lines.value.length === 1) {
      const gt = Number(totals.value.GrandTotal);
      payment_lines.value[0].amount = gt > 0 ? Number(gt.toFixed(2)) : 0;
    }
  }
});

const lineColumns = computed(() => [
  { title: t('ProductName'), key: 'product' },
  { title: t('Net_Unit_Price'), key: 'net_price', align: 'right' },
  { title: t('Stock'), key: 'stock', align: 'right' },
  { title: t('Quantity'), key: 'quantity', align: 'center' },
  { title: t('Discount'), key: 'discount', align: 'right' },
  { title: t('Tax'), key: 'tax', align: 'right' },
  { title: t('SubTotal'), key: 'subtotal', align: 'right' },
  { title: t('Action'), key: 'actions', width: 100, align: 'center' },
]);

// ---------------- products / lines ----------------

async function loadProducts() {
  if (!sale.value.warehouse_id) return;
  try {
    products.value = await http.get(
      `get_Products_by_warehouse/${sale.value.warehouse_id}`,
      { stock: 1, is_sale: 1, product_service: 1, product_combo: 1 }
    ) || [];
  } catch (e) {
    products.value = [];
    message.error(t('InvalidData'));
  }
}

function onWarehouseChange() {
  // Prices/stock are per-warehouse; existing lines would be stale.
  lines.value = [];
  loadProducts();
}

const quickAddProductOpen = ref(false);
// ProductsController@store doesn't hand back the created row, so re-fetch
// this warehouse's product list and find the new product by the code the
// modal generated, then add it exactly like a normal search pick.
async function onProductCreated({ code }) {
  await loadProducts();
  const idx = products.value.findIndex(p => p.product_code === code || p.code === code);
  if (idx === -1) {
    message.warning(t('Product_Not_Found'));
    return;
  }
  await onProductPicked(idx);
}

// Browse by Group: an alternative to the free-text search for catalogs that
// lean on Group Name to organize many products (only grouped products show
// up here -- ungrouped ones stay reachable via the regular search).
const browseGroupId = ref(undefined);
const browseProductIdx = ref(undefined);
const browseGroupOptions = computed(() => {
  const seen = new Map();
  products.value.forEach(p => {
    if (p.product_group_id && !seen.has(p.product_group_id)) {
      seen.set(p.product_group_id, p.group_name);
    }
  });
  return sortByLabel(Array.from(seen.entries()).map(([value, label]) => ({ value, label })));
});
const browseProductOptions = computed(() => {
  if (!browseGroupId.value) return [];
  const group = browseGroupOptions.value.find(g => g.value === browseGroupId.value);
  const mapped = products.value
    .map((p, i) => ({ p, i }))
    .filter(({ p }) => p.product_group_id === browseGroupId.value)
    .map(({ p, i }) => ({ value: i, label: stripGroupPrefix(p.name, group?.label) }));
  // The group's generic/base item (no further sub-item) is stored with the
  // literal stripped label "Main" -- relabel it clearly and pin it first
  // (a deliberate exception to alphabetical order: it's the "just use the
  // group itself" default, not a real sub-item name to sort among the rest).
  const isMain = o => String(o.label).trim().toLowerCase() === 'main';
  const mainEntry = mapped.find(isMain);
  const rest = sortByLabel(mapped.filter(o => !isMain(o)));
  return mainEntry ? [{ ...mainEntry, label: 'Main (use group name only)' }, ...rest] : rest;
});
// A product's own name often already carries its group as a "Group (Item)"
// prefix (e.g. "ProductKT-01 (productktsubname01)") -- once the group is
// the one just picked in the dropdown above, repeating it here in every
// row is redundant and reads as a duplicate. Strip it for this list only;
// the full name is still used everywhere else (line items, receipts...).
function stripGroupPrefix(name, groupName) {
  if (!groupName) return name;
  const prefix = `${groupName} (`;
  if (name.startsWith(prefix) && name.endsWith(')')) {
    return name.slice(prefix.length, -1);
  }
  return name;
}
async function onBrowseProductPicked(idx) {
  await onProductPicked(idx);
  // Keep the Group selected so the next item from the same group is one pick away.
  browseProductIdx.value = undefined;
}

// Camera / handheld scan: match the code exactly as legacy search() did
// (weighing-scale 13-digit codes included) and add the product, applying the
// embedded weight as the quantity when the scale barcode carries one.
async function onScan(code) {
  const hit = resolveScan(products.value, code, { weighing: true });
  if (!hit) {
    message.warning(t('Product_Not_Found'));
    return;
  }
  const p = products.value[hit.idx];
  await onProductPicked(hit.idx);
  if (hit.weight != null && p) {
    const line = lines.value.find(l => l.product_id === p.id);
    if (line) setQty(line, hit.weight);
  }
}

async function onProductPicked(idx) {
  searchValue.value = null;
  const p = products.value[idx];
  if (!p) return;
  const variantId = p.product_variant_id ?? 0;

  const existing = lines.value.find(
    l => l.product_id === p.id && (l.product_variant_id ?? 0) === (variantId || 0)
  );
  if (existing) {
    setQty(existing, Number(existing.quantity) + 1);
    return;
  }

  try {
    const d = await http.get(`show_product_data/${p.id}/${variantId}/${sale.value.warehouse_id}`);
    const line = {
      detail_id: nextDetailId++,
      product_id: d.id,
      product_variant_id: p.product_variant_id ?? null,
      product_type: d.product_type,
      code: d.code || p.code,
      name: d.name,
      // Prefills from the product's own default note (products.note); the
      // line-edit modal lets it be changed per line from there.
      note: d.note || '',
      stock: d.product_type !== 'is_service' ? d.qte_sale : '---',
      // Overselling: never seed the line with the (zero/negative) stock —
      // fall back to the configured default quantity (Settings -> Defaults
      // -> Default - Sales) so the product can actually be sold.
      // Services have no stock: always the default quantity (a service can
      // carry a meaningless negative stock row, e.g. -4, which used to become
      // the line's quantity). Stocked products without overselling start at
      // what's left, never below 0.
      quantity: d.product_type !== 'is_service' && !isOversellingAllowed.value && d.qte_sale !== undefined && d.qte_sale < defaultQuantity.value
        ? Math.max(0, Number(d.qte_sale) || 0)
        : defaultQuantity.value,
      Unit_price: d.Unit_price,
      Net_price: d.Net_price,
      discount: d.discount,
      discount_Method: d.discount_method,
      DiscountNet: d.DiscountNet,
      taxe: d.tax_price,
      tax_percent: d.tax_percent,
      tax_method: d.tax_method,
      unitSale: d.unitSale,
      sale_unit_id: d.sale_unit_id,
      min_price: Number(d.min_price) || 0,
      is_imei: d.is_imei,
      imei_number: '',
      serial_numbers: [],
      is_batch_tracked: !!d.is_batch_tracked,
      batches: [],
      etat: 'new',
      qte_copy: 0,
      price_type: 'retail',
      // Immutable baselines the retail/wholesale switch reprices from.
      retail_unit_price: d.Unit_price,
      wholesale_unit_price: d.Unit_price_wholesale,
      packs: d.packs || [],
      product_pack_id: null,
      pack_multiplier: 1,
      pack_name: null,
      subtotal: 0,
    };
    // Multi-pack selling: preselect the default pack, like legacy.
    const defPack = (line.packs || []).find(pk => pk.is_default) || null;
    line.product_pack_id = defPack ? defPack.id : null;
    line.pack_name = defPack ? defPack.name : null;

    recomputeLine(line);
    // A wholesale-priced product that breaches its own minimum falls back to
    // retail before the line is ever shown.
    if (Number(line.Net_price) < Number(line.min_price || 0)) {
      line.price_type = 'retail';
      applyPriceType(line);
    }
    lines.value.push(line);
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

// Mirrors legacy Verified_Qty exactly — the two forms differ:
// - create: cap qty×pack_multiplier at stock (clamp floor(stock/mult)),
//   bypassed entirely when overselling is allowed
// - edit: STRICT (legacy edit ignores the overselling switch); lines already
//   on the sale (etat 'current') may go up to stock + qte_copy since their
//   sold quantity was deducted from stock — violation resets to qte_copy;
//   lines added during the edit behave like create ones (cap = stock)
// Services (stock '---') are never stock-checked.
function setQty(line, value) {
  let qty = Number(value);
  const stocked = line.stock !== '---' && line.product_type !== 'is_service';
  if (isEdit.value && line.etat === 'current') {
    if (Number.isNaN(qty)) qty = Number(line.qte_copy) || 0;
    if (stocked && qty > Number(line.stock) + (Number(line.qte_copy) || 0)) {
      message.warning(t('LowStock'));
      qty = Number(line.qte_copy) || 0;
    }
  } else {
    if (Number.isNaN(qty)) qty = Number(line.stock) || 0;
    const mult = Number(line.pack_multiplier) || 1;
    const bypass = !isEdit.value && isOversellingAllowed.value;
    if (!bypass && stocked && qty * mult > Number(line.stock)) {
      message.warning(t('LowStock'));
      qty = mult > 1 ? Math.floor(Number(line.stock) / mult) : Number(line.stock);
    }
  }
  line.quantity = qty;
  recomputeLine(line);
}

function removeLine(index) {
  lines.value.splice(index, 1);
}

// ---------------- price type + multi-pack selling ----------------

function getSelectedPack(line) {
  if (!line?.packs || !line.product_pack_id) return null;
  return line.packs.find(p => String(p.id) === String(line.product_pack_id)) || null;
}

/** A pack is unsellable when one pack alone would exceed the stock on hand. */
function packOutOfStock(line, pack) {
  return !isOversellingAllowed.value
    && line.product_type !== 'is_service'
    && Number(pack.multiplier) > 1
    && Number(pack.multiplier) > Number(line.stock);
}

function packOptions(line) {
  return (line.packs || []).map(pack => ({
    value: pack.id,
    label: `${pack.name} (x${pack.multiplier})${packOutOfStock(line, pack) ? ` — ${t('Out_of_Stock')}` : ''}`,
    disabled: packOutOfStock(line, pack),
  }));
}

/** Reprice from the retail/wholesale baseline captured at add time. */
function applyPriceType(line) {
  const wantsWholesale = line.price_type === 'wholesale';
  const wholesale = line.wholesale_unit_price;
  const retail = line.retail_unit_price;
  const hasWholesale = wholesale !== undefined && wholesale !== null && wholesale !== '';
  const hasRetail = retail !== undefined && retail !== null && retail !== '';

  if (wantsWholesale && hasWholesale) line.Unit_price = parseFloat(wholesale);
  else if (hasRetail) line.Unit_price = parseFloat(retail);

  recomputeLine(line);
}

/**
 * A non-default pack pins the price to the pack's own price and ignores the
 * retail/wholesale baseline; the default pack falls back to that baseline.
 * pack_multiplier / pack_name always stay in sync — the backend uses them for
 * the stock math.
 */
function applyPackPrice(line) {
  const pack = getSelectedPack(line);
  if (!pack || pack.is_default) {
    line.product_pack_id = pack ? pack.id : null;
    line.pack_multiplier = 1;
    line.pack_name = pack ? pack.name : null;
    applyPriceType(line);
    return;
  }
  line.pack_multiplier = pack.multiplier;
  line.pack_name = pack.name;
  line.Unit_price = parseFloat(pack.price) || 0;
  recomputeLine(line);
}

function onChangePriceType(line, newType) {
  if (newType) line.price_type = newType;

  const pack = getSelectedPack(line);
  if (pack && !pack.is_default) {
    // A fixed pack price wins over the retail/wholesale choice.
    applyPackPrice(line);
    return;
  }
  applyPriceType(line);
  if (Number(line.Net_price) < Number(line.min_price || 0)) {
    message.warning(t('Price_below_min_not_allowed'));
    line.price_type = 'retail';
    applyPriceType(line);
  }
}

function onChangePack(line, packId) {
  line.product_pack_id = packId;
  applyPackPrice(line);
  if (!isOversellingAllowed.value && line.product_type !== 'is_service') {
    const mult = Number(line.pack_multiplier) || 1;
    const avail = Number(line.stock) || 0;
    if (mult > 1 && mult > avail) {
      // Not even one pack fits in stock — fall back to the default pack.
      message.warning(t('Pack_Stock_Insufficient'));
      const def = (line.packs || []).find(p => p.is_default) || null;
      line.product_pack_id = def ? def.id : null;
      applyPackPrice(line);
    } else if (mult > 1 && Number(line.quantity) * mult > avail) {
      message.warning(t('LowStock'));
      line.quantity = Math.floor(avail / mult);
      recomputeLine(line);
    }
  }
}

// ---------------- split payment lines ----------------

/** Legacy defaults a new line to the cash method when one exists. */
function defaultPaymentLine(amount) {
  let methodId = 2;
  if (paymentMethods.value.length) {
    const cash = paymentMethods.value.find(m => m.name && m.name.toLowerCase().includes('cash'));
    methodId = cash ? cash.id : paymentMethods.value[0].id;
  }
  return { payment_method_id: methodId, amount: Number(amount) || 0, account_id: undefined };
}

function initPaymentLines(amount) {
  payment_lines.value = [defaultPaymentLine(amount)];
}

function addPaymentLine() {
  // Prefilled with what is still due, to speed up a split.
  payment_lines.value.push(defaultPaymentLine(paymentDue.value > 0 ? paymentDue.value : 0));
}

function removePaymentLine(idx) {
  if (payment_lines.value.length <= 1) return;
  payment_lines.value.splice(idx, 1);
}

function onPaymentLineInput(line, value) {
  // Typed in the document currency; stored in base like every other amount.
  const v = Number(value);
  line.amount = !Number.isFinite(v) || v < 0 ? 0 : toBaseAmt(v);
}

// ---------------- line edit modal ----------------

const lineEditOpen = ref(false);
const lineEditLoading = ref(false);
const editingLine = ref(null);
const lineDraft = ref({});
const units = ref([]);

async function openLineEdit(line) {
  editingLine.value = line;
  lineDraft.value = {
    // The modal is typed in the document currency (fixed amounts only —
    // percentages pass through); applyLineEdit converts back to base.
    Unit_price: toDocAmt(line.Unit_price),
    tax_method: String(line.tax_method || '1'),
    tax_percent: line.tax_percent,
    discount_Method: String(line.discount_Method || '2'),
    discount: String(line.discount_Method || '2') === '2' ? toDocAmt(line.discount) : line.discount,
    sale_unit_id: line.sale_unit_id,
    note: line.note || '',
  };
  units.value = [];
  lineEditLoading.value = true;
  lineEditOpen.value = true;
  try {
    const data = await http.get('get_units', { id: line.product_id });
    units.value = Array.isArray(data) ? data : data?.units || [];
  } catch (e) {
    /* unit select stays hidden */
  } finally {
    lineEditLoading.value = false;
  }
}

function applyLineEdit() {
  const line = editingLine.value;
  Object.assign(line, {
    Unit_price: toBaseAmt(lineDraft.value.Unit_price),
    tax_method: lineDraft.value.tax_method,
    tax_percent: Number(lineDraft.value.tax_percent) || 0,
    discount_Method: lineDraft.value.discount_Method,
    discount: String(lineDraft.value.discount_Method) === '2'
      ? toBaseAmt(lineDraft.value.discount)
      : (Number(lineDraft.value.discount) || 0),
    sale_unit_id: lineDraft.value.sale_unit_id,
    note: lineDraft.value.note || '',
    // serial_numbers / imei_number / batches are managed by SerialPicker
    // and BatchAllocator, which mutate the line directly.
  });
  recomputeLine(line);
  lineEditOpen.value = false;
}

// ---------------- payment ----------------

function onPaymentStatusChange() {
  if (payment.value.status === 'paid') {
    payment.value.amount = totals.value.GrandTotal;
    // Prefill one line with the full total.
    initPaymentLines(Number(totals.value.GrandTotal) > 0 ? Number(totals.value.GrandTotal) : 0);
  } else if (payment.value.status === 'partial') {
    payment.value.amount = 0;
    // Start empty so the cashier types the part payment.
    initPaymentLines(0);
  } else {
    payment_lines.value = [];
  }
}

// ---------------- submit ----------------

// Same checks, same order, same messages as legacy. Create and edit differ:
// legacy edit's verifiedForm only checks empty quantities (no overstock or
// min-price re-check at submit — Verified_Qty already clamped input).
function validateForm() {
  // Name the missing field instead of the generic "fill the form" message.
  if (!sale.value.client_id) {
    message.warning(`${t('Customer')}: ${t('Field_is_required')}`);
    return false;
  }
  if (!sale.value.warehouse_id) {
    message.warning(`${t('warehouse')}: ${t('Field_is_required')}`);
    return false;
  }
  if (!lines.value.length) {
    message.warning(t('AddProductToList'));
    return false;
  }
  let emptyQty = 0;
  for (const l of lines.value) {
    if (l.quantity === '' || Number(l.quantity) === 0) {
      emptyQty += 1;
      continue;
    }
    if (isEdit.value) continue;
    const stocked = l.stock !== '---' && l.product_type !== 'is_service';
    if (!isOversellingAllowed.value && stocked && Number(l.quantity) > Number(l.stock)) {
      message.warning(t('LowStock'));
      return false;
    }
    if ((Number(l.min_price) || 0) > 0 && Number(l.Net_price) < Number(l.min_price)) {
      message.warning(t('Price_below_min_not_allowed'));
      return false;
    }
  }
  if (emptyQty > 0) {
    message.warning(t('AddQuantity'));
    return false;
  }
  if (Number(totals.value.GrandTotal) < 0) {
    message.warning(`${t('pos.Total_Payable')} ${t('cannot_be_negative')}`);
    return false;
  }
  // Batch allocation must be complete and consistent when completed —
  // messages come from legacy's firstBatchErrorMessage, key for key.
  if (sale.value.statut === 'completed' && hasBatchValidationErrors.value) {
    message.warning(firstBatchSelectError(lines.value, t));
    return false;
  }
  // Serial count must match quantity when completed (is_imei lines).
  if (sale.value.statut === 'completed') {
    const bad = lines.value.find(serialCountMismatch);
    if (bad) {
      message.warning(`${t('Serials_Count_Mismatch')} — ${bad.name || bad.code || ''}`);
      return false;
    }
  }
  // Payment lines are only validated when money is actually being recorded.
  if (!isEdit.value && payment.value.status !== 'pending' && sale.value.statut === 'completed') {
    if (!payment_lines.value.length || payment_lines.value.some(l => !l.payment_method_id)) {
      message.warning(t('PleaseSelect'));
      return false;
    }
    const totalPaid = totalPaidLines.value;
    if (totalPaid <= 0) {
      message.warning(`${t('Paying_Amount')}: ${t('Field_is_required')}`);
      return false;
    }
    // A split must not exceed the total; a SINGLE line may overpay, and the
    // surplus comes back as change.
    if (payment_lines.value.length > 1 && totalPaid > Number(totals.value.GrandTotal)) {
      message.warning(t('Paying_amount_is_greater_than_Grand_Total'));
      return false;
    }
    // Credit limit (0 = no limit) only bites when this sale adds new credit,
    // and only when the company has credit limits switched on.
    if (auth.customerCreditLimitEnabled && sale.value.client_id && selectedClientCreditLimit.value > 0) {
      const saleTotal = parseFloat(totals.value.GrandTotal || 0);
      if (totalPaid < saleTotal) {
        const newTotalDue = parseFloat(selectedClientNetBalance.value || 0) + (saleTotal - totalPaid);
        if (newTotalDue > selectedClientCreditLimit.value) {
          const exceeded = newTotalDue - selectedClientCreditLimit.value;
          message.error(
            `${t('Credit_Limit_Exceeded')}: ${moneyBase(exceeded)} ${t('exceeds_credit_limit_of')} ${moneyBase(selectedClientCreditLimit.value)}`
          );
          return false;
        }
      }
    }
  }
  return true;
}

function detailsPayload() {
  return lines.value.map(l => ({
    // Existing rows carry their detail id: the controller uses it to tell an
    // update from an insert, and to spot lines removed from the document.
    // Newly added lines have none, and null keeps them out of the old-id set.
    id: l.id ?? null,
    product_id: l.product_id,
    product_variant_id: l.product_variant_id ?? null,
    product_type: l.product_type,
    code: l.code,
    name: l.name,
    quantity: l.quantity,
    sale_unit_id: l.sale_unit_id,
    Unit_price: l.Unit_price,
    Net_price: l.Net_price,
    discount: l.discount,
    discount_Method: String(l.discount_Method || '2'),
    DiscountNet: l.DiscountNet,
    taxe: l.taxe,
    tax_percent: l.tax_percent,
    tax_method: String(l.tax_method || '1'),
    subtotal: l.subtotal,
    imei_number: l.imei_number || null,
    note: l.note || null,
    serial_numbers: serialsOf(l),
    price_type: l.price_type || 'retail',
    product_pack_id: l.product_pack_id || null,
    pack_multiplier: Number(l.pack_multiplier) || 1,
    pack_name: l.pack_name || null,
    // Legacy strips helper fields and keeps only valid allocations,
    // stamping each with the line's unit price.
    batches: l.is_batch_tracked && Array.isArray(l.batches)
      ? l.batches
          .filter(b => b && b.product_batch_id && Number(b.qty) > 0)
          .map(b => ({
            product_batch_id: Number(b.product_batch_id),
            qty: Number(b.qty),
            unit_price: l.Unit_price,
          }))
      : [],
  }));
}

const recurringSubjectDefault = computed(() => {
  const c = clients.value.find(x => Number(x.id) === Number(sale.value.client_id));
  return c ? `Recurring invoice - ${c.name}` : 'Recurring invoice';
});

/**
 * Recurring: the lines become a Sales Contract's lines with the same totals
 * (discount folded into Net_price; an inclusive-tax price keeps its tax,
 * like the contract's tax_method 2), each line's note becomes its per-
 * invoice note template. Order-level tax/discount/shipping, payments, serials,
 * batches and variants can't repeat on generated invoices, so they're refused.
 */
async function submitRecurring() {
  if (!sale.value.client_id) { message.warning(`${t('Customer')}: ${t('Field_is_required')}`); return; }
  if (!sale.value.warehouse_id) { message.warning(`${t('warehouse')}: ${t('Field_is_required')}`); return; }
  if (!lines.value.length) { message.warning(t('AddProductToList')); return; }
  if (lines.value.some(l => !(Number(l.quantity) > 0))) { message.warning(t('AddQuantity')); return; }
  if ((Number(sale.value.discount) || 0) || (Number(sale.value.shipping) || 0)) {
    message.warning('Recurring invoices don\'t repeat the order discount or shipping: set them to 0 (use the line discounts instead).');
    return;
  }
  // Order tax (e.g. the company's default 6.625%) becomes the contract's
  // Order Tax, so each invoice carries it as its own Order Tax (not as a tax
  // on every line). A fixed-amount order tax can't repeat.
  const orderRate = Number(sale.value.tax_rate) || 0;
  if (orderRate && String(sale.value.tax_Method || '1') === '2') {
    message.warning('A fixed-amount order tax can\'t repeat on recurring invoices: use a percentage (Tax Method) or line taxes.');
    return;
  }
  if (!dcIsBase.value) { message.warning('Recurring invoices are in the base currency: switch the currency back first.'); return; }
  const unsupported = lines.value.find(l => l.product_variant_id || l.is_imei || l.is_batch_tracked);
  if (unsupported) {
    message.warning(`${productDisplayName(unsupported.name)}: products with variants, serial numbers or batches can't be invoiced automatically.`);
    return;
  }

  const items = lines.value.map(l => {
    const inclusive = String(l.tax_method || '1') === '2';
    const unit = (Number(l.Net_price) || 0) + (inclusive ? (Number(l.taxe) || 0) : 0);
    return {
      product_id: l.product_id,
      name: l.name || 'Item',
      note: (l.note || '').trim() || null,
      quantity: Number(l.quantity) || 1,
      price: Math.round(unit * 10000) / 10000,
      // The line's own tax only; the order tax goes on the contract.
      tax_percent: Number(l.tax_percent) || 0,
      tax_method: inclusive ? '2' : '1',
    };
  });
  const r = recurring.value;
  const payload = {
    subject: (r.subject || '').trim() || recurringSubjectDefault.value,
    client_id: sale.value.client_id,
    warehouse_id: sale.value.warehouse_id,
    type: 'service',
    status: 'active',
    start_date: sale.value.date,
    end_date: r.end_date || null,
    description: sale.value.notes || '',
    invoice_note: (r.invoice_note || '').trim() || null,
    // Order Tax % of each invoice.
    order_tax_rate: orderRate,
    billing_enabled: true,
    // The NEXT invoices (created on their dates) come in as drafts to review;
    // the ones made now, while saving this form, are final.
    invoice_as_draft: true,
    billing_frequency: r.billing_frequency,
    billing_interval: r.billing_interval || 1,
    billing_count: r.billing_count || null,
    billing_day_mode: r.billing_day_mode,
    billing_day: r.billing_day || null,
    next_invoice_date: r.next_invoice_date || sale.value.date,
    items,
    generate_due: true,
    // Recurring service jobs (visits), when switched on.
    ...(serviceJobAvailable.value && serviceJob.value.enabled ? {
      jobs_enabled: true,
      jobs_service_item: (serviceJob.value.title || '').trim() || null,
      jobs_technician_id: serviceJob.value.technician_id || null,
      jobs_job_type: serviceJob.value.job_type || null,
      // Empty = all-day visits.
      jobs_time: serviceJob.value.time || null,
      jobs_lead_days: serviceJob.value.lead_days ?? 7,
      jobs_frequency: jobSchedule.value.billing_frequency,
      jobs_interval: jobSchedule.value.billing_interval || 1,
      jobs_count: jobSchedule.value.billing_count || null,
      jobs_day_mode: jobSchedule.value.billing_day_mode,
      jobs_day: jobSchedule.value.billing_day || null,
      next_job_date: jobSchedule.value.next_invoice_date || null,
    } : {}),
  };
  submitting.value = true;
  try {
    const res = await http.post('sales_contracts', payload);
    const made = res.invoices_created || [];
    message.success(made.length
      ? `Recurring billing created: ${made.length} invoice${made.length === 1 ? '' : 's'} so far (${made.map(m => m.ref).join(', ')}).`
      : 'Recurring billing created: the first invoice will be created on its date.', 6);
    if (res.jobs_created?.length) message.info(`${res.jobs_created.length} service job(s) created: ${res.jobs_created.map(j => j.ref).join(', ')}`, 6);
    router.push(`/sales-contracts/${res.id}`);
  } catch (e) {
    const errors = e?.data?.errors;
    if (errors) Object.values(errors).flat().forEach(m => message.error(String(m)));
    else message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

async function submit(asDraft = false) {
  if (isRecurring.value) {
    await submitRecurring();
    return;
  }
  // Save as draft: kept Pending (no stock out, no payment) and labelled Draft.
  const draft = asDraft === true;
  if (draft) sale.value.statut = 'pending';
  if (!validateForm()) return;
  submitting.value = true;

  const base = {
    // Edit: always say whether it stays a draft (Submit = finalize).
    ...(draft || isDraft.value ? { save_as_draft: draft ? 1 : 0 } : {}),
    date: sale.value.date,
    client_id: sale.value.client_id,
    warehouse_id: sale.value.warehouse_id,
    sales_agent_id: sale.value.sales_agent_id || null,
    // Always present (null clears the attribution back to the creator).
    seller_id: sale.value.seller_id || null,
    statut: sale.value.statut,
    notes: sale.value.notes,
    tax_rate: Number(sale.value.tax_rate) || 0,
    tax_Method: String(sale.value.tax_Method || '1'),
    TaxNet: totals.value.TaxNet,
    discount: Number(sale.value.discount) || 0,
    discount_Method: String(sale.value.discount_Method || '2'),
    shipping: Number(sale.value.shipping) || 0,
    GrandTotal: totals.value.GrandTotal,
    details: detailsPayload(),
    // Loyalty off for the company -> new sales carry no points. Edits keep the
    // sale's historical points (already in GrandTotal) so the total doesn't shift.
    discount_from_points: (auth.customerLoyaltyEnabled || isEdit.value) ? pointsState.value.discount_from_points : 0,
    used_points: (auth.customerLoyaltyEnabled || isEdit.value) ? pointsState.value.used_points : 0,
    // Multi-Currency snapshot ({} when the module is off)
    ...currencyPayload(),
  };

  try {
    if (isEdit.value) {
      const res = await http.put(`sales/${id.value}`, { ...base, ...serviceJobPayload(), ...serviceJobUpdatesPayload() });
      message.success(draft ? 'Draft saved' : t('Successfully_Updated'));
      serviceJobMessage(res);
      if (res?.service_job_updates) message.info(`${res.service_job_updates} service job(s) updated.`, 5);
    } else {
      const effective = sale.value.statut === 'completed' ? payment.value.status : 'pending';
      // Legacy sends the summed payment lines as the sale's amount, not the
      // single `payment.amount` field.
      const paidLines = effective !== 'pending'
        ? payment_lines.value
            .filter(l => Number(l.amount) > 0)
            .map(l => ({
              amount: Number(l.amount) || 0,
              payment_method_id: l.payment_method_id,
              account_id: l.account_id || null,
            }))
        : [];
      const amount = effective === 'pending' ? 0 : totalPaidLines.value;
      const res = await http.post('sales', {
        // One service job with this sale, when switched on.
        ...serviceJobPayload(),
        ...base,
        // Conversion: link the new sale back to its source quotation.
        ...(isConvert.value ? { quotation_id: id.value } : {}),
        // `payment` keeps the legacy single-payment shape (the first line
        // stands in for it); `payments` carries the full split.
        payment: {
          status: effective,
          payment_method_id: paidLines[0]?.payment_method_id ?? payment.value.payment_method_id,
          amount,
          received_amount: amount,
          account_id: paidLines[0]?.account_id ?? null,
        },
        payments: paidLines,
        amount: amount.toFixed(2),
        received_amount: amount.toFixed(2),
        change: paymentChange.value.toFixed(2),
      });
      message.success(draft ? 'Saved as draft (Pending): finalize it when ready.' : t('Successfully_Created'));
      serviceJobMessage(res);
    }
    router.push('/sales');
  } catch (e) {
    const errors = e?.data?.errors;
    if (errors) Object.values(errors).flat().forEach(msg => message.error(String(msg)));
    else message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

// ---------------- bootstrap ----------------

async function loadPosSettings() {
  try {
    const data = await http.get('get_pos_Settings');
    posSettings.value = data?.pos_settings || {};
  } catch (e) { /* strict stock checks stay on */ }
}

// Another customer: their service jobs to link, none linked yet.
watch(() => sale.value.client_id, (now, before) => {
  if (before !== undefined && now !== before) linkedJobIds.value = [];
  loadClientJobs();
});

onMounted(async () => {
  loadPosSettings();
  loadCurrencies();
  try {
    if (isConvert.value) {
      // The quotation payload carries the document + lines but NOT the
      // accounts / payment methods / points rate, which is why the legacy
      // converter's payment section threw the moment you touched it.
      const [conv, create] = await Promise.all([
        http.get(`convert_to_sale_data/${id.value}`),
        http.get('sales/create'),
      ]);
      clients.value = conv.clients || create.clients || [];
      warehouses.value = conv.warehouses || create.warehouses || [];
      agents.value = conv.sales_agents || create.sales_agents || [];
      salespeople.value = create.salespeople || [];
      salesSwitchEnabled.value = create.enable_pos_salesperson_switch === true;
      showPriceTypeDropdown.value = create.sale_show_price_type_dropdown !== false;
      defaultQuantity.value = Math.max(1, Number(create.sale_default_quantity) || 1);
      loyaltyPointsEnabled.value = create.sale_loyalty_points_enabled !== false;
      shippingEnabled.value = create.sale_shipping_enabled !== false;
      salesAgentEnabled.value = create.sale_sales_agent_enabled !== false;
      loadServiceJobOptions(create);
      accounts.value = create.accounts || [];
      paymentMethods.value = create.payment_methods || [];
      point_to_amount_rate.value = Number(create.point_to_amount_rate) || 0;

      const q = conv.sale || {};
      sale.value = {
        // The quotation's own date and status carry over, as in legacy.
        date: q.date,
        client_id: q.client_id || undefined,
        warehouse_id: q.warehouse_id || undefined,
        sales_agent_id: q.sales_agent_id || undefined,
        seller_id: undefined,
        statut: q.statut || 'completed',
        notes: q.notes || '',
        tax_rate: Number(q.tax_rate) || 0,
        // Quotations don't carry a tax method yet -- percent, matching
        // every sale/quotation's tax_rate meaning before this toggle existed.
        tax_Method: '1',
        discount: Number(q.discount) || 0,
        discount_Method: '2',
        shipping: Number(q.shipping) || 0,
      };
      // The converted sale keeps the quotation's currency snapshot.
      setDocCurrency(q.currency_id, q.exchange_rate);
      defaultSellerToSelf();
      lines.value = (conv.details || []).map(d => {
        const line = {
          ...d,
          detail_id: nextDetailId++,
          discount_Method: String(d.discount_Method ?? d.discount_method ?? '2'),
          tax_method: String(d.tax_method ?? '1'),
          is_batch_tracked: !!d.is_batch_tracked,
          batches: [],
          serial_numbers: [],
          price_type: d.price_type || 'retail',
          retail_unit_price: d.Unit_price,
          wholesale_unit_price: d.Unit_price_wholesale,
          packs: d.packs || [],
          product_pack_id: d.product_pack_id ?? null,
          pack_multiplier: d.pack_multiplier ?? 1,
          pack_name: d.pack_name ?? null,
        };
        recomputeLine(line);
        return line;
      });
      await loadProducts();
      // Status defaults to pending (unpaid): no payment line until the user
      // switches to paid/partial, which prefills via onPaymentStatusChange.
    } else if (isEdit.value) {
      const data = await http.get(`sales/${id.value}/edit`);
      clients.value = data.clients || [];
      warehouses.value = data.warehouses || [];
      agents.value = data.sales_agents || [];
      salespeople.value = data.salespeople || [];
      salesSwitchEnabled.value = data.enable_pos_salesperson_switch === true;
      showPriceTypeDropdown.value = data.sale_show_price_type_dropdown !== false;
      defaultQuantity.value = Math.max(1, Number(data.sale_default_quantity) || 1);
      loyaltyPointsEnabled.value = data.sale_loyalty_points_enabled !== false;
      shippingEnabled.value = data.sale_shipping_enabled !== false;
      salesAgentEnabled.value = data.sale_sales_agent_enabled !== false;
      loadServiceJobOptions(data);
      const s = data.sale || {};
      isDraft.value = !!s.is_draft;
      const dm = String(s.discount_Method ?? '2').toLowerCase().trim();
      sale.value = {
        date: s.date,
        client_id: s.client_id,
        warehouse_id: s.warehouse_id,
        sales_agent_id: s.sales_agent_id || undefined,
        seller_id: s.seller_id || undefined,
        statut: s.statut,
        notes: s.notes || '',
        tax_rate: Number(s.tax_rate) || 0,
        tax_Method: String(s.tax_Method || '1') === '2' ? '2' : '1',
        discount: Number(s.discount) || 0,
        discount_Method: dm === '1' || dm === 'percent' || dm === 'percentage' ? '1' : '2',
        shipping: Number(s.shipping) || 0,
      };
      pointsState.value = {
        discount_from_points: Number(s.discount_from_points) || 0,
        used_points: Number(s.used_points) || 0,
      };
      // Reopen the sale in its stored currency at its stored rate.
      setDocCurrency(s.currency_id, s.exchange_rate);
      lines.value = (data.details || []).map(d => {
        const line = {
          ...d,
          detail_id: nextDetailId++,
          discount_Method: String(d.discount_Method ?? d.discount_method ?? '2'),
          tax_method: String(d.tax_method ?? '1'),
          is_batch_tracked: !!d.is_batch_tracked,
          batches: [],
          // Legacy edit prefills the picker from the stored IMEI text.
          serial_numbers: Array.isArray(d.serial_numbers)
            ? d.serial_numbers
            : (d.is_imei && d.imei_number
                ? String(d.imei_number).split(/[\r\n,;\t]+/).map(s => s.trim()).filter(Boolean)
                : []),
          price_type: d.price_type || 'retail',
          retail_unit_price: d.retail_unit_price ?? d.Unit_price,
          wholesale_unit_price: d.wholesale_unit_price ?? d.Unit_price_wholesale,
          packs: d.packs || [],
          product_pack_id: d.product_pack_id ?? null,
          pack_multiplier: d.pack_multiplier ?? 1,
          pack_name: d.pack_name ?? null,
        };
        recomputeLine(line);
        return line;
      });
      await loadProducts();
    } else if (isDuplicate.value) {
      // Same shape as isEdit's bootstrap (reuse the exact prior line detail --
      // price/discount/tax as originally recorded), but the date/status reset
      // to fresh-create defaults and every line drops its old sale_detail id
      // (and edit-only bookkeeping fields) so submit() inserts all-new rows.
      const [src, create] = await Promise.all([
        http.get(`sales/${duplicateFromId.value}/edit`),
        http.get('sales/create'),
      ]);
      clients.value = src.clients || create.clients || [];
      warehouses.value = src.warehouses || create.warehouses || [];
      agents.value = src.sales_agents || create.sales_agents || [];
      salespeople.value = create.salespeople || [];
      salesSwitchEnabled.value = create.enable_pos_salesperson_switch === true;
      showPriceTypeDropdown.value = create.sale_show_price_type_dropdown !== false;
      defaultQuantity.value = Math.max(1, Number(create.sale_default_quantity) || 1);
      loyaltyPointsEnabled.value = create.sale_loyalty_points_enabled !== false;
      shippingEnabled.value = create.sale_shipping_enabled !== false;
      salesAgentEnabled.value = create.sale_sales_agent_enabled !== false;
      loadServiceJobOptions(create);
      accounts.value = create.accounts || [];
      paymentMethods.value = create.payment_methods || [];
      point_to_amount_rate.value = Number(create.point_to_amount_rate) || 0;

      const s = src.sale || {};
      const dm = String(s.discount_Method ?? '2').toLowerCase().trim();
      sale.value = {
        date: dayjs().format('YYYY-MM-DD'),
        client_id: s.client_id,
        warehouse_id: s.warehouse_id,
        sales_agent_id: s.sales_agent_id || undefined,
        seller_id: undefined,
        statut: s.statut || 'completed',
        notes: s.notes || '',
        tax_rate: Number(s.tax_rate) || 0,
        tax_Method: String(s.tax_Method || '1') === '2' ? '2' : '1',
        discount: Number(s.discount) || 0,
        discount_Method: dm === '1' || dm === 'percent' || dm === 'percentage' ? '1' : '2',
        shipping: Number(s.shipping) || 0,
      };
      // The duplicate keeps the source invoice's currency snapshot.
      setDocCurrency(s.currency_id, s.exchange_rate);
      defaultSellerToSelf();
      lines.value = (src.details || []).map(d => {
        const line = {
          ...d,
          id: undefined,
          detail_id: nextDetailId++,
          discount_Method: String(d.discount_Method ?? d.discount_method ?? '2'),
          tax_method: String(d.tax_method ?? '1'),
          is_batch_tracked: !!d.is_batch_tracked,
          batches: [],
          serial_numbers: [],
          etat: 'new',
          qte_copy: 0,
          price_type: d.price_type || 'retail',
          retail_unit_price: d.retail_unit_price ?? d.Unit_price,
          wholesale_unit_price: d.wholesale_unit_price ?? d.Unit_price_wholesale,
          packs: d.packs || [],
          product_pack_id: d.product_pack_id ?? null,
          pack_multiplier: d.pack_multiplier ?? 1,
          pack_name: d.pack_name ?? null,
        };
        recomputeLine(line);
        return line;
      });
      await loadProducts();
    } else {
      const data = await http.get('sales/create');
      clients.value = data.clients || [];
      warehouses.value = data.warehouses || [];
      agents.value = data.sales_agents || [];
      salespeople.value = data.salespeople || [];
      salesSwitchEnabled.value = data.enable_pos_salesperson_switch === true;
      showPriceTypeDropdown.value = data.sale_show_price_type_dropdown !== false;
      defaultQuantity.value = Math.max(1, Number(data.sale_default_quantity) || 1);
      loyaltyPointsEnabled.value = data.sale_loyalty_points_enabled !== false;
      shippingEnabled.value = data.sale_shipping_enabled !== false;
      salesAgentEnabled.value = data.sale_sales_agent_enabled !== false;
      loadServiceJobOptions(data);
      defaultSellerToSelf();
      accounts.value = data.accounts || [];
      paymentMethods.value = data.payment_methods || [];
      point_to_amount_rate.value = Number(data.point_to_amount_rate) || 0;
      // Order Tax starts at the company's configured Default Tax (System
      // Settings -> Defaults) instead of always 0.
      if (auth.user?.default_tax) {
        sale.value.tax_rate = Number(auth.user.default_tax) || 0;
      }
      // Pre-select the company's configured default warehouse (System
      // Settings -> Defaults) instead of leaving the picker blank, same as
      // manually picking it -- only when the user actually has access to it.
      const defaultWarehouseId = auth.user?.default_warehouse_id;
      if (defaultWarehouseId && warehouses.value.some(w => w.id === defaultWarehouseId)) {
        sale.value.warehouse_id = defaultWarehouseId;
        await loadProducts();
      }
      // Status defaults to pending (unpaid): no payment line until the user
      // switches to paid/partial, which prefills via onPaymentStatusChange.
    }
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/sales');
    return;
  } finally {
    loadingRecord.value = false;
  }
});
</script>

<style scoped>
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
/* Holds the modal body's height steady while its data loads, so swapping the
   spinner for the form doesn't resize the dialog. */
.modal-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 220px;
}
.sum-row {
  display: flex;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
  font-size: 14px;
}
.sum-row.grand {
  border-bottom: none;
  border-top: 2px solid rgba(5, 5, 5, 0.15);
  font-weight: 700;
  font-size: 16px;
}
.form-warn {
  margin-top: 8px;
  color: #faad14;
  font-size: 13px;
  text-align: center;
}
/* Service job card: jobs already made with the sale (Edit) */
.sale-jobs {
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 8px;
  margin-bottom: 10px;
}
.sale-jobs__item {
  padding: 6px 10px 8px;
  border-top: 1px solid rgba(128, 128, 128, 0.12);
}
.sale-jobs__item:first-child {
  border-top: 0;
}
.sale-jobs__row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  padding: 0 0 6px;
  font-size: 13px;
}
.sale-jobs__notes {
  white-space: pre-line;
  font-size: 12px;
  margin-bottom: 6px;
}

/* Recurring: notes on each invoice */
.rec-notes {
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 8px;
  padding: 10px 12px 4px;
  margin-bottom: 16px;
}
.rec-notes__title { font-weight: 600; }
.rec-notes__help { font-size: 12px; opacity: 0.65; margin: 2px 0 10px; }
.rec-notes__preview { font-size: 12px; opacity: 0.7; margin-top: 2px; }
.rec-notes__empty { font-size: 12px; opacity: 0.6; margin-bottom: 10px; }
.pay-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  padding: 8px;
  margin-bottom: 8px;
  border: 1px solid rgba(5, 5, 5, 0.1);
  border-radius: 6px;
}
.pay-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 24px;
  margin-top: 12px;
  font-size: 13px;
}
</style>
