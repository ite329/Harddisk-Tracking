/* ── State ── */
let models = [];
let catStyles = {};
let activeCategory = 'All';
let activeMode = 'scroll';
let query = '';
let loading = true;
let debounceTimer = null;

const API_URL = './api/models.json';

const MIN_LOADING_MS = 800;

async function fetchModels() {
  loading = true;
  render();
  const start = Date.now();
  try {
    const res = await fetch(API_URL);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    models = data.models;
    catStyles = data.categories || {};
  } catch (err) {
    console.error('Failed to fetch models:', err);
    models = [];
  }
  const elapsed = Date.now() - start;
  if (elapsed < MIN_LOADING_MS) {
    await new Promise(r => setTimeout(r, MIN_LOADING_MS - elapsed));
  }
  loading = false;
  render();
}

/* ── Elements ── */
const card           = document.getElementById('card');
const searchInput    = document.getElementById('searchInput');
const searchKbd      = document.getElementById('searchKbd');
const searchClear    = document.getElementById('searchClear');
const tableContainer = document.getElementById('tableContainer');
const tableBody      = document.getElementById('tableBody');
const viewCards      = document.getElementById('viewCards');
const viewStack      = document.getElementById('viewStack');
const viewScroll     = document.getElementById('viewScroll');
const emptyState     = document.getElementById('emptyState');
const resultCount    = document.getElementById('resultCount');
const themeToggle    = document.getElementById('themeToggle');
const refreshBtn     = document.getElementById('refreshBtn');
const exportBtn      = document.getElementById('exportBtn');
const modeSwitcher   = document.getElementById('modeSwitcher');
const modeIndicator  = document.getElementById('modeIndicator');
const categoryDropdown = document.getElementById('categoryDropdown');
const dropdownTrigger  = document.getElementById('dropdownTrigger');
const dropdownMenu     = document.getElementById('dropdownMenu');
const dropdownValue    = document.getElementById('dropdownValue');

/* ── Brand icon helper ── */
function brandIcon(iconId, color, bg) {
  const src = document.getElementById('svg-' + iconId);
  if (!src) return '';
  const svg = src.cloneNode(true);
  svg.removeAttribute('id');
  svg.setAttribute('width', '16');
  svg.setAttribute('height', '16');
  svg.setAttribute('fill', color);
  svg.setAttribute('aria-hidden', 'true');
  const wrap = document.createElement('div');
  wrap.appendChild(svg);
  return `<span class="brand-icon" style="background:${bg};border-color:${color}20">${wrap.innerHTML}</span>`;
}

/* ── Category badge helper ── */
function catBadge(name) {
  const s = catStyles[name];
  if (s) return `<span class="badge-category" style="color:${s.color};background:${s.bg}">${name}</span>`;
  return `<span class="badge-category">${name}</span>`;
}

function catBadges(cats) {
  return cats.map(catBadge).join('');
}

/* ── Skeletons ── */
function skeletonTableRows(n) {
  return Array.from({ length: n }, () => `
    <tr class="skeleton-row">
      <td><div class="model-name-cell"><span class="skel skel-icon"></span><span class="skel skel-text" style="width:8rem"></span></div></td>
      <td><span class="skel skel-text" style="width:5.5rem"></span></td>
      <td><span class="skel skel-pill"></span></td>
      <td><span class="skel skel-text" style="width:4.5rem"></span></td>
      <td><span class="skel skel-text" style="width:5rem"></span></td>
      <td><span class="skel skel-text" style="width:4rem"></span></td>
    </tr>`).join('');
}

function skeletonCards(n) {
  return Array.from({ length: n }, () => `
    <div class="model-card skeleton-card">
      <div class="model-card-header">
        <span class="skel skel-icon"></span>
        <div class="model-card-title" style="flex:1">
          <span class="skel skel-text" style="width:60%"></span>
          <span class="skel skel-text-sm" style="width:40%;margin-top:0.25rem"></span>
        </div>
      </div>
      <div class="model-card-meta">
        <div class="meta-item"><span class="skel skel-label"></span><span class="skel skel-pill"></span></div>
        <div class="meta-item"><span class="skel skel-label"></span><span class="skel skel-text" style="width:4rem"></span></div>
        <div class="meta-item"><span class="skel skel-label"></span><span class="skel skel-text" style="width:5rem"></span></div>
        <div class="meta-item"><span class="skel skel-label"></span><span class="skel skel-text" style="width:3.5rem"></span></div>
      </div>
    </div>`).join('');
}

