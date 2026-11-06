<template>
  <div class="page">
    <a-result
      status="404"
      :title="$t('tx_Page_not_available_1d3ac')"
      :sub-title="$t('tx_This_page_not_available_or_you_don_t_have_permission_to_view_f9eac')"
    >
      <template #extra>
        <a-button type="primary" @click="$router.push('/dashboard')">{{ $t('tx_Go_to_dashboard_46995') }}</a-button>
      </template>
    </a-result>
  </div>
</template>
