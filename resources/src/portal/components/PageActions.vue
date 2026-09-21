<template>
  <!--
    Buttons for the shell's page header. The header lives in PortalLayout; on a
    fresh page load the layout's DOM is not in the document yet when the view
    first renders, so the teleport waits for mount before looking for its target.
  -->
  <Teleport v-if="ready" to="#portal-page-actions">
    <slot />
  </Teleport>
</template>

<script>
export default {
  name: 'PageActions',
  data() { return { ready: false }; },
  mounted() {
    this.$nextTick(() => { this.ready = !!document.getElementById('portal-page-actions'); });
  },
};
</script>
