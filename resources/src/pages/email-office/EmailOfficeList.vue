<template>
  <div class="page">
    <PageHeader :title="incoming ? $t('Email_Received') : $t('Email_Sent')" :breadcrumb="[$t('Email_Office'), incoming ? $t('Email_Received') : $t('Email_Sent')]">
      <template #actions>
        <a-space>
          <a-button type="primary" @click="newEmail">
            <template #icon><EditOutlined /></template>
            {{ $t('tx_New_email_4f303') }}
          </a-button>
          <a-button v-if="auth.can('email_office_manage')" @click="openRules(null)">
            <template #icon><FilterOutlined /></template>
            {{ $t('Rules') }}
          </a-button>
          <a-button v-if="auth.can('email_office_manage')" @click="$router.push('/email-office/settings')">
            <template #icon><SettingOutlined /></template>
            {{ $t('Settings') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <!-- Received: the mailbox is checked from this page too while it is open
         (every N minutes, the company's setting) -- new emails show up without
         reloading, even where the server's scheduler isn't running. -->
    <div v-if="incoming && mailbox" class="eo-mailbox">
      <template v-if="mailbox.enabled">
        <SyncOutlined :spin="checking" />
        <span>
          {{ $t('tx_Mailbox_checked_automatically_every_1d8db') }} {{ mailbox.every }} min
          <template v-if="mailbox.last_polled_at"> {{ $t('tx_last_check_ac808') }} {{ dateTime(mailbox.last_polled_at) }}</template>
          <template v-if="mailbox.next_at && !checking"> {{ $t('tx_next_1d52a') }} {{ dayjs(mailbox.next_at).format('h:mm A') }}</template>
        </span>
        <a-tag v-if="mailbox.error" color="red" :title="mailbox.error">{{ $t('tx_Last_check_failed_c30b8') }}</a-tag>
      </template>
      <template v-else>
        <ExclamationCircleOutlined style="color: #d46b08" />
        <span>{{ $t('tx_Automatic_mailbox_check_is_929f2') }} <b>off</b> {{ $t('tx_for_this_company_new_emails_only_arrive_with_Check_now_8425c') }}</span>
        <a v-if="auth.can('email_office_manage')" @click="$router.push('/email-office/settings')">{{ $t('tx_Turn_it_on_in_Settings_729d4') }}</a>
      </template>
      <a-button v-if="auth.can('email_office_manage')" size="small" :loading="checking" @click="checkNow">{{ $t('tx_Check_now_2c872') }}</a-button>
    </div>

    <!-- Cards: click one to show only those emails. One row, sharing the width
         (scrolls sideways on a narrow screen instead of wrapping). -->
    <div class="eo-cards">
      <a-card
        v-for="c in cards" :key="c.key" size="small" hoverable
        :class="['eo-card', { 'eo-card--on': c.active }]" @click="c.click"
      >
        <div class="eo-card__label">{{ c.label }}</div>
        <div class="eo-card__value" :style="{ color: c.color }">{{ c.value }}</div>
        <div class="eo-card__hint">{{ c.hint }}</div>
      </a-card>
    </div>

    <a-card size="small" style="margin-bottom: 12px">
      <a-row :gutter="[12, 8]">
        <a-col :span="24" style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap">
          <PresetRangeFilter v-model:value="range" @change="crud.reload()" />
          <!-- Received: emails already recorded in Murano hidden by default (remembered per browser). -->
          <label v-if="incoming" class="eo-hide-done">
            <a-switch v-model:checked="hideRecorded" size="small" @change="onHideRecorded" />
            {{ $t('tx_Hide_recorded_0b19f') }}
          </label>
        </a-col>
        <!-- The filters: one row (From and Subject a bit wider). -->
        <a-col :span="24">
          <div class="eo-filters">
            <!-- From (received): a sender NAME (any of its addresses), optionally one
                 of its repeating subjects. To (sent): one address. -->
            <a-select
              v-model:value="filters.address" allow-clear show-search :filter-option="false" class="eo-filter eo-filter--wide"
              :placeholder="incoming ? $t('tx_From_sender_name_address_or_subject_47763') : $t('tx_To_recipient_4bb2c')" :options="addressOptions" :loading="addressLoading"
              :dropdown-match-select-width="incoming ? 420 : true"
              @search="searchAddresses" @focus="searchAddresses('')" @change="crud.reload()"
            />
            <!-- Subject: like From -- the subjects (an email + its Re: / Fwd: replies
                 count as one), most emails first, within the From / To picked. -->
            <a-select
              v-model:value="filters.subject" allow-clear show-search :filter-option="false" class="eo-filter eo-filter--wide"
              :placeholder="$t('Subject')" :options="subjectOptions" :loading="subjectLoading" :dropdown-match-select-width="420"
              @search="searchSubjects" @focus="searchSubjects('')" @change="crud.reload()"
            />
            <a-select v-model:value="filters.category" allow-clear :placeholder="$t('type')" class="eo-filter" :options="CATEGORY_OPTIONS" @change="crud.reload()" />
            <a-select v-model:value="filters.party_type" allow-clear :placeholder="$t('tx_From_to_d8608')" class="eo-filter" :options="PARTY_OPTIONS" @change="crud.reload()" />
            <a-select
              v-if="incoming" v-model:value="filters.linked" allow-clear :placeholder="$t('tx_Linked_0ef98')" class="eo-filter"
              :options="[{ value: 0, label: 'Not linked yet' }, { value: 1, label: 'Linked' }]" @change="crud.reload()"
            />
            <a-select v-else v-model:value="filters.source" allow-clear :placeholder="$t('tx_Sent_by_9a44e')" class="eo-filter" :options="SOURCE_OPTIONS" @change="crud.reload()" />
          </div>
        </a-col>
      </a-row>
    </a-card>

    <a-alert
      v-if="!crud.loading.value && stats.total === 0 && !anyFilter" type="info" show-icon style="margin-bottom: 12px"
      :message="incoming ? $t('tx_No_emails_yet_b148e') : $t('tx_No_sent_emails_yet_edabc')"
      :description="incoming ? $t('tx_Connect_the_company_mailbox_in_Email_Office_Settings_new_ema_7128f') : $t('tx_Every_email_Murano_sends_from_now_on_invoices_quotations_rem_310b3')"
    />

    <DataTable :crud="crud" :columns="columns" :table-key="`email-office|${direction}`" bulk-select>
      <!-- Selected rows: set their type / read state at once (no bulk delete). -->
      <template #bulk="{ ids }">
        <a-dropdown :trigger="['click']">
          <a-button size="small" :loading="bulkBusy">{{ $t('tx_Set_type_bc1be') }} <DownOutlined /></a-button>
          <template #overlay>
            <a-menu @click="({ key }) => bulkUpdate(ids, { category: key })">
              <a-menu-item v-for="o in CATEGORY_OPTIONS" :key="o.value">{{ o.label }}</a-menu-item>
            </a-menu>
          </template>
        </a-dropdown>
        <a-button size="small" :disabled="bulkBusy" @click="bulkUpdate(ids, { is_read: true })">{{ $t('tx_Mark_read_d4bb1') }}</a-button>
        <a-button size="small" :disabled="bulkBusy" @click="bulkUpdate(ids, { is_read: false })">{{ $t('tx_Mark_unread_f951e') }}</a-button>
      </template>
      <!-- Help on the header: what "Recorded in Murano" means. -->
      <template #headerCell="{ column, title }">
        <template v-if="column.key === 'linked'">
          {{ title }}
          <a-tooltip>
            <template #title>
              {{ $t('tx_Is_this_email_already_entered_in_Murano_fed7c') }}<br>
              <b>{{ $t('tx_Purchase_Expense_number_792e1') }}</b> {{ $t('tx_yes_the_bill_was_recorded_Create_expense_or_Link_9dfea') }}<br>
              <b>{{ $t('tx_Ticket_number_d1ccf') }}</b> {{ $t('tx_the_claim_became_a_helpdesk_ticket_22bc5') }}<br>
              <b>{{ $t('tx_On_customer_f52cb') }}</b> {{ $t('tx_a_customer_s_email_it_s_kept_on_that_customer_Email_conversa_47e6f') }}<br>
              <b>{{ $t('tx_To_record_cab22') }}</b> {{ $t('tx_a_bill_not_entered_yet_6db7a') }}<br>
              {{ $t('tx_Click_any_of_them_or_a48df') }} <b>{{ $t('tx_Add_111e7') }}</b>{{ $t('tx_to_open_the_window_that_adds_the_email_to_Murano_182d8') }}<br>
              <b>—</b> {{ $t('tx_nothing_to_record_039ed') }}
            </template>
            <QuestionCircleOutlined style="margin-inline-start: 4px; opacity: 0.6; cursor: help" />
          </a-tooltip>
        </template>
        <template v-else>{{ title }}</template>
      </template>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'actions'">
          <div class="eo-actions">
            <!-- Download the attachments (one: straight away; several: pick). -->
            <a-dropdown v-if="record.attachments_count > 1" :trigger="['click']">
              <a-tooltip :title="$t('tx_Download_attachments_20261')"><a-button size="small" type="text"><template #icon><DownloadOutlined /></template></a-button></a-tooltip>
              <template #overlay>
                <a-menu>
                  <a-menu-item v-for="a in record.attachment_names" :key="a.index" :disabled="!a.downloadable" @click="downloadAttachment(record, a)">{{ a.name }}</a-menu-item>
                </a-menu>
              </template>
            </a-dropdown>
            <a-tooltip v-else :title="record.attachments_count ? `Download ${record.attachment_names?.[0]?.name}` : $t('tx_No_attachments_c4f38')">
              <a-button size="small" type="text" :disabled="!record.attachments_count" @click="downloadAttachment(record, record.attachment_names?.[0])">
                <template #icon><DownloadOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('tx_Save_the_email_as_PDF_b7b48')">
              <a-button size="small" type="text" @click="emailPdf(record)"><template #icon><FilePdfOutlined /></template></a-button>
            </a-tooltip>
            <a-tooltip :title="incoming ? $t('tx_Reply_25d8d') : $t('tx_Write_again_ab53d')">
              <a-button size="small" type="text" @click="reply(record)"><template #icon><RollbackOutlined /></template></a-button>
            </a-tooltip>
            <!-- A sorting rule from this email's sender. -->
            <a-tooltip v-if="incoming && auth.can('email_office_manage')" :title="$t('tx_Create_a_rule_from_this_sender_e_g_always_move_to_Social_8a7dd')">
              <a-button size="small" type="text" @click="ruleFromEmail(record)"><template #icon><ThunderboltOutlined /></template></a-button>
            </a-tooltip>
            <!-- Archive (put away) / back out of the Archive or Spam folder. -->
            <a-tooltip :title="['archive', 'spam'].includes(record.category) ? $t('tx_Move_back_to_the_list_General_89978') : $t('tx_Archive_e727b')">
              <a-button size="small" type="text" @click="changeType(record, ['archive', 'spam'].includes(record.category) ? 'general' : 'archive', true)">
                <template #icon><InboxOutlined v-if="!['archive', 'spam'].includes(record.category)" /><UndoOutlined v-else /></template>
              </a-button>
            </a-tooltip>
          </div>
        </template>
        <template v-else-if="column.key === 'sent_at'">
          <span :class="{ 'eo-unread': !record.is_read }">{{ dateTime(record.sent_at) }}</span>
        </template>
        <template v-else-if="column.key === 'who'">
          <!-- Click: the email and its history (read-only). The filter icon lists every email with them. -->
          <a-tooltip :title="$t('tx_Open_the_email_and_its_history_31b56')">
            <a :class="{ 'eo-unread': !record.is_read }" @click="openEmail(record)">{{ incoming ? (record.from_name || record.from_email) : record.to_emails }}</a>
          </a-tooltip>
          <a-tooltip :title="incoming ? `Every email from ${senderOf(record)}` : `Only emails with ${addressOf(record)}`">
            <FilterOutlined class="eo-addr-filter" @click.stop="filterAddress(rowFilterValue(record))" />
          </a-tooltip>
          <div v-if="incoming && record.from_name" class="eo-muted">{{ record.from_email }}</div>
        </template>
        <template v-else-if="column.key === 'subject'">
          <a :class="{ 'eo-unread': !record.is_read }" @click="openEmail(record)">{{ record.subject }}</a>
          <PaperClipOutlined v-if="record.attachments_count" style="margin-inline-start: 6px; opacity: 0.6" />
          <!-- Same as the From column's icon: only emails with this subject (and its Re: / Fwd: replies). -->
          <a-tooltip :title="`Only emails with this subject (and its replies)`">
            <FilterOutlined class="eo-addr-filter" @click.stop="filterSubject(record.subject)" />
          </a-tooltip>
          <div class="eo-muted eo-snippet">{{ record.snippet }}</div>
        </template>
        <template v-else-if="column.key === 'category'">
          <!-- Click the type to change it right here; double-click = General
               (the quick fix for an email sorted wrongly). -->
          <a-dropdown v-model:open="typeMenu[record.id]" :trigger="['click']">
            <a-tag
              :color="CATEGORY_COLOR[record.category]" class="eo-type"
              :title="record.category !== 'general' ? $t('tx_Click_to_change_double_click_General_02be5') : $t('tx_Click_to_change_5eed7')"
              @click.stop @dblclick.stop="typeMenu[record.id] = false; changeType(record, 'general')"
            >
              {{ CATEGORY_LABEL[record.category] }} <DownOutlined style="font-size: 9px" />
            </a-tag>
            <template #overlay>
              <a-menu :selected-keys="[record.category]" @click="({ key }) => changeType(record, key)">
                <a-menu-item v-for="o in CATEGORY_OPTIONS" :key="o.value">{{ o.label }}</a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
        </template>
        <template v-else-if="column.key === 'party'">
          <template v-if="record.party_name">
            <div>{{ record.party_name }}</div>
            <div class="eo-muted">{{ PARTY_LABEL[record.party_type] }}</div>
          </template>
          <span v-else class="eo-muted">—</span>
        </template>
        <template v-else-if="column.key === 'linked'">
          <!-- A bill's reminder / payment confirmation: in that expense's history. -->
          <a-tooltip
            v-if="record.linked_type"
            :title="['reminder', 'payment'].includes(record.link_role)
              ? `In the history of ${LINKED_LABEL[record.linked_type].toLowerCase()} ${record.linked_label} (its ${record.link_role === 'payment' ? 'payment confirmation' : 'payment reminder'})`
              : `This email is already entered in Murano as ${LINKED_LABEL[record.linked_type].toLowerCase()} ${record.linked_label}`"
          >
            <a-tag :color="['reminder', 'payment'].includes(record.link_role) ? 'geekblue' : 'purple'" class="eo-rec" @click.stop="openRecordWindow(record)">
              {{ LINKED_LABEL[record.linked_type] }} {{ record.linked_label }}<template v-if="['reminder', 'payment'].includes(record.link_role)"> · {{ record.link_role }}</template>
            </a-tag>
          </a-tooltip>
          <!-- A Zelle deposit a rule prepared: a paid sale waiting for Confirm. -->
          <a-tooltip v-else-if="record.sale_draft" :title="`Zelle deposit from ${record.sale_draft.payer} — click to review and create the paid sale`">
            <a-tag color="gold" class="eo-rec" @click.stop="openSaleDraft(record)">{{ $t('tx_Confirm_sale_54ff1') }}{{ Number(record.sale_draft.amount).toFixed(2) }}</a-tag>
          </a-tooltip>
          <!-- A Zelle payment sent a rule prepared: an expense waiting for Confirm. -->
          <a-tooltip v-else-if="record.payment_draft" :title="`Zelle payment to ${record.payment_draft.payee} — click to review and record the expense`">
            <a-tag color="volcano" class="eo-rec" @click.stop="openPaymentDraft(record)">{{ $t('tx_Confirm_expense_c596e') }}{{ Number(record.payment_draft.amount).toFixed(2) }}</a-tag>
          </a-tooltip>
          <!-- A customer's email is recorded on the customer (their Email conversation tab). -->
          <a-tooltip v-else-if="record.party_type === 'client' && record.party_name" :title="`Recorded on the customer ${record.party_name} — click to see their Email conversation tab`">
            <a-tag color="green" class="eo-rec" @click.stop="openRecordWindow(record)">{{ $t('tx_On_customer_f52cb') }}</a-tag>
          </a-tooltip>
          <!-- This column opens the window to add the email to Murano (contact, expense,
               purchase, quotation, work order, type). -->
          <a-tooltip v-else-if="record.category === 'bill' && incoming" :title="$t('tx_A_bill_not_entered_yet_click_to_record_it_expense_purchase_0b06b')">
            <a-tag color="orange" class="eo-rec" @click.stop="openRecordWindow(record)">{{ $t('tx_To_record_cab22') }}</a-tag>
          </a-tooltip>
          <a-tooltip v-else :title="$t('tx_Add_this_email_to_Murano_customer_supplier_expense_purchase__68a37')">
            <a class="eo-add" @click.stop="openRecordWindow(record)">{{ $t('tx_Add_111e7') }}</a>
          </a-tooltip>
        </template>
        <template v-else-if="column.key === 'source'">{{ SOURCE_LABEL[record.source] || record.source }}</template>
      </template>
    </DataTable>

    <!-- From / Subject: the email + history, read-only. Recorded in Murano: the full window to add it. -->
    <EmailViewerDrawer
      v-model:open="viewerOpen" :email-id="viewerId" :read-only="viewerReadOnly" :thread="viewerThread"
      @changed="crud.fetchRows()" @filter-address="filterAddress" @create-rule="openRules"
    />
    <!-- The company's own sorting rules (e.g. TikTok / Facebook -> Social). -->
    <EmailRulesDrawer v-model:open="rulesOpen" :prefill="rulesPrefill" @changed="crud.fetchRows()" />

    <!-- Zelle deposit -> paid sale (prepared by a rule), confirmed here. -->
    <a-modal v-model:open="saleDraftOpen" :title="$t('tx_Confirm_sale_Zelle_deposit_0e36b')" :confirm-loading="saleDraftSaving" :ok-text="$t('tx_Create_paid_sale_fb53f')" :ok-button-props="{ disabled: !saleDraft || !saleDraft.ready }" @ok="confirmSaleDraft">
      <a-spin :spinning="saleDraftLoading">
        <template v-if="saleDraft">
          <a-alert v-if="saleDraft.existing_sale" type="info" show-icon style="margin-bottom: 12px"
            :message="`Already in Murano as sale ${saleDraft.existing_sale.Ref} — it will be linked, not created again.`" />
          <a-alert v-else-if="!saleDraft.ready" type="warning" show-icon style="margin-bottom: 12px"
            :message="$t('tx_The_rule_has_no_service_edit_the_rule_Rules_Prepare_a_paid_s_0b491')" />
          <a-descriptions :column="1" size="small" bordered>
            <a-descriptions-item :label="$t('Customer')">
              {{ saleDraft.client_name }} <a-tag v-if="saleDraft.client_new" color="green" style="margin-left: 6px">{{ $t('New_Customer') }}</a-tag>
            </a-descriptions-item>
            <a-descriptions-item :label="$t('Amount')">${{ Number(saleDraft.amount).toFixed(2) }}</a-descriptions-item>
            <a-descriptions-item :label="$t('tx_Date_time_34bb8')">{{ saleDraft.date }} {{ saleDraft.time }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Service')">{{ saleDraft.product || '—' }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Sales_Agent')">{{ saleDraft.sales_agent || '—' }}</a-descriptions-item>
            <a-descriptions-item :label="$t('tx_Paid_with_33ad1')">Zelle → {{ saleDraft.account || `account ending ${saleDraft.last4 || '?'} (not found — no account)` }}</a-descriptions-item>
            <a-descriptions-item :label="$t('tx_Confirmation_13a92')">{{ saleDraft.confirmation }}</a-descriptions-item>
          </a-descriptions>
        </template>
      </a-spin>
    </a-modal>

    <!-- Zelle payment sent -> expense (prepared by a rule), confirmed here. -->
    <a-modal v-model:open="payDraftOpen" :title="$t('tx_Confirm_expense_Zelle_payment_sent_657d1')" :confirm-loading="payDraftSaving" :ok-text="$t('tx_Record_expense_9e24e')" :ok-button-props="{ disabled: !payDraft || !payForm.expense_category_id }" @ok="confirmPaymentDraft">
      <a-spin :spinning="payDraftLoading">
        <template v-if="payDraft">
          <a-alert v-if="payDraft.existing_expense" type="info" show-icon style="margin-bottom: 12px"
            :message="`Already in Murano as expense ${payDraft.existing_expense.Ref} — it will be linked, not recorded again.`" />
          <a-descriptions :column="1" size="small" bordered style="margin-bottom: 12px">
            <a-descriptions-item :label="$t('tx_Paid_to_9823e')">{{ payDraft.payee }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Amount')">${{ Number(payDraft.amount).toFixed(2) }}</a-descriptions-item>
            <a-descriptions-item :label="$t('date')">{{ payDraft.date }} {{ payDraft.time }}</a-descriptions-item>
            <a-descriptions-item :label="$t('tx_Paid_with_33ad1')">Zelle ← {{ payDraft.account || `account ending ${payDraft.last4 || '?'} (not found — no account)` }}</a-descriptions-item>
            <a-descriptions-item :label="$t('tx_Confirmation_13a92')">{{ payDraft.confirmation || '—' }}</a-descriptions-item>
          </a-descriptions>
          <div class="eo-muted" style="margin-bottom: 8px">
            <template v-if="payDraft.mapped">{{ $t('tx_Saved_for_c0ed1') }}{{ payDraft.payee }}{{ $t('tx_change_it_here_if_needed_it_s_remembered_for_the_next_ones_a5e6c') }}</template>
            <template v-else>{{ $t('tx_First_payment_to_1f371') }}{{ payDraft.payee }}{{ $t('tx_who_is_it_Murano_remembers_it_for_the_next_ones_9e61e') }}</template>
          </div>
          <a-form layout="vertical">
            <a-form-item :label="$t('tx_Employee_1099_contractor_6f9bb')" :extra="$t('tx_Leave_empty_when_it_isn_t_a_team_member_a_supplier_a_service_09169')">
              <a-select v-model:value="payForm.employee_id" allow-clear show-search option-filter-prop="label" :placeholder="$t('tx_Not_an_employee_f7edf')" :options="payEmployeeOptions" />
            </a-form-item>
            <a-form-item :label="$t('Expense_Category')" required>
              <a-select v-model:value="payForm.expense_category_id" show-search option-filter-prop="label" :placeholder="$t('tx_Pick_a_category_2adff')" :options="payCategoryOptions" />
            </a-form-item>
          </a-form>
        </template>
      </a-spin>
    </a-modal>

    <!-- New email -->
    <a-modal v-model:open="composeOpen" :title="compose.subject && compose.replying ? $t('tx_Reply_25d8d') : $t('tx_New_email_4f303')" :confirm-loading="sending" :ok-text="$t('tx_Send_94966')" width="680px" @ok="send">
      <a-form layout="vertical">
        <a-form-item :label="$t('tx_Customer_optional_8c857')" :extra="$t('tx_Pick_a_customer_to_fill_in_their_email_2c268')">
          <a-select
            v-model:value="compose.client_id" show-search allow-clear :filter-option="false" :placeholder="$t('tx_Search_a_customer_f179c')"
            :options="clientOptions" @search="searchClients" @focus="searchClients('')" @change="pickClient"
          />
        </a-form-item>
        <a-form-item :label="$t('to')" required>
          <a-select v-model:value="compose.to" mode="tags" :token-separators="[',', ';', ' ']" placeholder="name@example.com" />
        </a-form-item>
        <a-form-item label="Cc">
          <a-select v-model:value="compose.cc" mode="tags" :token-separators="[',', ';', ' ']" />
        </a-form-item>
        <a-form-item :label="$t('Subject')" required><a-input v-model:value="compose.subject" /></a-form-item>
        <a-form-item :label="$t('Message')" required><a-textarea v-model:value="compose.body" :rows="8" /></a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
import { t as tEs } from '../../i18n/index.js';
/**
 * Email Office -> Email received (direction in) / Email sent (out):
 * GET email-office/emails -> {emails, totalRows, stats}. Cards filter by
 * type (bills to record, claims, reminders...); the drawer shows one email
 * and its actions (EmailViewerDrawer). Sent: "New email" (POST
 * email-office/compose).
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import {
  EditOutlined, SettingOutlined, PaperClipOutlined, QuestionCircleOutlined, FilterOutlined, DownOutlined,
  SyncOutlined, ExclamationCircleOutlined, DownloadOutlined, FilePdfOutlined, RollbackOutlined, InboxOutlined, UndoOutlined, ThunderboltOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import EmailViewerDrawer from '../../components/EmailViewerDrawer.vue';
import EmailRulesDrawer from '../../components/EmailRulesDrawer.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { sortByLabel } from '../../lib/filterOptions';
import { EMAIL_CATEGORY_LABEL, EMAIL_CATEGORY_COLOR, EMAIL_CATEGORY_OPTIONS, EMAIL_FOLDERS, loadEmailFolders } from '../../lib/emailCategories';
import http from '../../lib/http';

const props = defineProps({ direction: { type: String, default: 'in' } });
const incoming = computed(() => props.direction === 'in');
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const { dateTime } = useFormat();

const CATEGORY_LABEL = EMAIL_CATEGORY_LABEL;
const CATEGORY_COLOR = EMAIL_CATEGORY_COLOR;
const CATEGORY_OPTIONS = EMAIL_CATEGORY_OPTIONS;
loadEmailFolders(true); // the company's own folders (types + cards)
// Type column: change an email's type in place (kept as a manual choice).
const typeMenu = ref({}); // row id -> its type menu is open
async function changeType(record, category) {
  if (record.category === category) return;
  const old = record.category;
  record.category = category;
  try {
    await http.put(`email-office/emails/${record.id}`, { category });
    message.success(`Type changed to ${CATEGORY_LABEL[category]}.`);
    crud.fetchRows(); // card counts
  } catch (e) {
    record.category = old;
    message.error(e?.data?.message || 'Could not change the type.');
  }
}
const PARTY_LABEL = { client: 'Customer', provider: 'Supplier', employee: 'Employee' };
const PARTY_OPTIONS = sortByLabel([...Object.entries(PARTY_LABEL).map(([value, label]) => ({ value, label })), { value: 'none', label: tEs('tx_Unknown_sender_3dd1e', 'Unknown sender') }]);
const LINKED_LABEL = { sale: 'Sale', purchase: 'Purchase', expense: 'Expense', helpdesk_ticket: 'Ticket', quotation: 'Quotation', service_job: 'Work order' };
const SOURCE_LABEL = { system: 'Murano', compose: 'Email Office', mailbox: 'Mailbox' };
const SOURCE_OPTIONS = sortByLabel([
  { value: 'system', label: tEs('tx_Murano_invoices_reminders_9ab71', 'Murano (invoices, reminders…)') }, { value: 'compose', label: tEs('tx_Email_Office_New_email_7c516', 'Email Office (New email)') }, { value: 'mailbox', label: tEs('tx_Mailbox_webmail_phone_a2247', 'Mailbox (webmail / phone)') },
]);

const filters = ref({ from: '', to: '', category: undefined, party_type: undefined, linked: undefined, source: undefined, unread: undefined, address: route.query.address ? String(route.query.address) : undefined, subject: undefined });
const range = computed({
  get: () => (filters.value.from ? [filters.value.from, filters.value.to] : null),
  set: r => { filters.value.from = r ? r[0] : ''; filters.value.to = r ? r[1] : ''; },
});
const HIDE_KEY = 'email-office:hide-recorded';
const hideRecorded = ref((() => { try { return localStorage.getItem(HIDE_KEY) !== '0'; } catch { return true; } })());
function onHideRecorded(v) {
  try { localStorage.setItem(HIDE_KEY, v ? '1' : '0'); } catch { /* private window */ }
  crud.reload();
}
const anyFilter = computed(() => Object.values(filters.value).some(v => v !== '' && v !== undefined && v !== null));

const crud = useCrudTable('email-office/emails', {
  rowsKey: 'emails',
  sortField: 'sent_at',
  sortType: 'desc',
  params: () => ({
    direction: props.direction,
    from: filters.value.from || '', to: filters.value.to || '',
    category: filters.value.category || '', party_type: filters.value.party_type || '',
    linked: filters.value.linked ?? '', source: filters.value.source || '', unread: filters.value.unread ? 1 : '',
    address: filters.value.address || '',
    subject: filters.value.subject || '',
    hide_recorded: incoming.value && hideRecorded.value ? 1 : '',
  }),
});
crud.fetchRows();
watch(() => props.direction, () => {
  filters.value = { from: '', to: '', category: undefined, party_type: undefined, linked: undefined, source: undefined, unread: undefined, address: undefined, subject: undefined };
  crud.reload();
});

const stats = computed(() => crud.payload.value?.stats || { total: 0 });
function setFilter(patch) {
  const same = Object.entries(patch).every(([k, v]) => filters.value[k] === v);
  Object.keys(patch).forEach(k => { filters.value[k] = same ? undefined : patch[k]; });
  if (!same) Object.assign(filters.value, patch);
  crud.reload();
}
// Defaults -> Email Office: the cards this company switched off ("in:spam", "out:reminder").
const cards = computed(() => {
  const hidden = crud.payload.value?.hidden_cards || [];
  const dir = incoming.value ? 'in' : 'out';
  return allCards.value.filter(c => !hidden.includes(`${dir}:${c.key}`));
});
const allCards = computed(() => {
  const s = stats.value;
  const f = filters.value;
  const all = { key: 'all', label: incoming.value ? tEs('Received', 'Received') : tEs('tx_sent_mail', 'Sent'), value: s.total ?? 0, hint: tEs('tx_all_emails_5d490', 'all emails'), color: '#1f1f2c', active: !f.category && f.linked === undefined && !f.unread, click: () => { f.category = undefined; f.linked = undefined; f.unread = undefined; crud.reload(); } };
  if (incoming.value) {
    return [
      all,
      { key: 'unlinked', label: tEs('tx_Bills_to_record_97ac4', 'Bills to record'), value: s.unlinked_bills ?? 0, hint: tEs('tx_not_linked_to_a_purchase_expense_badf9', 'not linked to a purchase / expense'), color: '#d46b08', active: f.category === 'bill' && f.linked === 0, click: () => setFilter({ category: 'bill', linked: 0 }) },
      { key: 'bill', label: tEs('tx_Bills_f1324', 'Bills'), value: s.bill ?? 0, hint: tEs('tx_invoices_receipts_4fd9e', 'invoices, receipts'), color: '#fa8c16', active: f.category === 'bill' && f.linked === undefined, click: () => setFilter({ category: 'bill', linked: undefined }) },
      { key: 'claim', label: tEs('tx_Claims_64c4a', 'Claims'), value: s.claim ?? 0, hint: tEs('tx_complaints_refunds_158e6', 'complaints, refunds'), color: '#cf1322', active: f.category === 'claim', click: () => setFilter({ category: 'claim', linked: undefined }) },
      { key: 'supplier', label: tEs('Supplier', 'Supplier'), value: s.supplier ?? 0, hint: tEs('tx_orders_quotes_deliveries_30ddb', 'orders, quotes, deliveries'), color: '#08979c', active: f.category === 'supplier', click: () => setFilter({ category: 'supplier', linked: undefined }) },
      { key: 'general', label: 'General', value: s.general ?? 0, hint: tEs('tx_everything_else_72de9', 'everything else'), color: '#595959', active: f.category === 'general', click: () => setFilter({ category: 'general', linked: undefined }) },
      { key: 'unread', label: tEs('Unread', 'Unread'), value: s.unread ?? 0, hint: tEs('tx_not_opened_here_yet_d4580', 'not opened here yet'), color: '#1677ff', active: f.unread === 1, click: () => setFilter({ unread: 1 }) },
      // Folders: out of the normal list, opened from here.
      { key: 'social', label: 'Social', value: s.social ?? 0, hint: tEs('tx_social_networks_77dc7', 'social networks'), color: '#c41d7f', active: f.category === 'social', click: () => setFilter({ category: 'social', linked: undefined }) },
      // The company's own folders (from the rules' "+ New folder").
      ...EMAIL_FOLDERS.map(fd => ({
        key: fd.key, label: fd.name, value: s.by_category?.[fd.key] ?? 0, hint: tEs('tx_your_folder_ae938', 'your folder'), color: '#722ed1',
        active: f.category === fd.key, click: () => setFilter({ category: fd.key, linked: undefined }),
      })),
      { key: 'archive', label: tEs('tx_Archive_e727b', 'Archive'), value: s.archive ?? 0, hint: tEs('tx_put_away_f6aeb', 'put away'), color: '#8c8c8c', active: f.category === 'archive', click: () => setFilter({ category: 'archive', linked: undefined }) },
      { key: 'spam', label: 'Spam', value: s.spam ?? 0, hint: tEs('tx_junk_unwanted_e0714', 'junk / unwanted'), color: '#d4380d', active: f.category === 'spam', click: () => setFilter({ category: 'spam', linked: undefined }) },
    ];
  }
  return [
    all,
    { key: 'reminder', label: tEs('tx_Reminders_sent_a5ce3', 'Reminders sent'), value: s.reminder ?? 0, hint: tEs('tx_payment_appointment_reminders_25598', 'payment / appointment reminders'), color: '#1677ff', active: f.category === 'reminder', click: () => setFilter({ category: 'reminder' }) },
    { key: 'bill', label: tEs('tx_Bills_f1324', 'Bills'), value: s.bill ?? 0, hint: tEs('tx_about_invoices_bills_7ec3f', 'about invoices / bills'), color: '#fa8c16', active: f.category === 'bill', click: () => setFilter({ category: 'bill' }) },
    { key: 'claim', label: tEs('tx_Claims_64c4a', 'Claims'), value: s.claim ?? 0, hint: tEs('tx_helpdesk_support_38e8b', 'helpdesk / support'), color: '#cf1322', active: f.category === 'claim', click: () => setFilter({ category: 'claim' }) },
    { key: 'general', label: 'General', value: s.general ?? 0, hint: tEs('tx_everything_else_72de9', 'everything else'), color: '#595959', active: f.category === 'general', click: () => setFilter({ category: 'general' }) },
    { key: 'archive', label: tEs('tx_Archive_e727b', 'Archive'), value: s.archive ?? 0, hint: tEs('tx_put_away_f6aeb', 'put away'), color: '#8c8c8c', active: f.category === 'archive', click: () => setFilter({ category: 'archive' }) },
  ];
});

const columns = computed(() => [
  // Quick actions on the left: attachments, the email as PDF, reply.
  { title: '', key: 'actions', width: 160, align: 'center' },
  { title: tEs('date', 'Date'), dataIndex: 'sent_at', key: 'sent_at', sorter: true, width: 150 },
  { title: incoming.value ? tEs('tx_mail_from', 'From') : tEs('tx_mail_to', 'To'), key: 'who', width: 220 },
  // A default width (changeable in Customize columns): the preview wraps inside it.
  { title: tEs('Subject', 'Subject'), dataIndex: 'subject', key: 'subject', sorter: true, width: 420 },
  { title: tEs('type', 'Type'), dataIndex: 'category', key: 'category', sorter: true, width: 100 },
  { title: tEs('tx_Customer_supplier_0221f', 'Customer / supplier'), key: 'party', width: 170 },
  ...(incoming.value ? [{ title: tEs('tx_Recorded_in_Murano_52c13', 'Recorded in Murano'), key: 'linked', width: 170 }] : [{ title: tEs('tx_Sent_by_9a44e', 'Sent by'), key: 'source', width: 120 }]),
]);

// ---- From / To address filter ----
const addressOptions = ref([]);
const addressLoading = ref(false);
let addressTimer = null;
function searchAddresses(q) {
  clearTimeout(addressTimer);
  addressTimer = setTimeout(async () => {
    addressLoading.value = true;
    try {
      const data = await http.get('email-office/addresses', { direction: props.direction, search: q || '' });
      if (incoming.value) {
        // Received: one group per sender NAME (whatever address each email came
        // from), with "all its emails" + its repeating subjects (e.g. the monthly bill).
        addressOptions.value = (data.senders || []).map(s => ({
          label: s.sender,
          options: [
            { value: senderValue(s.sender), label: `All emails from ${s.sender} (${s.count})${s.addresses > 1 ? ` · ${s.addresses} addresses` : ''}` },
            ...s.subjects.map(x => ({ value: senderValue(s.sender, x.subject), label: `↳ ${x.subject} (${x.count})` })),
          ],
        }));
      } else {
        addressOptions.value = (data.addresses || []).map(a => ({ value: a.address, label: a.label }));
      }
      const all = addressOptions.value.flatMap(o => o.options || [o]);
      if (filters.value.address && !all.some(o => o.value === filters.value.address)) {
        addressOptions.value.unshift({ value: filters.value.address, label: filterLabel(filters.value.address) });
      }
    } finally {
      addressLoading.value = false;
    }
  }, 250);
}
// Filter value for a sender name (+ optional subject) -- the server matches
// the name without quotes, else the address (EmailOfficeController::SENDER_SQL).
const senderValue = (sender, subject) => JSON.stringify(subject ? { sender, subject } : { sender });
const senderOf = r => String(r.from_name || '').trim().replace(/^"+|"+$/g, '').trim() || r.from_email;
function filterLabel(v) {
  if (!String(v).startsWith('{')) return v;
  try {
    const o = JSON.parse(v);
    return o.subject ? `${o.sender} — ${o.subject}` : `All emails from ${o.sender}`;
  } catch {
    return v;
  }
}
// The other side of a row: its sender (received) or first recipient (sent).
const addressOf = r => (incoming.value ? r.from_email : String(r.to_emails || '').split(',')[0].trim());
// Row filter icon: received = every email under that sender name; sent = that address.
const rowFilterValue = r => (incoming.value ? senderValue(senderOf(r)) : addressOf(r));
function filterAddress(addr) {
  if (!addr) return;
  filters.value.address = addr;
  const all = addressOptions.value.flatMap(o => o.options || [o]);
  if (!all.some(o => o.value === addr)) addressOptions.value = [{ value: addr, label: filterLabel(addr) }, ...addressOptions.value];
  crud.reload();
}
if (filters.value.address) addressOptions.value = [{ value: filters.value.address, label: filterLabel(filters.value.address) }];

// ---- viewer ----
const viewerOpen = ref(false);
const viewerId = ref(null);
const viewerReadOnly = ref(true);
const viewerThread = ref([]);
// From / Subject: read the email and its history (same subject + same contact).
async function openEmail(r) {
  viewerReadOnly.value = true;
  viewerThread.value = [];
  viewerId.value = r.id;
  viewerOpen.value = true;
  r.is_read = true;
  try {
    const rows = (await http.get(`email-office/emails/${r.id}/conversation`)).emails || [];
    if (viewerId.value === r.id) viewerThread.value = rows.length > 1 ? rows : [];
  } catch (e) { /* the email alone */ }
}
// Recorded in Murano column: the full window to add the email to Murano.
function openRecordWindow(r) {
  viewerReadOnly.value = false;
  viewerThread.value = [];
  viewerId.value = r.id;
  viewerOpen.value = true;
  r.is_read = true;
}
// Recorded in Murano column, "Confirm sale": a Zelle deposit a rule prepared.
const saleDraftOpen = ref(false);
const saleDraftLoading = ref(false);
const saleDraftSaving = ref(false);
const saleDraft = ref(null);
const saleDraftId = ref(null);
async function openSaleDraft(r) {
  saleDraftId.value = r.id;
  saleDraft.value = null;
  saleDraftOpen.value = true;
  saleDraftLoading.value = true;
  try {
    saleDraft.value = (await http.get(`email-office/emails/${r.id}/sale-draft`)).draft;
  } catch (e) {
    message.error(e?.data?.message || 'Could not read the deposit.');
    saleDraftOpen.value = false;
  } finally {
    saleDraftLoading.value = false;
  }
}
async function confirmSaleDraft() {
  saleDraftSaving.value = true;
  try {
    const res = await http.post(`email-office/emails/${saleDraftId.value}/sale-draft`, {});
    message.success(res.created
      ? `Sale ${res.Ref} created and paid${res.client_created ? ' (new customer)' : ''}.`
      : `Linked to the sale already there: ${res.Ref}.`);
    saleDraftOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || 'Could not create the sale.');
  } finally {
    saleDraftSaving.value = false;
  }
}
// Recorded in Murano column, "Confirm expense": a Zelle payment sent a rule prepared.
const payDraftOpen = ref(false);
const payDraftLoading = ref(false);
const payDraftSaving = ref(false);
const payDraft = ref(null);
const payDraftId = ref(null);
const payForm = ref({ employee_id: undefined, expense_category_id: undefined });
const payEmployees = ref([]);
const payCategories = ref([]);
const payEmployeeOptions = computed(() => sortByLabel(payEmployees.value.map(e => ({ value: e.id, label: e.name }))));
const payCategoryOptions = computed(() => sortByLabel(payCategories.value.map(c => ({ value: c.id, label: c.name }))));
async function openPaymentDraft(r) {
  payDraftId.value = r.id;
  payDraft.value = null;
  payDraftOpen.value = true;
  payDraftLoading.value = true;
  try {
    const res = await http.get(`email-office/emails/${r.id}/payment-draft`);
    payDraft.value = res.draft;
    payEmployees.value = res.employees || [];
    payCategories.value = res.categories || [];
    payForm.value = { employee_id: res.draft.employee_id || undefined, expense_category_id: res.draft.expense_category_id || undefined };
  } catch (e) {
    message.error(e?.data?.message || 'Could not read the payment.');
    payDraftOpen.value = false;
  } finally {
    payDraftLoading.value = false;
  }
}
async function confirmPaymentDraft() {
  payDraftSaving.value = true;
  try {
    const res = await http.post(`email-office/emails/${payDraftId.value}/payment-draft`, {
      employee_id: payForm.value.employee_id || null, expense_category_id: payForm.value.expense_category_id,
    });
    message.success(res.created ? `Expense ${res.Ref} recorded.` : `Linked to the expense already there: ${res.Ref}.`);
    payDraftOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || 'Could not record the expense.');
  } finally {
    payDraftSaving.value = false;
  }
}
// Actions column: a rule from this email's sender (From contains its domain).
function ruleFromEmail(r) {
  const domain = String(r.from_email || '').split('@')[1] || r.from_email || '';
  const parts = domain.split('.');
  const base = parts.length > 2 ? parts.slice(-2).join('.') : domain;
  const name = String(r.from_name || '').trim().replace(/^"+|"+$/g, '') || base;
  openRules({
    name: `From ${name}`, conditions: [{ field: 'from', op: 'contains', value: base }], set_category: undefined,
    _email: { from_email: r.from_email, from_name: r.from_name, subject: r.subject, snippet: r.snippet },
  });
}
if (route.query.open) openEmail({ id: Number(route.query.open) });
// Panel closed: refresh the rows, so the list never shows an email's old state.
watch(viewerOpen, open => { if (!open) crud.fetchRows(); });

