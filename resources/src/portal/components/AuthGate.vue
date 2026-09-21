<template>
  <div>
    <div v-if="checking" class="rst-login-screen w-100">
      <div class="container py-4 text-center">
        <div class="spinner-border text-primary mb-3" role="status"></div>
        <div class="text-secondary">{{ $t('checking_access') }}</div>
      </div>
    </div>
    <router-view v-else />
  </div>
</template>

<script>
import http from '../lib/http';

export default {
  name: 'PortalAuthGate',
  data() {
    return { checking: true };
  },
  mounted() {
    var self = this;
    http.get('/portal/me')
      .then(function (r) {
        if (r.data && r.data.portal_client) {
          self.checking = false;
        } else {
          window.location.replace('/portal/login');
        }
      })
      .catch(function () {
        window.location.replace('/portal/login');
      });
  },
};
</script>
