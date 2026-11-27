<template>
  <div class="page" :class="{ 'board-mode': boardMode }">
    <!-- ================= Header ================= -->
    <div class="page-header">
      <!-- A transport company's dashboard is a board: one screen, so no greeting and the filters in one row. -->
      <div v-if="!boardMode || demoMode">
        <a-typography-title v-if="!boardMode" :level="3" style="margin: 0; font-size: 22px">{{ greeting }}</a-typography-title>
        <a-typography-text v-if="demoMode" type="secondary">{{ t('Dashboard_Demo_Subtitle') }}</a-typography-text>
      </div>

      <!-- "Business summary" follows the filter too since 2026-11-12 (user's request; hidden there 11-11 when it changed nothing). -->
      <!-- One height for every control, groups apart, "Todos" first on its own, a Reset at the end and the bar centred when
           nothing else shares the row (user's rules, 2026-11-26). -->
      <div class="dash-filters">
        <div class="df-all" :title="$t('tx_Double_click_a_date_button_to_make_it_your_default_on_every__9834a')" @dblclick="toggleDatePreset('all')">
          <a-button :type="period === 'all' ? 'primary' : 'default'" :disabled="loading" @click="period = 'all'; load()">
            {{ $t(ALL_PRESET.labelKey) }}<StarFilled v-if="savedDatePreset === 'all'" class="def-star" />
          </a-button>
        </div>
        <!-- Same date presets + editable Start date / to as Sales -> List Sales. -->
        <DateRangeFields :value="rangeFields" :disabled="loading" @change="onRangeEdit" />
        <!-- Double-click any filter choice = your default when the Dashboard
             opens (★ marks it; double-click it again to clear). Saved on the
             user's account, so it follows them to every PC. -->
        <div class="preset-wrap">
          <!-- The first click of a double-click already selects the button,
               so the double-click saves the current choice. -->
          <!-- The date default is the user's ONE default for every page with
               these buttons (lib/datePresetDefault.js). -->
          <!-- "Día" (2026-11-24): a calendar day, ‹ › to move it; between the dates and "All". -->
          <DayPresetControl :active="period === 'day'" :single="period === 'today'" :disabled="loading"
            @pick="period = 'day'; load()" />
          <div class="preset-buttons" :title="$t('tx_Double_click_a_date_button_to_make_it_your_default_on_every__9834a')" @dblclick="toggleDatePreset(period)">
            <a-radio-group v-model:value="period" button-style="solid" :disabled="loading" @change="load()">
              <a-radio-button v-for="p in PERIOD_PRESETS" :key="p.value" :value="p.value">
                {{ $t(p.labelKey) }}<StarFilled v-if="savedDatePreset === p.value" class="def-star" />
              </a-radio-button>
            </a-radio-group>
          </div>
          <!-- Phones: the 8 preset buttons don't fit in a row (they overlapped
               the other filters), so the same choice is a dropdown. Kept in
               the presets' own order (All -> Last Year), not alphabetical. -->
          <div class="preset-select" :title="$t('tx_Double_click_a_date_button_to_make_it_your_default_on_every__9834a')" @dblclick="toggleDatePreset(period)">
            <a-select
              :value="period ?? undefined" style="width: 100%" :disabled="loading" :placeholder="$t('CustomRange')"
              @update:value="v => (period = v)"
              :options="RANGE_PRESETS.map(p => ({ value: p.value, label: savedDatePreset === p.value ? `${$t(p.labelKey)} ★` : $t(p.labelKey) }))"
              @change="load()"
            />
          </div>
        </div>
        <div :title="DEFAULT_HINT" @dblclick="toggleDefault('warehouse_id', warehouseId, warehouseLabel(warehouseId))">
          <a-select
            v-model:value="warehouseId"
            class="wh-select"
            :options="warehouseOptions"
            @change="load()"
          />
        </div>
        <!-- Multi-Currency: view the base-currency figures converted -->
        <div v-if="currencyVisible" :title="DEFAULT_HINT" @dblclick="toggleDefault('currency_id', currentCurrencyId, currencyLabel(currentCurrencyId))">
          <ViewCurrencySelect class="wh-select" />
        </div>
        <div class="df-actions">
          <a-button type="primary" :loading="loading" :title="$t('Refresh')" @click="load()">
            <template #icon><ReloadOutlined /></template>
            <template v-if="!boardMode">{{ $t('Refresh') }}</template>
          </a-button>
          <!-- Reset = the user's own defaults (★), else today and every location. -->
          <a-button :disabled="loading" :title="es ? 'Volver a los filtros por defecto' : 'Back to the default filters'" @click="resetFilters">
            <template #icon><UndoOutlined /></template>{{ es ? 'Restablecer' : 'Reset' }}
          </a-button>
        </div>
      </div>
    </div>

    <!-- Demo-data notice -->
    <a-alert
      v-if="demoMode && !loading"
      type="warning"
      show-icon
      style="margin-bottom: 24px"
      :message="$t('Demo_Data_Notice')"
      :description="$t('Demo_Data_Notice_Help')"
    />

    <!-- Full-page loader; content mounts only when data is ready so charts
         never render (and self-mutate their DOM) with empty options. -->
    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <!-- The blocks top to bottom in the company's order (System Settings -> Dashboard). -->
    <div v-else class="dash-blocks" :class="{ 'only-tabs': boardMode && transportTab !== 'transport_general' }">
      <!-- ================= Transport: one tab per panel, the board by default (2026-11-04).
           A tab loads its panel the first time it opens. No bar with only one panel. ========= -->
      <!-- Boards grouped by what they are about, as folder tabs on a strip, the open board on white inside a bordered card, no
           icons, every tab in sight (user's rules, 2026-11-26). The source line goes UNDER each board. -->
      <div v-if="transportTabs.length" class="board-tabs" :class="tabStyle" :style="{ order: Math.min(...transportTabs.map(k => blockPos[k] ?? Infinity)) }">
        <div v-if="transportTabs.length > 1" class="bt-bar" role="tablist">
          <div v-for="g in tabGroups" :key="g.key" class="bt-group">
            <div class="bt-group-label">{{ g.label }}</div>
            <div class="bt-group-tabs">
              <button
                v-for="k in g.tabs" :key="k" type="button" role="tab" class="bt-tab" :class="{ active: transportTab === k }"
                :aria-selected="transportTab === k" :title="tabTitle(k)" @click="transportTab = k"
              >{{ tabLabel(k) }}</button>
            </div>
          </div>
        </div>
        <div class="bt-body" :class="{ alone: transportTabs.length < 2 }">
          <a-tabs v-model:active-key="transportTab" class="transport-tabs" :tab-bar-style="{ display: 'none' }">
            <a-tab-pane v-if="transportTabs.includes('transport_daysales')" key="transport_daysales" :tab="tabLabel('transport_daysales')">
              <TransportDaySalesPanel :range="rangeFields" />
              <ReportSourceBar board="transport_daysales" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <a-tab-pane v-if="transportTabs.includes('transport_production')" key="transport_production" :tab="tabLabel('transport_production')">
              <TransportProductionPanel :range="rangeFields" />
              <ReportSourceBar board="transport_production" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <!-- AuraSoft's Liquidación General (2026-11-24): by point of sale and cashier, with the cash each one hands in. -->
            <a-tab-pane v-if="transportTabs.includes('transport_pointsales')" key="transport_pointsales" :tab="tabLabel('transport_pointsales')">
              <TransportPointSalesPanel :range="rangeFields" />
              <ReportSourceBar board="transport_pointsales" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <!-- AuraSoft's Liquidación Detallada and parcels kardex (2026-11-24). -->
            <a-tab-pane v-if="transportTabs.includes('transport_cashbook')" key="transport_cashbook" :tab="tabLabel('transport_cashbook')">
              <TransportCashbookPanel :range="rangeFields" />
              <ReportSourceBar board="transport_cashbook" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <a-tab-pane v-if="transportTabs.includes('transport_customers')" key="transport_customers" :tab="tabLabel('transport_customers')">
              <TransportCustomersPanel :range="rangeFields" />
              <ReportSourceBar board="transport_customers" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <a-tab-pane v-if="transportTabs.includes('transport_aura_parcels')" key="transport_aura_parcels" :tab="tabLabel('transport_aura_parcels')">
              <TransportAuraParcelsPanel />
              <ReportSourceBar board="transport_aura_parcels" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <!-- Programación (2026-11-26): the week's departures with the seats sold, the forecast and the date's changes. -->
            <a-tab-pane v-if="transportTabs.includes('transport_programming')" key="transport_programming" :tab="tabLabel('transport_programming')">
              <TransportProgrammingPanel />
              <ReportSourceBar board="transport_programming" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <a-tab-pane v-if="transportTabs.includes('transport_departures')" key="transport_departures" :tab="tabLabel('transport_departures')">
              <TransportDeparturesPanel :range="rangeFields" />
              <ReportSourceBar board="transport_departures" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <a-tab-pane v-if="transportTabs.includes('transport_outlook')" key="transport_outlook" :tab="tabLabel('transport_outlook')">
              <TransportOutlookPanel />
              <ReportSourceBar board="transport_outlook" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <a-tab-pane v-if="transportTabs.includes('transport_owner')" key="transport_owner" :tab="tabLabel('transport_owner')">
              <TransportOwnerPanel :range="rangeFields" />
              <ReportSourceBar board="transport_owner" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <a-tab-pane v-if="transportTabs.includes('transport_profit')" key="transport_profit" :tab="tabLabel('transport_profit')">
              <TransportProfitPanel :range="rangeFields" />
              <ReportSourceBar board="transport_profit" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <!-- What the business spends, by kind (2026-11-11): goes with "Profit by route", same permission. -->
            <a-tab-pane v-if="transportTabs.includes('transport_expenses')" key="transport_expenses" :tab="tabLabel('transport_expenses')">
              <TransportExpensesPanel :range="rangeFields" />
              <ReportSourceBar board="transport_expenses" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <a-tab-pane v-if="transportTabs.includes('transport_indicators')" key="transport_indicators" :tab="tabLabel('transport_indicators')">
              <TransportIndicatorsPanel :range="rangeFields" view="profit" />
              <ReportSourceBar board="transport_indicators" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <a-tab-pane v-if="transportTabs.includes('transport_risks')" key="transport_risks" :tab="tabLabel('transport_risks')">
              <TransportIndicatorsPanel :range="rangeFields" view="risks" />
              <ReportSourceBar board="transport_risks" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <a-tab-pane v-if="transportTabs.includes('transport_parcels')" key="transport_parcels" :tab="tabLabel('transport_parcels')">
              <TransportParcelsPanel :range="rangeFields" />
              <ReportSourceBar board="transport_parcels" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <a-tab-pane v-if="transportTabs.includes('transport_claims')" key="transport_claims" :tab="tabLabel('transport_claims')">
              <TransportClaimsPanel :range="period === 'all' ? null : rangeFields" />
              <ReportSourceBar board="transport_claims" :range="period === 'all' ? null : rangeFields" class="bt-source" />
            </a-tab-pane>
            <!-- Live: refreshes itself only while it is open. -->
            <a-tab-pane v-if="transportTabs.includes('transport_monitor')" key="transport_monitor" :tab="tabLabel('transport_monitor')" destroy-inactive-tab-pane>
              <TransportLiveBoard />
              <ReportSourceBar board="transport_monitor" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <a-tab-pane v-if="transportTabs.includes('transport')" key="transport" :tab="tabLabel('transport')">
              <TransportBoard :range="rangeFields" />
              <ReportSourceBar board="transport" :range="rangeFields" class="bt-source" />
            </a-tab-pane>
            <!-- No "General" tab (user's request, 2026-11-11): with the transport panels, the rest of the dashboard is not shown. -->
          </a-tabs>
        </div>
      </div>

      <!-- ================= Stat cards (legacy's two rows of four) ========= -->
      <!-- Cards share the row: as many as there are, max 6 per row (then wrap). -->
      <div v-if="statCards.length" class="card-grid" :style="{ order: blockPos.cards, '--cols': Math.min(statCards.length, 6) }">
        <div v-for="card in statCards" :key="card.label">
          <a-card :hoverable="true" class="stat-card" @click="card.link && $router.push(card.link)">
            <div class="stat-inner">
              <span class="stat-icon" :style="{ background: card.tint }">
                <component :is="card.icon" />
              </span>
              <div>
                <div class="stat-label">{{ card.label }}</div>
                <div class="stat-value">{{ card.money ? money(card.value) : card.value }}</div>
              </div>
            </div>
          </a-card>
        </div>
      </div>

      <!-- ================= Unpaid invoices by age (every open one, to today) ================= -->
      <div v-if="agingCards.length" :style="{ order: blockPos.aging }">
        <div class="aging-title">{{ $t('tx_Unpaid_invoices_by_age_e3a15') }} <span>{{ $t('tx_all_dates_days_since_the_invoice_f66da') }}</span></div>
        <div class="card-grid" :style="{ '--cols': Math.min(agingCards.length, 6) }">
          <div v-for="card in agingCards" :key="card.id">
            <a-card :hoverable="true" class="stat-card" @click="$router.push(card.link)">
              <div class="stat-inner">
                <span class="stat-icon" :style="{ background: card.tint, color: card.color }">
                  <ClockCircleOutlined />
                </span>
                <div>
                  <div class="stat-label">{{ card.label }} ({{ card.count }})</div>
                  <div class="stat-value">{{ money(card.value) }}</div>
                </div>
              </div>
            </a-card>
          </div>
        </div>
      </div>

      <!-- ================= Sales/Purchases + Top selling ================= -->
      <a-row v-if="show('chart_sales_purchases') || show('chart_top_selling')" :gutter="[16, 16]" :style="{ order: blockPos.sales_charts }">
        <a-col v-if="show('chart_sales_purchases')" :xs="24" :xl="show('chart_top_selling') ? 16 : 24">
          <a-card class="chart-card" :title="$t('Sales_And_Purchases')">
            <apexchart v-if="salesChart.series.length" :key="'bar-' + loadCount" type="bar" height="320" :options="salesChart.options" :series="salesChart.series" />
            <a-empty v-else :description="$t('No_data_available')" style="padding: 48px 0" />
          </a-card>
        </a-col>
        <a-col v-if="show('chart_top_selling')" :xs="24" :xl="show('chart_sales_purchases') ? 8 : 24">
          <a-card class="chart-card" :title="$t('Top_Selling_Products')">
            <apexchart v-if="donutChart.series.length" :key="'donut-' + loadCount" type="donut" height="320" :options="donutChart.options" :series="donutChart.series" />
            <a-empty v-else :description="$t('No_data_available')" style="padding: 48px 0" />
          </a-card>
        </a-col>
      </a-row>

      <!-- ================= Payment area + Top customers ================= -->
      <a-row v-if="show('chart_payment_sent_received') || show('chart_top_customers')" :gutter="[16, 16]" :style="{ order: blockPos.payment_charts }">
        <a-col v-if="show('chart_payment_sent_received')" :xs="24" :xl="show('chart_top_customers') ? 12 : 24">
          <a-card class="chart-card" :title="$t('Payment_Sent_Received')">
            <apexchart v-if="paymentChart.series.length" :key="'area-' + loadCount" type="area" height="300" :options="paymentChart.options" :series="paymentChart.series" />
            <a-empty v-else :description="$t('No_data_available')" style="padding: 48px 0" />
          </a-card>
        </a-col>
        <a-col v-if="show('chart_top_customers')" :xs="24" :xl="show('chart_payment_sent_received') ? 12 : 24">
          <a-card class="chart-card" :title="$t('Top_Customers')">
            <apexchart v-if="customerChart.series.length" :key="'pie-' + loadCount" type="pie" height="300" :options="customerChart.options" :series="customerChart.series" />
            <a-empty v-else :description="$t('No_data_available')" style="padding: 48px 0" />
          </a-card>
        </a-col>
      </a-row>

      <!-- ================= Sales by payment + Stock value ================= -->
      <!-- align=stretch so the right column matches the taller left card; the
           Quick actions card then grows to fill any leftover height. -->
      <a-row v-if="show('sales_by_payment') || show('stock_value') || (show('quick_actions') && quickActions.length)" :gutter="[16, 16]" align="stretch" :style="{ order: blockPos.payments_stock }">
        <a-col v-if="show('sales_by_payment')" :xs="24" :lg="12">
          <a-card class="fill-card" :title="$t('Sales_by_Payment')">
            <div class="paylist">
              <div v-for="p in payments" :key="p.name" class="pay-row">
                <span class="pay-icon" :style="{ background: p.color }">
                  <component :is="payIcon(p.name)" />
                </span>
                <div class="pay-main">
                  <div class="pay-top">
                    <span class="pay-name">{{ p.name }}</span>
                    <span class="pay-amount">{{ money(p.amount) }}</span>
                  </div>
                  <div class="pay-track">
                    <span class="pay-fill" :style="{ width: Math.min(p.percentage, 100) + '%', background: p.color }"></span>
                  </div>
                </div>
                <span class="pay-pct">{{ Math.round(p.percentage) }}%</span>
              </div>
            </div>
            <a-empty v-if="!payments.length" :description="$t('No_data_available')" />
          </a-card>
        </a-col>
        <a-col v-if="show('stock_value') || (show('quick_actions') && quickActions.length)" :xs="24" :lg="show('sales_by_payment') ? 12 : 24" class="dash-right">
          <a-card v-if="show('stock_value')" :title="$t('Stock_Value')">
            <template #extra>
              <a-tooltip :title="$t('Stock_Valuation_Hint')">
                <InfoCircleOutlined style="color: rgba(0,0,0,0.35)" />
              </a-tooltip>
            </template>
            <div class="stockval">
              <div
                v-for="s in stockItems" :key="s.label"
                class="stockval-row" :style="{ background: s.tint }"
              >
                <span class="stockval-icon" :style="{ background: s.color }">
                  <component :is="s.icon" />
                </span>
                <div class="stockval-text">
                  <div class="stockval-label">{{ s.label }}</div>
                  <div class="stockval-value" :style="{ color: s.color }">{{ money(s.value) }}</div>
                </div>
                <span class="stockval-bar" :style="{ background: s.color, width: s.width }"></span>
              </div>
            </div>
          </a-card>

          <!-- Quick actions — grows to fill the space under Stock value so the
               column bottom lines up with Sales by payment. Desktop take on the
               legacy dashboard's quick-module shortcuts. -->
          <a-card v-if="show('quick_actions') && quickActions.length" :title="$t('Quick_Actions')" class="qa-card">
            <div class="qa-grid">
              <button
                v-for="a in quickActions" :key="a.label"
                class="qa-tile" @click="$router.push(a.to)"
              >
                <span class="qa-icon" :style="{ color: a.color, background: a.tint }">
                  <component :is="a.icon" />
                </span>
                <span class="qa-label">{{ a.label }}</span>
              </button>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <!-- ================= Stock alert + Top selling ================= -->
      <a-row v-if="show('table_stock_alert') || show('table_top_selling_products')" :gutter="[16, 16]" :style="{ order: blockPos.tables }">
        <a-col v-if="show('table_stock_alert')" :xs="24" :xl="show('table_top_selling_products') ? 12 : 24">
          <a-card :title="$t('StockAlert')">
            <template #extra>
              <a-button type="link" @click="$router.push('/products')">{{ $t('View_all') }}</a-button>
            </template>
            <div v-if="stockAlerts.length" class="alertlist">
              <div v-for="a in alertItems" :key="a.code" class="alert-row">
                <span class="alert-icon"><WarningOutlined /></span>
                <div class="alert-main">
                  <div class="alert-top">
                    <span class="alert-name">{{ a.name }}</span>
                    <span class="alert-qty">{{ a.quantity }} / {{ a.stock_alert }}</span>
                  </div>
                  <div class="alert-meta">
                    <span>{{ a.code }}</span>
                    <span v-if="a.warehouse" class="alert-wh"><ShopOutlined /> {{ a.warehouse }}</span>
                  </div>
                  <div class="alert-track">
                    <span class="alert-fill" :style="{ width: a.width, background: a.color }"></span>
                  </div>
                </div>
              </div>
            </div>
            <a-empty v-else :description="$t('No_data_available')" />
          </a-card>
        </a-col>
        <a-col v-if="show('table_top_selling_products')" :xs="24" :xl="show('table_stock_alert') ? 12 : 24">
          <a-card :title="$t('Top_Selling_Products')">
            <div v-if="topProducts.length" class="toplist">
              <div v-for="(p, i) in topItems" :key="i" class="top-row">
                <span class="top-rank" :class="'rank-' + (i + 1)">{{ i + 1 }}</span>
                <div class="top-main">
                  <div class="top-name">{{ p.name }}</div>
                  <div class="top-track">
                    <span class="top-fill" :style="{ width: p.width }"></span>
                  </div>
                </div>
                <div class="top-figures">
                  <div class="top-amount">{{ money(p.total) }}</div>
                  <div class="top-sales">{{ p.total_sales }} {{ $t('Sold') }}</div>
                </div>
              </div>
            </div>
            <a-empty v-else :description="$t('No_data_available')" />
          </a-card>
        </a-col>
      </a-row>

      <!-- ================= Recent sales ================= -->
      <a-card v-if="show('table_recent_sales')" :style="{ order: blockPos.recent_sales }" :title="$t('Recent_Sales')">
        <template #extra>
          <a-button type="link" @click="$router.push('/sales')">{{ $t('View_all') }}</a-button>
        </template>
        <a-table
          :columns="columns"
          :data-source="sales"
          row-key="Ref"
          size="middle"
          :pagination="{ pageSize: 5, showSizeChanger: false }"
          :scroll="{ x: 'max-content' }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'statut'">
              <a-tag :color="tagColor(record.statut)">{{ record.statut }}</a-tag>
            </template>
            <template v-else-if="column.key === 'payment_status'">
              <a-tag :color="tagColor(record.payment_status)">{{ record.payment_status }}</a-tag>
            </template>
            <template v-else-if="MONEY_COLS.includes(column.key)">
              {{ money(record[column.key]) }}
            </template>
          </template>
        </a-table>
      </a-card>
    </div>

    <!-- Start page "customized by each user" (Defaults -> Users): the user's
         6 most used pages as shortcuts, and what opens after login. At the end
         of the page (user's request, 2026-11-01). -->
    <a-card v-if="customStart" v-show="!boardMode || transportTab === 'transport_general'" size="small" class="lu-card" style="margin-top: 16px">
      <template #title>
        <span><HistoryOutlined /> {{ tf('Shortcuts', 'Shortcuts') }}</span>
      </template>
      <template #extra>
        <!-- Buttons + what opens after sign-in are set on the Home Buttons page. -->
        <a-button size="small" @click="$router.push('/home-buttons')"><SettingOutlined /> {{ startPrefsLabel }}</a-button>
      </template>
      <div v-if="recentPages.length" class="lu-grid">
        <button v-for="p in recentPages" :key="p.path" type="button" class="lu-tile" @click="$router.push(p.path)">
          <span class="lu-icon"><component :is="p.icon" v-if="p.icon" :size="22" /><AppstoreOutlined v-else /></span>
          <span class="lu-label">{{ pageLabel(p) }}</span>
        </button>
      </div>
      <div v-else class="lu-empty">
        {{ $t('tx_No_shortcuts_yet_an_admin_can_choose_them_in_System_Settings_0ec2b') }}
      </div>
    </a-card>
  </div>
</template>

<script setup>
import { t as tEs } from '../i18n/index.js';
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import TransportOutlookPanel from '../components/TransportOutlookPanel.vue';
import { DATE_PRESETS, presetDates, presetOf } from '../lib/datePresets';
import DayPresetControl from '../components/DayPresetControl.vue';
// "Día" has its own control (DayPresetControl); the buttons are the ranges.
const RANGE_PRESETS = DATE_PRESETS.filter(p => p.value !== 'day');
// "Todos" stands apart on the left (2026-11-26); the phone's dropdown keeps the whole list.
const ALL_PRESET = RANGE_PRESETS.find(p => p.value === 'all') || { value: 'all', labelKey: 'All' };
const PERIOD_PRESETS = RANGE_PRESETS.filter(p => p.value !== 'all');
import DateRangeFields from '../components/DateRangeFields.vue';
import {
  ReloadOutlined, ShoppingCartOutlined, ShoppingOutlined, RollbackOutlined,
  UndoOutlined, DollarOutlined, WalletOutlined, FileTextOutlined, RiseOutlined,
  TagOutlined, AppstoreOutlined, InfoCircleOutlined,
  CreditCardOutlined, BankOutlined, WarningOutlined, ShopOutlined,
  CalculatorOutlined, TeamOutlined, ExclamationCircleOutlined, ClockCircleOutlined,
  LineChartOutlined, FundOutlined, AimOutlined, CarOutlined, BarChartOutlined, AuditOutlined, ScheduleOutlined, InboxOutlined, CalendarOutlined, TableOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import http from '../lib/http';
import { useAuthStore } from '../stores/auth';
import { useFormat } from '../composables/useFormat';
import { blockOrder } from '../config/dashboardSections';
import { moduleKeyForPath } from '../config/modules';
import ViewCurrencySelect from '../components/ViewCurrencySelect.vue';
import TransportBoard from '../components/TransportBoard.vue';
import TransportClaimsPanel from '../components/TransportClaimsPanel.vue';
import TransportDeparturesPanel from '../components/TransportDeparturesPanel.vue';
import TransportIndicatorsPanel from '../components/TransportIndicatorsPanel.vue';
import TransportLiveBoard from '../components/TransportLiveBoard.vue';
import TransportExpensesPanel from '../components/TransportExpensesPanel.vue';
import TransportOwnerPanel from '../components/TransportOwnerPanel.vue';
import TransportParcelsPanel from '../components/TransportParcelsPanel.vue';
import TransportProfitPanel from '../components/TransportProfitPanel.vue';
import TransportProductionPanel from '../components/TransportProductionPanel.vue';
import TransportPointSalesPanel from '../components/TransportPointSalesPanel.vue';
import TransportCashbookPanel from '../components/TransportCashbookPanel.vue';
import TransportAuraParcelsPanel from '../components/TransportAuraParcelsPanel.vue';
import TransportCustomersPanel from '../components/TransportCustomersPanel.vue';
import TransportDaySalesPanel from '../components/TransportDaySalesPanel.vue';
import TransportProgrammingPanel from '../components/TransportProgrammingPanel.vue';
import ReportSourceBar from '../components/ReportSourceBar.vue';
import { useUiStore } from '../stores/ui';
import { HistoryOutlined, SettingOutlined, StarFilled } from '@ant-design/icons-vue';
import { message } from 'ant-design-vue';
import { getUserPref, setUserPref } from '../lib/userPrefs';
import { DATE_DEFAULT_KEY, savedDatePreset, startPreset, toggleDatePreset } from '../lib/datePresetDefault';
import { viewCurrencies, viewCurrencyId, viewBaseCurrencyId } from '../lib/viewCurrencyState';
import { shortcuts, pageLabel, pageFullLabel, pageOptions } from '../lib/startPage';
import { sortByLabel } from '../lib/filterOptions';
import { t as tf } from '../i18n';

const { t } = useI18n();
const auth = useAuthStore();

/* ------------------------------------------- sections / start page (Defaults -> Users) */
// Dashboard cards / charts / tables the company switched off.
const hiddenSections = computed(() => new Set(auth.user?.dashboard_hidden_sections || []));
// Block id -> its position top to bottom (System Settings -> Dashboard).
const blockPos = computed(() => Object.fromEntries(blockOrder(auth.user?.dashboard_section_order).map((id, i) => [id, i])));
// (The old single 'stat_cards' switch, if saved, still hides every card.)
const show = id => !hiddenSections.value.has(id) && !(id.startsWith('card_') && hiddenSections.value.has('stat_cards'));
// Transport board: the company's module on and a role that sees Transport.
const transportBoard = computed(() => show('transport_board') && auth.moduleEnabled('transport')
  && (auth.can('transport_view') || auth.can('transport_manage')));
const profitPanel = computed(() => show('transport_profit') && auth.moduleEnabled('transport') && auth.can('transport_profit'));
const departuresPanel = computed(() => show('transport_departures') && auth.moduleEnabled('transport') && auth.can('transport_profit'));
const monitorPanel = computed(() => show('transport_monitor') && auth.moduleEnabled('transport') && auth.can('transport_monitor'));
const parcelsPanel = computed(() => show('transport_parcels') && auth.moduleEnabled('transport') && auth.can('transport_profit'));
const claimsPanel = computed(() => show('transport_claims') && auth.moduleEnabled('transport') && auth.moduleEnabled('forms')
  && (auth.can('forms_view') || auth.can('forms_manage')));
const ownerPanel = computed(() => show('transport_owner') && auth.can('dashboard') && auth.moduleEnabled('transport')
  && (auth.can('transport_view') || auth.can('transport_manage')));
const es = computed(() => useUiStore().locale === 'es');
// Same order as the tabs (the owner's reading order, 2026-11-11); the first one the user has opens by default.
const outlookPanel = computed(() => show('transport_outlook') && auth.moduleEnabled('transport') && (auth.can('transport_outlook') || auth.can('transport_profit')));
// Roles -> "Dashboard tabs" (2026-11-19, Turismo: each role its own board): only those, when the role has a list.
// Programación (2026-11-26, the user: every role sees it): any transport role.
const programmingPanel = computed(() => show('transport_programming') && auth.moduleEnabled('transport')
  && ['transport_view', 'transport_profit', 'transport_schedule', 'transport_monitor', 'transport_manage'].some(p => auth.can(p)));
const roleTabs = computed(() => (Array.isArray(auth.user?.dashboard_tabs) && auth.user.dashboard_tabs.length ? auth.user.dashboard_tabs : null));
// The boards grouped by what they are about (user's request, 2026-11-26: "ordenamos los tabs por grupo o entidad"), in this
// order; the first board the user has opens by default.
// Three groups, by who reads them (user, 2026-11-26): Gerencia, Administración, Operaciones; short names so they fit one row.
const TAB_GROUPS = [
  { key: 'mgmt', en: 'Management', es: 'Gerencia', tabs: ['transport_owner', 'transport_profit', 'transport_indicators', 'transport_risks', 'transport_outlook'] },
  { key: 'admin', en: 'Administration', es: 'Administración', tabs: ['transport_daysales', 'transport_pointsales', 'transport_cashbook', 'transport_customers', 'transport_expenses'] },
  { key: 'ops', en: 'Operations', es: 'Operaciones', tabs: ['transport_production', 'transport_programming', 'transport_departures', 'transport_monitor', 'transport', 'transport_aura_parcels', 'transport_parcels', 'transport_claims'] },
];
const TAB_LABELS = {
  transport_owner: ['Summary', 'Resumen'], transport_profit: ['Profit', 'Rentabilidad'], transport_indicators: ['Indicators', 'Indicadores'],
  transport_risks: ['Risks', 'Riesgos'], transport_outlook: ['Outlook', 'Mando'],
  transport_daysales: ['Sales', 'Ventas'], transport_pointsales: ['Points of sale', 'Pto. venta'], transport_cashbook: ['Cash', 'Caja'],
  transport_customers: ['Customers', 'Clientes'], transport_expenses: ['Expenses', 'Gastos'],
  transport_production: ['Production', 'Producción'], transport_programming: ['Programming', 'Programación'], transport_departures: ['Departures', 'Salidas'], transport_monitor: ['Control', 'Control'],
  transport: ['Transport', 'Transporte'], transport_aura_parcels: ['AuraSoft', 'AuraSoft'], transport_parcels: ['Parcels', 'Encomiendas'],
  transport_claims: ['Complaints', 'Reclamos'],
};
// The full name, shown when the mouse rests on a tab.
const TAB_TITLES = {
  transport_owner: 'Resumen del negocio', transport_profit: 'Rentabilidad por ruta', transport_outlook: 'Mando operativo',
  transport_daysales: 'Ventas del día', transport_pointsales: 'Ventas por punto de venta', transport_cashbook: 'Caja y clientes',
  transport_production: 'Producción por bus', transport_departures: 'Reporte de salidas', transport_monitor: 'Tablero de control',
  transport: 'Tablero de transporte', transport_aura_parcels: 'Encomiendas AuraSoft',
};
// The board tabs' look: navy bar, orange open tab (the user's pick, 2026-11-26).
const tabStyle = 'bt-style-d';
const tabTitle = k => (es.value ? TAB_TITLES[k] : null) || null;
const tabLabel = k => (TAB_LABELS[k] ? TAB_LABELS[k][es.value ? 1 : 0] : k);
const panelOn = {
  transport_daysales: () => profitPanel.value, transport_pointsales: () => profitPanel.value, transport_cashbook: () => profitPanel.value,
  transport_customers: () => profitPanel.value, transport_production: () => profitPanel.value, transport_programming: () => programmingPanel.value, transport_departures: () => departuresPanel.value,
  transport_outlook: () => outlookPanel.value, transport_monitor: () => monitorPanel.value, transport: () => transportBoard.value,
  transport_owner: () => ownerPanel.value, transport_profit: () => profitPanel.value, transport_expenses: () => profitPanel.value,
  transport_indicators: () => profitPanel.value, transport_risks: () => profitPanel.value, transport_aura_parcels: () => profitPanel.value,
  transport_parcels: () => parcelsPanel.value, transport_claims: () => claimsPanel.value,
};
const transportPanels = computed(() => TAB_GROUPS.flatMap(g => g.tabs).filter(k => panelOn[k]())
  .filter(k => !roleTabs.value || roleTabs.value.includes(k)));
const tabGroups = computed(() => TAB_GROUPS.map(g => ({ key: g.key, label: es.value ? g.es : g.en, tabs: g.tabs.filter(k => transportPanels.value.includes(k)) }))
  .filter(g => g.tabs.length));
const boardMode = computed(() => transportPanels.value.length > 0);
const transportTabs = computed(() => (boardMode.value ? [...transportPanels.value] : []));
// ?tab=transport_outlook (a role's home page or a link) opens that tab.
const transportPick = ref(typeof useRoute().query.tab === 'string' ? useRoute().query.tab : null);
const transportTab = computed({
  // Opens on the day's sales when the user has it (Mayte's first board, 2026-11-23), else the first board.
  // A role with its own tab list opens on the first one of it (2026-11-26: Operaciones opens on Programación).
  get: () => (transportTabs.value.includes(transportPick.value) ? transportPick.value
    : (roleTabs.value?.length && transportTabs.value.includes(roleTabs.value[0]) ? roleTabs.value[0]
      : (transportTabs.value.includes('transport_daysales') ? 'transport_daysales' : transportTabs.value[0]))),
  set: v => { transportPick.value = v; },
});
// The General tab's charts were drawn hidden: they need their width back when it opens.
watch(transportTab, () => nextTick(() => window.dispatchEvent(new Event('resize'))));

const customStart = computed(() => auth.user?.start_page_mode === 'custom');
// Admin-picked shortcut buttons, empty slots filled with the user's most used forms.
const recentPages = computed(() => shortcuts(auth, 8));
const formOptions = computed(() => sortByLabel(pageOptions(auth).map(p => ({ value: p.path, label: pageFullLabel(p) }))));
const startPrefsLabel = computed(() => {
  const p = auth.user?.start_preferences || {};
  if (p.mode !== 'form' || !p.form) return 'Home Buttons';
  const opt = formOptions.value.find(o => o.value === p.form);
  return 'Home Buttons · after sign-in: ' + (opt ? opt.label : p.form);
});
// Currency/precision/separators come from the user's settings, like the legacy
// app. money() follows the shared Multi-Currency display selector
// (ViewCurrencySelect) automatically.
const { money } = useFormat();

/* ------------------------------------------------------------------ state */
const loading = ref(true);
const loadCount = ref(0); // bumps per fetch; keys charts so they remount cleanly
const demoMode = ref(false);
// Filter defaults (double-click a choice): Location / Currency are this
// page's own {warehouse_id, currency_id} on the user's account
// (users.ui_preferences via lib/userPrefs); the date preset is the user's
// default for every page (lib/datePresetDefault.js).
const DEFAULTS_KEY = 'dashboard_filter_defaults';
const DEFAULT_HINT = t('tx_Double_click_to_make_this_your_default_again_to_clear_2d3cd');
const defaults = ref({ ...(getUserPref(DEFAULTS_KEY) || {}) });
// A Dashboard-only date default saved before it became global: promote it.
if (defaults.value.period) {
  if (!savedDatePreset.value) setUserPref(DATE_DEFAULT_KEY, defaults.value.period);
  delete defaults.value.period;
  setUserPref(DEFAULTS_KEY, Object.keys(defaults.value).length ? { ...defaults.value } : null);
}
const period = ref(startPreset('today'));
const warehouseId = ref(defaults.value.warehouse_id ?? '');
/** Reset (2026-11-26): the user's defaults (★), else today and every location. */
function resetFilters() {
  period.value = startPreset('today');
  warehouseId.value = defaults.value.warehouse_id ?? '';
  load();
}
// The currency choice is shared by every report page: only start from the
// default when nothing was picked yet this session.
if (defaults.value.currency_id != null && viewCurrencyId.value == null) viewCurrencyId.value = defaults.value.currency_id;
const currencyVisible = computed(() => auth.multiCurrencyEnabled && viewCurrencies.value.length > 1);
const currentCurrencyId = computed(() => viewCurrencyId.value ?? viewBaseCurrencyId.value);

function toggleDefault(field, value, label) {
  const d = { ...defaults.value };
  if (d[field] === value) {
    delete d[field];
    message.info(`${label}: no longer your default`);
  } else {
    d[field] = value;
    message.success(`${label} is now your default`);
  }
  defaults.value = d;
  setUserPref(DEFAULTS_KEY, Object.keys(d).length ? d : null);
}
const starred = (field, value, label) => (defaults.value[field] === value ? `${label} ★` : label);
const warehouseLabel = v => (v === '' ? t('All_Warehouses') : warehouses.value.find(w => w.id === v)?.name || String(v));
const currencyLabel = v => viewCurrencies.value.find(c => Number(c.id) === Number(v))?.code || String(v);
const warehouses = ref([]);
const report = ref({});
const payments = ref([]);
const stockValue = ref({ by_cost: 0, by_retail: 0, by_wholesale: 0 });
const sales = ref([]);
const stockAlerts = ref([]);
const topProducts = ref([]);
const salesChart = ref({ series: [], options: {} });
const donutChart = ref({ series: [], options: {} });
const paymentChart = ref({ series: [], options: {} });
const customerChart = ref({ series: [], options: {} });


// Time-of-day greeting: morning < 12:00, afternoon < 18:00, evening after.
const greeting = computed(() => {
  const h = new Date().getHours();
  const part = h < 12 ? t('Good_Morning') : h < 18 ? t('Good_Afternoon') : t('Good_Evening');
  return auth.username ? `${part}, ${auth.username}` : part;
});

const warehouseOptions = computed(() => [
  { value: '', label: starred('warehouse_id', '', t('All_Warehouses')) },
  ...sortByLabel(warehouses.value.map(w => ({ value: w.id, label: starred('warehouse_id', w.id, w.name) }))),
]);

/* ------------------------------------------------------------ formatting */
function tagColor(status) {
  const s = String(status || '').toLowerCase();
  if (['completed', 'received', 'paid'].includes(s)) return 'success';
  if (['pending', 'partial'].includes(s)) return 'warning';
  if (['ordered', 'sent'].includes(s)) return 'processing';
  if (['cancelled', 'canceled', 'unpaid'].includes(s)) return 'error';
  return 'default';
}

const PAYMENT_COLORS = { orange: '#fa8c16', blue: '#1677ff', green: '#52c41a', grey: '#8c8c8c' };

/** An icon that fits the payment method name (cash / card / bank / cheque). */
function payIcon(name) {
  const s = String(name || '').toLowerCase();
  if (s.includes('card')) return CreditCardOutlined;
  if (s.includes('bank') || s.includes('transfer')) return BankOutlined;
  if (s.includes('cheque') || s.includes('check')) return FileTextOutlined;
  return WalletOutlined; // cash and anything else
}
const MONEY_COLS = ['GrandTotal', 'paid_amount', 'due'];

/* The two legacy stat-card rows (four each), in order. `money: false` for the
   plain invoice count. Each links to the matching list page. */
const statCards = computed(() => {
  const r = report.value;
  const n = k => Number(r[k]) || 0;
  // `id` = the card's switch in Defaults -> Users -> Dashboard sections.
  return [
    { id: 'card_sales', label: t('Sales'), value: n('today_sales'), money: true, icon: ShoppingCartOutlined, tint: 'rgba(109,40,217,0.12)', link: '/sales' },
    { id: 'card_purchases', label: t('Purchases'), value: n('today_purchases'), money: true, icon: ShoppingOutlined, tint: 'rgba(22,119,255,0.12)', link: '/purchases' },
    { id: 'card_sales_returns', label: t('SalesReturn'), value: n('return_sales'), money: true, icon: RollbackOutlined, tint: 'rgba(250,140,22,0.12)', link: '/sale-returns' },
    { id: 'card_purchase_returns', label: t('PurchasesReturn'), value: n('return_purchases'), money: true, icon: UndoOutlined, tint: 'rgba(245,34,45,0.12)', link: '/purchase-returns' },
    { id: 'card_sales_due', label: t('Sales_Due'), value: n('sales_due'), money: true, icon: DollarOutlined, tint: 'rgba(250,140,22,0.12)', link: '/sales' },
    { id: 'card_purchase_due', label: t('Purchase_Due'), value: n('purchase_due'), money: true, icon: WalletOutlined, tint: 'rgba(22,119,255,0.12)', link: '/purchases' },
    { id: 'card_invoices', label: t('Invoices'), value: n('today_invoices'), money: false, icon: FileTextOutlined, tint: 'rgba(140,140,140,0.14)', link: '/sales' },
    // Invoices with no payment at all: amount owed, how many in the label; opens them in Sales.
    {
      id: 'card_unpaid_invoices', label: `${t('Unpaid_Invoices') === 'Unpaid_Invoices' ? 'Unpaid invoices' : t('Unpaid_Invoices')} (${n('unpaid_invoices')})`,
      value: n('unpaid_invoices_amount'), money: true, icon: ExclamationCircleOutlined, tint: 'rgba(245,34,45,0.12)',
      link: `/sales?payment_statut=unpaid${activeRange.value.from ? `&date_from=${activeRange.value.from}&date_to=${activeRange.value.to}` : ''}`,
    },
    { id: 'card_profit', label: t('Profit'), value: n('today_profit'), money: true, icon: RiseOutlined, tint: 'rgba(82,196,26,0.14)', link: '/reports/profit-and-loss' },
  ].filter(c => show(c.id));
});

/* Unpaid invoices by age: each card = how many + amount owed; click = those
   invoices in Sales (Unpaid + the invoice dates of that age band). */
const agingCards = computed(() => {
  const r = report.value;
  const day = n => dayjs().subtract(n, 'day').format('YYYY-MM-DD');
  const link = (from, to) => `/sales?payment_statut=unpaid&date_from=${from}&date_to=${to}`;
  return [
    { id: 'card_unpaid_0_30', key: '0_30', label: tEs('tx_0_30_days_8fd12', '0-30 days'), from: day(30), to: day(0), tint: 'rgba(250,173,20,0.14)', color: '#d48806' },
    { id: 'card_unpaid_31_60', key: '31_60', label: tEs('tx_31_60_days_566c3', '31-60 days'), from: day(60), to: day(31), tint: 'rgba(250,140,22,0.14)', color: '#d46b08' },
    { id: 'card_unpaid_61_90', key: '61_90', label: tEs('tx_61_90_days_3f910', '61-90 days'), from: day(90), to: day(61), tint: 'rgba(245,34,45,0.12)', color: '#cf1322' },
    // Over 90 days: from the first day there is (2000) to 91 days ago.
    { id: 'card_unpaid_90_plus', key: '90_plus', label: tEs('tx_Over_90_days_41a4a', 'Over 90 days'), from: '2000-01-01', to: day(91), tint: 'rgba(168,7,26,0.16)', color: '#a8071a' },
  ].filter(c => show(c.id)).map(c => ({
    ...c,
    count: Number(r[`unpaid_age_${c.key}`]) || 0,
    value: Number(r[`unpaid_age_${c.key}_amount`]) || 0,
    link: link(c.from, c.to),
  }));
});

const stockItems = computed(() => {
  const v = stockValue.value;
  const rows = [
    { label: t('By_Cost'), value: Number(v.by_cost) || 0, icon: DollarOutlined, color: '#6d28d9', tint: 'rgba(109,40,217,0.06)' },
    { label: t('By_Retail'), value: Number(v.by_retail) || 0, icon: TagOutlined, color: '#1677ff', tint: 'rgba(22,119,255,0.06)' },
    { label: t('By_Wholesale'), value: Number(v.by_wholesale) || 0, icon: AppstoreOutlined, color: '#52c41a', tint: 'rgba(82,196,26,0.06)' },
  ];
  // Bar width is each value relative to the largest, so the three compare visually.
  const max = Math.max(1, ...rows.map(r => r.value));
  return rows.map(r => ({ ...r, width: `${Math.round((r.value / max) * 100)}%` }));
});

// Low-stock rows with a depletion bar (qty vs alert threshold) and a severity
// colour — the emptier, the redder.
const alertItems = computed(() =>
  stockAlerts.value.map(a => {
    const qty = Number(a.quantity) || 0;
    const threshold = Number(a.stock_alert) || 0;
    const ratio = threshold > 0 ? qty / threshold : 0;
    return {
      ...a,
      width: `${Math.min(Math.round(ratio * 100), 100)}%`,
      color: ratio <= 0.34 ? '#f5222d' : '#fa8c16',
    };
  })
);

// Quick actions — only those the user can perform and whose module is on for
// the company (auth.can fail-opens in the offline demo, so all show there).
const quickActions = computed(() => [
  { label: t('Pos'), perm: 'Pos_view', to: '/pos', icon: CalculatorOutlined, color: '#6d28d9', tint: 'rgba(109,40,217,0.12)' },
  { label: t('New_Sale'), perm: 'Sales_add', to: '/sales/create', icon: ShoppingCartOutlined, color: '#1677ff', tint: 'rgba(22,119,255,0.12)' },
  { label: t('New_Purchase'), perm: 'Purchases_add', to: '/purchases/create', icon: ShoppingOutlined, color: '#fa8c16', tint: 'rgba(250,140,22,0.12)' },
  { label: t('Add_Product'), perm: 'products_add', to: '/products/create', icon: AppstoreOutlined, color: '#52c41a', tint: 'rgba(82,196,26,0.12)' },
  { label: t('Add_Customer'), perm: 'Customers_add', to: '/customers/create', icon: TeamOutlined, color: '#eb2f96', tint: 'rgba(235,47,150,0.12)' },
  { label: t('Reports'), perm: 'Reports_profit', to: '/reports/profit-and-loss', icon: RiseOutlined, color: '#13c2c2', tint: 'rgba(19,194,194,0.12)' },
].filter(a => auth.can(a.perm) && (!moduleKeyForPath(a.to) || auth.moduleEnabled(moduleKeyForPath(a.to)))));

// Top products with a bar scaled to the highest amount.
const topItems = computed(() => {
  const max = Math.max(1, ...topProducts.value.map(p => Number(p.total) || 0));
  return topProducts.value.map(p => ({
    ...p,
    width: `${Math.round(((Number(p.total) || 0) / max) * 100)}%`,
  }));
});

const columns = computed(() => [
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('Warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Status'), dataIndex: 'statut', key: 'statut' },
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', align: 'right' },
  { title: t('Paid'), dataIndex: 'paid_amount', key: 'paid_amount', align: 'right' },
  { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right' },
  { title: t('Payment'), dataIndex: 'payment_status', key: 'payment_status' },
]);

/* ------------------------------------------------------------------ dates */
// Same presets as Sales / Purchases / POS Sales (lib/datePresets.js), default Today.
// "All" = from the first sale on record (the server works it out: range=all).
const rangeFor = presetDates;
// Start date / to: the days the active preset covers ("All": as returned by
// the server). Editing them = a custom range (period null, no button active).
const allRange = ref({ from: '', to: '' });
const customRange = ref({ from: '', to: '' });
const queryRange = () => (period.value ? rangeFor(period.value) : customRange.value);
const activeRange = computed(() => (period.value === 'all' ? allRange.value : queryRange()));
const rangeFields = computed(() => (activeRange.value.from ? [activeRange.value.from, activeRange.value.to] : null));
function onRangeEdit(range) {
  period.value = range ? presetOf(range[0], range[1]) : 'all';
  if (!period.value) customRange.value = { from: range[0], to: range[1] };
  load();
}

/* ------------------------------------------------------------------ charts */
// Neutral chart chrome that reads correctly on both light and dark surfaces.
const CHART_BASE = { background: 'transparent', foreColor: '#8c8c8c', fontFamily: 'inherit', toolbar: { show: false } };
const GRID = { borderColor: 'rgba(128,128,128,0.2)', strokeDashArray: 4 };

// Cohesive, modern categorical palette shared across every dashboard chart.
const PALETTE = ['#6366f1', '#22d3ee', '#f59e0b', '#ec4899', '#10b981', '#8b5cf6', '#0ea5e9', '#f43f5e'];

function buildCharts(days, salesData, purchasesData, products, customers, pay) {
  salesChart.value = {
    series: [
      { name: t('Sales'), data: salesData },
      { name: t('Purchases'), data: purchasesData },
    ],
    options: {
      chart: { ...CHART_BASE, type: 'bar' },
      colors: ['#6366f1', '#22d3ee'],
      plotOptions: { bar: { columnWidth: '45%', borderRadius: 5, borderRadiusApplication: 'end' } },
      fill: {
        type: 'gradient',
        gradient: { shade: 'light', type: 'vertical', shadeIntensity: 0.2, opacityFrom: 0.95, opacityTo: 0.8, stops: [0, 100] },
      },
      dataLabels: { enabled: false },
      legend: { labels: { colors: '#595959' } },
      xaxis: { categories: days, axisBorder: { show: false }, axisTicks: { show: false } },
      grid: GRID,
      tooltip: { theme: 'light' },
    },
  };
  donutChart.value = {
    series: products.map(p => p.value),
    options: {
      chart: { ...CHART_BASE, type: 'donut' },
      labels: products.map(p => p.name),
      colors: PALETTE,
      stroke: { colors: ['#ffffff'], width: 3 },
      legend: { position: 'bottom', labels: { colors: '#595959' } },
      dataLabels: { enabled: false },
      plotOptions: { pie: { donut: { size: '72%' } } },
      tooltip: { theme: 'light' },
    },
  };
  // Payment sent / received — smooth area, red vs green like legacy.
  paymentChart.value = {
    series: [
      { name: t('Sent'), data: pay.sent },
      { name: t('Received'), data: pay.received },
    ],
    options: {
      chart: { ...CHART_BASE, type: 'area' },
      colors: ['#f43f5e', '#10b981'],
      stroke: { curve: 'smooth', width: 2.5 },
      fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.04, stops: [0, 95] } },
      dataLabels: { enabled: false },
      legend: { labels: { colors: '#595959' } },
      xaxis: { categories: pay.days, axisBorder: { show: false }, axisTicks: { show: false } },
      grid: GRID,
      tooltip: { theme: 'light' },
    },
  };
  // Top customers — pie, purple family.
  customerChart.value = {
    series: customers.map(c => c.value),
    options: {
      chart: { ...CHART_BASE, type: 'pie' },
      labels: customers.map(c => c.name),
      colors: PALETTE,
      stroke: { colors: ['#ffffff'], width: 3 },
      legend: { position: 'bottom', labels: { colors: '#595959' } },
      dataLabels: { enabled: true, formatter: v => `${Math.floor(v)}%` },
      tooltip: { theme: 'light' },
    },
  };
}

/* ------------------------------------------------------------------ data */
const DEMO = {
  report: {
    today_sales: 48290, today_purchases: 21400, return_sales: 1240, return_purchases: 860,
    sales_due: 6120, purchase_due: 3400, today_invoices: 37, today_profit: 17830,
  },
  payments: [
    { name: 'Cash', amount: 21730, percentage: 45, color: 'orange' },
    { name: 'Card', amount: 15450, percentage: 32, color: 'blue' },
    { name: 'Bank transfer', amount: 8210, percentage: 17, color: 'green' },
    { name: 'Cheque', amount: 2900, percentage: 6, color: 'grey' },
  ],
  stock: { by_cost: 128400, by_retail: 186900, by_wholesale: 152300 },
  days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
  sales: [4200, 5800, 4700, 6300, 7100, 8900, 5400],
  purchases: [2100, 3200, 2600, 3900, 3300, 4100, 2800],
  paymentSent: [1800, 2400, 2000, 3100, 2700, 3300, 2500],
  paymentReceived: [3900, 5100, 4300, 5900, 6200, 7800, 4900],
  products: [
    { name: 'Wireless Earbuds Pro', value: 38 },
    { name: 'Smart Watch S9', value: 26 },
    { name: 'Organic Coffee 1kg', value: 18 },
    { name: 'Yoga Mat Premium', value: 11 },
    { name: 'Other', value: 7 },
  ],
  customers: [
    { name: 'Sarah Miller', value: 32 },
    { name: 'Omar Khalil', value: 24 },
    { name: 'Lina Berrada', value: 19 },
    { name: 'James Chen', value: 15 },
    { name: 'Other', value: 10 },
  ],
  stockAlerts: [
    { code: 'PRD-1042', name: 'Wireless Earbuds Pro', warehouse: 'Main', quantity: 3, stock_alert: 10 },
    { code: 'PRD-0917', name: 'Organic Coffee 1kg', warehouse: 'Depot 2', quantity: 5, stock_alert: 15 },
    { code: 'PRD-0733', name: 'Yoga Mat Premium', warehouse: 'Main', quantity: 2, stock_alert: 8 },
  ],
  topProducts: [
    { name: 'Wireless Earbuds Pro', total_sales: 142, total: 40280 },
    { name: 'Smart Watch S9', total_sales: 98, total: 29400 },
    { name: 'Organic Coffee 1kg', total_sales: 76, total: 6080 },
    { name: 'Yoga Mat Premium', total_sales: 54, total: 4320 },
  ],
  lastSales: [
    { Ref: 'SL-2941', client_name: 'Sarah Miller', warehouse_name: 'Main', statut: 'completed', GrandTotal: 284, paid_amount: 284, due: 0, payment_status: 'paid' },
    { Ref: 'SL-2940', client_name: 'Omar Khalil', warehouse_name: 'Main', statut: 'pending', GrandTotal: 1120.5, paid_amount: 800.5, due: 320, payment_status: 'partial' },
    { Ref: 'SL-2939', client_name: 'Lina Berrada', warehouse_name: 'Depot 2', statut: 'completed', GrandTotal: 96.2, paid_amount: 96.2, due: 0, payment_status: 'paid' },
    { Ref: 'SL-2938', client_name: 'James Chen', warehouse_name: 'Main', statut: 'ordered', GrandTotal: 452.75, paid_amount: 0, due: 452.75, payment_status: 'unpaid' },
    { Ref: 'SL-2937', client_name: 'Fatima Zahra', warehouse_name: 'Depot 2', statut: 'cancelled', GrandTotal: 61.9, paid_amount: 61.9, due: 0, payment_status: 'paid' },
  ],
};

function applyDemo() {
  demoMode.value = true;
  report.value = DEMO.report;
  payments.value = DEMO.payments.map(p => ({ ...p, color: PAYMENT_COLORS[p.color] || '#6d28d9' }));
  stockValue.value = DEMO.stock;
  sales.value = DEMO.lastSales;
  stockAlerts.value = DEMO.stockAlerts;
  topProducts.value = DEMO.topProducts;
  buildCharts(
    DEMO.days, DEMO.sales, DEMO.purchases, DEMO.products, DEMO.customers,
    { days: DEMO.days, sent: DEMO.paymentSent, received: DEMO.paymentReceived }
  );
}

async function load() {
  loading.value = true;
  try {
    const { from, to } = queryRange();
    const data = await http.get('dashboard_data', {
      warehouse_id: warehouseId.value,
      to,
      from,
      range: period.value === 'all' ? 'all' : undefined,
    });
    if (data.range) allRange.value = { from: data.range.from, to: data.range.to };

    demoMode.value = false;
    const dash = data.report_dashboard.original;
    const r = dash.report;
    report.value = {
      today_sales: Number(r.today_sales) || 0,
      today_purchases: Number(r.today_purchases) || 0,
      return_sales: Number(r.return_sales) || 0,
      return_purchases: Number(r.return_purchases) || 0,
      sales_due: Number(r.sales_due) || 0,
      purchase_due: Number(r.purchase_due) || 0,
      today_invoices: Number(r.today_invoices) || 0,
      today_profit: Number(r.today_profit) || 0,
      unpaid_invoices: Number(r.unpaid_invoices) || 0,
      unpaid_invoices_amount: Number(r.unpaid_invoices_amount) || 0,
      ...Object.fromEntries(['0_30', '31_60', '61_90', '90_plus'].flatMap(k => [
        [`unpaid_age_${k}`, Number(r[`unpaid_age_${k}`]) || 0],
        [`unpaid_age_${k}_amount`, Number(r[`unpaid_age_${k}_amount`]) || 0],
      ])),
    };
    warehouses.value = data.warehouses || [];
    // A default Location the user can no longer see: back to all of them.
    if (warehouseId.value !== '' && !warehouses.value.some(w => w.id === warehouseId.value)) {
      warehouseId.value = '';
      setTimeout(load);
    }
    sales.value = dash.last_sales || [];
    stockAlerts.value = dash.stock_alert || [];
    topProducts.value = dash.products || [];
    payments.value = (data.sales_by_payment || []).map(p => ({
      name: p.name,
      amount: Number(p.amount) || 0,
      percentage: Number(p.percentage) || 0,
      color: PAYMENT_COLORS[p.color] || '#6d28d9',
    }));
    if (data.stock_value) {
      stockValue.value = {
        by_cost: Number(data.stock_value.by_cost) || 0,
        by_retail: Number(data.stock_value.by_retail) || 0,
        by_wholesale: Number(data.stock_value.by_wholesale) || 0,
      };
    }
    const pay = data.payments?.original || {};
    buildCharts(
      data.sales.original.days,
      data.sales.original.data,
      data.purchases.original.data,
      data.product_report.original || [],
      data.customers?.original || [],
      { days: pay.days || [], sent: pay.payment_sent || [], received: pay.payment_received || [] }
    );
  } catch (e) {
    applyDemo();
  } finally {
    loadCount.value++;
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
/* The blocks in the company's order (CSS order), 16px apart. */
/* The tab names the panel: little chrome, the content in front. */
.transport-tabs :deep(.ant-tabs-nav) { margin-bottom: 8px; }
/* Every board's tab always in sight (user's rule, 2026-11-26: "son tableros"): a second row when one is not enough, never the
   "..." menu that hides them. */
.transport-tabs :deep(.ant-tabs-nav-wrap) { overflow: visible !important; white-space: normal; }
.transport-tabs :deep(.ant-tabs-nav-wrap::before),
.transport-tabs :deep(.ant-tabs-nav-wrap::after) { display: none !important; }
.transport-tabs :deep(.ant-tabs-nav-list) { flex-wrap: wrap; row-gap: 4px; transform: none !important; }
.transport-tabs :deep(.ant-tabs-nav-operations) { display: none !important; }
/* Board mode (transport), 2026-11-13 "se ve cargado": the filters in their own light bar, the tabs as pills on a tinted
   strip in the company's colour (--app-primary-rgb), the open one filled; a little more air between them. */
.board-mode .page-header {
  margin-bottom: 10px; gap: 8px; padding: 8px 10px; border-radius: 10px; align-items: center;
  background: rgba(var(--app-primary-rgb, 22, 119, 255), 0.04); border: 1px solid rgba(var(--app-primary-rgb, 22, 119, 255), 0.12);
}
.board-mode .dash-blocks.only-tabs > :not(.board-tabs) { display: none !important; }
/* Boards as folder tabs (2026-11-26): groups on a tinted strip, the open tab white and joined to the white, bordered board. */
.board-tabs { --bt-line: #dfe5ee; --bt-pri: rgb(var(--app-primary-rgb, 22, 119, 255)); }
.bt-bar {
  display: flex; flex-wrap: wrap; align-items: flex-end; gap: 6px 0; padding: 6px 6px 0; border: 1px solid var(--bt-line);
  border-bottom: none; border-radius: 12px 12px 0 0; background: rgba(var(--app-primary-rgb, 22, 119, 255), 0.06);
}
.bt-bar { justify-content: center; }
.bt-group { display: flex; flex-direction: column; padding: 0 5px; }
.bt-group + .bt-group { border-left: 1px solid rgba(0, 0, 0, 0.08); }
/* The group's title centred over its tabs, in the company's colour between two thin lines. */
.bt-group-label {
  display: flex; align-items: center; gap: 8px; font-size: 10.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
  color: var(--bt-pri); line-height: 14px; padding: 0 4px 3px;
}
.bt-group-label::before,
.bt-group-label::after { content: ''; flex: 1; height: 1px; background: rgba(var(--app-primary-rgb, 22, 119, 255), 0.3); }
.bt-group-tabs { display: flex; flex-wrap: wrap; justify-content: center; gap: 1px; }
.bt-tab {
  position: relative; border: 1px solid transparent; border-bottom: none; background: transparent; cursor: pointer;
  padding: 6px 6px 7px; font-size: 12.5px; white-space: nowrap; line-height: 18px; color: rgba(0, 0, 0, 0.68); border-radius: 8px 8px 0 0;
  transition: background-color .15s, color .15s; margin-bottom: -1px;
}
/* Laptops (1366): still one row. */
@media (max-width: 1420px) {
  .bt-tab { padding: 6px 5px 7px; font-size: 12px; }
  .bt-group { padding: 0 4px; }
}
.bt-tab:hover { background: rgba(255, 255, 255, 0.7); color: var(--bt-pri); }
.bt-tab.active {
  background: #fff; color: var(--bt-pri); font-weight: 600; border-color: var(--bt-line); z-index: 1;
  box-shadow: inset 0 3px 0 var(--bt-pri);
}
.bt-body {
  background: #fff; border: 1px solid var(--bt-line); border-radius: 0 0 12px 12px; padding: 12px;
  box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
}
.bt-body.alone { border-radius: 12px; }
.bt-body :deep(.ant-tabs-content-holder) { padding: 0; }
.bt-source { margin-top: 10px; }

/* ---- Style D (user's reference, a Power BI board, 2026-11-26): a bar in the company's colour with a gradient, white text, the
   open board a translucent block in bold; the board below as a separate white card on the light page. ---- */
.bt-style-d .bt-bar {
  border: none; border-radius: 12px; padding: 5px 8px; margin-bottom: 10px; align-items: stretch;
  /* Navy and orange (the palette the user picked, 2026-11-26). */
  background: linear-gradient(100deg, #152d4a 0%, #1f3d63 60%, #27507d 100%);
  box-shadow: 0 4px 14px rgba(21, 45, 74, 0.22);
}
.bt-style-d .bt-group + .bt-group { border-left: 1px solid rgba(255, 255, 255, 0.22); }
/* The three group names stand out (2026-11-26): bigger, heavier, a condensed display face. */
.bt-style-d .bt-group-label {
  color: #f7a531; font-size: 13px; font-weight: 800; letter-spacing: .14em; line-height: 18px; padding: 1px 4px 3px;
  font-family: 'Segoe UI Black', 'Segoe UI', 'Arial Black', system-ui, sans-serif; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.35);
}
.bt-style-d .bt-group-label::before, .bt-style-d .bt-group-label::after { background: rgba(247, 165, 49, 0.35); }
.bt-style-d .bt-group-tabs { gap: 2px; }
.bt-style-d .bt-tab { color: rgba(255, 255, 255, 0.88); border: none; border-radius: 7px; margin: 0; padding: 5px 7px; transition: background-color .2s, color .2s; }
.bt-style-d .bt-tab:hover { background: rgba(255, 255, 255, 0.12); color: #fff; }
.bt-style-d .bt-tab.active { background: linear-gradient(180deg, #f9a83a, #f28c13); color: #fff; font-weight: 600; box-shadow: 0 2px 6px rgba(242, 140, 19, 0.45); }
.bt-style-d .bt-body { border-radius: 12px; border-color: #e3e8ef; box-shadow: 0 6px 20px rgba(21, 45, 74, 0.08); }
@media (max-width: 1500px) { .bt-style-d .bt-tab { padding: 5px 5px; font-size: 12px; } .bt-style-d .bt-group { padding: 0 4px; } }
@media (max-width: 1400px) { .bt-style-d .bt-tab { padding: 5px 4px; font-size: 11.5px; } .bt-style-d .bt-group-label { letter-spacing: .1em; } }
.board-mode .transport-tabs :deep(.ant-tabs-nav) {
  margin-bottom: 10px; padding: 4px; border-radius: 10px; background: rgba(var(--app-primary-rgb, 22, 119, 255), 0.07);
}
.board-mode .transport-tabs :deep(.ant-tabs-nav::before),
.board-mode .transport-tabs :deep(.ant-tabs-ink-bar) { display: none; }
.board-mode .transport-tabs :deep(.ant-tabs-tab) {
  padding: 5px 9px; font-size: 13px; border-radius: 7px; transition: background-color .15s, color .15s;
}
.board-mode .transport-tabs :deep(.ant-tabs-tab + .ant-tabs-tab) { margin-left: 2px; }
.board-mode .transport-tabs :deep(.ant-tabs-tab:hover) { background: rgba(var(--app-primary-rgb, 22, 119, 255), 0.12); }
.board-mode .transport-tabs :deep(.ant-tabs-tab .anticon) { margin-right: 5px; color: rgb(var(--app-primary-rgb, 22, 119, 255)); }
.board-mode .transport-tabs :deep(.ant-tabs-tab-active) {
  background: rgb(var(--app-primary-rgb, 22, 119, 255)); box-shadow: 0 1px 3px rgba(0, 0, 0, 0.18);
}
.board-mode .transport-tabs :deep(.ant-tabs-tab-active .ant-tabs-tab-btn),
.board-mode .transport-tabs :deep(.ant-tabs-tab-active .anticon) { color: #fff !important; font-weight: 600; }
/* The open tab keeps its colour under the mouse. */
.board-mode .transport-tabs :deep(.ant-tabs-tab.ant-tabs-tab-active:hover) { background: rgb(var(--app-primary-rgb, 22, 119, 255)); }
/* Laptops (1366): the filters stay in one row -- tighter dates, buttons and locations. */
@media (max-width: 1500px) {
  .board-mode .dash-filters :deep(.drf-date) { width: 88px; }
  .board-mode .dash-filters :deep(.drf .ant-picker-suffix) { display: none; }
  .board-mode .dash-filters :deep(.ant-radio-button-wrapper) { padding: 0 5px; font-size: 13px; }
  .board-mode .dash-filters .wh-select { width: 116px; min-width: 116px; }
  .board-mode .dash-filters { column-gap: 6px !important; }
}
/* Narrower screens: the closed tabs lose their icon, so all ten fit in the strip (the open one keeps it). */
@media (max-width: 1600px) {
  .board-mode .transport-tabs :deep(.ant-tabs-tab:not(.ant-tabs-tab-active) .anticon) { display: none; }
  .board-mode .transport-tabs :deep(.ant-tabs-tab) { padding: 5px 8px; }
}
.board-mode .dash-filters :deep(.drf-label) { display: none; }
.board-mode .dash-filters :deep(.drf-date) { width: 100px; }
.board-mode .dash-filters :deep(.drf) { padding: 0 2px; }
.board-mode .wh-select { width: 150px; min-width: 150px; }
.board-mode .dash-filters :deep(.ant-radio-button-wrapper) { padding: 0 8px; }
.board-mode .dash-filters :deep(.ant-radio-button-wrapper:first-child) { border-radius: 6px 0 0 6px; }
.board-mode .dash-filters :deep(.ant-radio-button-wrapper:last-child) { border-radius: 0 6px 6px 0; }
.transport-tabs :deep(.ant-card-body) { padding: 12px; }
.dash-blocks {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.dash-blocks > * {
  margin-top: 0 !important;
}
/* Stat cards: as many per row as there are, max 6 (--cols), then wrap;
   3 per row on tablets, 2 on phones. */
.card-grid {
  display: grid;
  gap: 16px;
  grid-template-columns: repeat(var(--cols, 4), minmax(0, 1fr));
}
@media (max-width: 1199px) {
  .card-grid { grid-template-columns: repeat(min(var(--cols, 4), 3), minmax(0, 1fr)); }
}
@media (max-width: 767px) {
  .card-grid { grid-template-columns: repeat(min(var(--cols, 4), 2), minmax(0, 1fr)); }
}
.card-grid .stat-card {
  height: 100%;
}
.aging-title {
  font-weight: 600;
  margin-bottom: 8px;
}
.aging-title span {
  font-weight: 400;
  opacity: 0.6;
  font-size: 12px;
}
/* "Last used" shortcuts: square buttons, up to 6 in a row. */
.lu-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(116px, 1fr));
  gap: 12px;
}
.lu-tile {
  aspect-ratio: 1 / 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 10px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 12px;
  background: transparent;
  color: inherit;
  cursor: pointer;
  transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s;
}
.lu-tile:hover {
  border-color: var(--ant-color-primary, #6d28d9);
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
  transform: translateY(-1px);
}
.lu-icon {
  width: 44px;
  height: 44px;
  display: grid;
  place-items: center;
  border-radius: 12px;
  font-size: 22px;
  background: rgba(109, 40, 217, 0.1);
  color: var(--ant-color-primary, #6d28d9);
}
.lu-label {
  font-size: 13px;
  font-weight: 500;
  text-align: center;
  line-height: 1.25;
}
.lu-empty {
  opacity: 0.55;
  font-size: 13px;
  padding: 8px 0;
}
/* Date-range keycard -- same look as Sales -> List Sales. */
.range-card {
  border-radius: 10px;
}
.range-card :deep(.ant-card-body) {
  padding: 6px 14px;
  display: flex;
  align-items: center;
  gap: 14px;
  height: 100%;
}
.range-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  white-space: nowrap;
}
.range-label {
  opacity: 0.6;
}
.range-value {
  font-size: 13px;
}
/* The filter bar (2026-11-26): every control 32 px high, the groups apart (Todos | dates | Día | periods | location | actions),
   centred when nothing else shares its row. */
.dash-filters {
  display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 8px 10px; flex: 1 1 auto;
}
.dash-filters > .df-all { margin-right: 6px; }
.dash-filters > .preset-wrap { margin: 0 6px; gap: 10px; align-items: center; }
.dash-filters .df-actions { display: inline-flex; gap: 6px; margin-left: 6px; }
.dash-filters :deep(.ant-btn:not(.ant-btn-sm)),
.dash-filters :deep(.ant-radio-button-wrapper),
.dash-filters :deep(.ant-select-single .ant-select-selector),
.dash-filters :deep(.drf) { height: 32px; }
.dash-filters :deep(.ant-radio-button-wrapper) { line-height: 30px; }
.dash-filters :deep(.drf) { display: inline-flex; align-items: center; box-sizing: border-box; }
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 24px;
}
.wh-select {
  min-width: 180px;
}
.preset-select {
  display: none;
}
/* "Día" then the range buttons, on one line. */
.preset-wrap {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}
.def-star {
  font-size: 10px;
  margin-left: 4px;
  color: #faad14;
}
/* Soft elevation on every dashboard card (stat tiles, charts, lists, table). */
.page :deep(.ant-card) {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
  border-radius: 10px;
}
.stat-card {
  cursor: pointer;
  transition: box-shadow 0.2s ease, transform 0.2s ease;
}
.stat-card:hover {
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
  transform: translateY(-2px);
}
.stat-inner {
  display: flex;
  align-items: center;
  gap: 14px;
}
.stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  color: #6d28d9;
  flex: none;
}
.stat-label {
  color: rgba(0, 0, 0, 0.45);
  font-size: 13px;
}
.stat-value {
  font-size: 20px;
  font-weight: 700;
  margin-top: 2px;
}
.chart-card :deep(.ant-card-body) {
  padding-top: 8px;
}
.stockval {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.stockval-row {
  position: relative;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
  border-radius: 12px;
  overflow: hidden;
}
.stockval-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 18px;
  flex: none;
}
.stockval-label {
  color: rgba(0, 0, 0, 0.5);
  font-size: 13px;
}
.stockval-value {
  font-size: 20px;
  font-weight: 700;
  line-height: 1.2;
}
/* Thin proportional bar pinned to the bottom of each row. */
.stockval-bar {
  position: absolute;
  left: 0;
  bottom: 0;
  height: 3px;
  border-radius: 0 3px 3px 0;
  opacity: 0.55;
  transition: width 0.4s ease;
}
.paylist {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.pay-row {
  display: flex;
  align-items: center;
  gap: 14px;
}
.pay-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 18px;
  flex: none;
}
.pay-main {
  flex: 1;
  min-width: 0;
}
.pay-top {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 6px;
}
.pay-name {
  color: rgba(0, 0, 0, 0.65);
  font-size: 13px;
}
.pay-amount {
  font-weight: 700;
}
.pay-track {
  height: 6px;
  border-radius: 3px;
  background: rgba(128, 128, 128, 0.15);
  overflow: hidden;
}
.pay-fill {
  display: block;
  height: 100%;
  border-radius: 3px;
  transition: width 0.4s ease;
}
.pay-pct {
  flex: none;
  width: 40px;
  text-align: right;
  font-size: 13px;
  color: rgba(0, 0, 0, 0.45);
}

/* ---- Stock alert list ---- */
.alertlist,
.toplist {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.alert-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}
.alert-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(245, 34, 45, 0.12);
  color: #f5222d;
  font-size: 16px;
  flex: none;
}
.alert-main {
  flex: 1;
  min-width: 0;
}
.alert-top {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}
.alert-name {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.alert-qty {
  flex: none;
  font-weight: 700;
  color: #f5222d;
}
.alert-meta {
  display: flex;
  gap: 12px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  margin: 2px 0 6px;
}
.alert-wh {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.alert-track {
  height: 5px;
  border-radius: 3px;
  background: rgba(128, 128, 128, 0.15);
  overflow: hidden;
}
.alert-fill {
  display: block;
  height: 100%;
  border-radius: 3px;
  transition: width 0.4s ease;
}

/* ---- Top selling products list ---- */
.top-row {
  display: flex;
  align-items: center;
  gap: 12px;
}
.top-rank {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 700;
  flex: none;
  background: rgba(128, 128, 128, 0.14);
  color: rgba(0, 0, 0, 0.55);
}
.top-rank.rank-1 { background: #f6c454; color: #7a5a00; }
.top-rank.rank-2 { background: #d5d9df; color: #4a4f57; }
.top-rank.rank-3 { background: #e6b183; color: #7a4410; }
.top-main {
  flex: 1;
  min-width: 0;
}
.top-name {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  margin-bottom: 6px;
}
.top-track {
  height: 5px;
  border-radius: 3px;
  background: rgba(128, 128, 128, 0.15);
  overflow: hidden;
}
.top-fill {
  display: block;
  height: 100%;
  border-radius: 3px;
  background: linear-gradient(90deg, #6d28d9, #a78bfa);
  transition: width 0.4s ease;
}
.top-figures {
  flex: none;
  text-align: right;
}
.top-amount {
  font-weight: 700;
  color: #52c41a;
}
.top-sales {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}

/* Right column stacks Stock value + Quick actions and fills the row height so
   its bottom aligns with the taller Sales-by-payment card on the left. */
.dash-right {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.fill-card {
  height: 100%;
}
.qa-card {
  flex: 1;
  display: flex;
  flex-direction: column;
}
.qa-card :deep(.ant-card-body) {
  flex: 1;
  display: flex;
  align-items: center;
}

/* ---- Quick actions ---- */
.qa-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  width: 100%;
}
.qa-tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 14px 8px;
  border: 1px solid rgba(5, 5, 5, 0.06);
  border-radius: 12px;
  background: none;
  cursor: pointer;
  transition: border-color 0.15s, transform 0.15s;
}
.qa-tile:hover {
  border-color: #6d28d9;
  transform: translateY(-2px);
}
.qa-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
}
.qa-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.7);
  text-align: center;
}

/* ============================ Responsive ============================ */
@media (max-width: 767px) {
  /* Greeting stacks above the filters, which take the full width. */
  .page-header {
    flex-direction: column;
    align-items: stretch;
    gap: 12px;
    margin-bottom: 16px;
  }
  /* One filter per row, each full width -- side by side they overlapped. */
  .dash-filters {
    width: 100%;
    flex-direction: column;
    align-items: stretch !important;
  }
  .dash-filters > * {
    width: 100%;
    min-width: 0;
    margin: 0 !important;
  }
  .dash-filters > .df-all { display: none; }
  .dash-filters .df-actions { display: flex; gap: 8px; }
  .range-card :deep(.ant-card-body) {
    justify-content: space-between;
  }
  .preset-buttons {
    display: none;
  }
  .preset-select {
    display: block;
    width: 100%;
  }
  .wh-select {
    width: 100%;
    min-width: 0;
  }
  .dash-filters :deep(.ant-btn) {
    width: 100%;
  }
  /* Two quick-action tiles per row instead of three. */
  .qa-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

/* Phones (2 stat cards per row): stack icon over label/value so the amount
   gets the full card width and never wraps, and trim the card padding. */
@media (max-width: 575px) {
  .stat-card :deep(.ant-card-body) {
    padding: 14px;
  }
  .stat-inner {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  }
  .stat-icon {
    width: 36px;
    height: 36px;
    font-size: 16px;
  }
  .stat-label {
    font-size: 12px;
  }
  .stat-value {
    font-size: 17px;
    white-space: nowrap;
  }
}
.kpi-delta-up {
  color: #52c41a;
}
.kpi-delta-down {
  color: #f5222d;
}
</style>