// ---- Subject filter (email-office/subjects): subjects with counts, like From ----
const subjectOptions = ref([]);
const subjectLoading = ref(false);
let subjectTimer = null;
function searchSubjects(q) {
  clearTimeout(subjectTimer);
  subjectTimer = setTimeout(async () => {
    subjectLoading.value = true;
    try {
      // Within the current From / To and dates, so the list fits what's shown.
      const p = { direction: props.direction, search: q || '', address: filters.value.address || '', from: filters.value.from || '', to: filters.value.to || '' };
      subjectOptions.value = (await http.get('email-office/subjects', p)).subjects || [];
      if (filters.value.subject && !subjectOptions.value.some(o => o.value === filters.value.subject)) {
        subjectOptions.value.unshift({ value: filters.value.subject, label: filters.value.subject });
      }
    } finally {
      subjectLoading.value = false;
    }
  }, 250);
}

// Bulk actions on the selected rows (email-office/emails/bulk).
const bulkBusy = ref(false);
async function bulkUpdate(ids, change) {
  if (!ids.length) return;
  bulkBusy.value = true;
  try {
    const r = await http.post('email-office/emails/bulk', { ids, ...change });
    const what = change.category ? `type set to ${CATEGORY_LABEL[change.category]}` : (change.is_read ? 'marked read' : 'marked unread');
    message.success(`${r.updated} email${r.updated === 1 ? '' : 's'} ${what}.`);
    crud.selectedIds.value = [];
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || 'Could not update the emails.');
  } finally {
    bulkBusy.value = false;
  }
}

