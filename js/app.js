// Shared page pieces for The Senior Family Table. Requires js/api.js.

const NAV = [
  ['index.html', 'Cookbooks', '📚'],
  ['search.html', 'Search', '🔍'],
];

// Header (+ bottom nav on phones). Call first on every page; returns the <main> element.
function renderShell(activeFile) {
  const links = NAV.map(([href, label, icon]) =>
    `<a href="${href}"${href === activeFile ? ' aria-current="page"' : ''}><span aria-hidden="true">${icon}</span> ${label}</a>`).join('');
  const header = document.createElement('header');
  header.className = 'site-header';
  header.innerHTML = `
    <div class="bar">
      <a class="brand" href="index.html"><span aria-hidden="true">🍽️</span> The Senior Family Table</a>
      <nav class="top-nav" aria-label="Main">${links}
        <button type="button" class="link-btn" id="nav-logout">Sign out</button></nav>
    </div>`;
  document.body.prepend(header);
  const bottom = document.createElement('nav');
  bottom.className = 'bottom-nav';
  bottom.setAttribute('aria-label', 'Main');
  bottom.innerHTML = links;
  document.body.append(bottom);
  document.getElementById('nav-logout').addEventListener('click', async () => {
    const token = getToken();
    clearToken();
    try { await apiPost('logout', { token }); } catch (e) { /* offline */ }
    window.location.href = 'index.html';
  });
  return document.querySelector('main');
}

// ---- Formatting ----

const FRACTIONS = [[1 / 8, '⅛'], [1 / 6, '⅙'], [1 / 5, '⅕'], [1 / 4, '¼'], [1 / 3, '⅓'], [3 / 8, '⅜'], [1 / 2, '½'],
  [5 / 8, '⅝'], [2 / 3, '⅔'], [3 / 4, '¾'], [7 / 8, '⅞']];

// 0.5 → "½", 1.25 → "1¼", 2 → "2", 0.4 → "0.4"
function formatQuantity(q) {
  if (q === null || q === undefined || q === '') { return ''; }
  const n = Number(q);
  const whole = Math.floor(n + 1e-9);
  const frac = n - whole;
  if (frac < 0.01) { return String(whole); }
  for (const [value, glyph] of FRACTIONS) {
    if (Math.abs(frac - value) < 0.01) { return (whole > 0 ? String(whole) : '') + glyph; }
  }
  return String(Math.round(n * 100) / 100);
}

// Editor-friendly text for a stored quantity: 1.5 → "1 1/2", 0.3333 → "1/3".
const PLAIN_FRACTIONS = [[1 / 8, '1/8'], [1 / 4, '1/4'], [1 / 3, '1/3'], [1 / 2, '1/2'], [2 / 3, '2/3'], [3 / 4, '3/4']];
function quantityToInput(q) {
  if (q === null || q === undefined) { return ''; }
  const n = Number(q);
  const whole = Math.floor(n + 1e-9);
  const frac = n - whole;
  if (frac < 0.01) { return String(whole); }
  for (const [value, text] of PLAIN_FRACTIONS) {
    if (Math.abs(frac - value) < 0.01) { return whole > 0 ? `${whole} ${text}` : text; }
  }
  return String(Math.round(n * 1000) / 1000);
}

// 155 → "2 hr 35 min"
function formatMinutes(m) {
  if (m === null || m === undefined) { return ''; }
  const h = Math.floor(m / 60);
  const min = m % 60;
  return [h ? `${h} hr` : '', min ? `${min} min` : ''].filter(Boolean).join(' ') || '0 min';
}

function formatDateTime(s) {
  const d = new Date(String(s).replace(' ', 'T'));
  return isNaN(d) ? String(s) : d.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

// Plain text with line breaks and blank-line paragraphs; always escaped.
function formatText(text) {
  if (!text) { return ''; }
  return String(text).split(/\n{2,}/).map(p => `<p>${escapeHtml(p).replace(/\n/g, '<br>')}</p>`).join('');
}

const ROLE_LABELS = {
  owner: 'Owner', editor: 'Editor', viewer: 'Viewer', co_owner: 'Co-owner',
};

function param(name) { return new URLSearchParams(window.location.search).get(name); }

// Short confirmation message at the bottom of the screen.
function toast(message) {
  let el = document.getElementById('toast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'toast';
    el.className = 'toast';
    el.setAttribute('role', 'status');
    document.body.append(el);
  }
  el.textContent = message;
  el.classList.add('show');
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => el.classList.remove('show'), 2600);
}

// Shows an API error next to its field (data-field="..."), or in the given box.
function showFieldError(form, err, fallbackBox) {
  form.querySelectorAll('.field-error[data-for]').forEach(e => { e.textContent = ''; });
  form.querySelectorAll('.invalid').forEach(e => e.classList.remove('invalid'));
  const field = err && err.field;
  if (field) {
    const input = form.querySelector(`[data-field="${CSS.escape(field)}"]`);
    if (input) {
      input.classList.add('invalid');
      input.focus();
      const box = form.querySelector(`.field-error[data-for="${CSS.escape(field)}"]`);
      if (box) { box.textContent = err.message; return; }
    }
  }
  if (fallbackBox) { fallbackBox.textContent = (err && err.message) || 'Something went wrong.'; }
}
