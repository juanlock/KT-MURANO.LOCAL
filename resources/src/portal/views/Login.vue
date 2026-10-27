<template>
  <!-- Portal gate: sign-in (+ the login security steps: two-step code, new password) -->
  <div>
    <div class="position-absolute top-0 p-3 rst-gate-lang"><LanguageMenu /></div>
    <div class="rst-login-screen w-100">
      <div class="container py-4 rst-gate-frame">
        <div class="text-center mb-4"><BrandMark /></div>

        <div class="card rst-login-panel">
          <div class="card-body p-4 p-md-5">
            <!-- 1. email + password -->
            <template v-if="!step">
              <h2 class="h2 text-center mb-1">{{ $t('welcome_back') }}</h2>
              <p class="text-secondary text-center mb-4">{{ $t('login_subtitle') }}</p>

              <form autocomplete="off" novalidate @submit.prevent="login">
                <div class="mb-3">
                  <label class="form-label" for="portal-email">{{ $t('email') }}</label>
                  <input id="portal-email" v-model="email" type="email" class="form-control" :class="{ 'is-invalid': !!error }" placeholder="you@company.com" autocomplete="username" required autofocus />
                </div>
                <div class="mb-3">
                  <label class="form-label" for="portal-password">{{ $t('password') }}</label>
                  <input id="portal-password" v-model="password" type="password" class="form-control" :class="{ 'is-invalid': !!error }" placeholder="••••••••" autocomplete="current-password" required />
                  <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
                </div>
                <div class="form-footer">
                  <button type="submit" class="btn btn-primary w-100" :disabled="loading">
                    <span v-if="loading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                    <i v-else class="ti ti-login-2 me-2"></i>
                    {{ loading ? $t('signing_in') : $t('sign_in') }}
                  </button>
                </div>
              </form>
            </template>

            <!-- 2. two-step code (email or authenticator app) -->
            <template v-else-if="step === '2fa'">
              <h2 class="h2 text-center mb-1">{{ $t('sec_verify_title') }}</h2>
              <p class="text-secondary text-center mb-4">
                {{ method === 'app' ? $t('sec_verify_app') : $t('sec_verify_email', { email: maskedEmail }) }}
              </p>
              <div v-if="info" class="alert alert-info py-2">{{ info }}</div>
              <form autocomplete="off" novalidate @submit.prevent="verify">
                <div class="mb-3">
                  <label class="form-label" for="portal-code">{{ $t('sec_code') }}</label>
                  <input id="portal-code" v-model="code" type="text" class="form-control text-center fs-3" :class="{ 'is-invalid': !!error }"
                    :inputmode="method === 'app' ? 'text' : 'numeric'" autocomplete="one-time-code" :maxlength="method === 'app' ? 12 : 6" required autofocus />
                  <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
                </div>
                <label class="form-check mb-3">
                  <input v-model="rememberDevice" class="form-check-input" type="checkbox" />
                  <span class="form-check-label">{{ $t('sec_remember') }}</span>
                </label>
                <button type="submit" class="btn btn-primary w-100" :disabled="loading">
                  <span v-if="loading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                  {{ $t('sec_verify_btn') }}
                </button>
              </form>
              <div class="d-flex justify-content-between mt-3">
                <a v-if="method === 'email'" href="#" @click.prevent="resend">{{ $t('sec_resend') }}</a><span v-else></span>
                <a href="#" class="text-secondary" @click.prevent="restart()">{{ $t('sec_back') }}</a>
              </div>
            </template>

            <!-- 3. new password instead of a standard one -->
            <template v-else>
              <h2 class="h2 text-center mb-1">{{ $t('sec_password_title') }}</h2>
              <p class="text-secondary text-center mb-4">{{ $t('sec_password_lead') }}</p>
              <form autocomplete="off" novalidate @submit.prevent="savePassword">
                <div class="mb-3">
                  <label class="form-label" for="portal-new">{{ $t('sec_new_password') }}</label>
                  <input id="portal-new" v-model="newPassword" type="password" class="form-control" autocomplete="new-password" required autofocus />
                </div>
                <div class="mb-2">
                  <label class="form-label" for="portal-new2">{{ $t('sec_repeat') }}</label>
                  <input id="portal-new2" v-model="newPassword2" type="password" class="form-control" :class="{ 'is-invalid': !!error }" autocomplete="new-password" required />
                  <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
                </div>
                <p class="text-secondary small mb-3">{{ $t('sec_rules') }}</p>
                <button type="submit" class="btn btn-primary w-100" :disabled="loading">
                  <span v-if="loading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                  {{ $t('sec_save') }}
                </button>
              </form>
              <div class="text-end mt-3"><a href="#" class="text-secondary" @click.prevent="restart()">{{ $t('sec_back') }}</a></div>
            </template>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import http from '../lib/http';
import { apiError, validationMessage } from '../lib/ui';
import LanguageMenu from '../components/LanguageMenu.vue';
import BrandMark from '../components/BrandMark.vue';

export default {
  components: { LanguageMenu, BrandMark },
  data() {
    return {
      email: '', password: '', error: '', info: '', loading: false,
      // Login security (2026-10-27): the steps the server asks for after the password.
      step: null, method: 'email', maskedEmail: '', code: '', rememberDevice: false, newPassword: '', newPassword2: '',
    };
  },
  methods: {
    /** The server answered with the next step, or with success (portal open). */
    handle(data) {
      if (data && data.step) {
        this.step = data.step;
        this.method = data.method || 'email';
        this.maskedEmail = data.email || '';
        this.info = data.warning || '';
        this.code = '';
        return;
      }
      window.location.href = '/portal/dashboard';
    },
    /** Back to email + password (the waiting sign-in expired, was locked or cancelled). */
    restart(message = '') {
      this.step = null;
      this.password = '';
      this.error = message;
      this.info = '';
    },
    async run(fn) {
      this.error = '';
      this.loading = true;
      try {
        this.handle((await fn()).data);
      } catch (e) {
        const status = e && e.response && e.response.status;
        const code = e && e.data && e.data.code;
        if (this.step && (status === 419 || code === 'restart' || code === 'expired')) this.restart(apiError(e, this.$t('login_failed')));
        else this.error = validationMessage(e, apiError(e, this.$t('login_failed')));
      } finally {
        this.loading = false;
      }
    },
    login() {
      return this.run(() => http.post('/portal/login', { email: this.email, password: this.password }));
    },
    verify() {
      return this.run(() => http.post('/portal/login/verify', { code: this.code, remember_device: this.rememberDevice }));
    },
    savePassword() {
      return this.run(() => http.post('/portal/login/password', { password: this.newPassword, password_confirmation: this.newPassword2 }));
    },
    async resend() {
      this.error = '';
      try {
        const res = await http.post('/portal/login/resend', {});
        this.info = (res.data && res.data.message) || this.$t('sec_resent');
      } catch (e) {
        this.error = apiError(e, this.$t('sec_send_failed'));
      }
    },
  },
};
</script>