// Subject column icon: filter by that subject (the server strips Re: / Fwd:).
function filterSubject(subject) {
  const label = String(subject || '').replace(/^\s*((re|fw|fwd|aw)\s*:\s*)+/i, '').trim();
  if (!label) return;
  const value = label.toLowerCase().replace(/\s+/g, ' ');
  filters.value.subject = value;
  if (!subjectOptions.value.some(o => o.value === value)) subjectOptions.value = [{ value, label }, ...subjectOptions.value];
  crud.reload();
}

// ---- automatic mailbox check while Received is open (email-office/auto-check) ----
const mailbox = ref(null);
const checking = ref(false);
let mailboxTimer = null;
async function autoCheck() {
  if (!incoming.value || checking.value || document.hidden) return;
  checking.value = true;
  try {
    const r = await http.post('email-office/auto-check', {});
    mailbox.value = r;
    if (r.checked && r.received + r.sent > 0) {
      message.info(`${r.received} new email${r.received === 1 ? '' : 's'} received${r.sent ? `, ${r.sent} sent` : ''}.`);
      crud.fetchRows();
    }
  } catch (e) { /* no access / no company picked: no status line */ } finally {
    checking.value = false;
  }
}
async function checkNow() {
  checking.value = true;
  try {
    const r = await http.post('email-office/settings/fetch', {});
    const n = r?.stats?.received || 0;
    message.success(n ? `${n} new email${n === 1 ? '' : 's'}.` : 'No new emails.');
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || 'Could not check the mailbox.');
  } finally {
    checking.value = false;
    autoCheck();
  }
}
onMounted(() => {
  autoCheck();
  mailboxTimer = setInterval(autoCheck, 60 * 1000); // the server decides when it's due
});
onBeforeUnmount(() => clearInterval(mailboxTimer));

