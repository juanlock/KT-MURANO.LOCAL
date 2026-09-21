<template>
  <div>
    <div class="position-absolute top-0 p-3 rst-gate-lang"><LanguageMenu /></div>
    <div class="rst-login-screen w-100">
      <div class="container py-4 rst-gate-frame">
        <div class="text-center mb-4"><BrandMark /></div>

        <div class="card rst-login-panel">
          <div class="card-body p-4 p-md-5">
            <h2 class="h2 text-center mb-1">{{ $t('set_your_password') }}</h2>
            <p v-if="email" class="text-secondary text-center mb-4">{{ $t('for_email') }} <strong>{{ email }}</strong></p>
            <p v-else class="text-secondary text-center mb-4">{{ $t('secure_your_account') }}</p>

            <form v-if="valid" novalidate @submit.prevent="submit">
              <div class="mb-3">
                <label class="form-label">{{ $t('new_password') }}</label>
                <input v-model="password" type="password" class="form-control" required minlength="8" :placeholder="$t('min_8_chars')" autocomplete="new-password" />
              </div>
              <div class="mb-3">
                <label class="form-label">{{ $t('confirm_password') }}</label>
                <input v-model="confirm" type="password" class="form-control" required :placeholder="$t('confirm_password')" autocomplete="new-password" />
              </div>
              <div v-if="error" class="alert alert-danger">{{ error }}</div>
              <div class="form-footer">
                <button type="submit" class="btn btn-primary w-100" :disabled="loading">
                  <span v-if="loading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                  <i v-else class="ti ti-key me-2"></i>
                  {{ loading ? $t('setting') : $t('set_password_sign_in') }}
                </button>
              </div>
            </form>

            <div v-else class="empty py-3">
              <div class="empty-icon"><i class="ti ti-alert-circle fs-1 text-danger"></i></div>
              <p class="empty-title">{{ invalidMsg }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import http from '../lib/http';
import { apiError } from '../lib/ui';
import LanguageMenu from '../components/LanguageMenu.vue';
import BrandMark from '../components/BrandMark.vue';

export default {
  components: { LanguageMenu, BrandMark },
  data() { return { token: '', email: '', password: '', confirm: '', valid: false, error: '', loading: false, invalidMsg: '' }; },
  mounted() {
    this.token = new URLSearchParams(window.location.search).get('token') || '';
    if (!this.token) { this.invalidMsg = this.$t('invite_missing'); return; }
    this.validate();
  },
  methods: {
    async validate() {
      try {
        const { data } = await http.get('/portal/validate-invite', { params: { token: this.token } });
        this.valid = data.valid;
        this.email = data.email || '';
        if (!this.valid) this.invalidMsg = data.message || this.$t('invite_invalid');
      } catch (_) {
        this.invalidMsg = this.$t('invite_expired');
      }
    },
    async submit() {
      if (this.password !== this.confirm) { this.error = this.$t('passwords_no_match'); return; }
      this.error = '';
      this.loading = true;
      try {
        await http.post('/portal/set-password', { token: this.token, password: this.password, password_confirmation: this.confirm });
        window.location.href = '/portal/dashboard';
      } catch (e) {
        this.error = apiError(e, this.$t('set_password_failed'));
      } finally {
        this.loading = false;
      }
    },
  },
};
</script>
