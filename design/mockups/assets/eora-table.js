/* =====================================================================
   EORA prototype — shared EoraDataTable behaviours (one implementation for every table)
   Spec: docs/uiux/EORA-COMPONENT-STANDARDS.md §5–6
   Production: TanStack Table (manual pagination/sorting/filtering) + shadcn primitives.
   Markup contract:
     <section class="card dt-card" data-dt>              container (density lives here)
       <… data-dt-density></…>                           slot → Comfortable | Compact toggle
       <div class="table-wrap"><table class="dt">…        sticky header; .sticky-start(-2)/.sticky-end columns
       <button data-row-menu='[{"label":"View","icon":"eye"},{"label":"Adjust","icon":"sliders","disabled":"Requires Inventory Controller"}]'>
       <div data-dt-pagination data-total="214" data-size="20" data-page="1">
   Density is a per-user preference applied to all tables (prototype: localStorage).
   ===================================================================== */
(function () {
  const KEY = 'eora.dt.density';
  const get = () => { try { return localStorage.getItem(KEY) || 'comfortable'; } catch (e) { return 'comfortable'; } };
  const set = v => { try { localStorage.setItem(KEY, v); } catch (e) { /* storage unavailable: keep in memory */ } };
  const ic = (n, c = 'icon') => `<i data-lucide="${n}" class="${c}" aria-hidden="true"></i>`;
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  let density = get();

  function applyDensity() {
    document.querySelectorAll('.dt-card').forEach(c => c.dataset.density = density);
    document.querySelectorAll('[data-dt-density] button').forEach(bt => bt.setAttribute('aria-pressed', String(bt.dataset.d === density)));
  }
  function densityToggle(slot) {
    slot.innerHTML = `<div class="density" role="group" aria-label="Table density">
      <button type="button" data-d="comfortable" aria-pressed="false">${ic('rows-3')}<span class="d-l">Comfortable</span></button>
      <button type="button" data-d="compact" aria-pressed="false">${ic('rows-4')}<span class="d-l">Compact</span></button></div>`;
    slot.querySelectorAll('button').forEach(bt => bt.addEventListener('click', () => { density = bt.dataset.d; set(density); applyDensity(); }));
  }

  function scrollShadows(wrap) {
    const upd = () => { wrap.classList.toggle('scroll-x-start', wrap.scrollLeft > 2); wrap.classList.toggle('scroll-x-end', wrap.scrollLeft + wrap.clientWidth < wrap.scrollWidth - 2); };
    wrap.addEventListener('scroll', upd, { passive: true }); addEventListener('resize', upd); upd();
  }

  /* Row action menu (one floating menu reused by all rows) */
  const menu = document.createElement('div');
  menu.className = 'popover fixed'; menu.setAttribute('role', 'menu'); menu.hidden = true; menu.style.width = '240px';
  document.body.appendChild(menu);
  let trigger = null;
  function closeMenu(restore = true) { if (menu.hidden) return; menu.hidden = true; if (trigger) { trigger.setAttribute('aria-expanded', 'false'); restore && trigger.focus(); } trigger = null; }
  function openMenu(btn) {
    if (trigger === btn) return closeMenu();
    closeMenu(false); trigger = btn;
    const items = JSON.parse(btn.dataset.rowMenu || '[]');
    menu.setAttribute('aria-label', btn.getAttribute('aria-label') || 'Row actions');
    menu.innerHTML = items.map(it => it === '-' ? '<div class="menu-sep" role="separator"></div>'
      : `<button class="menu-item" role="menuitem" type="button" ${it.disabled ? `aria-disabled="true" title="${esc(it.disabled)}"` : ''}>${ic(it.icon || 'dot')}<span>${esc(it.label)}${it.disabled ? `<span class="mi-sub">${esc(it.disabled)}</span>` : ''}</span></button>`).join('');
    menu.hidden = false; btn.setAttribute('aria-expanded', 'true');
    const r = btn.getBoundingClientRect();
    const top = r.bottom + 4 + menu.offsetHeight > innerHeight ? r.top - menu.offsetHeight - 4 : r.bottom + 4;
    menu.style.top = Math.max(8, top) + 'px'; menu.style.left = Math.max(8, Math.min(r.right - menu.offsetWidth, innerWidth - menu.offsetWidth - 8)) + 'px';
    window.EORA && EORA.refreshIcons();
    menu.querySelectorAll('.menu-item').forEach(mi => mi.addEventListener('click', () => {
      if (mi.getAttribute('aria-disabled') === 'true') return window.EORA && EORA.toast('Not available: ' + mi.title);
      closeMenu(); window.EORA && EORA.toast('Prototype: ' + mi.textContent.trim() + ' (permission re-checked by the server).');
    }));
    const first = menu.querySelector('.menu-item'); first && first.focus();
  }
  menu.addEventListener('keydown', e => {
    const its = [...menu.querySelectorAll('.menu-item')], i = its.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); its[(i + 1) % its.length].focus(); }
    if (e.key === 'ArrowUp') { e.preventDefault(); its[(i - 1 + its.length) % its.length].focus(); }
    if (e.key === 'Escape') { e.stopPropagation(); closeMenu(); }
    if (e.key === 'Tab') closeMenu(false);
  });
  document.addEventListener('click', e => { if (!menu.hidden && !menu.contains(e.target) && e.target.closest('[data-row-menu]') !== trigger) closeMenu(false); });
  document.addEventListener('click', e => { const b = e.target.closest('[data-row-menu]'); if (b) { e.stopPropagation(); openMenu(b); } });
  addEventListener('scroll', () => closeMenu(false), true);

  /* Pagination — identical markup in every module */
  function pagination(host) {
    const total = +host.dataset.total, size = +(host.dataset.size || 20), page = +(host.dataset.page || 1);
    const pages = Math.max(1, Math.ceil(total / size)), from = total ? (page - 1) * size + 1 : 0, to = Math.min(total, page * size);
    const nums = pages <= 5 ? [...Array(pages).keys()].map(i => i + 1) : [1, 2, 3, '…', pages];
    host.className = 'pagination';
    host.innerHTML = `<div class="pg-left"><span aria-live="polite">Showing <b class="tnum">${from.toLocaleString('en-US')}–${to.toLocaleString('en-US')}</b> of <b class="tnum">${total.toLocaleString('en-US')}</b></span>
      ${pages > 1 ? `<label class="page-size">Items per page <select class="select" aria-label="Items per page"><option>20</option><option>50</option><option>100</option></select></label>
      <span class="goto"><label for="${host.id || 'pg'}-goto">Go to page</label><input class="input" id="${host.id || 'pg'}-goto" inputmode="numeric" placeholder="1–${pages}"><button class="icon-btn bordered" type="button" aria-label="Go to page">${ic('arrow-right')}</button></span>` : ''}</div>
      <nav class="pages" aria-label="Pagination">
        <button class="nav-btn" type="button" ${page === 1 ? 'disabled' : ''} aria-label="First page">${ic('chevrons-left')}<span class="hide-sm">First</span></button>
        <button class="nav-btn" type="button" ${page === 1 ? 'disabled' : ''} aria-label="Previous page">${ic('chevron-left')}<span class="hide-sm">Prev</span></button>
        ${nums.map(n => n === '…' ? '<span class="ell" aria-hidden="true">…</span>' : `<button class="page-btn" type="button" ${n === page ? 'aria-current="page"' : ''} aria-label="Page ${n}">${n}</button>`).join('')}
        <button class="nav-btn" type="button" ${page === pages ? 'disabled' : ''} aria-label="Next page"><span class="hide-sm">Next</span>${ic('chevron-right')}</button>
        <button class="nav-btn" type="button" ${page === pages ? 'disabled' : ''} aria-label="Last page"><span class="hide-sm">Last</span>${ic('chevrons-right')}</button>
      </nav>`;
    if (host.dataset.size) host.querySelector('select') && (host.querySelector('select').value = String(size));
  }

  function init() {
    document.querySelectorAll('[data-dt-density]').forEach(densityToggle);
    document.querySelectorAll('.dt-card .table-wrap').forEach(scrollShadows);
    document.querySelectorAll('[data-dt-pagination]').forEach(pagination);
    applyDensity();
    window.EORA && EORA.refreshIcons();
  }
  window.EoraTable = { init, pagination, applyDensity };
  init();
})();
