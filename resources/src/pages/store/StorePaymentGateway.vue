<template>
  <div class="page">
    <PageHeader
      :title="$t('Payment_Gateway')"
      :breadcrumb="context === 'settings' ? [$t('Settings'), $t('Payment_Gateway')] : [$t('Store'), $t('Payment_Gateway')]"
    />

    <a-alert
      type="info" show-icon style="margin-bottom: 16px"
      message="PayPal, Paystack, Flutterwave, Razorpay, bKash and SSLCommerz are used by the ONLINE STORE checkout only."
      description="Stripe keys are shared globally (admin and online store use the same Stripe integration). Each tab switches its method on or off for the online-store checkout; Offline Payments holds the methods paid outside the system."
    />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
    <!-- Gateways — one card, one tab per provider -->
    <a-card :bordered="false" class="pg-card" style="margin-bottom: 16px">
      <a-tabs v-model:active-key="gatewayTab" :tab-position="isMobile ? 'top' : 'left'" class="pg-tabs">
        <!-- ============================== Stripe ============================== -->
        <a-tab-pane key="stripe">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': stripeOffered }"></span>
              <CreditCardOutlined /> Stripe
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="avatarSize" style="background: #635bff; flex: 0 0 auto">
                <template #icon><CreditCardOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">Stripe</div>
                <div class="pg-head__sub">{{ $t('Store') }} — {{ $t('Payment_Gateway') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="stripeOffered ? 'success' : 'default'">
                <template #icon><component :is="stripeOffered ? CheckCircleFilled : StopOutlined" /></template>
                {{ stripeOffered ? 'Enabled' : 'Disabled' }}
              </a-tag>
              <a-switch v-model:checked="stripeEnabled" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <!-- Keys stay editable even when cards are switched off, so turning
               them back on does not mean re-entering credentials. -->
          <a-form layout="vertical" class="pg-form">
            <!-- Field labels literal like legacy's .env-style naming. -->
            <a-form-item label="STRIPE KEY">
              <a-input v-model:value="gateway.stripe_key" :placeholder="$t('LeaveBlank')">
                <template #prefix><KeyOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item label="STRIPE SECRET" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="gateway.stripe_secret" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
          </a-form>
          <a-alert
            v-if="!stripeEnabled" type="info" show-icon style="margin-bottom: 20px"
            message="Card payments are switched off"
            description="Your keys are kept — the online-store checkout simply stops offering the card option until you switch it back on."
          />
          <a-alert
            v-else-if="!gateway.stripe_key" type="warning" show-icon style="margin-bottom: 20px"
            message="No Stripe key stored"
            description="Enter your Stripe key and secret to start accepting card payments in the online store."
          />

          <a-space>
            <a-button type="primary" :loading="saving" @click="save">
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
            <a-popconfirm
              v-if="gateway.stripe_key"
              :title="$t('RemoveStripeKeysConfirm')"
              @confirm="clearStripeKeys"
            >
              <a-button danger :loading="saving">{{ $t('RemoveKeys') }}</a-button>
            </a-popconfirm>
          </a-space>
        </a-tab-pane>

        <!-- ============================== PayPal ============================== -->
        <a-tab-pane key="paypal">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': paypal.enabled }"></span>
              <DollarCircleOutlined /> PayPal
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="avatarSize" style="background: #003087; flex: 0 0 auto">
                <template #icon><DollarCircleOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">PayPal <a-tag color="blue" style="margin-left: 4px">Online Store</a-tag></div>
                <div class="pg-head__sub">Used by the online store checkout only</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="paypal.enabled ? 'success' : 'default'">
                <template #icon><component :is="paypal.enabled ? CheckCircleFilled : StopOutlined" /></template>
                {{ paypal.enabled ? 'Enabled' : 'Disabled' }}
              </a-tag>
              <a-switch v-model:checked="paypal.enabled" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <a-form v-if="paypal.enabled" layout="vertical" class="pg-form">
            <a-form-item label="PAYPAL CLIENT ID">
              <a-input v-model:value="paypal.client_id" placeholder="AXxxxxxxxxxxxxxxxxxx">
                <template #prefix><KeyOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item
              label="PAYPAL CLIENT SECRET"
              :extra="paypal.secret_set ? $t('LeaveBlank') : undefined"
            >
              <a-input-password v-model:value="paypal.client_secret" :placeholder="paypal.secret_set ? $t('LeaveBlank') : ''" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item
              label="Sandbox (test mode)"
              extra="ON uses api-m.sandbox.paypal.com with sandbox credentials; turn OFF to charge real accounts on api-m.paypal.com."
            >
              <a-switch v-model:checked="paypal.test_mode" />
            </a-form-item>
            <a-form-item
              label="PAYPAL WEBHOOK ID (optional)"
              extra="Backstop for payments whose browser redirect never lands, plus refunds/chargebacks. Register a webhook on developer.paypal.com pointing to the URL below (events: PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.DENIED, PAYMENT.CAPTURE.REFUNDED) and paste its Webhook ID here."
            >
              <a-input v-model:value="paypal.webhook_id" placeholder="8XL79430PL0733531">
                <template #prefix><ApiOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-alert type="info" show-icon style="margin-bottom: 20px">
              <template #message>
                Webhook URL: <a-typography-text code copyable>{{ webhookUrl('paypal') }}</a-typography-text>
              </template>
            </a-alert>
          </a-form>
          <a-alert
            v-else type="info" show-icon style="margin-bottom: 20px"
            message="PayPal is disabled"
            description="Turn PayPal on and enter your REST app Client ID and Secret (from developer.paypal.com) to let customers pay with PayPal at the online-store checkout."
          />

          <a-button type="primary" :loading="paypalSaving" @click="savePaypal">
            <template #icon><SaveOutlined /></template>
            {{ $t('submit') }}
          </a-button>
        </a-tab-pane>

        <!-- ============================== Paystack ============================== -->
        <a-tab-pane key="paystack">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': paystack.enabled }"></span>
              <BankOutlined /> Paystack
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="avatarSize" style="background: #00c3f7; flex: 0 0 auto">
                <template #icon><BankOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">Paystack <a-tag color="blue" style="margin-left: 4px">Online Store</a-tag></div>
                <div class="pg-head__sub">Used by the online store checkout only</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="paystack.enabled ? 'success' : 'default'">
                <template #icon><component :is="paystack.enabled ? CheckCircleFilled : StopOutlined" /></template>
                {{ paystack.enabled ? 'Enabled' : 'Disabled' }}
              </a-tag>
              <a-switch v-model:checked="paystack.enabled" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <a-form v-if="paystack.enabled" layout="vertical" class="pg-form">
            <a-form-item label="PAYSTACK PUBLIC KEY">
              <a-input v-model:value="paystack.public_key" placeholder="pk_test_xxxxxxxx">
                <template #prefix><KeyOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item
              label="PAYSTACK SECRET KEY"
              :extra="paystack.secret_set ? $t('LeaveBlank') : undefined"
            >
              <a-input-password v-model:value="paystack.secret_key" :placeholder="paystack.secret_set ? $t('LeaveBlank') : 'sk_test_xxxxxxxx'" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-alert
              type="info" show-icon style="margin-bottom: 12px"
              message="Test vs live is decided by the key pair: pk_test_/sk_test_ keys run in test mode, pk_live_/sk_live_ keys charge real cards — there is no separate sandbox switch."
            />
            <a-alert type="info" show-icon style="margin-bottom: 20px">
              <template #message>
                Webhook URL (optional backstop — set it under Settings → API Keys &amp; Webhooks on the Paystack dashboard; requests are HMAC-verified with your secret key):
                <a-typography-text code copyable>{{ webhookUrl('paystack') }}</a-typography-text>
              </template>
            </a-alert>
          </a-form>
          <a-alert
            v-else type="info" show-icon style="margin-bottom: 20px"
            message="Paystack is disabled"
            description="Turn Paystack on and enter your Public and Secret keys (from dashboard.paystack.com → Settings → API Keys) to accept cards, bank transfers and mobile money at the online-store checkout."
          />

          <a-button type="primary" :loading="paystackSaving" @click="savePaystack">
            <template #icon><SaveOutlined /></template>
            {{ $t('submit') }}
          </a-button>
        </a-tab-pane>

        <!-- ============================== Flutterwave ============================== -->
        <a-tab-pane key="flutterwave">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': flutterwave.enabled }"></span>
              <GlobalOutlined /> Flutterwave
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="avatarSize" style="background: #f5a623; flex: 0 0 auto">
                <template #icon><GlobalOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">Flutterwave <a-tag color="blue" style="margin-left: 4px">Online Store</a-tag></div>
                <div class="pg-head__sub">Used by the online store checkout only</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="flutterwave.enabled ? 'success' : 'default'">
                <template #icon><component :is="flutterwave.enabled ? CheckCircleFilled : StopOutlined" /></template>
                {{ flutterwave.enabled ? 'Enabled' : 'Disabled' }}
              </a-tag>
              <a-switch v-model:checked="flutterwave.enabled" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <a-form v-if="flutterwave.enabled" layout="vertical" class="pg-form">
            <a-form-item label="FLUTTERWAVE PUBLIC KEY">
              <a-input v-model:value="flutterwave.public_key" placeholder="FLWPUBK_TEST-xxxxxxxx">
                <template #prefix><KeyOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item
              label="FLUTTERWAVE SECRET KEY"
              :extra="flutterwave.secret_set ? $t('LeaveBlank') : undefined"
            >
              <a-input-password v-model:value="flutterwave.secret_key" :placeholder="flutterwave.secret_set ? $t('LeaveBlank') : 'FLWSECK_TEST-xxxxxxxx'" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item
              label="SECRET HASH (webhooks, optional)"
              :extra="flutterwave.hash_set ? $t('LeaveBlank') : 'The Secret Hash you set under Settings → Webhooks on the Flutterwave dashboard; incoming webhooks are rejected unless their verif-hash header matches.'"
            >
              <a-input-password v-model:value="flutterwave.secret_hash" :placeholder="flutterwave.hash_set ? $t('LeaveBlank') : ''" autocomplete="new-password">
                <template #prefix><ApiOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-alert
              type="info" show-icon style="margin-bottom: 12px"
              message="Uses the Flutterwave v3 API with classic keys. Test vs live is decided by the key pair (FLWPUBK_TEST/FLWSECK_TEST vs live keys) — there is no separate sandbox switch. The store currency must be enabled on your Flutterwave account."
            />
            <a-alert type="info" show-icon style="margin-bottom: 20px">
              <template #message>
                Webhook URL (optional backstop — set it with the Secret Hash above on the Flutterwave dashboard):
                <a-typography-text code copyable>{{ webhookUrl('flutterwave') }}</a-typography-text>
              </template>
            </a-alert>
          </a-form>
          <a-alert
            v-else type="info" show-icon style="margin-bottom: 20px"
            message="Flutterwave is disabled"
            description="Turn Flutterwave on and enter your v3 Public and Secret keys (from dashboard.flutterwave.com → Settings → API) to accept cards, mobile money and bank transfers at the online-store checkout."
          />

          <a-button type="primary" :loading="flutterwaveSaving" @click="saveFlutterwave">
            <template #icon><SaveOutlined /></template>
            {{ $t('submit') }}
          </a-button>
        </a-tab-pane>

        <!-- ============================== Razorpay ============================== -->
        <a-tab-pane key="razorpay">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': razorpay.enabled }"></span>
              <ThunderboltOutlined /> Razorpay
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="avatarSize" style="background: #3395ff; flex: 0 0 auto">
                <template #icon><ThunderboltOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">Razorpay <a-tag color="blue" style="margin-left: 4px">Online Store</a-tag></div>
                <div class="pg-head__sub">Used by the online store checkout only</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="razorpay.enabled ? 'success' : 'default'">
                <template #icon><component :is="razorpay.enabled ? CheckCircleFilled : StopOutlined" /></template>
                {{ razorpay.enabled ? 'Enabled' : 'Disabled' }}
              </a-tag>
              <a-switch v-model:checked="razorpay.enabled" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <a-form v-if="razorpay.enabled" layout="vertical" class="pg-form">
            <a-form-item label="RAZORPAY KEY ID">
              <a-input v-model:value="razorpay.key_id" placeholder="rzp_test_xxxxxxxx">
                <template #prefix><KeyOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item
              label="RAZORPAY KEY SECRET"
              :extra="razorpay.secret_set ? $t('LeaveBlank') : undefined"
            >
              <a-input-password v-model:value="razorpay.key_secret" :placeholder="razorpay.secret_set ? $t('LeaveBlank') : ''" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item
              label="WEBHOOK SECRET (optional)"
              :extra="razorpay.webhook_secret_set ? $t('LeaveBlank') : 'The secret you define when creating the webhook on the Razorpay dashboard; incoming events are HMAC-verified against it and rejected otherwise.'"
            >
              <a-input-password v-model:value="razorpay.webhook_secret" :placeholder="razorpay.webhook_secret_set ? $t('LeaveBlank') : ''" autocomplete="new-password">
                <template #prefix><ApiOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-alert
              type="info" show-icon style="margin-bottom: 12px"
              message="Uses Razorpay Payment Links (cards, UPI, netbanking, wallets). Test vs live is decided by the key pair (rzp_test_/rzp_live_) — there is no separate sandbox switch."
            />
            <a-alert type="info" show-icon style="margin-bottom: 20px">
              <template #message>
                Webhook URL (optional backstop — register it with the secret above under Settings → Webhooks; events: payment_link.paid, payment.failed, refund.processed):
                <a-typography-text code copyable>{{ webhookUrl('razorpay') }}</a-typography-text>
              </template>
            </a-alert>
          </a-form>
          <a-alert
            v-else type="info" show-icon style="margin-bottom: 20px"
            message="Razorpay is disabled"
            description="Turn Razorpay on and enter your Key ID and Key Secret (from dashboard.razorpay.com → Settings → API Keys) to accept cards, UPI, netbanking and wallets at the online-store checkout."
          />

          <a-button type="primary" :loading="razorpaySaving" @click="saveRazorpay">
            <template #icon><SaveOutlined /></template>
            {{ $t('submit') }}
          </a-button>
        </a-tab-pane>

        <!-- ============================== bKash ============================== -->
        <a-tab-pane key="bkash">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': bkash.enabled }"></span>
              <MobileOutlined /> bKash
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="avatarSize" style="background: #e2136e; flex: 0 0 auto">
                <template #icon><MobileOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">bKash <a-tag color="blue" style="margin-left: 4px">Online Store</a-tag></div>
                <div class="pg-head__sub">Used by the online store checkout only</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="bkash.enabled ? 'success' : 'default'">
                <template #icon><component :is="bkash.enabled ? CheckCircleFilled : StopOutlined" /></template>
                {{ bkash.enabled ? 'Enabled' : 'Disabled' }}
              </a-tag>
              <a-switch v-model:checked="bkash.enabled" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <a-form v-if="bkash.enabled" layout="vertical" class="pg-form">
            <a-form-item label="BKASH APP KEY">
              <a-input v-model:value="bkash.app_key">
                <template #prefix><KeyOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item
              label="BKASH APP SECRET"
              :extra="bkash.secret_set ? $t('LeaveBlank') : undefined"
            >
              <a-input-password v-model:value="bkash.app_secret" :placeholder="bkash.secret_set ? $t('LeaveBlank') : ''" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item label="BKASH USERNAME">
              <a-input v-model:value="bkash.username" placeholder="01XXXXXXXXX">
                <template #prefix><KeyOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item
              label="BKASH PASSWORD"
              :extra="bkash.password_set ? $t('LeaveBlank') : undefined"
            >
              <a-input-password v-model:value="bkash.password" :placeholder="bkash.password_set ? $t('LeaveBlank') : ''" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item
              label="Sandbox (test mode)"
              extra="ON uses tokenized.sandbox.bka.sh with your sandbox credentials; turn OFF to charge real bKash accounts on tokenized.pay.bka.sh."
            >
              <a-switch v-model:checked="bkash.sandbox" />
            </a-form-item>
            <a-alert
              type="info" show-icon style="margin-bottom: 20px"
              message="Uses bKash Tokenized Checkout (merchant credentials from the bKash merchant portal). bKash charges in BDT only — the option is offered at checkout only while the store's active currency is BDT."
            />
          </a-form>
          <a-alert
            v-else type="info" show-icon style="margin-bottom: 20px"
            message="bKash is disabled"
            description="Turn bKash on and enter your Tokenized Checkout App Key, App Secret, Username and Password (from the bKash merchant portal) to let customers pay with bKash at the online-store checkout."
          />

          <a-button type="primary" :loading="bkashSaving" @click="saveBkash">
            <template #icon><SaveOutlined /></template>
            {{ $t('submit') }}
          </a-button>
        </a-tab-pane>

        <!-- ============================== SSLCommerz ============================== -->
        <a-tab-pane key="sslcommerz">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': sslcommerz.enabled }"></span>
              <SafetyCertificateOutlined /> SSLCommerz
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="avatarSize" style="background: #2e3192; flex: 0 0 auto">
                <template #icon><SafetyCertificateOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">SSLCommerz <a-tag color="blue" style="margin-left: 4px">Online Store</a-tag></div>
                <div class="pg-head__sub">Used by the online store checkout only</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="sslcommerz.enabled ? 'success' : 'default'">
                <template #icon><component :is="sslcommerz.enabled ? CheckCircleFilled : StopOutlined" /></template>
                {{ sslcommerz.enabled ? 'Enabled' : 'Disabled' }}
              </a-tag>
              <a-switch v-model:checked="sslcommerz.enabled" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <a-form v-if="sslcommerz.enabled" layout="vertical" class="pg-form">
            <a-form-item label="SSLCOMMERZ STORE ID">
              <a-input v-model:value="sslcommerz.store_id" placeholder="teststore00live">
                <template #prefix><KeyOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item
              label="SSLCOMMERZ STORE PASSWORD"
              :extra="sslcommerz.password_set ? $t('LeaveBlank') : undefined"
            >
              <a-input-password v-model:value="sslcommerz.store_password" :placeholder="sslcommerz.password_set ? $t('LeaveBlank') : ''" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item
              label="Sandbox (test mode)"
              extra="ON uses sandbox.sslcommerz.com with sandbox credentials; turn OFF to charge real customers on securepay.sslcommerz.com."
            >
              <a-switch v-model:checked="sslcommerz.sandbox" />
            </a-form-item>
            <a-alert
              type="info" show-icon style="margin-bottom: 12px"
              message="Hosted checkout covering cards, bKash/Nagad/Rocket mobile banking and internet banking in Bangladesh. Credentials come from your SSLCommerz merchant panel (Store ID + Store Password / API credentials)."
            />
            <a-alert type="info" show-icon style="margin-bottom: 20px">
              <template #message>
                IPN URL (recommended backstop — set it under My Stores → IPN Settings on the SSLCommerz merchant panel):
                <a-typography-text code copyable>{{ webhookUrl('sslcommerz') }}</a-typography-text>
              </template>
            </a-alert>
          </a-form>
          <a-alert
            v-else type="info" show-icon style="margin-bottom: 20px"
            message="SSLCommerz is disabled"
            description="Turn SSLCommerz on and enter your Store ID and Store Password (from the SSLCommerz merchant panel) to accept cards, mobile banking and internet banking at the online-store checkout."
          />

          <a-button type="primary" :loading="sslcommerzSaving" @click="saveSslcommerz">
            <template #icon><SaveOutlined /></template>
            {{ $t('submit') }}
          </a-button>
        </a-tab-pane>
        <!-- ============================== Manual / offline ============================== -->
        <a-tab-pane key="manual">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': anyOfflineEnabled }"></span>
              <DollarOutlined /> {{ $t('OfflinePayments') }}
            </span>
          </template>

          <a-alert
            type="info" show-icon style="margin-bottom: 20px"
            :message="$t('OfflinePaymentsIntro')"
          />

          <!-- Cash on delivery -->
          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="avatarSize" style="background: #52c41a; flex: 0 0 auto">
                <template #icon><DollarOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">{{ $t('CashOnDelivery') }}</div>
                <div class="pg-head__sub">{{ $t('CashOnDeliveryDesc') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-switch v-model:checked="flags.cod" />
            </div>
          </div>

          <a-divider />

          <!-- Mobile money -->
          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="avatarSize" style="background: #1677ff; flex: 0 0 auto">
                <template #icon><MobileOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">{{ $t('MobileMoney') }}</div>
                <div class="pg-head__sub">{{ $t('MobileMoneyDesc') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-switch v-model:checked="flags.mobile_money" />
            </div>
          </div>

          <a-divider />

          <!-- Store wallet balance (the same switch as the E-Wallet module) -->
          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="avatarSize" style="background: #722ed1; flex: 0 0 auto">
                <template #icon><WalletOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">{{ $t('PayWithWallet') }}</div>
                <div class="pg-head__sub">{{ $t('WalletMethodDesc') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-switch v-model:checked="flags.wallet" />
            </div>
          </div>

          <a-divider />

          <!-- GCash -->
          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="avatarSize" style="background: #0075c9; flex: 0 0 auto">
                <template #icon><MobileOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">GCash <a-tag color="blue" style="margin-left: 4px">Online Store</a-tag></div>
                <div class="pg-head__sub">{{ $t('GCashDesc') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-switch v-model:checked="manual.gcash_enabled" />
            </div>
          </div>

          <a-form v-if="manual.gcash_enabled" layout="vertical" class="pg-form" style="margin-top: 16px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('AccountName')">
                  <a-input v-model:value="manual.gcash_account_name" :maxlength="150" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('MobileNumber')">
                  <a-input v-model:value="manual.gcash_account_number" :maxlength="60" placeholder="09XX XXX XXXX" />
                </a-form-item>
              </a-col>
            </a-row>
            <a-form-item :label="$t('Instructions')" :extra="$t('OfflineInstructionsHint')">
              <a-textarea v-model:value="manual.gcash_instructions" :rows="3" :maxlength="1000" show-count />
            </a-form-item>
            <a-form-item :label="$t('QrCode')">
              <a-upload
                :file-list="gcashQrList"
                :before-upload="beforeQrUpload"
                accept="image/png,image/jpeg,image/webp"
                list-type="picture"
                @remove="removeQr"
              >
                <a-button v-if="!gcashQrList.length">
                  <template #icon><UploadOutlined /></template>
                  {{ $t('Upload') }}
                </a-button>
              </a-upload>
              <img v-if="!gcashQrList.length && manual.gcash_qr_url" :src="manual.gcash_qr_url" class="pg-qr" alt="GCash QR" />
            </a-form-item>
          </a-form>

          <a-divider />

          <!-- Bank transfer -->
          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="avatarSize" style="background: #4c5fd7; flex: 0 0 auto">
                <template #icon><BankOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">{{ $t('BankTransfer') }} <a-tag color="blue" style="margin-left: 4px">Online Store</a-tag></div>
                <div class="pg-head__sub">{{ $t('BankTransferDesc') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-switch v-model:checked="manual.bank_enabled" />
            </div>
          </div>

          <a-form v-if="manual.bank_enabled" layout="vertical" class="pg-form" style="margin-top: 16px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('BankName')">
                  <a-input v-model:value="manual.bank_name" :maxlength="150" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Branch')">
                  <a-input v-model:value="manual.bank_branch" :maxlength="150" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('AccountName')">
                  <a-input v-model:value="manual.bank_account_name" :maxlength="150" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('AccountNumber')">
                  <a-input v-model:value="manual.bank_account_number" :maxlength="60" />
                </a-form-item>
              </a-col>
            </a-row>
            <a-form-item :label="$t('Instructions')" :extra="$t('OfflineInstructionsHint')">
              <a-textarea v-model:value="manual.bank_instructions" :rows="3" :maxlength="1000" show-count />
            </a-form-item>
          </a-form>

          <a-divider />

          <!-- Cash on pickup -->
          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="avatarSize" style="background: #f7971e; flex: 0 0 auto">
                <template #icon><ShopOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">{{ $t('CashOnPickup') }} <a-tag color="blue" style="margin-left: 4px">Online Store</a-tag></div>
                <div class="pg-head__sub">{{ $t('CashOnPickupDesc') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-switch v-model:checked="manual.pickup_enabled" />
            </div>
          </div>

          <div v-if="manual.pickup_enabled" style="margin-top: 16px">
            <a-form layout="vertical" class="pg-form">
              <a-form-item :label="$t('Instructions')" :extra="$t('OfflineInstructionsHint')">
                <a-textarea v-model:value="manual.pickup_instructions" :rows="2" :maxlength="1000" show-count />
              </a-form-item>
            </a-form>

            <div class="pm-title" style="margin-top: 8px">{{ $t('PickupBranches') }}</div>
            <div class="pm-sub">{{ $t('PickupBranchesHint') }}</div>

            <a-alert
              v-if="!branches.length"
              type="warning" show-icon style="margin-bottom: 12px"
              :message="$t('NoStoreWarehouses')"
            />

            <a-table
              v-else
              :columns="branchColumns"
              :data-source="branches"
              :pagination="false"
              size="small"
              row-key="warehouse_id"
              :scroll="{ x: 'max-content' }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'active'">
                  <a-switch v-model:checked="record.active" size="small" />
                </template>
                <template v-else-if="column.key === 'address'">
                  <a-input v-model:value="record.address" :maxlength="255" :placeholder="record.city || ''" />
                </template>
                <template v-else-if="column.key === 'hours'">
                  <a-input v-model:value="record.hours" :maxlength="191" placeholder="Mon-Sat 9:00-18:00" />
                </template>
                <template v-else-if="column.key === 'contact'">
                  <a-input v-model:value="record.contact" :maxlength="100" />
                </template>
              </template>
            </a-table>
          </div>

          <a-button type="primary" :loading="manualSaving" style="margin-top: 20px" @click="saveManual">
            <template #icon><SaveOutlined /></template>
            {{ $t('submit') }}
          </a-button>
        </a-tab-pane>
      </a-tabs>
    </a-card>

    </template>
  </div>
</template>

<script setup>
/**
 * Online-store payment gateway — the storefront checkout uses the same global
 * Stripe keys as the admin (there is one Stripe integration). GET
 * get_payment_gateway → {gateway:{stripe_key, stripe_secret:'', deleted}};
 * POST payment_gateway {stripe_key, stripe_secret, deleted} — `deleted:true`
 * wipes the stored pair, which the Enable/Disable toggle maps to.
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  CreditCardOutlined, CheckCircleFilled, StopOutlined, KeyOutlined, LockOutlined, SaveOutlined,
  DollarOutlined, MobileOutlined, WalletOutlined, DollarCircleOutlined, BankOutlined, ApiOutlined,
  GlobalOutlined, ThunderboltOutlined, ShopOutlined, UploadOutlined, SafetyCertificateOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

// Rendered from two routes: /store/payment-gateway ('store', default) and
// /settings/payment-gateway ('settings' — only the breadcrumb differs).
defineProps({ context: { type: String, default: 'store' } });

const loading = ref(true);
const gatewayTab = ref('stripe');

// Phones cannot spare 170px for the vertical tab rail, so the gateway tabs flip
// to a horizontally scrollable top bar (and the provider avatars shrink) there.
const isMobile = ref(typeof window !== 'undefined' && window.innerWidth < 768);
const avatarSize = computed(() => (isMobile.value ? 40 : 52));
const onViewportResize = () => { isMobile.value = window.innerWidth < 768; };
onMounted(() => window.addEventListener('resize', onViewportResize));
onBeforeUnmount(() => window.removeEventListener('resize', onViewportResize));

const saving = ref(false);
// `enabled` = credentials are stored; `stripeEnabled` = the admin offers cards
// at checkout. Keeping them apart means switching cards off never wipes keys.
const enabled = ref(false);
const stripeEnabled = ref(true);
const gateway = ref({ stripe_key: '', stripe_secret: '' });
const stripeOffered = computed(() => stripeEnabled.value && !!gateway.value.stripe_key);

// Method on/off flags + the two ids the store-settings update requires.
const flags = reactive({ cod: true, mobile_money: true, wallet: false });


/**
 * Manual (offline) methods: GCash, bank transfer and cash on pickup. These
 * carry the account details the shopper pays to; GCash and bank transfer also
 * ask the shopper for a screenshot + reference number, verified per order.
 */
const manual = reactive({
  gcash_enabled: false,
  gcash_account_name: '',
  gcash_account_number: '',
  gcash_instructions: '',
  gcash_qr_url: '',
  bank_enabled: false,
  bank_name: '',
  bank_account_name: '',
  bank_account_number: '',
  bank_branch: '',
  bank_instructions: '',
  pickup_enabled: false,
  pickup_instructions: '',
});
const manualSaving = ref(false);
/** Lights the Offline Payments tab dot when any offline method is offered. */
const anyOfflineEnabled = computed(() =>
  manual.gcash_enabled || manual.bank_enabled || manual.pickup_enabled
  || flags.cod || flags.mobile_money || flags.wallet);
const gcashQrList = ref([]);
const gcashQrFile = ref(null);
const branches = ref([]);

const branchColumns = computed(() => [
  { title: t('Offer'), key: 'active', width: 80, align: 'center' },
  { title: t('warehouse'), dataIndex: 'name', key: 'name', width: 180 },
  { title: t('Address'), key: 'address', width: 260 },
  { title: t('PickupHours'), key: 'hours', width: 200 },
  { title: t('Phone'), key: 'contact', width: 160 },
]);

/** Hold the picked QR locally; it uploads with the rest of the tab. */
function beforeQrUpload(file) {
  gcashQrFile.value = file;
  gcashQrList.value = [{ uid: '-1', name: file.name, status: 'done', url: URL.createObjectURL(file) }];
  return false;
}
function removeQr() {
  gcashQrFile.value = null;
  gcashQrList.value = [];
  return true;
}

async function saveManual() {
  manualSaving.value = true;
  try {
    const body = {
      payment_gcash_enabled: manual.gcash_enabled ? 1 : 0,
      gcash_account_name: manual.gcash_account_name || '',
      gcash_account_number: manual.gcash_account_number || '',
      gcash_instructions: manual.gcash_instructions || '',
      payment_bank_transfer_enabled: manual.bank_enabled ? 1 : 0,
      bank_name: manual.bank_name || '',
      bank_account_name: manual.bank_account_name || '',
      bank_account_number: manual.bank_account_number || '',
      bank_branch: manual.bank_branch || '',
      bank_instructions: manual.bank_instructions || '',
      payment_cash_on_pickup_enabled: manual.pickup_enabled ? 1 : 0,
      pickup_instructions: manual.pickup_instructions || '',
      payment_cod_enabled: flags.cod ? 1 : 0,
      payment_mobile_money_enabled: flags.mobile_money ? 1 : 0,
      wallet_enabled: flags.wallet ? 1 : 0,
      // Required by the store-settings validator; sent back unchanged.
      default_currency_id: storeMeta.value.default_currency_id,
      default_warehouse_id: storeMeta.value.default_warehouse_id,
    };

    if (gcashQrFile.value) {
      // A file forces multipart; nulls would arrive as the string "null".
      const fd = new FormData();
      Object.entries(body).forEach(([k, v]) => {
        if (v !== null && v !== undefined) fd.append(k, v);
      });
      fd.append('gcash_qr', gcashQrFile.value);
      await http.postForm('admin/store/settings', fd);
      gcashQrFile.value = null;
    } else {
      await http.post('admin/store/settings', body);
    }

    // Branch details ride on their own endpoint (one row per warehouse).
    if (branches.value.length) {
      await http.post('store/pickup-branches', {
        branches: branches.value.map(b => ({
          warehouse_id: b.warehouse_id,
          active: !!b.active,
          address: b.address || null,
          hours: b.hours || null,
          contact: b.contact || null,
          notes: b.notes || null,
          sort_order: b.sort_order || 0,
        })),
      });
    }

    await loadManual();
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    manualSaving.value = false;
  }
}

/** Re-read the offline config + branch rows after a save. */
async function loadManual() {
  const [store, br] = await Promise.all([
    http.get('admin/store/settings').catch(() => null),
    http.get('store/pickup-branches').catch(() => null),
  ]);
  applyManualSettings(store?.settings || {});
  branches.value = (br?.branches || []).map(b => ({ ...b }));
}

function applyManualSettings(s) {
  manual.gcash_enabled = !!Number(s.payment_gcash_enabled);
  manual.gcash_account_name = s.gcash_account_name || '';
  manual.gcash_account_number = s.gcash_account_number || '';
  manual.gcash_instructions = s.gcash_instructions || '';
  manual.gcash_qr_url = s.gcash_qr_path ? `/${String(s.gcash_qr_path).replace(/^\/+/, '')}` : '';
  manual.bank_enabled = !!Number(s.payment_bank_transfer_enabled);
  manual.bank_name = s.bank_name || '';
  manual.bank_account_name = s.bank_account_name || '';
  manual.bank_account_number = s.bank_account_number || '';
  manual.bank_branch = s.bank_branch || '';
  manual.bank_instructions = s.bank_instructions || '';
  manual.pickup_enabled = !!Number(s.payment_cash_on_pickup_enabled);
  manual.pickup_instructions = s.pickup_instructions || '';
  // The switch-only offline methods live on the same tab, so they refresh here
  // too (both on first load and after a save).
  flags.cod = s.payment_cod_enabled == null ? true : !!Number(s.payment_cod_enabled);
  flags.mobile_money = s.payment_mobile_money_enabled == null ? true : !!Number(s.payment_mobile_money_enabled);
  flags.wallet = !!Number(s.wallet_enabled);
  gcashQrList.value = [];
}
const storeMeta = ref({ default_currency_id: null, default_warehouse_id: null });

// PayPal config lives on the store settings row (client_secret is write-only:
// the API never echoes it back, `secret_set` says whether one is stored).
const paypal = reactive({ enabled: false, client_id: '', client_secret: '', secret_set: false, test_mode: true, webhook_id: '' });
const paypalSaving = ref(false);

// Server-to-server webhook endpoints (routes/api.php → Api/Store/WebhookController).
const webhookUrl = gateway => `${window.location.origin}/api/store/webhooks/${gateway}`;

// Paystack config — same storage/leave-blank rules; test vs live is decided
// by the pk_test_/pk_live_ key pair, so there is no sandbox switch.
const paystack = reactive({ enabled: false, public_key: '', secret_key: '', secret_set: false });
const paystackSaving = ref(false);

// Flutterwave config — v3 classic keys + webhook Secret Hash; same rules.
const flutterwave = reactive({
  enabled: false, public_key: '', secret_key: '', secret_set: false,
  secret_hash: '', hash_set: false,
});
const flutterwaveSaving = ref(false);

// Razorpay config — Payment Links; key pair decides test vs live.
const razorpay = reactive({
  enabled: false, key_id: '', key_secret: '', secret_set: false,
  webhook_secret: '', webhook_secret_set: false,
});
const razorpaySaving = ref(false);

// bKash config — Tokenized Checkout merchant credentials; sandbox switch.
const bkash = reactive({
  enabled: false, app_key: '', app_secret: '', secret_set: false,
  username: '', password: '', password_set: false, sandbox: true,
});
const bkashSaving = ref(false);

// SSLCommerz config — Store ID + Store Password; sandbox switch.
const sslcommerz = reactive({
  enabled: false, store_id: '', store_password: '', password_set: false, sandbox: true,
});
const sslcommerzSaving = ref(false);

async function saveBkash() {
  if (bkash.enabled && (!bkash.app_key.trim() || !bkash.username.trim())) {
    message.warning('bKash App Key and Username are required');
    return;
  }
  bkashSaving.value = true;
  try {
    const body = {
      bkash_enabled: bkash.enabled ? 1 : 0,
      bkash_app_key: bkash.app_key.trim(),
      bkash_username: bkash.username.trim(),
      bkash_sandbox: bkash.sandbox ? 1 : 0,
      // Required by the store-settings validator; sent back unchanged.
      default_currency_id: storeMeta.value.default_currency_id,
      default_warehouse_id: storeMeta.value.default_warehouse_id,
    };
    // Blank secrets → omitted → backend keeps the stored ones.
    if (bkash.app_secret.trim()) body.bkash_app_secret = bkash.app_secret.trim();
    if (bkash.password.trim()) body.bkash_password = bkash.password.trim();
    await http.post('admin/store/settings', body);
    if (bkash.app_secret.trim()) bkash.secret_set = true;
    if (bkash.password.trim()) bkash.password_set = true;
    bkash.app_secret = '';
    bkash.password = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    bkashSaving.value = false;
  }
}

async function saveSslcommerz() {
  if (sslcommerz.enabled && !sslcommerz.store_id.trim()) {
    message.warning('SSLCommerz Store ID is required');
    return;
  }
  sslcommerzSaving.value = true;
  try {
    const body = {
      sslcommerz_enabled: sslcommerz.enabled ? 1 : 0,
      sslcommerz_store_id: sslcommerz.store_id.trim(),
      sslcommerz_sandbox: sslcommerz.sandbox ? 1 : 0,
      // Required by the store-settings validator; sent back unchanged.
      default_currency_id: storeMeta.value.default_currency_id,
      default_warehouse_id: storeMeta.value.default_warehouse_id,
    };
    // Blank secret → omitted → backend keeps the stored one.
    if (sslcommerz.store_password.trim()) body.sslcommerz_store_password = sslcommerz.store_password.trim();
    await http.post('admin/store/settings', body);
    if (sslcommerz.store_password.trim()) sslcommerz.password_set = true;
    sslcommerz.store_password = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    sslcommerzSaving.value = false;
  }
}

async function saveRazorpay() {
  if (razorpay.enabled && !razorpay.key_id.trim()) {
    message.warning('Razorpay Key ID is required');
    return;
  }
  razorpaySaving.value = true;
  try {
    const body = {
      razorpay_enabled: razorpay.enabled ? 1 : 0,
      razorpay_key_id: razorpay.key_id.trim(),
      // Required by the store-settings validator; sent back unchanged.
      default_currency_id: storeMeta.value.default_currency_id,
      default_warehouse_id: storeMeta.value.default_warehouse_id,
    };
    // Blank secrets → omitted → backend keeps the stored ones.
    if (razorpay.key_secret.trim()) body.razorpay_key_secret = razorpay.key_secret.trim();
    if (razorpay.webhook_secret.trim()) body.razorpay_webhook_secret = razorpay.webhook_secret.trim();
    await http.post('admin/store/settings', body);
    if (razorpay.key_secret.trim()) razorpay.secret_set = true;
    if (razorpay.webhook_secret.trim()) razorpay.webhook_secret_set = true;
    razorpay.key_secret = '';
    razorpay.webhook_secret = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    razorpaySaving.value = false;
  }
}

async function saveFlutterwave() {
  if (flutterwave.enabled && !flutterwave.public_key.trim()) {
    message.warning('Flutterwave Public Key is required');
    return;
  }
  flutterwaveSaving.value = true;
  try {
    const body = {
      flutterwave_enabled: flutterwave.enabled ? 1 : 0,
      flutterwave_public_key: flutterwave.public_key.trim(),
      // Required by the store-settings validator; sent back unchanged.
      default_currency_id: storeMeta.value.default_currency_id,
      default_warehouse_id: storeMeta.value.default_warehouse_id,
    };
    // Blank secrets → omitted → backend keeps the stored ones.
    if (flutterwave.secret_key.trim()) body.flutterwave_secret_key = flutterwave.secret_key.trim();
    if (flutterwave.secret_hash.trim()) body.flutterwave_secret_hash = flutterwave.secret_hash.trim();
    await http.post('admin/store/settings', body);
    if (flutterwave.secret_key.trim()) flutterwave.secret_set = true;
    if (flutterwave.secret_hash.trim()) flutterwave.hash_set = true;
    flutterwave.secret_key = '';
    flutterwave.secret_hash = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    flutterwaveSaving.value = false;
  }
}

async function savePaystack() {
  if (paystack.enabled && !paystack.public_key.trim()) {
    message.warning('Paystack Public Key is required');
    return;
  }
  paystackSaving.value = true;
  try {
    const body = {
      paystack_enabled: paystack.enabled ? 1 : 0,
      paystack_public_key: paystack.public_key.trim(),
      // Required by the store-settings validator; sent back unchanged.
      default_currency_id: storeMeta.value.default_currency_id,
      default_warehouse_id: storeMeta.value.default_warehouse_id,
    };
    // Blank secret → omitted → backend keeps the stored one.
    if (paystack.secret_key.trim()) body.paystack_secret_key = paystack.secret_key.trim();
    await http.post('admin/store/settings', body);
    if (paystack.secret_key.trim()) paystack.secret_set = true;
    paystack.secret_key = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    paystackSaving.value = false;
  }
}

async function savePaypal() {
  if (paypal.enabled && !paypal.client_id.trim()) {
    message.warning('PayPal Client ID is required');
    return;
  }
  paypalSaving.value = true;
  try {
    const body = {
      paypal_enabled: paypal.enabled ? 1 : 0,
      paypal_client_id: paypal.client_id.trim(),
      paypal_test_mode: paypal.test_mode ? 1 : 0,
      paypal_webhook_id: paypal.webhook_id.trim(),
      // Required by the store-settings validator; sent back unchanged.
      default_currency_id: storeMeta.value.default_currency_id,
      default_warehouse_id: storeMeta.value.default_warehouse_id,
    };
    // Blank secret → omitted → backend keeps the stored one.
    if (paypal.client_secret.trim()) body.paypal_client_secret = paypal.client_secret.trim();
    await http.post('admin/store/settings', body);
    if (paypal.client_secret.trim()) paypal.secret_set = true;
    paypal.client_secret = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    paypalSaving.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    await http.post('payment_gateway', {
      stripe_key: gateway.value.stripe_key,
      // blank secret → null keeps the stored one (leave-blank behaviour).
      stripe_secret: gateway.value.stripe_secret ? gateway.value.stripe_secret : null,
      deleted: false,
    });
    // The offer-cards-at-checkout switch lives on the store settings, which a
    // gateway-only role may not be allowed to write — the keys still save.
    let flagSaved = true;
    try {
      await http.post('admin/store/settings', {
        payment_stripe_enabled: stripeEnabled.value ? 1 : 0,
        default_currency_id: storeMeta.value.default_currency_id,
        default_warehouse_id: storeMeta.value.default_warehouse_id,
      });
    } catch (e) {
      flagSaved = false;
    }
    enabled.value = !!gateway.value.stripe_key;
    gateway.value.stripe_secret = '';
    if (flagSaved) {
      message.success(t('Successfully_Updated'));
    } else {
      message.warning(t('CardSwitchNotSaved'));
    }
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

/** Explicitly wipe the stored Stripe credentials (the old off-switch path). */
async function clearStripeKeys() {
  saving.value = true;
  try {
    await http.post('payment_gateway', { deleted: true });
    gateway.value.stripe_key = '';
    gateway.value.stripe_secret = '';
    enabled.value = false;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    const [gw, store] = await Promise.all([
      http.get('get_payment_gateway'),
      http.get('admin/store/settings').catch(() => null),
    ]);
    const g = gw?.gateway || {};
    gateway.value = { stripe_key: g.stripe_key || '', stripe_secret: '' };
    enabled.value = !!gateway.value.stripe_key;

    const s = store?.settings || {};
    stripeEnabled.value = s.payment_stripe_enabled == null ? true : !!Number(s.payment_stripe_enabled);
    paypal.enabled = !!Number(s.paypal_enabled);
    paypal.client_id = s.paypal_client_id || '';
    paypal.secret_set = !!s.paypal_secret_set;
    paypal.test_mode = s.paypal_test_mode == null ? true : !!Number(s.paypal_test_mode);
    paypal.webhook_id = s.paypal_webhook_id || '';
    paystack.enabled = !!Number(s.paystack_enabled);
    paystack.public_key = s.paystack_public_key || '';
    paystack.secret_set = !!s.paystack_secret_set;
    flutterwave.enabled = !!Number(s.flutterwave_enabled);
    flutterwave.public_key = s.flutterwave_public_key || '';
    flutterwave.secret_set = !!s.flutterwave_secret_set;
    flutterwave.hash_set = !!s.flutterwave_hash_set;
    razorpay.enabled = !!Number(s.razorpay_enabled);
    razorpay.key_id = s.razorpay_key_id || '';
    razorpay.secret_set = !!s.razorpay_secret_set;
    razorpay.webhook_secret_set = !!s.razorpay_webhook_secret_set;
    bkash.enabled = !!Number(s.bkash_enabled);
    bkash.app_key = s.bkash_app_key || '';
    bkash.secret_set = !!s.bkash_secret_set;
    bkash.username = s.bkash_username || '';
    bkash.password_set = !!s.bkash_password_set;
    bkash.sandbox = s.bkash_sandbox == null ? true : !!Number(s.bkash_sandbox);
    sslcommerz.enabled = !!Number(s.sslcommerz_enabled);
    sslcommerz.store_id = s.sslcommerz_store_id || '';
    sslcommerz.password_set = !!s.sslcommerz_password_set;
    sslcommerz.sandbox = s.sslcommerz_sandbox == null ? true : !!Number(s.sslcommerz_sandbox);
    applyManualSettings(s);
    http.get('store/pickup-branches')
      .then(br => { branches.value = (br?.branches || []).map(b => ({ ...b })); })
      .catch(() => {});
    storeMeta.value = {
      // The id rides along on the settings payload; `currency_code` is the currency
      // *symbol* (e.g. "$"), never an id. Do not fall back to it.
      default_currency_id: s.default_currency_id != null ? Number(s.default_currency_id) : null,
      default_warehouse_id: s.default_warehouse_id ?? null,
    };
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.pg-card {
  border: 1px solid #f0f0f0;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
}
/* Webhook URLs are long enough to push a phone-width card sideways. */
.pg-card :deep(.ant-alert-message),
.pg-card :deep(.ant-alert-description) {
  word-break: break-word;
}
.pg-card :deep(.ant-typography code) {
  word-break: break-all;
}
.pg-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.pg-head__left {
  display: flex;
  align-items: center;
  gap: 14px;
}
.pg-head__title {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.2;
}
.pg-head__sub {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}
.pg-head__right {
  display: flex;
  align-items: center;
  gap: 10px;
}
.pg-form {
  max-width: 520px;
  width: 100%;
  margin-bottom: 4px;
}

/* Gateway tabs (vertical, left rail): provider icon + name + a small status
   dot that shows at a glance which gateways are live without opening their tab. */
.pg-tabs :deep(.ant-tabs-nav) {
  min-width: 170px;
}
.pg-tabs :deep(.ant-tabs-tab) {
  justify-content: flex-start;
  text-align: left;
}
.pg-tab {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-weight: 600;
}
.pg-tab__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #d9d9d9;
  flex: 0 0 auto;
}
.pg-tab__dot--on {
  background: #52c41a;
  box-shadow: 0 0 0 3px rgba(82, 196, 26, 0.18);
}

/* Offline payments tab */
.pg-qr {
  display: block;
  max-width: 160px;
  margin-top: 8px;
  border-radius: 8px;
  border: 1px solid #f0f0f0;
}
.pm-title {
  font-size: 16px;
  font-weight: 600;
}
.pm-sub {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  margin-bottom: 16px;
}
/* ---------------- Mobile (< 768px) ----------------
   The tab rail flips to a top bar (see `isMobile`); the rest is about giving
   the narrow card its width back: less chrome padding, smaller heads, and a
   provider row that keeps its status tag + switch on the same line. */
@media (max-width: 767px) {
  .pg-card :deep(.ant-card-body) {
    padding: 14px 12px;
  }
  .pg-tabs :deep(.ant-tabs-nav) {
    min-width: 0;
    margin-bottom: 12px;
  }
  /* Top tabs scroll horizontally rather than squeezing eight providers in. */
  .pg-tabs :deep(.ant-tabs-tab) {
    padding: 8px 12px;
    font-size: 13px;
  }
  .pg-tabs :deep(.ant-tabs-tab + .ant-tabs-tab) {
    margin-left: 4px;
  }
  .pg-head {
    flex-wrap: nowrap;
    align-items: flex-start;
    gap: 10px;
  }
  .pg-head__left {
    gap: 10px;
    min-width: 0;
    align-items: flex-start;
  }
  .pg-head__title {
    font-size: 15px;
    line-height: 1.3;
  }
  .pg-head__sub {
    font-size: 11px;
  }
  .pg-head__right {
    flex: 0 0 auto;
    gap: 6px;
    padding-top: 2px;
  }

  /* Save / remove buttons stack instead of overflowing the card. */
  .pg-card :deep(.ant-space) {
    flex-wrap: wrap;
  }
  .pg-qr {
    max-width: 120px;
  }
}

/* Narrow phones cannot fit the provider name and the status tag + switch on
   one line, so the controls drop to their own right-aligned row. */
@media (max-width: 479px) {
  .pg-head {
    flex-wrap: wrap;
  }
  .pg-head__left {
    flex: 1 1 100%;
  }
  .pg-head__right {
    margin-left: auto;
  }
}
</style>
