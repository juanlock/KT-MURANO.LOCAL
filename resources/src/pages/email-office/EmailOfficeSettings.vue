<template>
  <div class="page">
    <PageHeader :title="`${$t('Email_Office')} — ${$t('Email_Office_Settings')}`" :breadcrumb="[$t('Email_Office'), $t('Email_Office_Settings')]" />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 64px 0"><a-spin size="large" /></div>

    <a-row v-else :gutter="16">
      <a-col :xs="24" :lg="16">
        <a-card size="small" title="Mailbox connection">
          <a-alert type="info" show-icon style="margin-bottom: 16px">
            <template #message>Connect the email account where the company receives its bills and messages.</template>
            <template #description>
              Murano reads it every few minutes and copies new emails here (it never deletes or moves anything in your mailbox).
              Pick your provider to fill the server details, then type the email and its password.
            </template>
          </a-alert>

          <div class="eo-presets">
            <span class="eo-muted">Provider:</span>
            <a-button v-for="p in PRESETS" :key="p.key" size="small" @click="applyPreset(p)">{{ p.label }}</a-button>
          </div>
          <a-alert v-if="presetNote" type="warning" show-icon :message="presetNote" style="margin-bottom: 12px" />

          <a-form layout="vertical">
            <a-row :gutter="12">
              <a-col :xs="24" :md="8">
                <a-form-item label="Connection type" extra="IMAP (recommended) also reads the Sent folder. POP3 reads the Inbox only.">
                  <a-select v-model:value="form.protocol" :options="[{ value: 'imap', label: 'IMAP (default)' }, { value: 'pop3', label: 'POP3', disabled: !pop3Available }]" @change="defaultPort" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="10">
                <a-form-item label="Server" :extra="form.protocol === 'pop3' ? 'e.g. pop.gmail.com' : 'e.g. imap.gmail.com'">
                  <a-input v-model:value="form.host" placeholder="imap.yourprovider.com" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="3">
                <a-form-item label="Port">
                  <a-input-number v-model:value="form.port" :min="1" :max="65535" style="width: 100%" :placeholder="String(suggestedPort)" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="3">
                <a-form-item label="Security">
                  <a-select v-model:value="form.encryption" :options="[{ value: 'ssl', label: 'SSL' }, { value: 'tls', label: 'TLS' }, { value: 'none', label: 'None' }]" @change="defaultPort" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item label="Email / username" extra="Usually the full email address.">
                  <a-input v-model:value="form.username" placeholder="bills@yourcompany.com" autocomplete="off" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item label="Password" :extra="hasPassword ? 'Saved — leave empty to keep it.' : 'Gmail / Outlook with 2-step login need an App password.'">
                  <a-input-password v-model:value="form.password" :placeholder="hasPassword ? '••••••••' : ''" autocomplete="new-password" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Folder to read" extra="Where the emails arrive.">
                  <a-auto-complete v-model:value="form.folder" :options="folders.map(f => ({ value: f }))" placeholder="INBOX" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item label="Check every" extra="How often Murano looks for new emails.">
                  <a-select v-model:value="form.check_every_minutes" :options="EVERY" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item label="First import: last" extra="Days of old email copied the first time.">
                  <a-input-number v-model:value="form.days_back" :min="1" :max="365" addon-after="days" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :span="24">
                <a-space direction="vertical">
                  <a-checkbox v-model:checked="form.import_sent" :disabled="form.protocol === 'pop3'">Also import the Sent folder (emails written from webmail or a phone)</a-checkbox>
                  <a-checkbox v-model:checked="form.enabled"><b>Import automatically</b> (every {{ everyLabel }})</a-checkbox>
                </a-space>
              </a-col>
            </a-row>

            <a-collapse ghost style="margin-top: 12px">
              <a-collapse-panel key="smtp" header="Sending (optional) — for “New email” in Email sent">
                <div class="eo-muted" style="margin-bottom: 8px">Empty = emails go out through the company mail server (Settings → Mail). Fill it to send from this mailbox instead (same login as above).</div>
                <a-row :gutter="12">
                  <a-col :xs="24" :md="10"><a-form-item label="SMTP server"><a-input v-model:value="form.smtp_host" placeholder="smtp.yourprovider.com" /></a-form-item></a-col>
                  <a-col :xs="12" :md="5"><a-form-item label="Port"><a-input-number v-model:value="form.smtp_port" :min="1" :max="65535" style="width: 100%" placeholder="587" /></a-form-item></a-col>
                  <a-col :xs="12" :md="5"><a-form-item label="Security"><a-select v-model:value="form.smtp_encryption" allow-clear :options="[{ value: 'tls', label: 'TLS' }, { value: 'ssl', label: 'SSL' }, { value: 'none', label: 'None' }]" /></a-form-item></a-col>
                  <a-col :xs="24" :md="12"><a-form-item label="From name"><a-input v-model:value="form.from_name" placeholder="Accounts — KcolTech" /></a-form-item></a-col>
                  <a-col :xs="24" :md="12"><a-form-item label="From email"><a-input v-model:value="form.from_email" placeholder="(the username)" /></a-form-item></a-col>
                </a-row>
              </a-collapse-panel>
            </a-collapse>

            <a-space wrap style="margin-top: 12px">
              <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
              <a-button :loading="testing" @click="test">Test connection</a-button>
              <a-button :loading="fetching" :disabled="!hasPassword && !form.password" @click="fetchNow">Check mailbox now</a-button>
            </a-space>

            <!-- The last test / check result, kept on screen (a toast disappears). -->
            <a-alert v-if="result" :type="result.ok ? 'success' : 'error'" show-icon style="margin-top: 12px" :message="result.message">
              <template v-if="result.suggest" #description>
                <div style="margin-top: 4px">
                  Suggested for {{ result.suggest.provider }}: <b>{{ result.suggest.host }}</b> (receiving) and <b>{{ result.suggest.smtp_host }}</b> (sending).
                </div>
                <a-button type="primary" size="small" style="margin-top: 8px" :loading="testing" @click="useSuggestion">Use these servers and test again</a-button>
              </template>
            </a-alert>
          </a-form>
        </a-card>
      </a-col>

      <a-col :xs="24" :lg="8">
        <!-- First time: bring in the WHOLE mailbox, in batches, with progress. -->
        <a-card size="small" title="Import all emails (first time)" style="margin-bottom: 16px">
          <div class="eo-muted" style="margin-bottom: 10px">
            Brings in every email of the mailbox — Inbox{{ form.import_sent ? ' and Sent' : '' }}, oldest first, 50 at a time.
            Emails already in Murano are skipped, so it's safe to run again. After it, the automatic import only reads the new ones.
          </div>

          <template v-if="fi && ['running', 'paused'].includes(fi.status)">
            <a-progress :percent="fiPercent" :status="fi.status === 'paused' ? 'normal' : 'active'" />
            <div class="eo-fi-line">
              <b>{{ fi.read }}</b> of <b>{{ fi.total }}</b> read · <b>{{ fi.imported }}</b> new · {{ fi.skipped }} already there
              <span v-if="fiEta" class="eo-muted"> · {{ fiEta }}</span>
            </div>
            <div class="eo-muted">
              {{ fi.status === 'paused' ? 'Paused.' : 'Importing… keep this page open, or leave it: the scheduler continues it in the background (when it runs on this server).' }}
            </div>
            <a-alert v-if="fi.error" type="warning" show-icon :message="fi.error" description="It retries the same batch." style="margin-top: 8px" />
            <a-space style="margin-top: 10px">
              <a-button v-if="fi.status === 'running'" @click="fiAction('pause')">Pause</a-button>
              <a-button v-else type="primary" @click="fiAction('resume')">Resume</a-button>
              <a-popconfirm title="Stop the import? Emails already imported stay." @confirm="fiAction('cancel')">
                <a-button danger>Cancel</a-button>
              </a-popconfirm>
            </a-space>
          </template>

          <template v-else>
            <a-alert
              v-if="fi && fi.status === 'done'" type="success" show-icon style="margin-bottom: 10px"
              :message="`Done ${fi.finished_at ? dateTime(fi.finished_at) : ''} — ${fi.imported} new email(s) from ${fi.total}.`"
            />
            <a-alert
              v-else-if="fi && fi.status === 'cancelled'" type="info" show-icon style="margin-bottom: 10px"
              :message="`Cancelled after ${fi.read} of ${fi.total} — ${fi.imported} imported. Starting again skips them.`"
            />
            <a-popconfirm
              :title="`Import every email of ${form.username || 'the mailbox'}? It takes about 1 minute per 90 emails (5,000 emails ≈ 1 hour).`"
              ok-text="Import all" @confirm="fiStart"
            >
              <a-button type="primary" block :loading="fiStarting" :disabled="!hasPassword || !form.host">
                {{ fi && fi.status === 'done' ? 'Import all again' : 'Import all emails' }}
              </a-button>
            </a-popconfirm>
            <div v-if="!hasPassword || !form.host" class="eo-muted" style="margin-top: 6px">Save the connection (and test it) first.</div>
          </template>
        </a-card>

        <a-card size="small" title="Status" style="margin-bottom: 16px">
          <a-descriptions :column="1" size="small">
            <a-descriptions-item label="Automatic import">
              <a-tag :color="saved.enabled ? 'green' : 'default'">{{ saved.enabled ? `On — every ${everyLabel}` : 'Off' }}</a-tag>
            </a-descriptions-item>
            <a-descriptions-item label="Last check">{{ saved.last_polled_at ? dateTime(saved.last_polled_at) : 'Never' }}</a-descriptions-item>
            <a-descriptions-item v-if="saved.last_stats" label="Last result">
              {{ saved.last_stats.received || 0 }} received, {{ saved.last_stats.sent || 0 }} sent
            </a-descriptions-item>
            <a-descriptions-item label="In Murano">{{ counts.received }} received · {{ counts.sent }} sent</a-descriptions-item>
          </a-descriptions>
          <a-alert v-if="saved.last_error" type="error" show-icon :message="saved.last_error" style="margin-top: 8px" />
        </a-card>

        <a-card size="small" title="What Email Office does">
          <ul class="eo-list">
            <li><b>Bills</b> (invoices, receipts, statements) are marked so you can create the <b>expense</b> or link the <b>purchase</b> from the email.</li>
            <li><b>Claims</b> (complaints, refunds, “not working”) can become a <b>helpdesk ticket</b> in one click.</li>
            <li><b>Email sent</b> lists every email Murano sends — invoices, quotations, <b>reminders</b>… — so you can count them.</li>
            <li>Emails from / to a customer appear in the customer's <b>Email conversation</b> tab.</li>
          </ul>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Email Office -> Settings (email_office_manage): the company mailbox --
 * GET/PUT email-office/settings, POST .../test (log in, list folders),
 * POST .../fetch (import now). Provider presets fill server / port /
 * security. Password is never sent back (empty = keep).
 */
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { message } from 'ant-design-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useFormat } from '../../composables/useFormat';