// ---- rules ----
const rulesOpen = ref(false);
const rulesPrefill = ref(null);
// prefill: a rule to start from (the email panel's "Create rule from this sender").
function openRules(prefill) {
  rulesPrefill.value = prefill || null;
  rulesOpen.value = true;
}

// ---- new email / reply ----
const composeOpen = ref(false);
const sending = ref(false);
const emptyCompose = () => ({ client_id: undefined, to: [], cc: [], subject: '', body: '', replying: false });
const compose = ref(emptyCompose());
function newEmail() {
  compose.value = emptyCompose();
  composeOpen.value = true;
}
// Reply (received) / write again (sent): to the other side, "Re:" subject,
// the original quoted below -- it joins the same conversation.
async function reply(r) {
  let e = r;
  try {
    e = (await http.get(`email-office/emails/${r.id}`)).email || r;
  } catch (err) { /* reply with what the row has */ }
  const to = incoming.value ? [e.from_email] : String(e.to_emails || '').split(',').map(s => s.trim()).filter(Boolean);
  const subject = /^\s*re\s*:/i.test(e.subject || '') ? e.subject : `Re: ${e.subject || ''}`;
  const quoted = String(e.body_text || e.snippet || '').split('\n').map(l => `> ${l}`).join('\n');
  compose.value = {
    ...emptyCompose(), replying: true, to: to.filter(Boolean), subject,
    body: `\n\n— On ${dayjs(e.sent_at).format('MMM D, YYYY h:mm A')}, ${e.from_name || e.from_email} wrote:\n${quoted}`,
  };
  composeOpen.value = true;
}
// Download one attachment (save as its file name).
async function downloadAttachment(r, a) {
  if (!a) return;
  try {
    await http.download(`email-office/emails/${r.id}/attachments/${a.index}`, a.name);
  } catch (e) {
    message.error(tEs('tx_Could_not_download_the_attachment_e1000', 'Could not download the attachment.'));
  }
}
// The email as a PDF.
async function emailPdf(r) {
  try {
    await http.download(`email-office/emails/${r.id}/pdf`, `${String(r.subject || 'email').replace(/[^\w\- ]+/g, '').slice(0, 60) || 'email'}.pdf`);
  } catch (e) {
    message.error(tEs('tx_Could_not_create_the_PDF_c7214', 'Could not create the PDF.'));
  }
}
const clientOptions = ref([]);
const clientEmails = new Map();
let clientTimer = null;
function searchClients(q) {
  clearTimeout(clientTimer);
  clientTimer = setTimeout(async () => {
    const data = await http.get('clients', { search: q || '', limit: 20, page: 1, SortField: 'name', SortType: 'asc' });
    const rows = (data.clients || []).filter(c => c.email);
    rows.forEach(c => clientEmails.set(c.id, c.email));
    clientOptions.value = sortByLabel(rows.map(c => ({ value: c.id, label: `${c.name} — ${c.email}` })));
  }, 250);
}
function pickClient(id) {
  const e = clientEmails.get(id);
  if (e && !compose.value.to.includes(e)) compose.value.to = [...compose.value.to, e];
}
async function send() {
  const c = compose.value;
  if (!c.to.length || !c.subject.trim() || !c.body.trim()) {
    message.error(tEs('tx_To_subject_and_message_are_required_5f88c', 'To, subject and message are required.'));
    return;
  }
  sending.value = true;
  try {
    await http.post('email-office/compose', { to: c.to, cc: c.cc, subject: c.subject, body: c.body });
    message.success(tEs('tx_Sent_a87d3', 'Sent.'));
    composeOpen.value = false;
    compose.value = emptyCompose();
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || 'Could not send.');
  } finally {
    sending.value = false;
  }
}
</script>

