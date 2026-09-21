<template>
  <div>
    <PageActions>
      <router-link to="/quotations" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>{{ $t('back_to_quotations') }}</router-link>
    </PageActions>

    <form class="card" @submit.prevent="submit">
      <div class="card-header">
        <div>
          <h3 class="card-title mb-0">{{ $t('request_a_quotation') }}</h3>
          <p class="card-subtitle mb-0">{{ $t('quotation_request_subtitle') }}</p>
        </div>
      </div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label">{{ $t('subject') }} <span class="form-label-description">{{ $t('optional') }}</span></label>
          <input v-model="form.subject" type="text" class="form-control" maxlength="190" :placeholder="$t('subject_placeholder')" />
        </div>
        <div class="mb-3">
          <label class="form-label required">{{ $t('details') }}</label>
          <textarea v-model="form.notes" class="form-control" rows="5" required maxlength="5000" :placeholder="$t('details_placeholder')"></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label">{{ $t('items') }} <span class="form-label-description">{{ $t('optional') }}</span></label>
          <div v-for="(item, i) in form.items" :key="i" class="row g-2 mb-2 align-items-center">
            <div class="col"><input v-model="item.description" type="text" class="form-control" :placeholder="$t('item_description')" /></div>
            <div class="col-4 col-sm-3 col-md-2"><input v-model.number="item.quantity" type="number" min="0" step="0.01" class="form-control" :placeholder="$t('qty')" /></div>
            <div class="col-auto">
              <button type="button" class="btn btn-icon btn-ghost-danger" :title="$t('remove')" @click="removeItem(i)"><i class="ti ti-trash"></i></button>
            </div>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="addItem"><i class="ti ti-plus me-1"></i>{{ $t('add_item') }}</button>
        </div>
        <div v-if="error" class="alert alert-danger mb-0 mt-3">{{ error }}</div>
      </div>
      <div class="card-footer d-flex justify-content-end gap-2">
        <router-link to="/quotations" class="btn btn-ghost-secondary">{{ $t('cancel') }}</router-link>
        <button type="submit" class="btn btn-primary" :disabled="submitting">
          <span v-if="submitting" class="spinner-border spinner-border-sm me-2" role="status"></span>
          <i v-else class="ti ti-send me-1"></i>
          {{ submitting ? $t('submitting') : $t('submit_request') }}
        </button>
      </div>
    </form>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import { apiError } from '../lib/ui';

export default {
  components: { PageActions },
  data() {
    return { form: { subject: '', notes: '', items: [{ description: '', quantity: 1 }] }, submitting: false, error: '' };
  },
  methods: {
    pageMeta() { return { title: this.$t('request_a_quotation'), pretitle: this.$t('quotations'), crumbs: [{ label: this.$t('quotations'), to: '/quotations' }] }; },
    addItem() { this.form.items.push({ description: '', quantity: 1 }); },
    removeItem(i) { this.form.items.splice(i, 1); },
    async submit() {
      if (!this.form.notes || !this.form.notes.trim()) { this.error = this.$t('describe_request'); return; }
      this.error = '';
      this.submitting = true;
      try {
        const items = this.form.items.filter((it) => it.description && it.description.trim());
        await http.post('/portal/quotations', { subject: this.form.subject || undefined, notes: this.form.notes, items: items.length ? items : undefined });
        this.$router.push('/quotations');
      } catch (e) {
        this.error = apiError(e, this.$t('quotation_submit_failed'));
      }
      this.submitting = false;
    },
  },
};
</script>
