<template>
  <div class="page">
    <PageHeader :title="$t('update_settings')" :breadcrumb="[$t('Settings'), $t('Update_Log')]" />

    <a-card>
      <a-tabs>
        <!-- ============================ Automatic upgrade (upload-based) ============================ -->
        <a-tab-pane key="system" :tab="$t('Automatic_Update')">

          <a-alert
            type="error" show-icon style="margin-bottom: 16px"
            message="Note 1: If you have made any changes in the code manually then your changes will be lost."
          />

          <!-- Recovery / interrupted run banner -->
          <a-alert
            v-if="needsAttention"
            type="error" show-icon style="margin-bottom: 16px"
            :message="$t('Update_Needs_Attention')"
          >
            <template #description>
              <div style="margin-bottom: 10px">
                {{ attentionText }}
                <div v-if="state && state.error" style="margin-top: 6px; font-size: 12px">
                  <b>{{ $t('Failed_Step') }}:</b> {{ state.error.phase }} — {{ state.error.message }}
                </div>
              </div>
              <a-space wrap>
                <a-button size="small" type="primary" :loading="stepping" @click="resumeRun">{{ $t('Resume_Update') }}</a-button>
                <a-button size="small" danger :loading="stepping" @click="confirmRollback">{{ $t('Rollback_Now') }}</a-button>
                <a-button size="small" @click="openRecoveryConsole">{{ $t('Open_Recovery_Console') }}</a-button>
              </a-space>
            </template>
          </a-alert>

          <a-row :gutter="[16, 16]">
            <!-- Version + upload -->
            <a-col :xs="24" :lg="10">
              <a-card size="small" style="height: 100%">
                <a-statistic :title="$t('Installed_Version')" :value="currentVersion || '—'" />
                <div style="font-size: 12px; color: #8c8c8c; margin-bottom: 16px">{{ $t('Currently_Running') }}</div>

                <template v-if="!running">
                  <a-upload-dragger
                    v-if="!packageInfo"
                    :accept="'.zip'"
                    :show-upload-list="false"
                    :custom-request="onUploadRequest"
                    :disabled="uploading"
                  >
                    <p style="font-size: 34px; color: #1677ff; margin-bottom: 6px"><InboxOutlined /></p>
                    <p style="font-weight: 600">{{ $t('Upload_Update_Package') }}</p>
                    <p style="font-size: 12px; color: #8c8c8c">{{ $t('Upload_Update_Package_Hint') }}</p>
                  </a-upload-dragger>

                  <div v-if="uploading" style="margin-top: 12px">
                    <a-progress :percent="uploadPercent" size="small" />
                    <div style="font-size: 12px; color: #8c8c8c">{{ $t('Uploading_Package') }}…</div>
                  </div>

                  <template v-if="packageInfo && !uploading">
                    <a-descriptions size="small" :column="1" bordered style="margin-top: 4px">
                      <a-descriptions-item :label="$t('File')">{{ packageInfo.filename }}</a-descriptions-item>
                      <a-descriptions-item :label="$t('Size')">{{ formatBytes(packageInfo.size) }}</a-descriptions-item>
                      <a-descriptions-item v-if="packageInfo.version" :label="$t('Version')">v{{ packageInfo.version }}</a-descriptions-item>
                    </a-descriptions>
                    <a-space style="margin-top: 12px" wrap>
                      <a-button :loading="validating" @click="validatePackage">
                        <SafetyCertificateOutlined v-if="!validating" /> {{ $t('Validate_Package') }}
                      </a-button>
                      <a-button
                        v-if="report && report.ok" type="primary" danger
                        @click="confirmStart"
                      >
                        <CloudUploadOutlined /> {{ $t('Update_Now') }}
                      </a-button>
                      <a-button @click="removePackage"><DeleteOutlined /> {{ $t('Remove') }}</a-button>
                    </a-space>
                  </template>
                </template>
                <a-alert v-else type="info" show-icon :message="$t('Update_In_Progress_Banner')" style="margin-top: 4px" />
              </a-card>
            </a-col>

            <!-- Validation report -->
            <a-col :xs="24" :lg="14">
              <a-card size="small" style="height: 100%" :title="$t('Pre_Update_Checks')">
                <template v-if="report">
                  <div style="max-height: 300px; overflow: auto">
                    <div
                      v-for="(check, i) in report.checks" :key="i"
                      style="display: flex; align-items: flex-start; gap: 8px; margin-bottom: 8px"
                    >
                      <CheckCircleFilled v-if="check.ok" style="color: #52c41a; margin-top: 3px" />
                      <ExclamationCircleFilled v-else-if="check.level === 'warning'" style="color: #faad14; margin-top: 3px" />
                      <CloseCircleFilled v-else style="color: #ff4d4f; margin-top: 3px" />
                      <div style="font-size: 13px">
                        {{ check.label }}
                        <div v-if="!check.ok && check.detail" style="font-size: 12px; color: #8c8c8c">{{ check.detail }}</div>
                      </div>
                    </div>
                  </div>
                  <a-divider style="margin: 12px 0" />
                  <a-tag :color="report.ok ? 'success' : 'error'">
                    {{ report.ok ? $t('Package_Ready_To_Install') : $t('Package_Cannot_Be_Installed') }}
                  </a-tag>
                </template>
                <div v-else style="color: #8c8c8c; font-size: 13px">{{ $t('Upload_Then_Validate_Hint') }}</div>
              </a-card>
            </a-col>

            <!-- Live run progress -->
            <a-col :span="24" v-if="state">
              <a-card size="small">
                <template #title>
                  {{ state.type === 'restore' ? $t('Restoring_Backup') : (isRollingBack ? $t('Rolling_Back') : $t('Update_Progress')) }}
                  <a-tag style="margin-left: 8px" :color="statusColor">{{ statusLabel }}</a-tag>
                </template>
                <template #extra>
                  <span v-if="state.from_version" style="font-size: 12px; color: #8c8c8c">
                    v{{ state.from_version }} → v{{ state.to_version || '?' }}
                  </span>
                </template>

                <a-progress
                  :percent="state.percent || 0"
                  :status="progressStatus"
                />
                <a-steps
                  v-if="state.type === 'update' && !isRollingBack"
                  :current="currentStepIndex" size="small" style="margin: 16px 0 4px"
                  :status="state.status === 'failed' || state.status === 'recovery_required' ? 'error' : undefined"
                  :items="updateSteps.map(s => ({ title: s.label }))"
                />
                <div v-else style="margin: 12px 0 4px; font-size: 13px">
                  <b>{{ $t('Current_Step') }}:</b> {{ state.phase }}
                </div>

                <a-alert
                  v-if="state.maintenance_secret"
                  type="warning" show-icon style="margin-top: 12px"
                  :message="$t('Maintenance_Bypass_Hint')"
                >
                  <template #description>
                    <a :href="origin + '/' + state.maintenance_secret" target="_blank">{{ origin }}/{{ state.maintenance_secret }}</a>
                    &nbsp;•&nbsp;
                    <a :href="origin + '/system-update/recovery'" target="_blank">{{ $t('Open_Recovery_Console') }}</a>
                  </template>
                </a-alert>

                <a-alert
                  v-if="state.status === 'completed'"
                  type="success" show-icon style="margin-top: 12px"
                  :message="state.type === 'restore' ? $t('Backup_Restored_Successfully') : $t('Update_Successful')"
                  :description="$t('Reload_After_Update_Hint')"
                >
                </a-alert>
                <a-alert
                  v-if="state.status === 'rolled_back'"
                  type="warning" show-icon style="margin-top: 12px"
                  :message="$t('Update_failed_rolled_back')"
                  :description="state.error ? (state.error.phase + ' — ' + state.error.message) : ''"
                />

                <a-space style="margin-top: 12px" wrap>
                  <a-button v-if="state.status === 'completed'" type="primary" @click="reloadApp">{{ $t('Reload_Application') }}</a-button>
                  <a-button v-if="isTerminal" @click="discardRun">{{ $t('Dismiss') }}</a-button>
                </a-space>

                <a-collapse v-if="(state.log_tail || []).length" style="margin-top: 14px" ghost>
                  <a-collapse-panel key="log" :header="$t('Technical_Log')">
                    <pre class="update-log">{{ state.log_tail.join('\n') }}</pre>
                  </a-collapse-panel>
                </a-collapse>
              </a-card>
            </a-col>

            <!-- Update history -->
            <a-col :xs="24" :lg="14">
              <a-card size="small" :title="$t('Update_History')">
                <a-table
                  :columns="historyColumns" :data-source="history"
                  size="small" :pagination="{ pageSize: 8 }" row-key="id"
                >
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'status'">
                      <a-tooltip :title="record.error ? record.error.message : ''">
                        <a-tag :color="historyStatusColor(record.status)">{{ historyStatusLabel(record.status) }}</a-tag>
                      </a-tooltip>
                    </template>
                    <template v-else-if="column.key === 'versions'">
                      {{ record.type === 'restore' ? $t('Restore') + ' ' : '' }}
                      v{{ record.from_version || '?' }} → v{{ record.to_version || '?' }}
                    </template>
                    <template v-else-if="column.key === 'duration'">
                      {{ record.duration != null ? formatDuration(record.duration) : '—' }}
                    </template>
                    <template v-else-if="column.key === 'user'">
                      {{ record.user ? record.user.name : '—' }}
                    </template>
                  </template>
                </a-table>
              </a-card>
            </a-col>

            <!-- Backups -->
            <a-col :xs="24" :lg="10">
              <a-card size="small" :title="$t('Update_Backups')">
                <template #extra>
                  <a-space size="small">
                    <span style="font-size: 12px; color: #8c8c8c">{{ $t('Keep_Last') }}</span>
                    <a-input-number v-model:value="retention" :min="1" :max="20" size="small" style="width: 60px" />
                    <a-button size="small" @click="saveRetention">{{ $t('Save') }}</a-button>
                  </a-space>
                </template>
                <a-table
                  :columns="backupColumns" :data-source="backups"
                  size="small" :pagination="false" row-key="id"
                >
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'backup'">
                      <div style="font-size: 13px">v{{ record.version || '?' }} <a-tag v-if="!record.complete" color="warning" style="margin-left: 4px">{{ $t('Incomplete') }}</a-tag></div>
                      <div style="font-size: 12px; color: #8c8c8c">{{ record.created_at }} • {{ formatBytes(record.files_size + record.db_size) }}</div>
                    </template>
                    <template v-else-if="column.key === 'actions'">
                      <a-space size="small">
                        <a-tooltip :title="$t('Restore_This_Backup')">
                          <a-button size="small" :disabled="!record.complete || running" @click="confirmRestore(record)"><HistoryOutlined /></a-button>
                        </a-tooltip>
                        <a-tooltip :title="$t('Del')">
                          <a-button size="small" danger :disabled="running" @click="confirmDeleteBackup(record)"><DeleteOutlined /></a-button>
                        </a-tooltip>
                      </a-space>
                    </template>
                  </template>
                </a-table>
                <div style="font-size: 12px; color: #8c8c8c; margin-top: 8px">{{ $t('Backups_Stored_Hint') }}</div>
              </a-card>
            </a-col>
          </a-row>
        </a-tab-pane>

        <!-- ============================ Manual upgrade ============================ -->
        <a-tab-pane key="manual" :tab="$t('Manual_Update')">
          <h4>Please follow these steps, To Update your application</h4>
          <a-alert type="error" style="margin: 8px 0" message="Note 1: If you have made any changes in the code manually then your changes will be lost." />
          <a-alert type="error" style="margin-bottom: 16px" message='Note 2: only admin or user who has permission "update_settings" can upgrade the system' />
          <ol class="manual-steps">
            <li>
              Take back up of your database. Go to <router-link to="/settings/backup">Backup</router-link>, click Generate Backup —
              you will find it in <strong>/storage/app/public/backup</strong> — or export your database from PhpMyAdmin and save it to your PC.
            </li>
            <li>Take back up of your files before updating.</li>
            <li>Download the latest version from your CodeCanyon account and extract it.</li>
            <li>
              Remove the previous files, <strong>except</strong>:
              <ul>
                <li>file: <strong>.env</strong></li>
                <li>folder: <strong>storage</strong></li>
                <li>folder: <strong>/public/images</strong></li>
              </ul>
            </li>
            <li>
              Re-upload the files and folders from the new update, <strong>except</strong> the same three paths above.
            </li>
            <li>Visit <strong><a :href="origin + '/update'" target="_blank">{{ origin }}/update</a></strong> to update your database.</li>
            <li>Hard-clear your browser cache.</li>
            <li>You are done! Enjoy the updated application.</li>
          </ol>
          <a-alert type="error" message="Note: If any pages are not loading or blank, make sure you cleared your browser cache." />
        </a-tab-pane>

        <!-- ============================ Update Guide ============================ -->
        <a-tab-pane key="guide" :tab="$t('Update_Guide')">
          <div class="guide">

            <h3>1. Before you update</h3>
            <ol class="manual-steps">
              <li>Download the <strong>latest full ZIP package</strong> of the application from your CodeCanyon account (<em>Downloads → Installable files</em>). Do not extract it — the updater needs the ZIP exactly as downloaded.</li>
              <li>Make sure you are logged in as an administrator who has the <strong>"update_settings"</strong> permission. Nobody else can see or run the updater.</li>
              <li>Pick a quiet moment: during the update the application switches to maintenance mode for a short time and other users are locked out.</li>
              <li>You do <strong>not</strong> need to back up manually, extract files, run commands, edit configuration files, or touch the database — the updater does all of that automatically. (An extra manual backup never hurts, of course.)</li>
            </ol>
            <a-alert type="warning" show-icon style="margin: 8px 0 20px"
              message="If you have modified the application code manually, your changes will be overwritten by the update. Your data, uploads, settings and .env are always preserved." />

            <h3>2. Running the update</h3>
            <ol class="manual-steps">
              <li>Open <strong>Settings → System Update</strong> (this page) and stay on the <strong>System Update</strong> tab.</li>
              <li><strong>Upload the ZIP</strong>: click or drag the downloaded package into the upload box. Large files are uploaded in small chunks, so this also works on shared hosting with low upload limits. A checksum guards against corrupted or incomplete uploads.</li>
              <li><strong>Validation runs automatically</strong> after the upload (you can re-run it with the "Validate package" button). The system checks: ZIP integrity, package structure and completeness, version compatibility (downgrades are refused), required PHP version and extensions, file permissions, free disk space, database connection, configuration, and that no other update is running.</li>
              <li>Review the <strong>Pre-update checks</strong> panel. The <strong>Update Now</strong> button only appears when every critical check passes. If a check fails, the message tells you exactly what to fix.</li>
              <li>Click <strong>Update Now</strong> and confirm. Then simply <strong>keep this page open</strong> and watch the progress rail. The system performs, in order:
                <ul>
                  <li><strong>Backup</strong> — a full database dump, a ZIP of every application file the update can change, and a copy of <code>.env</code> are created and <em>verified</em>. Your uploads (<code>public/images</code>) and <code>storage/</code> are never touched by an update, so they are not archived. If a backup cannot be created, the update stops here and nothing is changed.</li>
                  <li><strong>Prepare</strong> — the new version is extracted into a staging area and validated again. The live application is still untouched.</li>
                  <li><strong>Maintenance</strong> — maintenance mode is enabled; visitors see a friendly "We'll be right back" screen.</li>
                  <li><strong>Files</strong> — the new files are switched in near-instantly; the old files are kept aside for rollback. Protected paths (<code>.env</code>, <code>storage/</code>, <code>public/images/</code>) are never replaced.</li>
                  <li><strong>Database</strong> — migrations run, then permissions, translations and templates are refreshed.</li>
                  <li><strong>Cache</strong> — all caches are cleared and rebuilt.</li>
                  <li><strong>Verify</strong> — health checks confirm the new version boots, the database works, all migrations ran, and configuration is valid.</li>
                  <li><strong>Done</strong> — maintenance mode is lifted and temporary files are cleaned up.</li>
                </ul>
              </li>
              <li>When you see <strong>"Update successful"</strong>, click <strong>Reload application</strong> and hard-refresh your browser (Ctrl+F5 / Cmd+Shift+R) so the new admin panel assets load.</li>
            </ol>
            <a-alert type="info" show-icon style="margin: 8px 0 20px"
              message="No internet connection is needed during the update — everything comes from the uploaded ZIP. Losing your connection or closing the browser does NOT break the update: the server remembers exactly where it stopped." />

            <h3>3. If something goes wrong</h3>
            <ol class="manual-steps">
              <li><strong>Automatic rollback</strong> — if anything fails after files started changing (extraction error, migration failure, failed health check, disk full, …), the system automatically restores the previous files and the database from the verified backup, lifts maintenance mode, and reports <em>"Update failed — previous version restored"</em>. You end up exactly where you started.</li>
              <li><strong>Interrupted update</strong> (server restart, timeout, closed browser, power loss) — when you reopen this page, a red banner offers two safe choices: <strong>Continue update</strong> (resumes from the exact step where it stopped) or <strong>Roll back now</strong> (restores the previous version from the backup).</li>
              <li><strong>Admin panel unreachable</strong> — use the standalone <strong>recovery console</strong> at <code>/system-update/recovery</code>. It works even while the site is in maintenance mode or the admin panel assets are broken, and offers the same Continue / Roll back actions. There is also a discreet "Administrator access" link at the bottom of the maintenance screen.</li>
              <li><strong>Locked out by maintenance mode</strong> — while an update is running, this page shows a <strong>bypass link</strong>. Save it if you need to get back in (for example after your session expired); it lets you through the maintenance screen so you can log in again.</li>
              <li><strong>Another admin tries to update at the same time</strong> — they will see <em>"An update is currently in progress. Please wait until it has finished."</em> Only one update can ever run at a time.</li>
            </ol>

            <h3>4. Backups &amp; restore</h3>
            <ol class="manual-steps">
              <li>Every update creates a backup <em>before</em> touching anything: all application files the update can replace, a copy of <code>.env</code>, and a full database dump. Uploads and <code>storage/</code> are never modified by an update and are therefore not part of it — keep your own backups of those. Backups live in <code>storage/app/updater/backups</code> and are never accessible from the web.</li>
              <li>The <strong>Update backups</strong> panel on the System Update tab lists them with date, version and size. From there you can <strong>restore</strong> any complete backup (files + database — a safety copy of the current database is taken first) or <strong>delete</strong> old ones.</li>
              <li>Use <strong>"Keep last N"</strong> to choose how many backups are retained; older ones are removed automatically after each successful update. The only remaining complete backup can never be deleted — you always keep a way back.</li>
            </ol>

            <h3>5. Manual update (fallback method)</h3>
            <p style="margin-bottom: 8px">
              If you prefer updating by hand, or the uploader cannot be used on your server,
              follow the step-by-step instructions on the <strong>{{ $t('Manual_Update') }}</strong> tab of this page.
            </p>
            <a-alert type="error" show-icon style="margin-top: 8px"
              message="If pages look broken or blank after any update, clear your browser cache first — it is almost always the cause." />
          </div>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * System Update — upload-based resumable updater.
 *
 * The server runs a state machine (api/system-update/*): upload chunks →
 * validate → start → then POST step repeatedly; every call performs one
 * bounded phase (backup, stage, swap, migrate, …) and returns the full
 * status. Steps keep working during maintenance mode (whitelisted routes).
 * If the browser closes mid-run the state survives server-side: on reload
 * the banner offers Resume / Rollback, and /system-update/recovery is a
 * standalone console that works even when the SPA is broken.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  InboxOutlined, CheckCircleFilled, CloseCircleFilled, ExclamationCircleFilled,
  SafetyCertificateOutlined, CloudUploadOutlined, DeleteOutlined, HistoryOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const CHUNK_SIZE = 2 * 1024 * 1024;
const origin = window.location.origin;

const currentVersion = ref('');
const state = ref(null);
const packageInfo = ref(null);
const report = ref(null);
const history = ref([]);
const backups = ref([]);
const retention = ref(3);

const uploading = ref(false);
const uploadPercent = ref(0);
const validating = ref(false);
const stepping = ref(false);
let pollTimer = null;
let stepErrors = 0;

// --------------------------------------------------------------- computed

const running = computed(() => !!state.value && state.value.status === 'running' && !state.value.stalled);
const isTerminal = computed(() => !!state.value && ['completed', 'failed', 'rolled_back'].includes(state.value.status));
const isRollingBack = computed(() => !!state.value && String(state.value.phase || '').startsWith('rb_'));

const needsAttention = computed(() => {
  if (!state.value) return false;
  if (state.value.status === 'recovery_required') return true;
  return state.value.status === 'running' && state.value.stalled && !stepping.value;
});
const attentionText = computed(() => {
  if (!state.value) return '';
  if (state.value.status === 'recovery_required') return t('Recovery_Required_Text');
  return t('Update_Interrupted_Text');
});

const statusLabel = computed(() => {
  if (!state.value) return '';
  const map = {
    running: state.value.stalled ? t('Interrupted') : t('Running'),
    completed: t('Successful'),
    failed: t('Failed'),
    rolled_back: t('Rolled_Back'),
    recovery_required: t('Recovery_Required'),
  };
  return map[state.value.status] || state.value.status;
});
const statusColor = computed(() => {
  if (!state.value) return 'default';
  if (state.value.status === 'completed') return 'success';
  if (state.value.status === 'running') return state.value.stalled ? 'error' : 'processing';
  if (state.value.status === 'rolled_back') return 'warning';
  return 'error';
});
const progressStatus = computed(() => {
  if (!state.value) return 'normal';
  if (['failed', 'recovery_required'].includes(state.value.status)) return 'exception';
  if (state.value.status === 'completed') return 'success';
  return 'active';
});

const updateSteps = computed(() => [
  { label: t('Creating_Backup'), phases: ['init', 'backup_db', 'backup_files', 'backup_verify'] },
  { label: t('Preparing_Update'), phases: ['stage_extract', 'stage_validate', 'maintenance_on'] },
  { label: t('Updating_Files'), phases: ['apply'] },
  { label: t('Updating_Database'), phases: ['migrate', 'finalize'] },
  { label: t('Clearing_Cache'), phases: ['cache'] },
  { label: t('Verifying_Installation'), phases: ['verify'] },
  { label: t('Done'), phases: ['maintenance_off', 'cleanup', 'completed'] },
]);
const currentStepIndex = computed(() => {
  if (!state.value) return 0;
  if (state.value.status === 'completed') return updateSteps.value.length - 1;
  const idx = updateSteps.value.findIndex(s => s.phases.includes(state.value.phase));
  return idx >= 0 ? idx : 0;
});

const historyColumns = computed(() => [
  { title: t('Date'), dataIndex: 'started_at', key: 'started_at', width: 150 },
  { title: t('Version'), key: 'versions' },
  { title: t('Updated_By'), key: 'user' },
  { title: t('Duration'), key: 'duration', width: 90 },
  { title: t('Status'), key: 'status', width: 110 },
]);
const backupColumns = computed(() => [
  { title: t('Backup'), key: 'backup' },
  { title: '', key: 'actions', width: 90 },
]);

// ----------------------------------------------------------------- status

function applyStatus(data) {
  if (!data) return;
  currentVersion.value = data.current_version || currentVersion.value;
  state.value = data.state || null;
  packageInfo.value = data.package || null;
  report.value = data.package && data.package.report ? data.package.report : null;
  history.value = data.history || [];
  backups.value = data.backups || [];
  if (typeof data.retention === 'number') retention.value = data.retention;
}

async function refreshStatus() {
  try {
    applyStatus(await http.get('system-update/status'));
  } catch (e) { /* keep the page usable */ }
}

