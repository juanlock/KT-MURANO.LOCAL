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

      <!-- No "delete account" here (user's rule, 2026-10-18): only the company's
           admin closes a customer's portal access (People -> Customers: portal access, list or edit form). -->
    </div>

    <div v-else class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading_profile') }}</div>
    </div>

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
    };
  },
  computed: {
    initials() { return initials(this.profile && this.profile.client && this.profile.client.name); },
  },
  mounted() { this.fetch(); },
  methods: {
    pageMeta() { return { title: this.$t('profile'), pretitle: this.$t('account'), crumbs: [] }; },
    async fetch() {
      try {
        const { data } = await http.get('/portal/profile');
        this.profile = data;
      } catch (_) {}
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
