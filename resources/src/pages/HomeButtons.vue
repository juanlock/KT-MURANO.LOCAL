<template>
  <div class="page">
    <PageHeader :title="$t('Home_Buttons')" :breadcrumb="['Home Buttons']" />

    <a-alert
      v-if="auth.user?.start_page_mode !== 'custom'"
      type="info" show-icon style="margin-bottom: 16px"
      :message="$t('tx_Your_company_shows_the_regular_dashboard_after_sign_in_32134')"
      :description="$t('tx_These_buttons_appear_on_your_dashboard_once_an_admin_selects_88bd7')"
    />

    <a-row :gutter="[16, 16]">
      <a-col :xs="24" :xl="14">
        <!-- The 8 buttons: company default / my most used form / a form I choose. -->
        <a-card size="small" :title="$t('tx_My_buttons_7b88b')">
          <template #extra>
            <a-button size="small" @click="slots = Array(8).fill(DEFAULT)">{{ $t('tx_Use_company_defaults_71fcf') }}</a-button>
          </template>
          <div class="hb-slots">
            <div v-for="(slot, i) in slots" :key="i" class="hb-slot">
              <span class="hb-num">{{ i + 1 }}</span>
              <a-select
                v-model:value="slots[i]" show-search option-filter-prop="label" style="flex: 1; min-width: 0"
                :options="slotOptions(i)"
              />
            </div>
          </div>
        </a-card>

        <!-- What opens right after signing in. -->
        <a-card size="small" :title="$t('tx_After_I_sign_in_c297f')" style="margin-top: 16px">
          <a-radio-group v-model:value="mode" style="display: flex; flex-direction: column; gap: 6px">
            <a-radio value="recent">{{ $t('tx_Show_my_home_page_with_these_buttons_5dda8') }}</a-radio>
            <a-radio value="form">{{ $t('tx_Open_a_form_automatically_8e2f5') }}</a-radio>
          </a-radio-group>
          <a-select
            v-if="mode === 'form'" v-model:value="form" show-search option-filter-prop="label"
            :options="formOptions" :placeholder="$t('tx_Choose_a_form_055dc')" style="width: 100%; max-width: 420px; margin-top: 10px"
          />
        </a-card>

        <div style="margin-top: 16px">
          <a-button type="primary" :loading="saving" :disabled="mode === 'form' && !form" @click="save">{{ $t('Save') }}</a-button>
        </div>
      </a-col>

      <!-- Live preview of the buttons as they'll show on the dashboard. -->
      <a-col :xs="24" :xl="10">
        <a-card size="small" :title="$t('PWA_Preview')">
          <div v-if="preview.length" class="hb-grid">
            <div v-for="p in preview" :key="p.path" class="hb-tile">
              <span class="hb-icon"><component :is="p.icon" v-if="p.icon" :size="22" /><AppstoreOutlined v-else /></span>
              <span class="hb-label">{{ pageLabel(p) }}</span>
            </div>
          </div>
          <div v-else style="opacity: 0.55; font-size: 13px">
            {{ $t('tx_Nothing_to_show_yet_pick_forms_or_use_the_system_for_a_while_43290') }}
          </div>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
import { t as tEs } from '../i18n/index.js';
/**
 * Each user's own Home Buttons (users.start_preferences.slots, PUT
 * user_start_page): 8 shortcut buttons on top of the dashboard, each one the
 * company default (System Settings -> Defaults -> Users), "my most used form"
 * or a form the user picks -- plus what opens right after signing in.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { AppstoreOutlined } from '@ant-design/icons-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../components/PageHeader.vue';
import http from '../lib/http';
import { useAuthStore } from '../stores/auth';
import { useMenuStore } from '../stores/menu';
import { sortByLabel } from '../lib/filterOptions';
import { allPages, pageOptions, pageLabel, pageFullLabel, shortcuts } from '../lib/startPage';

const { t } = useI18n();
const auth = useAuthStore();

const DEFAULT = '__default';
const MOST_USED = '*';
const slots = ref(Array(8).fill(DEFAULT));
const mode = ref('recent');
const form = ref(undefined);
const saving = ref(false);

// The company's own menu (Manage Menu names) -- reactive: it can load after this page.
const byPath = computed(() => new Map(allPages().map(p => [p.path, p])));
const formOptions = computed(() => sortByLabel(pageOptions(auth).map(p => ({ value: p.path, label: pageFullLabel(p) }))));

// "Company default" says what the admin put on that button.
function slotOptions(i) {
  const admin = (auth.user?.start_page_shortcuts || [])[i];
  const adminPage = admin && byPath.value.get(admin);
  return [
    { value: DEFAULT, label: `Company default: ${adminPage ? pageFullLabel(adminPage) : 'my most used form'}` },
    { value: MOST_USED, label: tEs('tx_My_most_used_form_94731', 'My most used form') },
    ...formOptions.value,
  ];
}

const toSaved = v => (v === DEFAULT ? null : v);

// Preview = the same resolution the dashboard uses, with the unsaved choices.
const preview = computed(() => shortcuts({
  ...auth,
  can: p => auth.can(p),
  user: { ...auth.user, start_preferences: { ...(auth.user?.start_preferences || {}), slots: slots.value.map(toSaved) } },
}, 8));

onMounted(() => {
  // The sidebar normally loaded the company's menu already; make sure.
  const menuStore = useMenuStore();
  if (menuStore.positions === null) menuStore.loadPositions();
  const p = auth.user?.start_preferences || {};
  slots.value = Array.from({ length: 8 }, (_, i) => ((p.slots || [])[i] != null ? p.slots[i] : DEFAULT));
  mode.value = p.mode === 'form' ? 'form' : 'recent';
  form.value = p.form || undefined;
});

async function save() {
  saving.value = true;
  try {
    const res = await http.put('user_start_page', { mode: mode.value, form: form.value || null, slots: slots.value.map(toSaved) });
    auth.user.start_preferences = { ...(auth.user.start_preferences || {}), ...res.start_preferences };
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.hb-slots {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 14px;
}
@media (max-width: 640px) {
  .hb-slots { grid-template-columns: 1fr; }
}
.hb-slot {
  display: flex;
  align-items: center;
  gap: 8px;
}
.hb-num {
  width: 24px;
  height: 24px;
  flex: none;
  display: grid;
  place-items: center;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  background: rgba(109, 40, 217, 0.1);
  color: var(--ant-color-primary, #6d28d9);
}
.hb-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
  gap: 10px;
}
.hb-tile {
  aspect-ratio: 1 / 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 8px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 12px;
}
.hb-icon {
  width: 40px;
  height: 40px;
  display: grid;
  place-items: center;
  border-radius: 12px;
  font-size: 20px;
  background: rgba(109, 40, 217, 0.1);
  color: var(--ant-color-primary, #6d28d9);
}
.hb-label {
  font-size: 12px;
  font-weight: 500;
  text-align: center;
  line-height: 1.25;
}
</style>
