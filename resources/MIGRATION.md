# Stocky Next — Vue 3 + Ant Design migration

Strangler-fig migration off the Vue 2.7 + Bootstrap-Vue admin. This app serves
migrated pages at `/next/*`; every other module deep-links back to the legacy
SPA, so users get one navigation surface throughout.

Build (never run automatically — the repo owner builds): `npm run vue3:build`

## Status

| Wave | Scope | Done |
|---|---|---|
| Foundation | router, auth, http, i18n, theme, menu | ✅ |
| A — simple CRUD | Brands, Categories, SubCategories, Units, SizeGuides, Currencies, Warehouses | ✅ 7 |
| B — reports | 58 report pages | **56 — DONE** (2 stay in legacy by decision: ai_reports, sales-3d-dashboard) |
| C | People ✅, HRM (all) ✅, assets ✅, bookings core ✅, meetings ✅, KB ✅, projects ✅, tasks ✅, contracts ✅, marketing ✅ | **DONE** (analytics_report follows by decision) |
| D | documents **DONE**: all 8 modules' lists + details ✅, deposits full CRUD ✅, all 5 multi-line forms ✅ (lib/lineCalc.js shared math, legacy validation parity), **v2 batch allocation + serial picker ✅** (BatchAllocator, SerialPicker, lib/batchValidation.js — sale/quotation select + purchase entry). Still deferred: packs selector, points UI, multi payment lines | ✅ |
| E | products: **list ✅, detail ✅, count stock ✅, batches ✅, serial numbers ✅, product form v1 ✅** (single/service; variant/combo → legacy); imports + barcode pending | nearly done |
| F | settings: **15 pages ✅** (payment methods/gateway, warehouse locations, mail, login devices, system health, sms+email templates, custom fields, backup, POS settings, appearance, SMS providers, languages + translations editor); system settings/receipt designer/module+update/webhooks/woocommerce/quickbooks pending | in progress |
| G | POS | pending — **migrate to Vue 3 with the design kept 100% identical to current pos.vue (user decision: 1:1 visual port, NO Ant Design restyle)**. 17.6k lines; its own multi-batch project. Until then sidebar deep-links to `/#/app/pos`. |

### Wave C notes

Customers (reference for the wave): list `GET clients` →
`{clients, totalRows, company_info, accounts, payment_methods}` with
name/code/phone/email filter params; pay-due `POST clients_pay_due` /
`clients_pay_return_due` `{client_id, amount, notes, payment_method_id,
account_id}` (methods/accounts ship in the list payload); form `POST clients`
/ `GET|PUT clients/{id}`; phone-duplicate probe `GET
check_phone_duplicate?phone&type=client` is a non-blocking warning. Full-page
create/edit is the Wave C form pattern (`people/CustomerForm.vue`, route param
decides mode). Still legacy: CSV import, ledger, details, points adjust,
opening-balance adjust, ecommerce sub-lists. Label gotchas: return due =
`Total_Sell_Return_Due`, phone dup = `Phone_Already_Registered`, form titles
are plain `Add`/`Edit`.

Suppliers mirrors customers: `GET providers` → `{providers, totalRows,
accounts, payment_methods}`; pay `POST pay_supplier_due` /
`pay_purchase_return_due` `{provider_id, …}` (pay-due button ALSO needs the
`pay_supplier_due` permission); form `POST providers` / `GET|PUT
providers/{id}`; phone probe `type=provider`; return-due label =
`Total_Purchase_Return_Due`. Still legacy: supplier CSV import.

Users: list `GET users` → `{users, totalRows, roles, warehouses}`; status
toggle `PUT users_switch_activated/{id}` `{statut, id}` (send the NEW value);
create `POST users` (FormData: …, `role` = role_id, `is_all_warehouses`,
`record_view` 1|0, `assigned_to[i]`, optional `avatar`); edit bootstrap
`GET users/{id}/edit` → `{user, roles, warehouses, assigned_warehouses}`, save
`POST users/{id}` + `_method=put` with **`NewPassword`** (blank = keep; create
uses `password`). Create-mode role/warehouse lists come from
`GET users?limit=1`. Phone probe `type=user`. Labels: record-view = `ShowAll`,
password placeholder on edit = `LeaveBlank`.