<style scoped>
/* Cards and filters: each in ONE row, sharing the width; a narrow screen
   scrolls the row sideways instead of wrapping it into two. */
.eo-cards {
  display: flex;
  gap: 12px;
  margin-bottom: 12px;
  overflow-x: auto;
  padding-bottom: 2px;
}
.eo-cards > .eo-card {
  flex: 1 1 0;
  min-width: 120px;
}
.eo-filters {
  display: flex;
  gap: 8px;
  overflow-x: auto;
}
.eo-filter {
  flex: 1 1 0;
  min-width: 130px;
}
.eo-filter--wide {
  flex-grow: 1.6;
  min-width: 180px;
}
.eo-card {
  cursor: pointer;
  height: 100%;
}
.eo-card--on {
  border-color: #6d28d9;
  box-shadow: 0 0 0 1px #6d28d9 inset;
}
.eo-card__label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
}
.eo-card__value {
  font-size: 22px;
  font-weight: 600;
  line-height: 1.3;
}
.eo-card__hint {
  font-size: 11px;
  color: rgba(0, 0, 0, 0.45);
}
.eo-actions {
  display: flex;
  justify-content: center;
  gap: 0;
}
.eo-rec {
  cursor: pointer;
}
.eo-add {
  font-size: 12px;
  color: #8c8c8c;
}
.eo-add:hover {
  color: #6d28d9;
}
.eo-rec:hover {
  filter: brightness(0.92);
  text-decoration: underline;
}
.eo-att-count {
  cursor: pointer;
  color: #6d28d9;
  white-space: nowrap;
}
.eo-att {
  padding: 2px 0;
  max-width: 360px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.eo-type {
  cursor: pointer;
  user-select: none; /* a double-click must not select the text */
}
.eo-mailbox {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin: -4px 0 12px;
  color: #666;
  font-size: 13px;
}
.eo-hide-done {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  white-space: nowrap;
}
.eo-muted {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.5);
}
.eo-snippet {
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.eo-addr-filter {
  margin-inline-start: 6px;
  opacity: 0.45;
  cursor: pointer;
}
.eo-addr-filter:hover {
  opacity: 1;
  color: #6d28d9;
}
.eo-unread {
  font-weight: 600;
}
</style>