const { dateTime } = useFormat();

const PRESETS = [
  { key: 'gmail', label: 'Gmail', host: 'imap.gmail.com', port: 993, encryption: 'ssl', smtp: ['smtp.gmail.com', 587, 'tls'], note: 'Gmail: turn on IMAP in Gmail settings and use an App password (Google account → Security → App passwords).' },
  { key: 'outlook', label: 'Outlook / Microsoft 365', host: 'outlook.office365.com', port: 993, encryption: 'ssl', smtp: ['smtp.office365.com', 587, 'tls'], note: 'Microsoft 365: IMAP must be allowed for the mailbox; with 2-step login use an App password.' },
  // Hostinger sells two mail services: Titan (MX *.titan.email) and its classic one.
  { key: 'titan', label: 'Titan (Hostinger email)', host: 'imap.titan.email', port: 993, encryption: 'ssl', smtp: ['smtp.titan.email', 465, 'ssl'], note: 'Titan: use the full email address and its email password.' },
  { key: 'hostinger', label: 'Hostinger (classic)', host: 'imap.hostinger.com', port: 993, encryption: 'ssl', smtp: ['smtp.hostinger.com', 465, 'ssl'] },
  { key: 'godaddy', label: 'GoDaddy', host: 'imap.secureserver.net', port: 993, encryption: 'ssl', smtp: ['smtpout.secureserver.net', 465, 'ssl'] },
  { key: 'yahoo', label: 'Yahoo', host: 'imap.mail.yahoo.com', port: 993, encryption: 'ssl', smtp: ['smtp.mail.yahoo.com', 465, 'ssl'], note: 'Yahoo needs an App password.' },
  { key: 'zoho', label: 'Zoho', host: 'imap.zoho.com', port: 993, encryption: 'ssl', smtp: ['smtp.zoho.com', 465, 'ssl'] },
];
const EVERY = [
  { value: 5, label: '5 minutes' }, { value: 15, label: '15 minutes' }, { value: 30, label: '30 minutes' },
  { value: 60, label: '1 hour' }, { value: 180, label: '3 hours' }, { value: 720, label: '12 hours' }, { value: 1440, label: 'Once a day' },
];

