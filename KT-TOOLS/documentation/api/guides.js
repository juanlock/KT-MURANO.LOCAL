// Guide pages for the Stocky API Reference. Plain HTML fragments rendered by app.js.
// Elements with data-dynamic="…" are filled from the OpenAPI document at runtime.
window.STOCKY_GUIDES = [
{
  id: 'introduction',
  title: 'Introduction',
  summary: 'What the API covers, base URL, surfaces and how to read this reference.',
  html: `
<p class="lead">Stocky exposes everything its web app does — inventory, POS, sales, purchases, customers, HR, accounting, the online store, the client portal and the industry modules — as a JSON REST API. This reference is generated from the application source and enriched with hand-written explanations for every endpoint and parameter.</p>

<h2 id="base-url">Base URL</h2>
<p>All paths in this reference are relative to the root of your Stocky installation, for example <code>https://your-stocky.example.com</code>. Admin, mobile, portal and public endpoints live under <code>/api/…</code>; storefront endpoints live under <code>/online_store/…</code>.</p>
<pre class="code"><code>curl "https://your-stocky.example.com/api/ping"
# → {"status":"ok"}</code></pre>

<h2 id="surfaces">API surfaces</h2>
<p>Stocky has several audiences, each with its own authentication. Every endpoint shows a <em>surface</em> chip so you know which credentials to use.</p>
<div data-dynamic="surfaces"></div>
<p>See <a href="#/guide/authentication">Authentication</a> for how to obtain credentials for each surface.</p>

<h2 id="request-format">Request &amp; response format</h2>
<ul>
  <li>Send and receive JSON. Always add <code>Accept: application/json</code> so validation and authentication failures come back as JSON instead of an HTML redirect.</li>
  <li>Endpoints that accept file uploads use <code>multipart/form-data</code>; they are marked <em>multipart</em> in the reference.</li>
  <li>Dates are <code>YYYY-MM-DD</code>, timestamps <code>YYYY-MM-DD HH:MM:SS</code> in the instance timezone (System Settings → Timezone).</li>
  <li>Booleans are accepted as <code>true</code>/<code>false</code> or <code>1</code>/<code>0</code> (strings are fine in multipart bodies).</li>
  <li>Monetary amounts are decimals in the <strong>base currency</strong> unless the endpoint documents a document currency (<code>currency_id</code> + <code>exchange_rate</code>).</li>
  <li>Identifiers are integer primary keys. Documents also carry a human reference (<code>Ref</code>) built from the prefix configured in settings, e.g. <code>SL_1112</code>.</li>
</ul>

<h2 id="reading">How to read an endpoint</h2>
<ul>
  <li><strong>Method + path</strong> — path parameters are shown as <code>{id}</code>.</li>
  <li><strong>Chips</strong> — surface, required <a href="#/guide/permissions">permission</a>, rate limit, pagination and other facts extracted from the code.</li>
  <li><strong>Parameters</strong> — grouped by location (path, query, body). <span class="chip" style="font-size:10.5px">inferred</span> means the type was guessed from the field name because the controller reads it without a validation rule; treat the documented description as authoritative.</li>
  <li><strong>Responses</strong> — status codes with the JSON shape and an example. Click a status to expand it.</li>
  <li><strong>Code samples</strong> — ready-to-run cURL, JavaScript and PHP requests on the right. Set your base URL and token in the <em>Try-it settings</em> (gear icon) and the samples use them.</li>
  <li><strong>Try it</strong> — send the request from your browser (same origin or CORS-enabled server required).</li>
</ul>

<h2 id="tooling">Import into Postman / Insomnia</h2>
<p>The whole reference is also available as an OpenAPI 3.0.3 document: <a href="openapi.json" download>download openapi.json</a>. Import it into Postman, Insomnia, Bruno or any OpenAPI-aware tool to get a ready-made collection with all 1 600+ requests. Vendor extensions (<code>x-permission</code>, <code>x-paginated</code>, <code>x-surface</code>, …) carry the extra facts shown in this site.</p>

<h2 id="search">Keyboard shortcuts</h2>
<table><tr><th>Shortcut</th><th>Action</th></tr>
<tr><td><kbd>Ctrl</kbd> <kbd>K</kbd> or <kbd>/</kbd></td><td>Open search</td></tr>
<tr><td><kbd>↑</kbd> <kbd>↓</kbd> <kbd>↵</kbd></td><td>Navigate search results</td></tr>
<tr><td><kbd>Esc</kbd></td><td>Close dialogs</td></tr></table>
`},

{
  id: 'authentication',
  title: 'Authentication',
  summary: 'Bearer tokens for the admin & mobile API, session cookies for the portal and storefront, public endpoints.',
  html: `
<p class="lead">Stocky uses Laravel Passport personal access tokens for the admin and mobile APIs, and Laravel session cookies for the client portal and the storefront. A handful of endpoints are public.</p>

<h2 id="admin">Admin API (Bearer token)</h2>
<p>Exchange a staff user's email and password for a token with <a href="#/op/post_api_getAccessToken"><code>POST /api/getAccessToken</code></a>:</p>
<pre class="code"><code>curl -X POST "https://your-stocky.example.com/api/getAccessToken" \\
  -H "Accept: application/json" -H "Content-Type: application/json" \\
  -d '{"email":"admin@example.com","password":"123456"}'

# 200 OK
{"Stocky_token":"eyJ0eXAiOiJKV1QiLCJhbGciOi…","username":"William Castillo","status":true}</code></pre>
<div class="callout warn"><p>The login endpoint always answers <strong>200</strong>. Check <code>status</code>: <code>true</code> = success, <code>false</code> = wrong credentials, <code>"NotActive"</code> = the account is disabled.</p></div>
<p>Send the token on every call, either as a header or as the cookie the login endpoint sets:</p>
<pre class="code"><code>Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOi…
# or (used by the web app)
Cookie: Stocky_token=eyJ0eXAiOiJKV1QiLCJhbGciOi…</code></pre>
<ul>
  <li>Tokens do not expire on their own. They are revoked by <a href="#/op/post_api_logout"><code>POST /api/logout</code></a>, by the inactivity timeout (<code>session_timeout_minutes</code> in System Settings) and from <em>Settings → Security → Active sessions</em>.</li>
  <li>Every request passes the <code>Is_Active</code> middleware: deactivating a user logs them out on their next request.</li>
  <li>Use <a href="#/op/get_api_user"><code>GET /api/user</code></a> to fetch the authenticated user and their permissions.</li>
  <li>A revoked, expired or missing token returns <code>401 {"message":"Unauthenticated."}</code>.</li>
</ul>

<h2 id="mobile">Mobile API</h2>
<p>The mobile app uses the same Passport tokens but logs in through <a href="#/op/post_api_mobile_login"><code>POST /api/mobile/login</code></a> (rate-limited to 10 requests per minute). The response bundles the token, the user and the list of permission names so the app can build its menu offline. Mobile login is refused when the mobile app is disabled in <em>System Settings → Mobile App</em>. All other <code>/api/mobile/*</code> endpoints require the Bearer token like the admin API.</p>

<h2 id="portal">Client portal (session)</h2>
<p>Portal customers authenticate with <a href="#/op/post_api_portal_login"><code>POST /api/portal/login</code></a>, which starts a Laravel session on the <code>portal</code> guard. The browser then sends the <code>laravel_session</code> cookie automatically. Because portal routes run through the <code>web</code> middleware, <strong>mutating requests need the CSRF token</strong>: read the <code>XSRF-TOKEN</code> cookie and send it back as the <code>X-XSRF-TOKEN</code> header. Portal routes are throttled to 60 requests per minute.</p>

<h2 id="storefront">Storefront (session)</h2>
<p>Shoppers log in with <a href="#/op/post_online_store_login"><code>POST /online_store/login</code></a> or register with <a href="#/op/post_online_store_register"><code>POST /online_store/register</code></a> (guard <code>store</code>). The same session-cookie and CSRF rules as the portal apply. Storefront routes return <code>404</code> when the online store is disabled (<code>store.enabled</code> middleware).</p>

<h2 id="public">Public endpoints</h2>
<p>Endpoints marked <span class="chip public" style="font-size:10.5px">public</span> need no credentials: health probes, the login/registration endpoints, password reset, the translation catalogue, the PDF/print renderers, payment-gateway return URLs and webhook receivers. Several are rate-limited (shown as a chip).</p>

<h2 id="tryit">Using the Try-it console</h2>
<p>Open the gear icon in the top bar, enter your base URL and token (or click <em>Get token via login…</em>). The code samples switch to your values and the <em>Try it</em> button on every endpoint sends real requests from the browser. The Stocky server must be on the same origin as this documentation or allow it through CORS.</p>
`},

{
  id: 'pagination',
  title: 'Pagination, sorting & filtering',
  summary: 'The limit / page / SortField / SortType / search convention shared by every list endpoint.',
  html: `
<p class="lead">Every list endpoint marked <span class="chip" style="font-size:10.5px">paginated</span> accepts the same five query parameters and returns <code>totalRows</code> next to the rows.</p>

<table><tr><th>Parameter</th><th>Type</th><th>Default</th><th>Meaning</th></tr>
<tr><td><code>limit</code></td><td>integer</td><td>10</td><td>Rows per page. <code>-1</code> returns every row (no pagination).</td></tr>
<tr><td><code>page</code></td><td>integer</td><td>1</td><td>1-based page number. Ignored when <code>limit=-1</code>.</td></tr>
<tr><td><code>SortField</code></td><td>string</td><td><code>id</code></td><td>Column to sort on. Must be a real column of the underlying table (unknown columns fall back to the default).</td></tr>
<tr><td><code>SortType</code></td><td><code>asc</code> | <code>desc</code></td><td><code>desc</code></td><td>Sort direction.</td></tr>
<tr><td><code>search</code></td><td>string</td><td>—</td><td>Free-text <code>LIKE %term%</code> match across the resource's searchable columns (reference, names, phone, email…).</td></tr></table>

<pre class="code"><code>GET /api/clients?limit=25&amp;page=2&amp;SortField=name&amp;SortType=asc&amp;search=acme

{
  "clients": [ { "id": 41, "name": "ACME Ltd", … } ],
  "totalRows": 138
}</code></pre>
<p>The rows key is named after the resource (<code>clients</code>, <code>sales</code>, <code>products</code>, <code>report</code>, <code>data</code>…) — each endpoint documents it. A few newer endpoints use Laravel's paginator instead (<code>data</code>, <code>total</code>, <code>per_page</code>, <code>current_page</code>); those are described individually.</p>

<h2 id="filters">Column filters</h2>
<p>List endpoints with a <span class="chip" style="font-size:10.5px">filters</span> chip accept the listed columns as query parameters for exact matches (dates and references use <code>LIKE</code>). For example <code>GET /api/sales?statut=completed&amp;client_id=41&amp;payment_statut=unpaid</code>. Date-ranged lists and reports take <code>from</code> and <code>to</code> (<code>YYYY-MM-DD</code>) and default to the last 30 days when omitted, unless the endpoint says otherwise.</p>

<h2 id="scoping">Warehouse scoping and record visibility</h2>
<p>Two rules are applied server-side to almost every list and report and cannot be overridden by query parameters:</p>
<ul>
  <li><strong>Warehouses</strong> — a user without the <em>all warehouses</em> flag only sees rows belonging to the warehouses assigned to them (<code>user_warehouse</code>). A <code>warehouse_id</code> filter outside that set is ignored.</li>
  <li><strong>Record view</strong> — a user without the <code>record_view</code> permission only sees documents they created (<code>user_id = caller</code>).</li>
</ul>
<p><code>totalRows</code> always reflects the scoped result, so it is safe to use for paging.</p>

<h2 id="bulk">Bulk operations</h2>
<p>Most resources offer <code>POST …/delete/by_selection</code> taking <code>{"selectedIds": [1,2,3]}</code>, and many export endpoints (<code>…/export/excel</code>, <code>…_pdf</code>) accept the same filters as the list to export exactly what is displayed.</p>
`},

{
  id: 'errors',
  title: 'Errors',
  summary: 'HTTP status codes, validation error format and the legacy envelopes you will meet.',
  html: `
<p class="lead">Stocky is a Laravel application, so most errors follow Laravel's JSON conventions. Send <code>Accept: application/json</code> to make sure you get JSON rather than a redirect.</p>

<table><tr><th>Status</th><th>When</th><th>Body</th></tr>
<tr><td><code>200</code></td><td>Success. Many write endpoints return <code>{"success": true}</code>; some return the created/updated record.</td><td>—</td></tr>
<tr><td><code>400</code></td><td>Business-rule failure reported through the <code>sendError</code> envelope (older controllers).</td><td><code>{"success": false, "message": "…", "errors": {…}}</code></td></tr>
<tr><td><code>401</code></td><td>Missing, expired or revoked token / session.</td><td><code>{"message": "Unauthenticated."}</code></td></tr>
<tr><td><code>403</code></td><td>Authenticated but lacking the required <a href="#/guide/permissions">permission</a>, or the account is inactive.</td><td><code>{"message": "This action is unauthorized."}</code></td></tr>
<tr><td><code>404</code></td><td>No record for the given id (Eloquent <code>findOrFail</code>), disabled module, or unknown route.</td><td><code>{"message": "No query results for model [App\\\\Models\\\\Client] 42"}</code></td></tr>
<tr><td><code>409</code></td><td>Conflict — e.g. another update running, reset blocked by references, duplicate serial.</td><td><code>{"message": "…"}</code></td></tr>
<tr><td><code>422</code></td><td>Validation failed.</td><td>see below</td></tr>
<tr><td><code>429</code></td><td>Rate limit exceeded (endpoints with a <em>throttle</em> chip).</td><td><code>{"message": "Too Many Attempts."}</code></td></tr>
<tr><td><code>500</code></td><td>Unhandled exception. A few legacy reads return <code>{"statut": "error"}</code> with 500 when a settings row is missing.</td><td>—</td></tr></table>

<h2 id="validation">Validation errors (422)</h2>
<pre class="code"><code>{
  "message": "The given data was invalid.",
  "errors": {
    "client_id": ["The client id field is required."],
    "details": ["The details field must be an array."]
  }
}</code></pre>
<p>Keys are the field names (dot-notation for nested fields such as <code>details.0.quantity</code>); values are arrays of messages in the request locale.</p>

<h2 id="envelopes">Envelopes to be aware of</h2>
<ul>
  <li><strong>Login</strong> — <code>POST /api/getAccessToken</code> answers <code>200</code> with <code>status: false</code> for bad credentials instead of <code>401</code>.</li>
  <li><strong>sendResponse / sendError</strong> — controllers built on the <code>BaseController</code> wrap results as <code>{"success": true, "data": …, "message": "…"}</code> and errors as <code>{"success": false, "message": "…", "errors": …}</code> (HTTP 400 or 404). Endpoints using them are marked in their response descriptions.</li>
  <li><strong>Feature not available</strong> — some reports return <code>200</code> with <code>{"supported": false}</code> when the relevant migration has not been run (batch/pharmacy tables).</li>
</ul>
`},

{
  id: 'permissions',
  title: 'Permissions',
  summary: 'How role permissions map to endpoints and what the permission chips mean.',
  html: `
<p class="lead">Access to the admin API is governed by role permissions. Each user has one role; each role holds a set of permission slugs (e.g. <code>Sales_add</code>, <code>Reports_profit</code>, <code>setting_system</code>). Controllers check the slug through Laravel policies before doing anything.</p>

<h2 id="chips">Reading the permission chip</h2>
<p>Each endpoint shows the slug(s) it checks, for example <span class="chip perm" style="font-size:10.5px">Sales_add</span>. Some endpoints list two slugs joined by "or" — either grants access (e.g. an area-specific slug or the global <code>setting_system</code>). Endpoints without a permission chip require only a valid login; endpoints marked <em>public</em> require nothing.</p>
<p>The <a href="#/op/get_api_user"><code>GET /api/user</code></a> response includes the caller's permission names, so a client can hide features the user cannot use before making requests. Requests that fail the check return <code>403</code>.</p>

<h2 id="special">Special flags</h2>
<table><tr><th>Flag</th><th>Effect</th></tr>
<tr><td><code>record_view</code></td><td>See all users' documents in lists and reports. Without it, only the caller's own documents are visible.</td></tr>
<tr><td><code>is_all_warehouses</code> (user setting)</td><td>Access every warehouse. Otherwise only assigned warehouses are visible/usable.</td></tr>
<tr><td><code>check_record</code></td><td>Checked when editing/deleting someone else's document.</td></tr></table>

<h2 id="all">All permission slugs used by the API</h2>
<p>Generated from the reference. Click a slug to search for the endpoints that use it.</p>
<div data-dynamic="permissions"></div>
`},

{
  id: 'webhooks',
  title: 'Webhooks',
  summary: 'Outgoing event webhooks with HMAC signatures, the generic incoming endpoint and payment-gateway callbacks.',
  html: `
<p class="lead">Stocky can push events to your systems (outgoing webhooks), receive events from third parties (incoming webhooks) and accept payment-provider notifications for the online store.</p>

<h2 id="outgoing">Outgoing webhooks</h2>
<p>Create a subscription with <a href="#/op/post_api_webhooks"><code>POST /api/webhooks</code></a> giving a <code>url</code>, the <code>events</code> to subscribe to (see <a href="#/op/get_api_webhooks_available-events"><code>GET /api/webhooks/available-events</code></a>, e.g. <code>sale.created</code>, <code>product.updated</code>), optional custom <code>headers</code> and a <code>timeout_seconds</code>. A signing <code>secret</code> is generated for you.</p>
<p>Each delivery is an HTTP <code>POST</code> with a JSON body and these headers:</p>
<pre class="code"><code>Content-Type: application/json
X-Stocky-Event: sale.created
X-Stocky-Signature: &lt;hex HMAC-SHA256 of the raw body using the webhook secret&gt;</code></pre>
<p>Verify the signature before trusting a payload:</p>
<pre class="code"><code>// Node.js
const crypto = require('crypto');
const expected = crypto.createHmac('sha256', secret).update(rawBody).digest('hex');
const ok = crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(req.headers['x-stocky-signature']));</code></pre>
<pre class="code"><code>// PHP
$expected = hash_hmac('sha256', $rawBody, $secret);
$ok = hash_equals($expected, $_SERVER['HTTP_X_STOCKY_SIGNATURE'] ?? '');</code></pre>
<p>Respond with any <code>2xx</code> within the timeout. Failed deliveries are retried and every attempt is logged — inspect them with <a href="#/op/get_api_webhooks_deliveries"><code>GET /api/webhooks/deliveries</code></a>, send a test event with <code>POST /api/webhooks/{id}/test</code>, and rotate the secret with <code>POST /api/webhooks/{id}/regenerate-secret</code>.</p>

<h2 id="incoming">Incoming webhooks</h2>
<p><a href="#/op/post_api_webhooks_incoming_source"><code>POST /api/webhooks/incoming/{source}</code></a> is a public receiver for third-party systems. The request is logged, its signature is checked against the secret configured for that <code>source</code>, and the payload is dispatched to the matching handler. Review received payloads under <em>Integrations → Incoming logs</em> or via <code>GET /api/webhooks/incoming-logs</code>.</p>

<h2 id="gateways">Payment gateway callbacks</h2>
<p>The online store exposes provider-specific webhook receivers (<code>/api/store/webhooks/paypal</code>, <code>…/razorpay</code>, <code>…/paystack</code>, <code>…/flutterwave</code>, <code>…/sslcommerz</code>) plus browser return URLs. Each verifies the provider's signature scheme before marking an order paid; webhook and return URL are idempotent with each other. Marketplace integrations (Shopify, Salla) have their own receivers under <code>/api/shopify/webhook</code> and <code>/api/salla/webhook</code>. See the <em>Integrations</em> section for details.</p>
`},

{
  id: 'conventions',
  title: 'Conventions & field glossary',
  summary: 'Recurring field names, status values, document lines and other patterns you will see everywhere.',
  html: `
<p class="lead">The API grew with the product, so a few names are unconventional. This page collects the patterns that repeat across modules.</p>

<h2 id="fields">Recurring field names</h2>
<table><tr><th>Field</th><th>Meaning</th></tr>
<tr><td><code>Ref</code></td><td>Human document reference (prefix + number), e.g. <code>SL_1112</code>, <code>PR_1043</code>, <code>QT_1005</code>, <code>TR_1001</code>, <code>AD_1002</code>.</td></tr>
<tr><td><code>statut</code></td><td>Document status (French spelling). Sales: <code>completed</code> / <code>pending</code> / <code>ordered</code>; purchases: <code>received</code> / <code>pending</code> / <code>ordered</code>; transfers: <code>completed</code> / <code>pending</code> / <code>sent</code>.</td></tr>
<tr><td><code>payment_statut</code></td><td><code>paid</code> / <code>partial</code> / <code>unpaid</code>, computed from <code>paid_amount</code> vs <code>GrandTotal</code>.</td></tr>
<tr><td><code>GrandTotal</code>, <code>TaxNet</code>, <code>discount</code>, <code>shipping</code></td><td>Document totals: grand total, order-level tax amount, order discount and shipping.</td></tr>
<tr><td><code>tax_rate</code> / <code>tax_percent</code></td><td>Order-level / line-level tax percentage.</td></tr>
<tr><td><code>tax_method</code></td><td><code>1</code> = exclusive (tax added on top), <code>2</code> = inclusive.</td></tr>
<tr><td><code>discount_Method</code> / <code>discount_method</code></td><td><code>1</code> = percentage, <code>2</code> = fixed amount.</td></tr>
<tr><td><code>adresse</code>, <code>CompanyAdress</code></td><td>Address (French / legacy spelling kept for compatibility).</td></tr>
<tr><td><code>client_id</code>, <code>provider_id</code></td><td>Customer and supplier ids (<em>client</em> = customer, <em>provider</em> = supplier).</td></tr>
<tr><td><code>montant</code></td><td>Payment amount.</td></tr>
<tr><td><code>date</code></td><td>Document date <code>YYYY-MM-DD</code>; many create endpoints also store the current time.</td></tr>
<tr><td><code>selectedIds</code></td><td>Array of ids for bulk delete endpoints (<code>…/delete/by_selection</code>).</td></tr>
<tr><td><code>is_pos</code></td><td><code>1</code> when a sale was created from the POS screen.</td></tr></table>

<h2 id="lines">Document lines</h2>
<p>Sales, quotations, purchases, returns, transfers and adjustments send their lines in a <code>details[]</code> array. A typical sale line:</p>
<pre class="code"><code>{
  "product_id": 12,
  "product_variant_id": null,
  "quantity": 2,
  "sale_unit_id": 1,
  "Unit_price": 49.90,
  "tax_percent": 15,
  "tax_method": "1",
  "discount": 0,
  "discount_Method": "2",
  "subtotal": 114.77,
  "imei_number": "SN-0001,SN-0002",
  "batches": [ { "product_batch_id": 3, "qty": 2 } ]
}</code></pre>
<p>Stock is affected only when the document status is <code>completed</code> / <code>received</code>. Quantities are multiplied by the unit's operator and by <code>pack_multiplier</code> for multi-pack sales.</p>

<h2 id="deletes">Soft deletes</h2>
<p>Most records are soft-deleted (<code>deleted_at</code>). Deleted rows disappear from lists and return <code>404</code> on direct access. Deleting a completed document reverses its stock and payment effects.</p>

<h2 id="uploads">File uploads</h2>
<p>Use <code>multipart/form-data</code> with <code>POST</code>. Where a resource is updated with <code>PUT</code> and needs a file, most controllers expose a <code>POST …/{id}/update</code> or <code>POST …/{id}</code> variant, because PHP does not parse multipart bodies on <code>PUT</code>. Images are usually resized server-side; size limits are stated per endpoint.</p>

<h2 id="locale">Language</h2>
<p>Validation messages and generated documents follow the request locale. The admin app stores the locale with <code>POST /api/sync-locale</code>; API clients can send an <code>X-Locale</code> header (<code>en</code>, <code>ar</code>, …).</p>

<h2 id="sections">Reference sections</h2>
<div data-dynamic="sections"></div>
`}
];
