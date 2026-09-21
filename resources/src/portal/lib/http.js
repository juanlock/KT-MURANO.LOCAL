/**
 * Portal HTTP client — the Vue 3 port of the axios setup in the legacy
 * portal.js. Deliberately mimics axios's shape ({ data }, error.response.status)
 * so the ported views are a near-verbatim `axios` -> `http` swap.
 *
 *  - base URL /api/ (so '/portal/me' -> '/api/portal/me', as axios combined it)
 *  - sends the session cookie + X-Requested-With + X-CSRF-TOKEN (from the meta
 *    tag), matching the legacy defaults
 *  - a 401/403 on any /portal/ URL bounces to /portal/login (except while
 *    already on a guest page), reproducing the legacy response interceptor
 */
const BASE = '/api';

function csrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return (meta && meta.getAttribute('content')) || window.__PORTAL_CSRF__ || '';
}

function fullUrl(url) {
  if (/^https?:\/\//i.test(url)) return url;
  return `${BASE}/${String(url).replace(/^\//, '')}`;
}

function onGuestPage() {
  const p = window.location.pathname;
  return p.indexOf('/portal/login') !== -1 || p.indexOf('/portal/set-password') !== -1;
}

async function request(method, url, body, config = {}) {
  const headers = {
    'X-Requested-With': 'XMLHttpRequest',
    Accept: 'application/json',
    ...(config.headers || {}),
  };
  const token = csrfToken();
  if (token) headers['X-CSRF-TOKEN'] = token;

  const init = { method, credentials: 'same-origin', headers };

  // Build the URL, appending config.params like axios does.
  let target = fullUrl(url);
  if (config.params && typeof config.params === 'object') {
    const qs = new URLSearchParams();
    Object.entries(config.params).forEach(([k, v]) => {
      if (v !== undefined && v !== null) qs.append(k, v);
    });
    const q = qs.toString();
    if (q) target += (target.indexOf('?') === -1 ? '?' : '&') + q;
  }

  // axios lets DELETE carry a payload via `config.data`; the delete/get
  // helpers pass no body, so pick it up here or it would be dropped.
  if ((body === undefined || body === null) && config.data !== undefined && config.data !== null) {
    body = config.data;
  }

  if (body !== undefined && body !== null) {
    if (body instanceof FormData) {
      init.body = body; // let the browser set the multipart boundary
    } else {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
  }

  let res;
  try {
    res = await fetch(target, init);
  } catch (networkErr) {
    return Promise.reject({ message: 'Network error', response: null });
  }

  // Reproduce the legacy interceptor: portal 401/403 -> login redirect.
  const isPortal = String(url).indexOf('/portal/') !== -1 || String(url).indexOf('portal/') === 0;
  if (isPortal && (res.status === 401 || res.status === 403) && !onGuestPage()) {
    window.location.replace('/portal/login');
    return Promise.reject({ response: { status: res.status } });
  }

  let data = null;
  const text = await res.text();
  if (text) {
    try { data = JSON.parse(text); } catch (e) { data = text; }
  }

  if (!res.ok) {
    return Promise.reject({ response: { status: res.status, data }, data });
  }
  return { data, status: res.status };
}

const http = {
  get: (url, config) => request('GET', url, null, config),
  post: (url, body, config) => request('POST', url, body, config),
  put: (url, body, config) => request('PUT', url, body, config),
  patch: (url, body, config) => request('PATCH', url, body, config),
  delete: (url, config) => request('DELETE', url, null, config),
};

export default http;