const loading = ref(true);
const saving = ref(false);
const testing = ref(false);
const fetching = ref(false);
const pop3Available = ref(false);
const hasPassword = ref(false);
const presetNote = ref('');
const folders = ref([]);
const counts = ref({ received: 0, sent: 0 });
const saved = ref({});
const form = ref({
  enabled: false, protocol: 'imap', host: '', port: null, encryption: 'ssl', username: '', password: '', folder: 'INBOX',
  import_sent: true, check_every_minutes: 15, days_back: 30, smtp_host: '', smtp_port: null, smtp_encryption: undefined, from_name: '', from_email: '',
});

const suggestedPort = computed(() => (form.value.protocol === 'pop3'
  ? (form.value.encryption === 'ssl' ? 995 : 110)
  : (form.value.encryption === 'ssl' ? 993 : 143)));
const everyLabel = computed(() => (EVERY.find(e => e.value === (saved.value.check_every_minutes || form.value.check_every_minutes)) || {}).label || '');

function defaultPort() {
  if (!form.value.port || [993, 143, 995, 110].includes(form.value.port)) form.value.port = suggestedPort.value;
}
function applyPreset(p) {
  Object.assign(form.value, { protocol: 'imap', host: p.host, port: p.port, encryption: p.encryption, folder: 'INBOX' });
  if (!form.value.smtp_host) [form.value.smtp_host, form.value.smtp_port, form.value.smtp_encryption] = p.smtp;
  presetNote.value = p.note || '';
}