function skeletonStack(n) {
  return Array.from({ length: n }, () => `
    <div class="stack-row skeleton-stack">
      <div class="stack-identity">
        <span class="skel skel-icon"></span>
        <span class="skel skel-text" style="width:9rem"></span>
        <span class="skel skel-text-sm" style="width:5rem"></span>
      </div>
      <div class="stack-fields">
        <span class="skel skel-pill"></span>
        <span class="skel skel-text" style="width:4.5rem"></span>
        <span class="skel skel-text" style="width:5rem"></span>
        <span class="skel skel-text" style="width:4rem"></span>
      </div>
    </div>`).join('');
}

/* ── Filter ── */
function filterModels() {
  const q = query.toLowerCase();
  return models.filter(m => {
    const cats = m.categories || [];
    const catMatch = activeCategory === 'All' || cats.some(c => c.toLowerCase() === activeCategory.toLowerCase());
    const catText = cats.join(' ').toLowerCase();
    const qMatch = !q || m.name.toLowerCase().includes(q) || m.provider.toLowerCase().includes(q) || catText.includes(q);
    return catMatch && qMatch;
  });
}

/* ── Re-trigger view animation ── */
function reanimateView(el) {
  el.style.animation = 'none';
  el.offsetHeight;
  el.style.animation = '';
}

/* ── Render ── */
let wasLoading = false;
let lastCount = 8;

function renderContent(filtered) {
  tableBody.innerHTML = filtered.map(m => `
    <tr>
      <td><div class="model-name-cell">${brandIcon(m.icon, m.iconColor, m.iconBg)}<span class="model-name">${m.name}</span>${m.recent ? '<span class="badge-recent">Recent</span>' : ''}</div></td>
      <td>${m.provider}</td>
      <td><div class="badge-group">${catBadges(m.categories)}</div></td>
      <td class="mono">${m.params}</td>
      <td class="mono">${m.context}</td>
      <td class="mono">${m.latency}</td>
    </tr>`).join('');

  viewCards.innerHTML = filtered.map(m => `
    <div class="model-card">
      <div class="model-card-header">
        ${brandIcon(m.icon, m.iconColor, m.iconBg)}
        <div class="model-card-title">
          <strong>${m.name}</strong>
          ${m.recent ? '<span class="badge-recent">Recent</span>' : ''}
          <span class="model-card-provider">${m.provider}</span>
        </div>
      </div>
      <div class="model-card-meta">
        <div class="meta-item"><span class="meta-label">Category</span><div class="badge-group">${catBadges(m.categories)}</div></div>
        <div class="meta-item"><span class="meta-label">Parameters</span><span class="mono">${m.params}</span></div>
        <div class="meta-item"><span class="meta-label">Context</span><span class="mono">${m.context}</span></div>
        <div class="meta-item"><span class="meta-label">Latency</span><span class="mono">${m.latency}</span></div>
      </div>
    </div>`).join('');

  viewStack.innerHTML = filtered.map(m => `
    <div class="stack-row">
      <div class="stack-identity">
        ${brandIcon(m.icon, m.iconColor, m.iconBg)}
        <span class="model-name">${m.name}</span>
        ${m.recent ? '<span class="badge-recent">Recent</span>' : ''}
        <span class="model-card-provider">\u00B7 ${m.provider}</span>
      </div>
      <div class="stack-fields">
        <div class="stack-field"><span class="meta-label">Category</span><div class="badge-group">${catBadges(m.categories)}</div></div>
        <div class="stack-field"><span class="meta-label">Params</span><span class="meta-value mono">${m.params}</span></div>
        <div class="stack-field"><span class="meta-label">Context</span><span class="meta-value mono">${m.context}</span></div>
        <div class="stack-field"><span class="meta-label">Latency</span><span class="meta-value mono">${m.latency}</span></div>
      </div>
    </div>`).join('');
}

