<template>
  <div class="page">
    <a-result
      status="404"
      title="Page not available"
      sub-title="This page not available, or you don't have permission to view it."
    >
      <template #extra>
        <a-button type="primary" @click="$router.push('/dashboard')">Go to dashboard</a-button>
      </template>
    </a-result>
  </div>
</template>
