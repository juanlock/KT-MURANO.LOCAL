<template>
  <!-- Portal gate: sign-in -->
  <div>
    <div class="position-absolute top-0 p-3 rst-gate-lang"><LanguageMenu /></div>
    <div class="rst-login-screen w-100">
      <div class="container py-4 rst-gate-frame">
        <div class="text-center mb-4"><BrandMark /></div>

        <div class="card rst-login-panel">
          <div class="card-body p-4 p-md-5">
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
  data() { return { email: '', password: '', error: '', loading: false }; },
  methods: {
    async login() {
      this.error = '';
      this.loading = true;
      try {
        await http.post('/portal/login', { email: this.email, password: this.password });
        window.location.href = '/portal/dashboard';
      } catch (e) {
        this.error = apiError(e, this.$t('login_failed'));
      } finally {
        this.loading = false;
      }
    },
  },
};
</script>
