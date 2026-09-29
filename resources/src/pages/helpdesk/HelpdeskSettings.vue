<template>
  <div class="page">
    <PageHeader :title="$t('Helpdesk_Settings')" :breadcrumb="[$t('Helpdesk'), $t('Helpdesk_Settings')]">
      <template #extra><a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button></template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-row :gutter="12">
        <a-col :xs="24" :lg="12">
          <a-card size="small" title="Tickets">
            <a-form layout="vertical">
              <a-form-item label="Ticket number prefix" extra="e.g. HD- gives HD-00001"><a-input v-model:value="s.ticket_prefix" style="max-width: 160px" /></a-form-item>
              <a-form-item label="Categories" extra="Type a category and press Enter">
                <a-select v-model:value="s.categories" mode="tags" :token-separators="[',']" placeholder="General, Billing, Technical…" />
              </a-form-item>
              <a-form-item>
                <a-checkbox v-model:checked="s.auto_reply">Send an automatic "we received your request" email</a-checkbox>
              </a-form-item>
              <a-form-item>
                <a-checkbox v-model:checked="s.public_form_enabled">Public "Open a ticket" form (for suppliers and anyone without a login)</a-checkbox>
                <div v-if="s.public_form_enabled && s.public_form_url" class="muted" style="margin-top: 4px">
                  Link: <a :href="s.public_form_url" target="_blank" rel="noopener">{{ s.public_form_url }}</a>
                  <span> — on the company's own domain it is simply <code>/support</code>.</span>
                </div>
              </a-form-item>
            </a-form>
          </a-card>
          <a-card size="small" title="Agents" style="margin-top: 12px">
            <div class="muted" style="margin-bottom: 6px">Users whose role has "Helpdesk: work tickets" (the "Helpdesk" role, admins). Change roles in Users / Roles.</div>
            <a-tag v-for="a in s.agents || []" :key="a.id" style="margin-bottom: 4px">{{ a.name }}</a-tag>
            <a-empty v-if="!(s.agents || []).length" description="No agents yet" />
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="12">
          <a-card size="small" title="Helpdesk mailbox (read emails into tickets)">
            <a-alert
              v-if="s.last_error" type="warning" show-icon style="margin-bottom: 10px"
              :message="s.last_error" :description="s.last_polled_at ? `Last check: ${fmt(s.last_polled_at)}` : ''"
            />
            <div v-else-if="s.last_polled_at" class="muted" style="margin-bottom: 10px">Last check: {{ fmt(s.last_polled_at) }} — OK</div>
            <a-form layout="vertical">
              <a-form-item><a-checkbox v-model:checked="s.imap_enabled">Import new emails automatically (every 5 minutes)</a-checkbox></a-form-item>
              <a-row :gutter="10">
                <a-col :span="14"><a-form-item label="IMAP server"><a-input v-model:value="s.imap_host" placeholder="imap.gmail.com / mail.company.com" /></a-form-item></a-col>
                <a-col :span="5"><a-form-item label="Port"><a-input-number v-model:value="s.imap_port" :min="1" :max="65535" placeholder="993" style="width: 100%" /></a-form-item></a-col>
                <a-col :span="5"><a-form-item label="Security"><a-select v-model:value="s.imap_encryption" :options="encOptions" /></a-form-item></a-col>
              </a-row>
              <a-row :gutter="10">
                <a-col :span="12"><a-form-item label="Mailbox login (email)"><a-input v-model:value="s.imap_username" autocomplete="off" placeholder="support@company.com" /></a-form-item></a-col>
                <a-col :span="12">
                  <a-form-item label="Password" :extra="s.has_password ? 'Saved — leave blank to keep it' : 'App password for Gmail/Outlook'">
                    <a-input-password v-model:value="s.imap_password" autocomplete="new-password" />
                  </a-form-item>
                </a-col>
              </a-row>
              <a-form-item label="Folder"><a-input v-model:value="s.imap_folder" placeholder="INBOX" style="max-width: 220px" /></a-form-item>

              <a-divider orientation="left" plain>Sending replies</a-divider>
              <div class="muted" style="margin-bottom: 8px">
                Optional: send through this mailbox's SMTP (same login). Empty = the system mail server. Replies always come back to the mailbox and are added to the ticket.
              </div>
              <a-row :gutter="10">
                <a-col :span="14"><a-form-item label="SMTP server"><a-input v-model:value="s.smtp_host" placeholder="smtp.gmail.com" /></a-form-item></a-col>
                <a-col :span="5"><a-form-item label="Port"><a-input-number v-model:value="s.smtp_port" :min="1" :max="65535" placeholder="465" style="width: 100%" /></a-form-item></a-col>
                <a-col :span="5"><a-form-item label="Security"><a-select v-model:value="s.smtp_encryption" :options="encOptions" allow-clear /></a-form-item></a-col>
              </a-row>
              <a-row :gutter="10">
                <a-col :span="12"><a-form-item label="From name"><a-input v-model:value="s.from_name" placeholder="Company Support" /></a-form-item></a-col>
                <a-col :span="12"><a-form-item label="From / reply-to email" extra="Defaults to the mailbox login"><a-input v-model:value="s.from_email" /></a-form-item></a-col>
              </a-row>
              <a-space>
                <a-button :loading="testing" @click="test">Test connection</a-button>
                <a-button :loading="fetching" :disabled="!s.imap_host" @click="fetchNow">Check mailbox now</a-button>
              </a-space>
            </a-form>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/** Helpdesk settings (helpdesk_manage): GET/PUT helpdesk/settings, POST .../test, POST .../fetch. */
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const loading = ref(true);
const saving = ref(false);
const testing = ref(false);
const fetching = ref(false);
const s = ref({ categories: [] });
const encOptions = [{ value: 'ssl', label: 'SSL' }, { value: 'tls', label: 'STARTTLS' }, { value: 'none', label: 'None' }];
const fmt = d => (d ? String(d).replace('T', ' ').substring(0, 16) : '');
const payload = () => {
  const { agents, has_password, public_form_url, id, company_id, created_at, updated_at, last_polled_at, last_error, ...rest } = s.value;
  return rest;
};
const errText = e => (e?.data?.errors && Object.values(e.data.errors).flat().join(' ')) || e?.data?.message || 'Could not save.';

async function load() {
  loading.value = true;
  try { s.value = { ...(await http.get('helpdesk/settings')), imap_password: '' }; } finally { loading.value = false; }
}
async function save() {
  saving.value = true;
  try { s.value = { ...(await http.put('helpdesk/settings', payload())), imap_password: '' }; message.success('Saved.'); } catch (e) { message.error(errText(e)); } finally { saving.value = false; }
}
async function test() {
  testing.value = true;
  try { const r = await http.post('helpdesk/settings/test', payload()); message.success(r.message, 6); } catch (e) { message.error(e?.data?.message || 'Could not connect.', 8); } finally { testing.value = false; }
}
async function fetchNow() {
  fetching.value = true;
  try {
    const r = await http.post('helpdesk/settings/fetch');
    message.success(`${r.imported} new ticket(s), ${r.replies} repl(ies) added, ${r.skipped} skipped.`, 6);
    load();
  } catch (e) { message.error(e?.data?.message || 'Could not read the mailbox.', 8); load(); } finally { fetching.value = false; }
}
onMounted(load);
</script>

<style scoped>
.muted { opacity: 0.7; font-size: 13px; }
</style>
