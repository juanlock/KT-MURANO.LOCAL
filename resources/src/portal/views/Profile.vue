<template>
  <div>
    <div v-if="profile" class="row row-cards">
      <!-- Account info -->
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header">
            <div class="d-flex align-items-center gap-3">
              <span class="avatar avatar-lg rounded-circle rst-avatar-accent">{{ initials }}</span>
              <div>
                <h3 class="card-title mb-0">{{ profile.client && profile.client.name }}</h3>
                <div class="text-secondary">{{ profile.portal_email }}</div>
              </div>
            </div>
          </div>
          <div class="card-body">
            <div class="datagrid">
              <div class="datagrid-item"><div class="datagrid-title">{{ $t('portal_email') }}</div><div class="datagrid-content pc-wrap">{{ profile.portal_email }}</div></div>
              <div class="datagrid-item"><div class="datagrid-title">{{ $t('client') }}</div><div class="datagrid-content">{{ profile.client && profile.client.name }}</div></div>
              <div class="datagrid-item"><div class="datagrid-title">{{ $t('email') }}</div><div class="datagrid-content pc-wrap">{{ (profile.client && profile.client.email) || '—' }}</div></div>
              <div class="datagrid-item"><div class="datagrid-title">{{ $t('phone') }}</div><div class="datagrid-content">{{ (profile.client && profile.client.phone) || '—' }}</div></div>
              <div class="datagrid-item"><div class="datagrid-title">{{ $t('address') }}</div><div class="datagrid-content">{{ (profile.client && profile.client.adresse) || '—' }}</div></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Password -->
      <div class="col-lg-6">
        <form class="card" @submit.prevent="changePassword">
          <div class="card-header">
            <div>
              <h3 class="card-title mb-0">{{ $t('change_password') }}</h3>
              <div class="text-secondary">{{ $t('use_8_chars') }}</div>
            </div>
          </div>
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label">{{ $t('current_password') }}</label>
              <input v-model="currentPassword" type="password" class="form-control" required placeholder="••••••••" autocomplete="current-password" />
            </div>
            <div class="mb-3">
              <label class="form-label">{{ $t('new_password') }}</label>
              <input v-model="newPassword" type="password" class="form-control" required minlength="8" :placeholder="$t('min_8_chars')" autocomplete="new-password" />
            </div>
            <div class="mb-3">
              <label class="form-label">{{ $t('confirm_new_password') }}</label>
              <input v-model="confirmPassword" type="password" class="form-control" required placeholder="••••••••" autocomplete="new-password" />
            </div>
            <div v-if="pwError" class="alert alert-danger mb-3">{{ pwError }}</div>
            <div v-if="pwSuccess" class="alert alert-success mb-3">{{ $t('password_updated') }}</div>
          </div>
          <div class="card-footer text-end">
            <button type="submit" class="btn btn-primary" :disabled="pwLoading">
              <span v-if="pwLoading" class="spinner-border spinner-border-sm me-2" role="status"></span>
              <i v-else class="ti ti-lock me-1"></i>
              {{ pwLoading ? $t('updating') : $t('update_password') }}
            </button>
          </div>
        </form>
      </div>

      <!-- Danger zone -->
      <div class="col-12">
        <div class="card border-danger">
          <div class="card-header">
            <div>
              <h3 class="card-title mb-0 text-danger">{{ $t('delete_account') }}</h3>
              <div class="text-secondary">{{ $t('delete_account_subtitle') }}</div>
            </div>
          </div>
          <div class="card-body">
            <p class="text-secondary">{{ $t('delete_copy_1') }}</p>
            <p class="text-secondary">{{ $t('delete_copy_2') }}</p>
            <button type="button" class="btn btn-danger" @click="openDelete"><i class="ti ti-user-off me-1"></i>{{ $t('delete_account') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-else class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading_profile') }}</div>
    </div>

    <!-- Confirmation dialog (Bootstrap markup, Vue-driven) -->
    <template v-if="delOpen">
      <div class="modal modal-blur fade show d-block" tabindex="-1" role="dialog" aria-modal="true" @click.self="closeDelete">
        <div class="modal-dialog modal-dialog-centered" role="document">
          <form class="modal-content" @submit.prevent="confirmDelete">
            <div class="modal-header">
              <h5 class="modal-title text-danger">{{ $t('delete_account_q') }}</h5>
              <button type="button" class="btn-close" :aria-label="$t('cancel')" @click="closeDelete"></button>
            </div>
            <div class="modal-body">
              <p class="text-secondary">{{ $t('delete_warning') }}</p>
              <div class="mb-3">
                <label class="form-label">{{ $t('type_email_confirm') }}</label>
                <input v-model="delEmail" type="text" class="form-control" autocomplete="off" :placeholder="profile && profile.portal_email" />
              </div>
              <div class="mb-3">
                <label class="form-label">{{ $t('password') }}</label>
                <input v-model="delPassword" type="password" class="form-control" autocomplete="current-password" placeholder="••••••••" />
              </div>
              <div class="mb-2">
                <label class="form-label">{{ $t('reason') }} <span class="form-label-description">{{ $t('optional') }}</span></label>
                <textarea v-model="delReason" class="form-control" rows="2" maxlength="500" :placeholder="$t('reason_placeholder')"></textarea>
              </div>
              <div v-if="delError" class="alert alert-danger mb-0">{{ delError }}</div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-ghost-secondary" @click="closeDelete">{{ $t('cancel') }}</button>
              <button type="submit" class="btn btn-danger" :disabled="delLoading || !canDelete">
                <span v-if="delLoading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                {{ delLoading ? $t('deleting') : $t('delete_my_account') }}
              </button>
            </div>
          </form>
        </div>
      </div>
      <div class="modal-backdrop fade show"></div>
    </template>
  </div>
</template>

<script>
import http from '../lib/http';
import { apiError, initials } from '../lib/ui';

export default {
  data() {
    return {
      profile: null,
      currentPassword: '', newPassword: '', confirmPassword: '',
      pwError: '', pwSuccess: false, pwLoading: false,
      delOpen: false, delEmail: '', delPassword: '', delReason: '', delError: '', delLoading: false,
    };
  },
  computed: {
    canDelete() { return !!this.delEmail.trim() && !!this.delPassword; },
    initials() { return initials(this.profile && this.profile.client && this.profile.client.name); },
  },
  watch: {
    delOpen(v) { document.body.classList.toggle('pc-modal-open', v); },
  },
  mounted() { this.fetch(); },
  beforeUnmount() { document.body.classList.remove('pc-modal-open'); },
  methods: {
    pageMeta() { return { title: this.$t('profile'), pretitle: this.$t('account'), crumbs: [] }; },
    async fetch() {
      try {
        const { data } = await http.get('/portal/profile');
        this.profile = data;
      } catch (_) {}
    },
    openDelete() { this.delOpen = true; this.delError = ''; this.delEmail = ''; this.delPassword = ''; this.delReason = ''; },
    closeDelete() { if (!this.delLoading) this.delOpen = false; },
    async confirmDelete() {
      if (!this.canDelete) return;
      this.delError = '';
      this.delLoading = true;
      try {
        await http.delete('/portal/profile', { data: { current_password: this.delPassword, confirm_email: this.delEmail.trim(), reason: this.delReason || null } });
        window.location.href = '/portal/login';
      } catch (e) {
        this.delError = apiError(e, this.$t('delete_failed'));
        this.delLoading = false;
      }
    },
    async changePassword() {
      if (this.newPassword !== this.confirmPassword) { this.pwError = this.$t('passwords_no_match'); return; }
      this.pwError = '';
      this.pwSuccess = false;
      this.pwLoading = true;
      try {
        await http.put('/portal/profile/password', { current_password: this.currentPassword, password: this.newPassword, password_confirmation: this.confirmPassword });
        this.pwSuccess = true;
        this.currentPassword = this.newPassword = this.confirmPassword = '';
      } catch (e) {
        this.pwError = apiError(e, this.$t('update_password_failed'));
      }
      this.pwLoading = false;
    },
  },
};
</script>