Marketing (all endpoints under `marketing/`): campaigns `{campaigns, totalRows}`
with `type`/`status` filters; the form submits **multipart FormData via
`postForm` for both create and edit** (`POST marketing/campaigns[/{id}]` — the
API keeps a POST route for multipart updates); booleans as 1/0,
`scheduled_at` "YYYY-MM-DD HH:mm"; sending/sent campaigns 422 on edit; send =
`POST …/{id}/send`. Segments carry a `filters{…}` object and a preview endpoint
(`POST marketing/segments/preview` → `{count}`). Templates: one page serves
sms/whatsapp/email via the route param `:type`. Reports endpoint is NOT
paginated (`date_from`/`date_to`, defaults to last 3 months) — exports use the
loaded rows. i18n trap variant: the 5 dashboard stat keys (`Total_Messages_Sent`
etc.) are used only dynamically in legacy so `check-keys.js` flags them missing;
they exist in the translations DB table (verify via tinker, not grep).

HRM heavy four: Employees list filters username/employment_type/company_id
(companies ship in the list payload); the *_name join columns must NOT be
sortable (sorter sends the dataIndex, and `orderBy(company_name)` 500s — legacy
remapped them to `*_id`). EmployeeForm cascades: company →
`core/get_departments_by_company` + `core/get_office_shift_by_company`,
department → `core/get_designations_by_department` (plain arrays; labels are
`.department` / `.designation`). Employee details page stays legacy
(deep-link). Leaves: endpoint `leave` (singular), employees per department via
`get_employees_by_department` (NOT under `core/`), multipart save with
`_method=put` on edit, and a **200 with `{isvalid:false}`** means the leave
balance is insufficient (`remaining_leaves_are_insufficient`). Payroll selects
ship in the list payload; no bulk delete (no API route — legacy's button was
broken too).

**Not yet verified in a browser: the Excel/PDF export path and ReportChart.**
Every report shares them. Verify before adding more reports.

## Rules (each learned the hard way)

0. **Every ad-hoc call to a legacy list endpoint must send
   `page, SortField, SortType, search, limit`.** The controllers feed
   `SortField` into `orderBy()` unchecked — omitting it can 500 (this bounced
   `/users/create`). And create-form bootstrap failures must degrade, not
   redirect away.

1. **No `?v=` query on the module script.** Lazy chunks import `../app.js`
   without it; a query loads a second copy of the app + Vue and every `<slot>`
   rendered from a chunk throws. Assets are content-hashed via Vite's manifest
   (`next.blade.php` reads `.vite/manifest.json`).
2. **No `manualChunks`.** Hand-splitting vendors broke module init order
   ("Cannot access 'Jt' before initialization"). Rollup's automatic splitting is
   correct.
3. **All HTTP through `src/lib/http.js`.** The API uses Passport's cookie guard,
   which 401s any request — GETs included — lacking `X-XSRF-TOKEN`.
4. **Titles come from `config/menu.js`, not filenames or permissions.** All three
   differ: `Serial_Sold_Report.vue` / perm `serial_numbers_report` / label
   `Sold_Serial_Numbers`. Verify every key with `check-keys.js` before building.
5. **Declare rows explicitly** (`rowsKey` or `select`). Payloads with several
   arrays (`warehouses`, `suppliers`, `customers`) make auto-detection pick the
   wrong one — silently wrong data, no error.
6. **Never hide a panel when data is empty.** Indistinguishable from a bug; show
   an empty state (see `ReportChart`).
7. `columns` / `rules` must be `computed()` so they re-render on language change.
8. a-menu items: `icon` takes a function, `label` must be a plain string.

## Migrating a report

1. Extract the contract: `node <scratchpad>/report-contracts.js Foo_Report.vue`
   — prints endpoints, response keys, columns, filters.
2. Copy `src/pages/reports/DeadStockReport.vue` (or `DiscountSummaryReport.vue`
   for a date range; `TopSuppliersReport.vue` for a nested payload).
3. Take the title key + permission from `config/menu.js`; verify with
   `check-keys.js`.
4. Add a **static-imported-free** lazy route in `src/router/index.js` with
   `meta.permission`.
