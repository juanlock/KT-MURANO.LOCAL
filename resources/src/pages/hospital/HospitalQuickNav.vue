<template>
  <!-- The hospital forms the user's role can open, as buttons (the side menu starts hidden in a hospital company). -->
  <nav v-if="items.length" class="hqn">
    <router-link to="/hospital/home" class="hqn-b home" :class="{ on: route.path === '/hospital/home' }">
      <HomeFilled /><span>{{ s('Home') }}</span>
    </router-link>
    <router-link
      v-for="i in items" :key="i.path" :to="i.path" class="hqn-b" :class="{ on: route.path.startsWith(i.path) }"
      :style="{ '--c': i.color }"
    >
      <component :is="NAV_ICONS[i.icon]" /><span>{{ s(i.label) }}</span>
    </router-link>
  </nav>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { HomeFilled } from '@ant-design/icons-vue';
import { useAuthStore } from '../../stores/auth';
import { allowedNav } from './hospitalNav';
import { NAV_ICONS } from './navIcons';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/HospitalNav.es.js';

const { s } = useHospitalText(ES);
const route = useRoute();
const auth = useAuthStore();
const items = computed(() => allowedNav(auth).flatMap(g => g.items.map(i => ({ ...i, color: g.color }))));
</script>

<style scoped>
.hqn { display: flex; gap: 6px; overflow-x: auto; padding: 2px 0 10px; margin-bottom: 4px; scrollbar-width: thin; }
.hqn-b {
  --c: #1677ff;
  flex: none; display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px;
  border: 1px solid rgba(128, 128, 128, 0.25); color: inherit; font-size: 13px; white-space: nowrap; background: rgba(128, 128, 128, 0.04);
}
.hqn-b :deep(.anticon) { color: var(--c); }
.hqn-b:hover { border-color: var(--c); }
.hqn-b.on { background: var(--c); border-color: var(--c); color: #fff; }
.hqn-b.on :deep(.anticon) { color: #fff; }
.hqn-b.home { --c: #0f172a; font-weight: 600; }
</style>
