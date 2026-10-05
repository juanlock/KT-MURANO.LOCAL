<template>
  <!-- Staff photos: "All" + one photo per staff member. Click = only that
       person's appointments (click again = everyone); hovering shows a card
       with their name and a link to their employee details. -->
  <div class="staff-strip">
    <a-tooltip title="Everyone">
      <a-avatar :size="size" class="staff-avatar" :class="{ 'staff-avatar--on': !modelValue }" @click="pick(null)">All</a-avatar>
    </a-tooltip>
    <a-popover v-for="s in staff" :key="s.id" placement="bottom" :mouse-enter-delay="0.25">
      <template #content>
        <div class="staff-pop">
          <div class="staff-pop__name">{{ s.name }}</div>
          <a class="staff-pop__act" @click="pick(modelValue === s.id ? null : s.id)">
            {{ modelValue === s.id ? 'Show everyone' : 'Only their appointments' }}
          </a>
          <a v-if="s.employee_id && canEmployee" class="staff-pop__act" @click="openEmployee(s)">Employee details →</a>
          <span v-else-if="!s.employee_id" class="staff-pop__muted">No employee record linked</span>
        </div>
      </template>
      <a-avatar
        :size="size" :src="s.avatar || undefined" class="staff-avatar"
        :class="{ 'staff-avatar--on': modelValue === s.id }" @click="pick(modelValue === s.id ? null : s.id)"
      >{{ staffInitials(s.name) }}</a-avatar>
    </a-popover>
  </div>
</template>

<script setup>
/**
 * Staff photo strip (Bookings calendar + list). staff = [{id (user), name,
 * avatar, employee_id}] from the bookings filter_options; v-model = the
 * selected staff user id (null = everyone).
 */
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const props = defineProps({
  staff: { type: Array, default: () => [] },
  modelValue: { type: [Number, String], default: null },
  size: { type: Number, default: 36 },
});
const emit = defineEmits(['update:modelValue', 'change']);
const router = useRouter();
const auth = useAuthStore();
const canEmployee = computed(() => auth.can('view_employee'));

function pick(id) {
  emit('update:modelValue', id);
  emit('change', id);
}
function openEmployee(s) {
  router.push(`/hrm/employees/${s.employee_id}/details`);
}
function staffInitials(name) {
  return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
}
</script>

<style scoped>
.staff-strip {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.staff-avatar {
  cursor: pointer;
  background: #ede9fe;
  color: #6d28d9;
  font-weight: 600;
  border: 2px solid transparent;
  transition: border-color 0.15s, transform 0.15s;
}
.staff-avatar:hover {
  transform: translateY(-1px);
}
.staff-avatar--on {
  border-color: var(--ant-color-primary, #6d28d9);
}
.staff-pop {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 170px;
}
.staff-pop__name {
  font-weight: 600;
}
.staff-pop__muted {
  color: #8c8c8c;
  font-size: 12px;
}
</style>