5. Add one `MIGRATED_ROUTES` entry in `config/menu.js` — the sidebar rewires
   itself. Also add it to the generator's list (scratchpad `gen-menu2.js`) so a
   menu regeneration doesn't drop it.
6. Verify: every `MIGRATED_ROUTES` key must resolve against the sidebar config.

## Report contract cheatsheet (verified)

| Endpoint | Rows | Filters |
|---|---|---|
| `report/dead_stock` | `report` | period, warehouse_id |
| `report/zero_sales_products` | `report` | period, warehouse_id |
| `report/negative_stock` | `rows` (+`warehouses`) | — |
| `report/expiry` | `batches` (+`warehouses`,`kpis`) | expiry_window, warehouse_id |
| `report/discount_summary` | `report` (+`timeseries`,`overall_total`) | from, to |
| `report/tax_summary` | `report` (+`timeseries`,`totals`) | from, to |
| `report/customer_loyalty_points` | `rows` (+`totals`) | from, to |
| `report/inactive_customers` | `report` | period |
| `report/draft_invoices` | `report` | from, to, warehouse_id |
| `report/top_suppliers` | **`data.rows`** (+`data.kpis`,`topByValue`) | from, to, warehouse_id |
| `report/warranty_guarantee` | `rows` (+`customers`) | from, to, status |
| `report/stock_adjustment` | **`data.rows`** | from, to, warehouse_id |
| `report/serials/sold\|available\|movements\|inventory` | `report` (+`warehouses`) | warehouse_id |
| `report/batches/register` | `batches` (+`warehouses`,`suppliers`) | warehouse_id, supplier_id, status |
| `payment_sale`, `payment_purchase`, `payment/returns_sale`, `payment/returns_purchase` | `payments` | from, to |
| `report/client` (customers), `report/provider` (suppliers) | `report` | — |
| `report/cash_registers` | `registers` (+`users`,`warehouses`) | from, to, warehouse_id |
| `report/attendance_summary` | `report` (+`companies`,`employees`) | scope=daily→`date`, scope=monthly→`month` (YYYY-MM), company_id, employee_id |
| `report/service_jobs` | `rows` (+`clients`,`technicians`) | from, to, client_id, technician_id |
| `report/service_checklist_completion` | `rows` (no totalRows) | from, to |
| `report/customer_maintenance_history` | **`jobs`** (+`clients`) | from, to, client_id |
| `report/users` | `report` | — |
| `security/login-activity-report` (not report/*) | `sessions` | — (labels hardcoded English in legacy too) |
| `error-logs` (not report/*) | **`logs`**, total under **`total`** | takes **`per_page`**, not `limit` — needs `select` + lazy param |
| `report/top_products` | `products` | — |
| `report/top_customers` | `customers` | — |
| `report/expenses_report` | `reports` (+`warehouses`) | from, to, warehouse_id |
| `report/deposits_report` | `reports` | from, to |
| `report/sales` | `sales` (+`customers`,`warehouses`,`sellers`) | Ref, client_id, warehouse_id, user_id, statut, payment_statut (empty-string when unset) |
| `report/purchases` | `purchases` (+`suppliers`,`warehouses`) | provider_id, warehouse_id, statut, payment_statut |
| `get_products_stock_alerts` (not report/*) | **Laravel paginator**: `products.data`/`products.total` | `warehouse` (not warehouse_id) |
| `report/stock` | `report` (+`warehouses`) | warehouse_id |
| `report/product_report` | `products` (+`warehouses`) | from, to, warehouse_id |
| `report/product_sales_report` | `sales` (+`customers`,`warehouses`) | from, to, warehouse_id, client_id |
| `report/product_purchases_report` | `purchases` (+`suppliers`,`warehouses`) | from, to, warehouse_id, provider_id |
| `report/seller_report` | `report` (+`paymentMethods`,`warehouses`) | **start_date/end_date** (not from/to), warehouse_id; legacy also has start_time/end_time shift filters (not carried over) |
| `report/report_transactions` | `payments` (+clients, suppliers, sales, purchases, payment_methods, payment_summary) | from, to, client_id, provider_id, payment_method_id (sale_id/purchase_id pickers not carried over) |
| `report/internal_location_report` | `rows` (+`warehouses`) | warehouse_id |
| `report/sales_by_category_report`, `report/sales_by_brand_report` | `reports` (+`currency`) | from, to — one component (`SalesByGroupReport.vue`), `meta.kind` selects |
| `report/profit_and_loss` | **KPI object** `data.{sales_count/sum, …, profit_fifo, profit_average_cost}` (+`warehouses`) — NOT a table; custom tile page (`ProfitAndLossReport.vue`) | from, to, warehouse_id |
| `report/cash_flow_report` | `rows` (+`total_inflow/total_outflow/net_cash_flow`, `timeseries[{d,inflow,outflow,net}]`, warehouses, payment_methods, accounts) | from, to, warehouse_id, **group_by=account\|method**, account_id/payment_method_id (only for the active grouping) |
| `report/stock_transfer` | **nested** `data.rows`/`data.totalRows` (+`data.kpis`, `data.timeseries[{d,qty}]`, `data.routes`, `warehouses`) | from, to, warehouse_id |
| `report/return_ratio_report` | **KPI object** `data.{sales_sum, returns_sales_sum, sales_return_ratio_pct, purchases_*}` — custom page | from, to, warehouse_id |
| `report/stock_aging` (+ separate `report/stock_aging/filters` for options) | `report` | dimension=product\|variant, buckets=30,60,90 (comma-joined), warehouse_id, brand_id, category_id |
| `report/inventory_valuation_summary` | `reports` (+`warehouses`) | warehouse_id |
| `report/stock_inventory_valuation` | `reports` (+`warehouses`) | **date_from/date_to** (5th date dialect), warehouse_id |

The four payment endpoints are **not** under `report/`. They share one component
(`PaymentsReport.vue`) selected by `meta.kind`; `router-view` is keyed by path so
sibling routes remount (useCrudTable captures its endpoint at setup).

All list endpoints also take `page/SortField/SortType/search/limit`; `limit=-1`
means "all rows" (used by export).

## Remaining reports (41)

Owner decisions (2026-07-16): `warehouse_report` ported as one tabbed page
(`WarehouseReport.vue` — overview KPIs + 6 lazy table tabs + count-stock chart;
note the API path typo `warhouse_count_stock` is real). `ai_reports` and
`sales-3d-dashboard` STAY IN LEGACY (deep-linked; revisit at end of migration).
`analytics_report` deferred until after Wave C. Also outside the sidebar:
`batch_history_report` (drill-down: `report/batches/{id}/history` — belongs
with the batches page migration).

## POS 1:1 port plan (user rule: design stays 100% identical — no restyle)

pos.vue inventory: template 3,274 L · script 6,171 L · styles ~8,200 L
(scoped **SCSS** + small global block) · 15 b-modals · ~23 endpoints.

**Approach:** copy template + SCSS essentially verbatim into
`pages/pos/PosPage.vue` (options API — valid in Vue 3), swap only plumbing:
axios→lib/http, Fire bus→local emitter, $bvModal/$bvToast→thin local shims,
b-* components→`src/pos-compat/` shims that render the SAME markup/classes so
the copied SCSS keeps working. `sass` added to devDependencies —
**run `npm install` inside vue3-app/ before the next build.**

Batches (each ends buildable; `/pos` route stays unlisted and the sidebar
keeps deep-linking to legacy until final sign-off):
1. pos-compat shims (BModal/BButton/BRow/BCol/BFormGroup/BFormInput/BFormSelect/
   BDropdown/BPagination/BAlert + v-select wrapper) + fullscreen `/pos` route
   outside AdminLayout
2. Shell: header bar (register status, selectors, customer, icon cluster) +
   products grid + search/scanner + pagination — endpoints pos/data_create_pos,
   get_pos_Settings_api, get_Settings_data
3. Cart engine: lines, qty stepper, price types, packs, batches/serial panels
   (reuse BatchAllocator/SerialPicker logic), totals/charges/points rows
4. Payment flow: pay modal, multi payment lines, POST pos/create_pos, cash
   register (cash-registers/current/{uid})
5. Receipt modal: all 5 layouts + ZATCA/invoice-URL QR (qrcode lib decision)
6. Drafts (create_draft/data_draft_convert_sale/remove_draft_sale), today's
   sales, product return, calculator, quick-add customer, offline/sync
7. Side-by-side parity pass vs legacy, then flip the sidebar POS entry