/* ------------------------------------------------ import all (first time) */
const fi = ref(null); // settings.full_import
const fiStarting = ref(false);
const fiPercent = computed(() => (fi.value?.total ? Math.min(100, Math.round((fi.value.read / fi.value.total) * 100)) : 0));
// Time left at this session's pace (measured while this page drives it).
const fiPace = ref({ at: 0, read: 0 });
const fiEta = computed(() => {
  const f = fi.value;
  if (f?.status !== 'running' || !fiPace.value.at) return '';
  const secs = (Date.now() - fiPace.value.at) / 1000;
  const done = f.read - fiPace.value.read;
  if (secs < 20 || done <= 0) return 'estimating time left…';
  const left = Math.round(((f.total - f.read) / (done / secs)) / 60);
  return left <= 1 ? 'about a minute left' : `about ${left} min left`;
});
let fiLoopOn = false;
let alive = true;
onUnmounted(() => { alive = false; });

// While the page is open and the import runs: one call = a few batches.
async function fiLoop() {
  if (fiLoopOn) return;
  fiLoopOn = true;
  fiPace.value = { at: Date.now(), read: fi.value?.read || 0 };
  try {
    while (alive && fi.value?.status === 'running') {
      try {
        const r = await http.post('email-office/import-all', { action: 'step' });
        fi.value = r.full_import;
      } catch (e) {
        fi.value = { ...fi.value, error: e?.data?.message || 'Connection problem — retrying.' };
        await new Promise(res => setTimeout(res, 5000));
      }
      await new Promise(res => setTimeout(res, 400));
    }
    if (alive && fi.value?.status === 'done') {
      message.success(`Import finished — ${fi.value.imported} new email(s).`, 6);
      await load();
    }
  } finally {
    fiLoopOn = false;
  }
}

