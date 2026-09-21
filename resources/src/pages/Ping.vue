<template>
  <!-- Deliberately minimal: one Ant component, no layout, no charts, no data.
       If THIS crashes, the problem is the Vue/Ant pairing itself, not our pages. -->
  <div style="padding: 48px; font-family: sans-serif">
    <p>Plain HTML renders (no Vue involved in this line).</p>
    <p>Vue interpolation: {{ message }}</p>
    <a-button type="primary" @click="clicks++">Ant button — clicked {{ clicks }}×</a-button>
  </div>
</template>

<script setup>
import { ref } from 'vue';

const message = ref('Vue is alive');
const clicks = ref(0);
</script>