function render() {
  if (loading) {
    const n = lastCount || 8;
    resultCount.textContent = 'Loading\u2026';
    emptyState.classList.add('hidden');
    tableBody.innerHTML = skeletonTableRows(n);
    viewCards.innerHTML = skeletonCards(n);
    viewStack.innerHTML = skeletonStack(n);
    wasLoading = true;
    return;
  }

  const filtered = filterModels();
  lastCount = filtered.length;
  resultCount.textContent = filtered.length === 1 ? '1 model' : filtered.length + ' models';
  emptyState.classList.toggle('hidden', filtered.length > 0);

  /* Crossfade from skeleton → real content — lock height to prevent shift */
  if (wasLoading) {
    wasLoading = false;
    const prevHeight = tableContainer.offsetHeight;
    tableContainer.style.height = prevHeight + 'px';
    tableContainer.style.overflow = 'hidden';
    tableContainer.style.opacity = '0';
    renderContent(filtered);
    requestAnimationFrame(() => {
      const newHeight = tableContainer.scrollHeight;
      tableContainer.style.transition = 'opacity 0.3s ease, height 0.3s ease';
      tableContainer.style.opacity = '1';
      tableContainer.style.height = newHeight + 'px';
      setTimeout(() => {
        tableContainer.style.height = '';
        tableContainer.style.overflow = '';
        tableContainer.style.transition = '';
      }, 320);
    });
  } else {
    renderContent(filtered);
  }
}

/* ── Search ── */
searchInput.addEventListener('input', () => {
  clearTimeout(debounceTimer);
  const val = searchInput.value;
  searchKbd.classList.toggle('hidden', val.length > 0);
  searchClear.classList.toggle('hidden', val.length === 0);
  debounceTimer = setTimeout(() => { query = val; render(); }, 200);
});

searchClear.addEventListener('click', () => {
  searchInput.value = ''; query = '';
  searchKbd.classList.remove('hidden');
  searchClear.classList.add('hidden');
  searchInput.focus(); render();
});

/* ── Custom category dropdown ── */
dropdownTrigger.addEventListener('click', () => {
  const isOpen = categoryDropdown.classList.toggle('open');
  dropdownTrigger.setAttribute('aria-expanded', isOpen);
});

dropdownMenu.addEventListener('click', e => {
  const item = e.target.closest('.dropdown-item');
  if (!item) return;
  dropdownMenu.querySelectorAll('.dropdown-item').forEach(i => {
    i.classList.remove('active');
    i.setAttribute('aria-selected', 'false');
  });
  item.classList.add('active');
  item.setAttribute('aria-selected', 'true');
  activeCategory = item.dataset.value;
  dropdownValue.textContent = item.querySelector('span').textContent;
  categoryDropdown.classList.remove('open');
  dropdownTrigger.setAttribute('aria-expanded', 'false');
  render();
});

document.addEventListener('click', e => {
  if (!categoryDropdown.contains(e.target)) {
    categoryDropdown.classList.remove('open');
    dropdownTrigger.setAttribute('aria-expanded', 'false');
  }
});

/* ── Mode switcher ── */
modeSwitcher.addEventListener('click', e => {
  const btn = e.target.closest('.mode-btn');
  if (!btn || btn.classList.contains('active')) return;

  modeSwitcher.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  modeIndicator.setAttribute('data-pos', btn.dataset.index);
  activeMode = btn.dataset.mode;
  tableContainer.setAttribute('data-mode', activeMode);
  const view = { scroll: viewScroll, cards: viewCards, stack: viewStack }[activeMode];
  if (view) reanimateView(view);
});

/* ── Theme toggle ── */
themeToggle.addEventListener('click', () => {
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const next = isDark ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  card.classList.toggle('dark', !isDark);
});

/* ── Refresh ── */
refreshBtn.addEventListener('click', () => {
  refreshBtn.classList.add('spinning');
  fetchModels().finally(() => refreshBtn.classList.remove('spinning'));
});

/* ── Export CSV ── */
exportBtn.addEventListener('click', () => {
  const filtered = filterModels();
  if (!filtered.length) return;
  const header = 'Name,Provider,Categories,Parameters,Context,Latency';
  const rows = filtered.map(m => `"${m.name}","${m.provider}","${m.categories.join(', ')}","${m.params}","${m.context}","${m.latency}"`);
  const blob = new Blob([header + '\n' + rows.join('\n')], { type: 'text/csv' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'ai-models.csv';
  a.click();
  URL.revokeObjectURL(a.href);
});

/* ── Keyboard shortcuts ── */
document.addEventListener('keydown', e => {
  if ((e.metaKey || e.ctrlKey) && e.key === 'k') { e.preventDefault(); searchInput.focus(); }
  if (e.key === '/' && !['INPUT','SELECT'].includes(document.activeElement.tagName)) { e.preventDefault(); searchInput.focus(); }
  if (e.key === 'Escape') {
    searchInput.value = ''; query = ''; searchInput.blur();
    searchKbd.classList.remove('hidden'); searchClear.classList.add('hidden');
    render();
  }
});

/* ── Init ── */
fetchModels();