// ----------------------------------------------------------------- upload

async function sha256OfFile(file) {
  if (!window.crypto || !window.crypto.subtle || file.size > 64 * 1024 * 1024) return null;
  try {
    const buf = await file.arrayBuffer();
    const digest = await window.crypto.subtle.digest('SHA-256', buf);
    return Array.from(new Uint8Array(digest)).map(b => b.toString(16).padStart(2, '0')).join('');
  } catch (e) {
    return null;
  }
}

async function onUploadRequest({ file }) {
  if (!/\.zip$/i.test(file.name)) {
    message.error(t('Only_Zip_Allowed'));
    return;
  }
  uploading.value = true;
  uploadPercent.value = 0;
  report.value = null;
  try {
    const total = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
    const uploadId = 'up-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
    const hash = await sha256OfFile(file);
    for (let index = 0; index < total; index++) {
      const blob = file.slice(index * CHUNK_SIZE, Math.min(file.size, (index + 1) * CHUNK_SIZE));
      const form = new FormData();
      form.append('chunk', blob, 'chunk.bin');
      form.append('index', String(index));
      form.append('total', String(total));
      form.append('upload_id', uploadId);
      form.append('filename', file.name);
      if (hash && index === total - 1) form.append('sha256', hash);
      await http.postForm('system-update/upload', form);
      uploadPercent.value = Math.round(((index + 1) / total) * 100);
    }
    message.success(t('Package_Uploaded'));
    await refreshStatus();
    await validatePackage();
  } catch (e) {
    message.error(e?.data?.message || t('Upload_Failed'));
  } finally {
    uploading.value = false;
  }
}

