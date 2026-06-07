'use strict';

// ── SIDEBAR TOGGLE ──────────────────────────────────
const sidebar = document.getElementById('sidebar');
const menuBtn = document.getElementById('menuBtn');
const sidebarToggle = document.getElementById('sidebarToggle');
let overlay = null;

function openSidebar() {
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', closeSidebar);
  }
  sidebar?.classList.add('open');
  overlay.classList.add('active');
}

function closeSidebar() {
  sidebar?.classList.remove('open');
  overlay?.classList.remove('active');
}

menuBtn?.addEventListener('click', openSidebar);
sidebarToggle?.addEventListener('click', closeSidebar);

// ── TOAST ──────────────────────────────────────────
const ICONS = {
  success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>',
  error:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
  info:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
  warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
};

window.toast = function(message, type = 'info', duration = 4000) {
  const container = document.getElementById('toast-container');
  if (!container) return;
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  // Icon is static SVG markup from a controlled constant — safe to use innerHTML
  const iconEl = document.createElement('span');
  iconEl.innerHTML = ICONS[type] || ICONS.info;
  const msgEl = document.createElement('span');
  msgEl.className = 'toast-message';
  msgEl.textContent = message;
  el.appendChild(iconEl);
  el.appendChild(msgEl);
  container.appendChild(el);
  requestAnimationFrame(() => { requestAnimationFrame(() => el.classList.add('show')); });
  setTimeout(() => {
    el.classList.remove('show');
    el.addEventListener('transitionend', () => el.remove(), { once: true });
  }, duration);
};

// ── MODAL ──────────────────────────────────────────
window.Modal = {
  open(id) {
    const backdrop = document.getElementById(id);
    if (!backdrop) return;
    backdrop.style.display = 'flex';
    requestAnimationFrame(() => { requestAnimationFrame(() => backdrop.classList.add('active')); });
    document.body.style.overflow = 'hidden';
  },
  close(id) {
    const backdrop = document.getElementById(id);
    if (!backdrop) return;
    backdrop.classList.remove('active');
    backdrop.addEventListener('transitionend', () => {
      backdrop.style.display = 'none';
      document.body.style.overflow = '';
    }, { once: true });
  }
};

// Close modal on backdrop click or close button
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-backdrop')) {
    Modal.close(e.target.id);
  }
  if (e.target.closest('[data-modal-close]')) {
    const modal = e.target.closest('.modal-backdrop');
    if (modal) Modal.close(modal.id);
  }
});

// Close on Escape
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-backdrop.active').forEach(m => Modal.close(m.id));
  }
});

// ── API HELPER ─────────────────────────────────────
window.api = async function(url, options = {}) {
  const defaults = {
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  };
  const config = { ...defaults, ...options };
  if (config.body && typeof config.body === 'object' && !(config.body instanceof FormData)) {
    config.body = JSON.stringify(config.body);
  }
  if (config.body instanceof FormData) {
    delete config.headers['Content-Type'];
  }
  try {
    const res = await fetch(url, config);
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.error || 'Erreur serveur');
    return data;
  } catch (err) {
    toast(err.message, 'error');
    throw err;
  }
};

// Champs personnalisés : remplir / collecter les inputs name="cf_*"
window.cfFill = function(scope, values) {
  const el = typeof scope === 'string' ? document.getElementById(scope) : scope;
  if (!el) return;
  el.querySelectorAll('[name^="cf_"]').forEach(i => {
    const key = i.name.slice(3);
    const v = values && values[key] != null ? values[key] : '';
    if (i.type === 'checkbox') i.checked = (v == 1 || v === '1' || v === true);
    else i.value = v;
  });
};
window.cfCollect = function(scope) {
  const el = typeof scope === 'string' ? document.getElementById(scope) : scope;
  const out = {};
  if (el) el.querySelectorAll('[name^="cf_"]').forEach(i => {
    out[i.name] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value;
  });
  return out;
};

// ── TABLE SORT ─────────────────────────────────────
function initTableSort(tableId) {
  const table = document.getElementById(tableId);
  if (!table) return;
  const headers = table.querySelectorAll('thead th[data-sort]');
  let currentCol = null, currentDir = 1;

  headers.forEach(th => {
    th.style.cursor = 'pointer';
    th.addEventListener('click', () => {
      const col = th.dataset.sort;
      if (col === currentCol) {
        currentDir *= -1;
      } else {
        currentCol = col;
        currentDir = 1;
        headers.forEach(h => h.classList.remove('sorted'));
      }
      th.classList.add('sorted');
      sortTable(table, col, currentDir);
    });
  });
}

