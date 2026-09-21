<template>
  <StorePaymentGateway context="settings" />
</template>

<script setup>
/**
 * Settings → Payment Gateway is the SAME page as Store → Payment Gateway
 * (one Stripe integration shared globally; PayPal/Paystack/Flutterwave/
 * Razorpay are online-store-only and labeled as such inside). Only the
 * breadcrumb differs, via the `context` prop.
 */
import StorePaymentGateway from '../store/StorePaymentGateway.vue';
</script>
