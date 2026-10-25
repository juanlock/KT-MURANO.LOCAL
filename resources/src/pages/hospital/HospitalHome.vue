<template>
  <!-- Hospital home (2026-10-25): big buttons for the forms the user's role can open, by stage of the stay. -->
  <div class="page hh">
    <div class="hh-head">
      <h1>{{ s('Hello, {name}', { name: firstName }) }}</h1>
      <p>{{ s('What do you want to do?') }}</p>
    </div>
    <a-empty v-if="!groups.length" :description="s('Your role has no hospital screens yet.')" />
    <section v-for="g in groups" :key="g.stage" class="hh-g">
      <h2 :style="{ color: g.color }">{{ s(g.stage) }}</h2>
      <div class="hh-tiles">
        <router-link v-for="i in g.items" :key="i.path" :to="i.path" class="tile" :style="{ '--c': g.color }">
          <span class="t-ic"><component :is="NAV_ICONS[i.icon]" /></span>
          <span class="t-l">{{ s(i.label) }}</span>
        </router-link>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useAuthStore } from '../../stores/auth';
import { allowedNav } from './hospitalNav';
import { NAV_ICONS } from './navIcons';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/HospitalNav.es.js';

const { s } = useHospitalText(ES);
const auth = useAuthStore();
const groups = computed(() => allowedNav(auth));
const firstName = computed(() => auth.user?.firstname || auth.user?.username || '');
</script>

<style scoped>
.hh-head h1 { margin: 0; font-size: 26px; font-weight: 800; }
.hh-head p { margin: 4px 0 18px; opacity: 0.7; font-size: 15px; }
.hh-g { margin-bottom: 22px; }
.hh-g h2 { font-size: 15px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin: 0 0 10px; }
.hh-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
.tile {
  --c: #1677ff;
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; min-height: 120px; padding: 14px 10px;
  border-radius: 16px; border: 1.5px solid rgba(128, 128, 128, 0.2); background: rgba(128, 128, 128, 0.03); color: inherit; text-align: center;
  transition: transform 0.12s, box-shadow 0.12s, border-color 0.12s;
}
.tile:hover { transform: translateY(-2px); border-color: var(--c); box-shadow: 0 8px 20px -10px var(--c); }
.t-ic { width: 54px; height: 54px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 26px; color: #fff; background: var(--c); }
.t-l { font-weight: 600; font-size: 14.5px; line-height: 1.2; }
</style>