function sortTable(table, col, dir) {
  const tbody = table.querySelector('tbody');
  const rows = Array.from(tbody.querySelectorAll('tr'));
  rows.sort((a, b) => {
    const aVal = a.querySelector(`[data-col="${col}"]`)?.textContent.trim() || '';
    const bVal = b.querySelector(`[data-col="${col}"]`)?.textContent.trim() || '';
    const aNum = parseFloat(aVal.replace(/[^0-9.-]/g, ''));
    const bNum = parseFloat(bVal.replace(/[^0-9.-]/g, ''));
    if (!isNaN(aNum) && !isNaN(bNum)) return (aNum - bNum) * dir;
    return aVal.localeCompare(bVal, 'fr') * dir;
  });
  tbody.append(...rows);
}

// ── LIVE SEARCH ────────────────────────────────────
function initLiveSearch(inputId, tableId) {
  const input = document.getElementById(inputId);
  const table = document.getElementById(tableId);
  if (!input || !table) return;

  let debounceTimer;
  input.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      const q = input.value.toLowerCase().trim();
      const rows = table.querySelectorAll('tbody tr');
      let visible = 0;
      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const show = !q || text.includes(q);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
      });
      const empty = table.querySelector('.empty-row');
      if (empty) empty.style.display = visible === 0 ? '' : 'none';
    }, 200);
  });
}

// ── FILTER SELECT ──────────────────────────────────
function initFilterSelect(selectId, tableId, colClass) {
  const select = document.getElementById(selectId);
  const table = document.getElementById(tableId);
  if (!select || !table) return;

  select.addEventListener('change', () => {
    const val = select.value.toLowerCase();
    const rows = table.querySelectorAll('tbody tr:not(.empty-row)');
    rows.forEach(row => {
      const cell = row.querySelector(`.${colClass}`);
      const text = cell?.textContent.toLowerCase().trim() || '';
      row.style.display = !val || text.includes(val) ? '' : 'none';
    });
  });
}

// ── DONUT CHART (SVG) ─────────────────────────────
window.renderDonut = function(canvasId, segments, opts = {}) {
  const el = document.getElementById(canvasId);
  if (!el) return;
  const size = opts.size || 140;
  const strokeWidth = opts.stroke || 14;
  const r = (size - strokeWidth) / 2;
  const cx = size / 2;
  const cy = size / 2;
  const total = segments.reduce((s, seg) => s + seg.value, 0);
  if (total === 0) return;

  const circumference = 2 * Math.PI * r;
  let offset = -Math.PI / 2;
  let svgPaths = '';

  segments.forEach(seg => {
    const fraction = seg.value / total;
    const arcLength = fraction * circumference;
    const x1 = cx + r * Math.cos(offset);
    const y1 = cy + r * Math.sin(offset);
    const endAngle = offset + fraction * Math.PI * 2;
    const x2 = cx + r * Math.cos(endAngle);
    const y2 = cy + r * Math.sin(endAngle);
    const largeArc = fraction > 0.5 ? 1 : 0;
    if (fraction > 0) {
      const safeTip = `${String(seg.label).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}: ${Number(seg.value)}`;
      svgPaths += `<path d="M ${x1} ${y1} A ${r} ${r} 0 ${largeArc} 1 ${x2} ${y2}"
        fill="none" stroke="${seg.color}" stroke-width="${strokeWidth}"
        stroke-linecap="round" data-tip="${safeTip}"/>`;
    }
    offset = endAngle;
  });

  el.innerHTML = `<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}"
    style="filter: drop-shadow(0 0 12px rgba(0,0,0,0.4))">
    <circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="${strokeWidth}"/>
    ${svgPaths}
  </svg>`;
};

// ── AUTO-INIT ──────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  initTableSort('main-table');
  initLiveSearch('search-input', 'main-table');
  initFilterSelect('os-filter', 'main-table', 'col-os');
  initFilterSelect('status-filter', 'main-table', 'col-status');
  initFilterSelect('dept-filter', 'main-table', 'col-dept');
});

// ── PAGE PROGRESS BAR ────────────────────────────────────────
(function() {
  const bar = document.getElementById('page-progress');
  if (!bar) return;
  document.addEventListener('click', e => {
    const a = e.target.closest('a[href]');
    if (!a || a.target === '_blank' || e.metaKey || e.ctrlKey) return;
    const href = a.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript')) return;
    bar.style.width = '0%'; bar.style.opacity = '1';
    requestAnimationFrame(() => { setTimeout(() => { bar.style.width = '70%'; }, 10); });
  });
  window.addEventListener('pageshow', () => {
    bar.style.width = '100%';
    setTimeout(() => { bar.style.opacity = '0'; setTimeout(() => { bar.style.width = '0%'; }, 300); }, 200);
  });
})();