async function validatePackage() {
  validating.value = true;
  try {
    report.value = await http.post('system-update/validate');
    await refreshStatus();
    if (!report.value.ok) message.warning(t('Package_Cannot_Be_Installed'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    validating.value = false;
  }
}

async function removePackage() {
  try {
    applyStatus(await http.delete('system-update/package'));
    report.value = null;
    message.success(t('Removed_successfully'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

// -------------------------------------------------------------------- run

function confirmStart() {
  Modal.confirm({
    title: t('Are_you_sure'),
    content: t('Start_Update_Confirm_Text'),
    okText: t('Yes_update'),
    cancelText: t('Cancel'),
    okType: 'danger',
    onOk: startUpdate,
  });
}

async function startUpdate() {
  try {
    applyStatus(await http.post('system-update/start'));
    runStepLoop();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

function runStepLoop() {
  if (stepping.value) return;
  stepping.value = true;
  stepErrors = 0;
  const next = async () => {
    try {
      applyStatus(await http.post('system-update/step'));
      stepErrors = 0;
    } catch (e) {
      // Transient failures are expected around the file swap (new code is
      // being loaded) — retry with backoff before giving up.
      stepErrors += 1;
      if (stepErrors > 10) {
        stepping.value = false;
        message.error(t('Update_Connection_Lost'));
        await refreshStatus();
        return;
      }
      setTimeout(next, 2500);
      return;
    }
    if (state.value && state.value.status === 'running') {
      setTimeout(next, 500);
    } else {
      stepping.value = false;
      if (state.value && state.value.status === 'completed') {
        message.success(state.value.type === 'restore' ? t('Backup_Restored_Successfully') : t('Update_Successful'));
      }
    }
  };
  next();
}

async function resumeRun() {
  try {
    applyStatus(await http.post('system-update/resume'));
    runStepLoop();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

function confirmRollback() {
  Modal.confirm({
    title: t('Are_you_sure'),
    content: t('Rollback_Confirm_Text'),
    okText: t('Rollback_Now'),
    cancelText: t('Cancel'),
    okType: 'danger',
    onOk: async () => {
      try {
        applyStatus(await http.post('system-update/rollback'));
        runStepLoop();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

async function discardRun() {
  try {
    applyStatus(await http.post('system-update/discard'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

function reloadApp() {
  window.location.reload(true);
}

function openRecoveryConsole() {
  window.open(origin + '/system-update/recovery', '_blank');
}

// ---------------------------------------------------------------- backups

function confirmRestore(backup) {
  Modal.confirm({
    title: t('Are_you_sure'),
    content: t('Restore_Backup_Confirm_Text') + ' (v' + (backup.version || '?') + ' — ' + backup.created_at + ')',
    okText: t('Restore'),
    cancelText: t('Cancel'),
    okType: 'danger',
    onOk: async () => {
      try {
        applyStatus(await http.post('system-update/restore-backup', { id: backup.id }));
        runStepLoop();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

function confirmDeleteBackup(backup) {
  Modal.confirm({
    title: t('Are_you_sure'),
    content: t('Delete_Backup_Confirm_Text'),
    okText: t('Del'),
    cancelText: t('Cancel'),
    okType: 'danger',
    onOk: async () => {
      try {
        applyStatus(await http.delete('system-update/backups/' + encodeURIComponent(backup.id)));
        message.success(t('Deleted_in_successfully'));
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

async function saveRetention() {
  try {
    applyStatus(await http.post('system-update/retention', { retention: retention.value }));
    message.success(t('Successfully_Saved'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

// ---------------------------------------------------------------- helpers

function formatBytes(bytes) {
  if (!bytes) return '0 B';
  const k = 1024; const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.min(sizes.length - 1, Math.floor(Math.log(bytes) / Math.log(k)));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}
function formatDuration(seconds) {
  if (seconds < 60) return seconds + 's';
  const m = Math.floor(seconds / 60); const s = seconds % 60;
  return m + 'm ' + s + 's';
}
function historyStatusColor(status) {
  return { completed: 'success', running: 'processing', failed: 'error', rolled_back: 'warning', recovery_required: 'error' }[status] || 'default';
}
function historyStatusLabel(status) {
  const map = {
    completed: t('Successful'), running: t('Running'), failed: t('Failed'),
    rolled_back: t('Rolled_Back'), recovery_required: t('Recovery_Required'),
  };
  return map[status] || status;
}

onMounted(async () => {
  await refreshStatus();
  // A run left "running" by a closed browser can simply be continued.
  if (state.value && state.value.status === 'running' && !state.value.stalled) {
    runStepLoop();
  }
  pollTimer = setInterval(() => {
    if (!stepping.value && !uploading.value) refreshStatus();
  }, 10000);
});
onBeforeUnmount(() => {
  if (pollTimer) clearInterval(pollTimer);
});
</script>

<style scoped>
.manual-steps li {
  margin-bottom: 10px;
}
.manual-steps ul {
  margin-top: 6px;
}
.guide {
  max-width: 900px;
}
.guide h3 {
  font-size: 16px;
  font-weight: 600;
  margin: 18px 0 10px;
}
.guide h3:first-child {
  margin-top: 4px;
}
.guide code {
  background: rgba(0, 0, 0, 0.06);
  border-radius: 4px;
  padding: 1px 5px;
  font-size: 12px;
}
.update-log {
  background: #101418;
  color: #c9d1d9;
  font-size: 12px;
  border-radius: 8px;
  padding: 12px;
  max-height: 260px;
  overflow: auto;
  white-space: pre-wrap;
  margin: 0;
}
</style>