async function fiStart() {
  fiStarting.value = true;
  try {
    await http.put('email-office/settings', { ...form.value, smtp_encryption: form.value.smtp_encryption || null });
    const r = await http.post('email-office/import-all', { action: 'start' });
    fi.value = r.full_import;
    fiLoop();
  } catch (e) {
    result.value = { ok: false, message: e?.data?.message || 'Could not start the import.', suggest: e?.data?.suggest || null };
  } finally {
    fiStarting.value = false;
  }
}

async function fiAction(action) {
  try {
    const r = await http.post('email-office/import-all', { action });
    fi.value = r.full_import;
    if (action === 'resume') fiLoop();
  } catch (e) {
    message.error(e?.data?.message || 'Could not do that.');
  }
}

async function load() {
  const data = await http.get('email-office/settings');
  const s = data.settings || {};
  saved.value = s;
  fi.value = s.full_import || null;
  if (fi.value?.status === 'running') fiLoop();
  hasPassword.value = !!s.has_password;
  pop3Available.value = !!data.pop3_available;
  counts.value = data.counts || counts.value;
  Object.keys(form.value).forEach(k => { if (k !== 'password' && s[k] !== undefined && s[k] !== null) form.value[k] = s[k]; });
  form.value.password = '';
}

async function save() {
  saving.value = true;
  try {
    await http.put('email-office/settings', { ...form.value, smtp_encryption: form.value.smtp_encryption || null });
    message.success('Saved.');
    await load();
  } catch (e) {
    message.error(e?.data?.message || 'Could not save.');
  } finally {
    saving.value = false;
  }
}

// Last Test / Check result: { ok, message, suggest? } (suggest = the provider's general servers).
const result = ref(null);

async function test() {
  testing.value = true;
  try {
    await http.put('email-office/settings', { ...form.value, smtp_encryption: form.value.smtp_encryption || null });
    const r = await http.post('email-office/settings/test');
    folders.value = r.folders || [];
    result.value = { ok: true, message: `Connected — ${r.messages} email(s) in “${form.value.folder || 'INBOX'}”${r.sent_folder ? `; Sent folder: ${r.sent_folder}` : ''}.` };
    await load();
  } catch (e) {
    result.value = { ok: false, message: e?.data?.message || 'Could not connect.', suggest: e?.data?.suggest || null };
  } finally {
    testing.value = false;
  }
}

// Fill in the suggested servers (receiving + sending) and test again.
function useSuggestion() {
  const s = result.value?.suggest;
  if (!s) return;
  Object.assign(form.value, {
    protocol: 'imap', host: s.host, port: s.port, encryption: s.encryption,
    smtp_host: s.smtp_host, smtp_port: s.smtp_port, smtp_encryption: s.smtp_encryption,
  });
  test();
}

async function fetchNow() {
  fetching.value = true;
  try {
    await http.put('email-office/settings', { ...form.value, smtp_encryption: form.value.smtp_encryption || null });
    const r = await http.post('email-office/settings/fetch');
    const s = r.stats || {};
    result.value = { ok: true, message: `Imported ${s.received || 0} received and ${s.sent || 0} sent email(s).` };
    await load();
  } catch (e) {
    result.value = { ok: false, message: e?.data?.message || 'Could not read the mailbox.', suggest: e?.data?.suggest || null };
    await load();
  } finally {
    fetching.value = false;
  }
}

onMounted(async () => {
  try {
    await load();
  } catch (e) {
    message.error(e?.data?.message || 'Could not load the settings.');
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.eo-presets {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
  margin-bottom: 12px;
}
.eo-muted {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
}
.eo-fi-line {
  margin: 4px 0;
}
.eo-list {
  padding-inline-start: 18px;
  margin: 0;
}
.eo-list li {
  margin-bottom: 6px;
}
</style>
