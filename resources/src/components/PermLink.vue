<template>
  <!-- A link only when the user's role may open the target page; otherwise just
       the text (never a link that lands on "you don't have permission"). -->
  <router-link v-if="allowed" :to="to" v-bind="$attrs"><slot /></router-link>
  <span v-else class="perm-link--off" v-bind="$attrs"><slot /></span>
</template>

<script setup>
/**
 * <PermLink :to="`/sales/${id}`" permission="Sales_view">SL_0019</PermLink>
 * permission: one key or a list (any of them). SuperAdmin always allowed.
 */
import { computed } from 'vue';
import { useAuthStore } from '../stores/auth';

defineOptions({ inheritAttrs: false });
const props = defineProps({
  to: { type: [String, Object], required: true },
  permission: { type: [String, Array], default: null },
});
const auth = useAuthStore();
const allowed = computed(() => {
  if (!props.permission || auth.user?.type === 'SuperAdmin') return true;
  const perms = Array.isArray(props.permission) ? props.permission : [props.permission];
  return perms.some(p => auth.can(p));
});
</script>
