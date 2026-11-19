// The Senior Family Table — talks to api/api.php on MyDataWorld.
const CONFIG = {
  // Served locally (testing), the API sits next to the pages.
  API_URL: ['localhost', '127.0.0.1'].includes(window.location.hostname)
    ? 'api/api.php'
    : 'https://seniorfamily.org/senior-family-table-api/api.php',
};

// ---- Login (My Apps Hub SSO token, or the login form below) ----
const TOKEN_KEY = 'sftToken';
function getToken() { try { return localStorage.getItem(TOKEN_KEY) || ''; } catch (e) { return ''; } }
function saveToken(t) { try { localStorage.setItem(TOKEN_KEY, t); } catch (e) { /* private mode */ } }
function clearToken() { try { localStorage.removeItem(TOKEN_KEY); } catch (e) { /* ignore */ } }
function authHeaders() {
  const t = getToken();
  return t ? { Authorization: `Bearer ${t}` } : {};
}

// A failed request. `field` names the form field the message belongs to.
class ApiError extends Error {
  constructor(message, field, status, data) {
    super(message);
    this.field = field || null;
    this.status = status;
    this.data = data || null;
  }
}

async function readResponse(res) {
  let data = null;
  try { data = await res.json(); } catch (e) { /* not JSON */ }
  // 401 = not signed in; 403 *about app access* = signed in but not granted. Other 403s
  // ("you can view but not edit") are ordinary errors shown in place.
  const noAppAccess = res.status === 403 && /access to The Senior Family Table/.test((data && data.error) || '');
  if (res.status === 401 || noAppAccess) {
    throw new ApiError('not-authorized', null, res.status, data);
  }
  if (!res.ok || !data || data.ok === false) {
    throw new ApiError((data && data.error) || `Request failed (${res.status})`, data && data.field, res.status, data);
  }
  return data;
}

async function apiGet(action, params) {
  const qs = new URLSearchParams({ action, ...(params || {}) });
  return readResponse(await fetch(`${CONFIG.API_URL}?${qs}`, { headers: authHeaders() }));
}

async function apiPost(action, payload) {
  return readResponse(await fetch(CONFIG.API_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'text/plain', ...authHeaders() },
    body: JSON.stringify({ action, ...(payload || {}) }),
  }));
}

function escapeHtml(str) {
  const d = document.createElement('div');
  d.textContent = str ?? '';
  return d.innerHTML;
}

// Login form, or "not granted yet", in place of the page.
function renderGate(container, reason) {
  if (reason === 'denied') {
    container.innerHTML = `<div class="card narrow"><h2>Almost there</h2><p>Your login isn't set up for The Senior Family Table yet.
      Ask someone in the family to share a cookbook with you, or ask to be granted the app in My Apps Hub.</p></div>`;
    return;
  }
  container.innerHTML = `
    <div class="card narrow">
      <h2>Sign in</h2>
      <p class="muted">Use the same email and password as My Apps Hub. Opening The Senior Family Table from the Hub signs you in automatically.</p>
      <label for="g-email">Email</label>
      <input type="email" id="g-email" autocomplete="username">
      <label for="g-pw">Password</label>
      <input type="password" id="g-pw" autocomplete="current-password">
      <div class="field-error" id="g-msg" role="alert"></div>
      <div class="actions"><button type="button" class="btn" id="g-btn">Sign in</button></div>
    </div>`;
  const email = document.getElementById('g-email');
  const pw = document.getElementById('g-pw');
  const msg = document.getElementById('g-msg');
  const btn = document.getElementById('g-btn');
  async function attempt() {
    msg.textContent = '';
    btn.disabled = true;
    try {
      const data = await apiPost('login', { username: email.value.trim(), password: pw.value });
      saveToken(data.token);
      window.location.reload();
    } catch (e) {
      msg.textContent = e.status === 401 || e.status === 400 ? (e.data && e.data.error) || 'Incorrect email or password.' : 'Something went wrong. Please try again.';
      btn.disabled = false;
    }
  }
  btn.addEventListener('click', attempt);
  pw.addEventListener('keydown', ev => { if (ev.key === 'Enter') { attempt(); } });
  email.focus();
}

function handleError(container, err) {
  if (err && err.message === 'not-authorized') {
    renderGate(container, err.status === 403 ? 'denied' : 'no-token');
  } else if (err && err.status === 404) {
    container.innerHTML = `<div class="card"><p>${escapeHtml(err.message)}.</p><p><a href="index.html">Back to your cookbooks</a></p></div>`;
  } else {
    console.error(err);
    container.innerHTML = `<div class="card"><p>Couldn't load right now. Check your connection and refresh.</p></div>`;
  }
}

// My Apps Hub opens the app with ?token=... — keep it as the login and take it out of the address bar.
function captureSso() {
  const params = new URLSearchParams(window.location.search);
  const token = params.get('token');
  if (!token) { return; }
  saveToken(token);
  params.delete('token');
  const rest = params.toString();
  window.history.replaceState({}, document.title, window.location.pathname + (rest ? `?${rest}` : ''));
}
captureSso();
