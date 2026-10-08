/* =====================================================================
   EORA Suite — EoraAppShell prototype renderer (v2.1)
   Renders EoraPrimarySidebar, EoraSecondarySidebar, EoraTopbar (responsive priorities,
   command search, workspace switcher, context, notification center, profile) and the
   mobile navigation sheet from window.EORA_NAV.
   Page contract (on <body>):
     data-module="ppic" data-item="control-tower"
     data-crumbs="PPIC / Planning|Reconciliation|Control Tower"   (first|middle…|current)
     data-context="period" | "warehouse"   (optional page context)
     data-proto="banner text"
   The page's <main id="page"> is moved into the workspace.
   Spec: docs/uiux/EORA-NAVIGATION-ARCHITECTURE.md
   Production: React components (Radix Dialog/Popover/Tabs, cmdk) — this file is a prototype only.
   ===================================================================== */
(function () {
  const NAV = window.EORA_NAV;
  const b = document.body;
  let role = 'PPIC Planner';
  let activeModule = b.dataset.module || 'dashboard';
  const activeItem = b.dataset.item || '';
  const crumbs = (b.dataset.crumbs || '').split('|');
  const context = (b.dataset.context || '').split(',').filter(Boolean);

  const el = (tag, attrs = {}, html = '') => { const e = document.createElement(tag); for (const [k, v] of Object.entries(attrs)) { if (v === false || v == null) continue; e.setAttribute(k, v === true ? '' : v); } if (html) e.innerHTML = html; return e; };
  const ic = (name, cls = 'icon') => `<i data-lucide="${name}" class="${cls}" aria-hidden="true"></i>`;
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const badges = () => NAV.sampleBadges[role] || {};
  const allowed = m => { const r = NAV.roleModules[role]; return r === '*' || r.includes(m.id); };
  const visibleModules = () => NAV.modules.filter(allowed);
  const items = m => m.groups.flatMap(g => g.items).filter(i => i.release !== 'V2');
  const moduleBadge = m => [...new Set(items(m).map(i => i.badge).filter(Boolean))].reduce((a, k) => a + (badges()[k] || 0), 0);
  const nb = n => n ? `<span class="nbadge" aria-hidden="true">${n > 99 ? '99+' : n}</span>` : '';
  const isMobile = () => matchMedia('(max-width: 767px)').matches;
  const isOverlaySecondary = () => matchMedia('(max-width: 1279px)').matches;

  /* ---------- focus trap + scroll lock helpers ---------- */
  const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  function trapFocus(container, e) {
    if (e.key !== 'Tab') return;
    const f = [...container.querySelectorAll(FOCUSABLE)].filter(x => x.offsetParent !== null);
    if (!f.length) return;
    const first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }
  let lockCount = 0;
  const lockScroll = on => { lockCount = Math.max(0, lockCount + (on ? 1 : -1)); b.classList.toggle('scroll-locked', lockCount > 0); };

  /* ---------- skeleton ---------- */
  const page = document.getElementById('page');
  const app = el('div', { class: 'app', id: 'app' });
  if (b.dataset.proto) app.appendChild(el('div', { class: 'proto-note', role: 'note' }, `${ic('flask-conical')}<span>${b.dataset.proto}</span><span style="margin-left:auto"><a href="index.html">All mockups</a></span>`));
  const psb = el('nav', { class: 'psb', 'aria-label': 'Modules' });
  const ssb = el('nav', { class: 'ssb', id: 'ssb', 'aria-label': 'Module menu' });
  const main = el('div', { class: 'main-col' });
  const topbar = el('header', { class: 'topbar' });
  const workspace = el('div', { class: 'workspace', id: 'workspace' });
  main.append(topbar, workspace);
  app.append(psb, ssb, main);
  b.prepend(app);
  if (page) workspace.appendChild(page);
  const backdrop = el('div', { class: 'backdrop', hidden: true });
  b.appendChild(backdrop);

  /* ---------- tooltip (hover + focus) ---------- */
  const tip = el('div', { class: 'tooltip', role: 'tooltip', id: 'eora-tip', hidden: true });
  b.appendChild(tip);
  const showTip = (t, text) => { tip.textContent = text; tip.hidden = false; const r = t.getBoundingClientRect(); tip.style.left = (r.right + 10) + 'px'; tip.style.top = (r.top + r.height / 2 - tip.offsetHeight / 2) + 'px'; };
  const hideTip = () => { tip.hidden = true; };

  /* ---------- primary sidebar ---------- */
  function renderPrimary() {
    const mods = visibleModules(), top = mods.filter(m => !m.pinBottom), bottom = mods.filter(m => m.pinBottom);
    const item = m => { const n = moduleBadge(m); return `<li><button class="psb-item" type="button" data-mod="${m.id}" aria-label="${esc(m.label)}${n ? `, ${n} need${n === 1 ? 's' : ''} your action` : ''}" ${m.id === activeModule ? 'aria-current="true"' : ''}>${ic(m.icon)}${nb(n)}</button></li>`; };
    psb.innerHTML = `<div class="psb-logo" aria-label="EORA Suite" role="img">E</div><ul class="psb-list">${top.map(item).join('')}</ul><div class="psb-spacer"></div><ul class="psb-list">${bottom.map(item).join('')}</ul>`;
    const btns = [...psb.querySelectorAll('.psb-item')];
    btns.forEach((bt, i) => {
      const m = NAV.modules.find(x => x.id === bt.dataset.mod);
      const n = moduleBadge(m), label = m.label + (n ? ` · ${n} to action` : '');
      bt.addEventListener('mouseenter', () => showTip(bt, label)); bt.addEventListener('mouseleave', hideTip);
      bt.addEventListener('focus', () => showTip(bt, label)); bt.addEventListener('blur', hideTip);
      bt.addEventListener('click', () => { activeModule = m.id; renderPrimary(); renderSecondary(); if (isOverlaySecondary()) openSecondary(); else app.classList.remove('secondary-collapsed'); psb.querySelector(`[data-mod="${m.id}"]`).focus(); });
      bt.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); btns[(i + (e.key === 'ArrowDown' ? 1 : -1) + btns.length) % btns.length].focus(); }
        if (e.key === 'ArrowRight') { e.preventDefault(); const f = ssb.querySelector('.ssb-link, .ssb-group-btn'); f && f.focus(); }
      });
    });
    refreshIcons();
  }

  /* ---------- secondary menu markup (shared by sidebar + mobile sheet) ---------- */
  function menuGroups(m, prefix) {
    return m.groups.map((g, gi) => {
      const its = g.items.filter(i => i.release !== 'V2');
      if (!its.length) return '';
      const gid = `${prefix}-${m.id}-${gi}`;
      return `<div class="ssb-group"><button class="ssb-group-btn" type="button" aria-expanded="true" aria-controls="${gid}"><span>${esc(g.label)}</span>${ic('chevron-down')}</button>
        <ul class="ssb-items" id="${gid}">${its.map(i => { const n = i.badge ? (badges()[i.badge] || 0) : 0; return `<li><a class="ssb-link" href="${i.href || '#'}" ${i.id === activeItem && m.id === b.dataset.module ? 'aria-current="page"' : ''} data-proto-link="${i.href ? '' : '1'}" ${n ? `aria-label="${esc(i.label)}, ${n} to action"` : ''}>${ic(i.icon)}<span>${esc(i.label)}</span>${i.decision ? `<span class="ssb-tag" title="Pending business decision ${i.decision}">Pending ${i.decision}</span>` : nb(n)}</a></li>`; }).join('')}</ul></div>`;
    }).join('');
  }
  function wireMenu(root) {
    root.querySelectorAll('.ssb-group-btn').forEach(gb => gb.addEventListener('click', () => { const open = gb.getAttribute('aria-expanded') === 'true'; gb.setAttribute('aria-expanded', String(!open)); document.getElementById(gb.getAttribute('aria-controls')).hidden = open; }));
    root.querySelectorAll('[data-proto-link="1"]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); toast('Prototype: this menu item has no mockup page yet.'); }));
  }

  /* ---------- secondary sidebar ---------- */
  function renderSecondary() {
    const m = NAV.modules.find(x => x.id === activeModule);
    ssb.innerHTML = `<div class="ssb-head"><span class="ssb-title">${ic('menu')}${esc(m.label)}</span><button class="icon-btn" type="button" id="ssbCollapse" aria-label="Close module menu">${ic('panel-left-close')}</button></div><div class="ssb-scroll">${menuGroups(m, 'g')}</div>`;
    wireMenu(ssb);
    ssb.querySelector('#ssbCollapse').addEventListener('click', () => { if (isOverlaySecondary()) closeSecondary(); else app.classList.add('secondary-collapsed'); });
    refreshIcons();
  }
  let ssbReturn = null;
  function openSecondary() { ssbReturn = document.activeElement; app.classList.add('secondary-open'); backdrop.hidden = false; backdrop.dataset.for = 'ssb'; }
  function closeSecondary() { app.classList.remove('secondary-open'); if (backdrop.dataset.for === 'ssb') backdrop.hidden = true; ssbReturn && ssbReturn.focus && ssbReturn.focus(); }

  /* ---------- topbar ---------- */
  const mid = crumbs.slice(1, -1);
  const crumbHtml = `<a href="#">${esc(crumbs[0])}</a>${ic('chevron-right')}` +
    mid.map(c => `<a href="#" class="crumb-mid">${esc(c)}</a><span class="crumb-mid-sep">${ic('chevron-right')}</span>`).join('') +
    (mid.length ? `<span class="crumb-ellipsis" title="${esc(mid.join(' / '))}">…</span><span class="crumb-ellipsis">${ic('chevron-right')}</span>` : '') +
    `<span aria-current="page">${esc(crumbs[crumbs.length - 1])}</span>`;
  const ctxBtn = {
    period: `<button class="ctx-select period" type="button" data-pop="periodPop" aria-haspopup="dialog" aria-expanded="false" aria-label="Production date 08 Oct 2026, all shifts. Change">${ic('calendar')}<span class="ctx-2"><span class="ctx-k">Production date</span><span class="ctx-v">08 Oct · All shifts</span></span>${ic('chevron-down')}</button>`,
    warehouse: `<button class="ctx-select period" type="button" data-pop="whPop" aria-haspopup="dialog" aria-expanded="false" aria-label="Warehouse RM-WH1. Change">${ic('warehouse')}<span class="ctx-2"><span class="ctx-k">Warehouse</span><span class="ctx-v">RM-WH1</span></span>${ic('chevron-down')}</button>`,
  };
  topbar.innerHTML = `
    <button class="menu-trigger" type="button" id="mnavBtn" aria-haspopup="dialog" aria-expanded="false" aria-controls="mnav">${ic('menu', 'icon icon-lg')}Menu</button>
    <button class="icon-btn ssb-toggle" type="button" id="ssbToggle" aria-label="Show or hide module menu">${ic('panel-left')}</button>
    <nav class="crumbs" aria-label="Breadcrumb">${crumbHtml}</nav>
    <button class="gsearch" type="button" data-cmdk aria-label="Search records (Ctrl K)">${ic('search')}<span class="ph">Search PO, WO, lot, part…</span><span class="kbd" aria-hidden="true">Ctrl K</span></button>
    <button class="icon-btn gsearch-icon" type="button" data-cmdk aria-label="Search records (Ctrl K)">${ic('search', 'icon icon-lg')}</button>
    ${context.map(c => ctxBtn[c] || '').join('')}
    <button class="ctx-select ws" type="button" data-pop="wsPop" aria-haspopup="dialog" aria-expanded="false" aria-label="Company PT Sample Manufaktur, plant A Cikarang. Change">${ic('building-2')}<span class="ctx-2"><span class="ctx-k">PT Sample Manufaktur</span><span class="ctx-v">Plant A · Cikarang</span></span>${ic('chevrons-up-down')}</button>
    <button class="icon-btn tb-more" type="button" data-pop="morePop" aria-haspopup="dialog" aria-expanded="false" aria-label="Company, plant and page context">${ic('building-2', 'icon icon-lg')}</button>
    <span class="tb-sep" aria-hidden="true"></span>
    <button class="icon-btn" type="button" id="bell" aria-haspopup="dialog" aria-expanded="false" aria-controls="notif">${ic('bell', 'icon icon-lg')}<span id="bellBadge"></span></button>
    <button class="user-btn" type="button" data-pop="userPop" aria-haspopup="menu" aria-expanded="false" aria-label="Account menu, R. Pratama"><span class="avatar" aria-hidden="true">RP</span><span class="user-meta"><b>R. Pratama</b><span id="roleLbl">${role}</span></span>${ic('chevron-down')}</button>`;

  /* ---------- popovers (workspace, context, overflow, profile) ---------- */
  const POPS = {
    wsPop: () => `<div class="menu-label">Workspace</div>
      <div class="menu-field" style="padding:6px 12px;display:flex;flex-direction:column;gap:4px"><label for="coSel" class="hint">Company</label><select class="select" id="coSel"><option>PT Sample Manufaktur</option><option>PT Sample Komponen</option></select></div>
      <div class="menu-field" style="padding:6px 12px;display:flex;flex-direction:column;gap:4px"><label for="plSel" class="hint">Plant</label><select class="select" id="plSel"><option>Plant A · Cikarang</option><option>Plant B · Karawang</option></select></div>
      <div class="hint" style="padding:4px 12px 8px">Only companies and plants you are assigned to are listed. Switching reloads data in the new scope.</div>`,
    periodPop: () => `<div class="menu-label">Page context</div>
      <div class="menu-field" style="padding:6px 12px;display:flex;flex-direction:column;gap:4px"><label for="pdSel" class="hint">Production date</label><input class="input" id="pdSel" value="08 Oct 2026"></div>
      <div class="menu-field" style="padding:6px 12px;display:flex;flex-direction:column;gap:4px"><label for="shSel" class="hint">Shift</label><select class="select" id="shSel"><option>All shifts</option><option>S1 07:00–15:00</option><option>S2 15:00–23:00</option><option>S3 23:00–07:00</option></select></div>
      <div class="hint" style="padding:4px 12px 8px">Shift cut-off follows the plant shift calendar.</div>`,
    whPop: () => `<div class="menu-label">Page context</div><div class="menu-field" style="padding:6px 12px;display:flex;flex-direction:column;gap:4px"><label for="whSel" class="hint">Warehouse</label><select class="select" id="whSel"><option>RM-WH1</option><option>RM-WH2</option></select></div>`,
    morePop: () => `${POPS.wsPop()}${context.includes('period') ? '<div class="menu-sep"></div>' + POPS.periodPop() : ''}${context.includes('warehouse') ? '<div class="menu-sep"></div>' + POPS.whPop() : ''}`,
    userPop: () => `<div class="menu-label">R. Pratama · ${role}</div>
      <div class="menu-sep"></div>
      <div class="menu-label">Prototype: view navigation as role</div>
      ${Object.keys(NAV.roleModules).map(r => `<button class="menu-item" role="menuitemradio" aria-checked="${r === role}" data-role="${r}">${ic(r === role ? 'check' : 'user')}${r}</button>`).join('')}
      <div class="menu-sep"></div>
      <button class="menu-item" role="menuitem">${ic('user')}Profile & devices</button>
      <button class="menu-item" role="menuitem">${ic('bell-ring')}Notification preferences</button>
      <button class="menu-item" role="menuitem">${ic('log-out')}Sign out</button>`,
  };
  const pop = el('div', { class: 'popover fixed', id: 'tbPop', role: 'dialog', hidden: true, style: 'width:280px' });
  b.appendChild(pop);
  let popTrigger = null;
  function openPop(trigger) {
    if (popTrigger === trigger && !pop.hidden) return closePop();
    closePop(false); closeNotif(false);
    popTrigger = trigger; pop.innerHTML = POPS[trigger.dataset.pop]();
    pop.setAttribute('role', trigger.getAttribute('aria-haspopup') === 'menu' ? 'menu' : 'dialog');
    pop.setAttribute('aria-label', trigger.getAttribute('aria-label') || 'Menu');
    pop.hidden = false; trigger.setAttribute('aria-expanded', 'true');
    const r = trigger.getBoundingClientRect();
    pop.style.top = (r.bottom + 6) + 'px';
    pop.style.left = Math.max(8, Math.min(r.right - pop.offsetWidth, innerWidth - pop.offsetWidth - 8)) + 'px';
    refreshIcons();
    pop.querySelectorAll('[data-role]').forEach(x => x.addEventListener('click', () => switchRole(x.dataset.role)));
    pop.querySelectorAll('select').forEach(s => s.addEventListener('change', () => toast(`Prototype: ${s.previousElementSibling.textContent} changed — data reloads in the new scope.`)));
    const f = pop.querySelector(FOCUSABLE); f && f.focus();
  }
  function closePop(restore = true) { if (pop.hidden) return; pop.hidden = true; if (popTrigger) { popTrigger.setAttribute('aria-expanded', 'false'); restore && popTrigger.focus(); } popTrigger = null; }
  topbar.querySelectorAll('[data-pop]').forEach(t => t.addEventListener('click', e => { e.stopPropagation(); openPop(t); }));
  pop.addEventListener('keydown', e => {
    trapFocus(pop, e);
    if (pop.getAttribute('role') === 'menu' && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { e.preventDefault(); const its = [...pop.querySelectorAll('.menu-item')]; const i = its.indexOf(document.activeElement); its[(i + (e.key === 'ArrowDown' ? 1 : -1) + its.length) % its.length].focus(); }
  });
  document.addEventListener('click', e => { if (!pop.hidden && !pop.contains(e.target)) closePop(false); });

  function switchRole(r) {
    role = r; if (!allowed(NAV.modules.find(m => m.id === activeModule))) activeModule = visibleModules()[0].id;
    closePop(); topbar.querySelector('#roleLbl').textContent = role;
    renderPrimary(); renderSecondary(); renderBell();
    toast(`Navigation and badges filtered for ${role}. The server still enforces every permission.`);
  }

  /* ---------- command search (permission-aware) ---------- */
  const SEARCH = [
    { g: 'Work orders', t: 'WO-1182 · BRKT-220 Bracket Seat LH', s: 'Production complete · Plant A', i: 'clipboard-list' },
    { g: 'Work orders', t: 'WO-1201 · BRKT-310', s: 'Running · WC-PRESS1', i: 'clipboard-list' },
    { g: 'Material lots', t: 'C-07 · SPCC 1.2 × 120 coil', s: 'At production 47.600 kg · WC-PRESS1', i: 'cylinder' },
    { g: 'Material lots', t: 'C-08 · SPCC 1.2 × 120 coil', s: 'Unrestricted 2,450.000 kg · RM-WH1', i: 'cylinder' },
    { g: 'Customer POs', t: 'PO-24-0918 · CUST-04', s: '2 lines · 1 at risk', i: 'file-text' },
    { g: 'Reconciliation cases', t: 'RC-0041 · RUN-1182-S1', s: 'Evidence collected · Gross Yield Variance 47 pcs', i: 'search-check' },
    { g: 'Pages', t: 'PPIC Control Tower', s: 'PPIC / Planning', i: 'radar' },
    { g: 'Pages', t: 'Raw Material', s: 'Inventory › Stock', i: 'cylinder' },
  ];
  const cmdk = el('div', { class: 'dialog-backdrop', hidden: true });
  cmdk.innerHTML = `<div class="dialog" role="dialog" aria-modal="true" aria-label="Search records">
    <div class="cmdk-input">${ic('search', 'icon icon-lg')}<input id="cmdkInput" type="text" placeholder="Search PO, WO, lot, part, case or page" role="combobox" aria-expanded="true" aria-controls="cmdkList" aria-autocomplete="list" aria-label="Search records" autocomplete="off"><button class="icon-btn" type="button" id="cmdkClose" aria-label="Close search">${ic('x')}</button></div>
    <div class="cmdk-list" id="cmdkList" role="listbox" aria-label="Results"></div>
    <div class="cmdk-foot"><span>Results only include records you are permitted to view in this tenant.</span><span>↑↓ move · Enter open · Esc close</span></div></div>`;
  b.appendChild(cmdk);
  const cin = cmdk.querySelector('#cmdkInput'), clist = cmdk.querySelector('#cmdkList');
  let cSel = 0, cReturn = null;
  function renderCmdk() {
    const q = cin.value.trim().toLowerCase();
    const res = SEARCH.filter(r => !q || (r.t + r.s).toLowerCase().includes(q));
    cSel = Math.min(cSel, Math.max(0, res.length - 1));
    let last = '';
    clist.innerHTML = res.length ? res.map((r, i) => { const h = r.g !== last ? `<div class="cmdk-group" role="presentation">${r.g}</div>` : ''; last = r.g; return `${h}<button class="cmdk-item" role="option" id="opt${i}" aria-selected="${i === cSel}" type="button" tabindex="-1" data-i="${i}">${ic(r.i)}<span><span>${esc(r.t)}</span><br><span class="sub">${esc(r.s)}</span></span></button>`; }).join('')
      : `<div class="state" style="padding:var(--space-8)"><h3>No results for “${esc(cin.value)}”</h3><p>Check the number or try a part name. Records outside your plant or permissions are not shown.</p></div>`;
    cin.setAttribute('aria-activedescendant', res.length ? `opt${cSel}` : '');
    clist.querySelectorAll('.cmdk-item').forEach(x => x.addEventListener('click', () => { closeCmdk(); toast('Prototype: would open ' + res[x.dataset.i].t + ' after a permission check.'); }));
    refreshIcons();
  }
  function openCmdk() { cReturn = document.activeElement; closePop(false); closeNotif(false); cmdk.hidden = false; lockScroll(true); cin.value = ''; cSel = 0; renderCmdk(); cin.focus(); }
  function closeCmdk() { if (cmdk.hidden) return; cmdk.hidden = true; lockScroll(false); cReturn && cReturn.focus(); }
  cin.addEventListener('input', () => { cSel = 0; renderCmdk(); });
  cin.addEventListener('keydown', e => {
    const n = clist.querySelectorAll('.cmdk-item').length;
    if (e.key === 'ArrowDown' && n) { e.preventDefault(); cSel = (cSel + 1) % n; renderCmdk(); }
    if (e.key === 'ArrowUp' && n) { e.preventDefault(); cSel = (cSel - 1 + n) % n; renderCmdk(); }
    if (e.key === 'Enter' && n) { e.preventDefault(); clist.querySelectorAll('.cmdk-item')[cSel].click(); }
  });
  cmdk.addEventListener('keydown', e => trapFocus(cmdk, e));
  cmdk.addEventListener('click', e => { if (e.target === cmdk) closeCmdk(); });
  cmdk.querySelector('#cmdkClose').addEventListener('click', closeCmdk);
  topbar.querySelectorAll('[data-cmdk]').forEach(x => x.addEventListener('click', openCmdk));

  /* ---------- mobile navigation sheet (drill-down) ---------- */
  const mnav = el('div', { class: 'mnav', id: 'mnav', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Navigation', hidden: true });
  b.appendChild(mnav);
  const mnavBtn = topbar.querySelector('#mnavBtn');
  let mnavLevel = 'menu';
  function renderMnav() {
    const m = NAV.modules.find(x => x.id === activeModule);
    const head = `<div class="mnav-head"><span class="brand"><span class="psb-logo" aria-hidden="true">E</span>EORA Suite</span><button class="icon-btn" type="button" id="mnavClose" aria-label="Close navigation">${ic('x', 'icon icon-lg')}</button></div>`;
    const body = mnavLevel === 'modules'
      ? `<div class="mnav-title" id="mnavT">Modules</div><ul style="list-style:none;margin:0;padding:0" aria-labelledby="mnavT">${visibleModules().map(x => { const n = moduleBadge(x); return `<li><button class="mnav-mod" type="button" data-mod="${x.id}" ${x.id === activeModule ? 'aria-current="true"' : ''}>${ic(x.icon, 'icon icon-lg')}<span>${esc(x.label)}</span>${n ? `<span class="nbadge" aria-hidden="true">${n}</span><span class="sr-only">, ${n} to action</span>` : ''}${ic('chevron-right', 'icon chev')}</button></li>`; }).join('')}</ul>`
      : `<button class="mnav-back" type="button" id="mnavBack">${ic('chevron-left')}All modules</button><div class="mnav-title">${esc(m.label)}</div>${menuGroups(m, 'mg')}`;
    mnav.innerHTML = head + `<div class="mnav-body">${body}</div>`;
    mnav.querySelector('#mnavClose').addEventListener('click', () => closeMnav());
    const back = mnav.querySelector('#mnavBack'); if (back) back.addEventListener('click', () => { mnavLevel = 'modules'; renderMnav(); mnav.querySelector(`[data-mod="${activeModule}"]`).focus(); });
    mnav.querySelectorAll('.mnav-mod').forEach(x => x.addEventListener('click', () => { activeModule = x.dataset.mod; mnavLevel = 'menu'; renderMnav(); renderPrimary(); renderSecondary(); mnav.querySelector('#mnavBack').focus(); }));
    wireMenu(mnav); refreshIcons();
  }
  function openMnav() { closePop(false); closeNotif(false); mnavLevel = 'menu'; renderMnav(); mnav.hidden = false; backdrop.hidden = false; backdrop.dataset.for = 'mnav'; mnavBtn.setAttribute('aria-expanded', 'true'); lockScroll(true); mnav.querySelector('#mnavClose').focus(); }
  function closeMnav(restore = true) { if (mnav.hidden) return; mnav.hidden = true; backdrop.hidden = true; mnavBtn.setAttribute('aria-expanded', 'false'); lockScroll(false); restore && mnavBtn.focus(); }
  mnavBtn.addEventListener('click', openMnav);
  mnav.addEventListener('keydown', e => trapFocus(mnav, e));
  backdrop.addEventListener('click', () => { if (backdrop.dataset.for === 'mnav') closeMnav(); else closeSecondary(); });
  matchMedia('(min-width: 768px)').addEventListener('change', e => { if (e.matches) closeMnav(false); });

  /* ---------- notification center ---------- */
  const N = [
    { id: 1, day: 'Today', t: 'Approval needed: stock adjustment ADJ-0092', m: 'Inventory · −4.200 kg at WC-PRESS1 · requested by D. Lestari', time: '09:32', act: true, unread: true, icon: 'stamp', tone: 'warning', link: 'Review request', roles: ['PPIC Planner', 'Company Super Admin'] },
    { id: 2, day: 'Today', t: 'Variance case RC-0041 assigned to your team', m: 'Reconciliation · RUN-1182-S1 · Gross Yield Variance 47 pcs', time: '09:05', act: true, unread: true, icon: 'search-check', tone: 'warning', link: 'Open case', roles: ['PPIC Planner', 'Company Super Admin'] },
    { id: 3, day: 'Today', t: '4 lots waiting for inspection', m: 'Quality · oldest IL-1182-01, 26 h', time: '08:55', act: true, unread: true, icon: 'hourglass', tone: 'warning', link: 'Open queue', roles: ['Quality Inspector', 'Company Super Admin'] },
    { id: 4, day: 'Today', t: 'QC released 300 pcs · lot O-1180-02', m: 'Quality → FG Warehouse · awaiting FG receipt', time: '08:51', act: false, unread: true, icon: 'badge-check', tone: 'success', link: 'View lot', roles: '*' },
    { id: 5, day: 'Today', t: 'Material C-10 issued to WC-PRESS1', m: 'Materials · 700.0 kg · awaiting custody confirmation', time: '07:30', act: true, unread: false, icon: 'hand', tone: 'info', link: 'Open issue', roles: ['Materials PIC'] },
    { id: 6, day: 'Today', t: 'Customer PO-24-0931 revised (rev 2)', m: 'Demand · line 1 due date 12 → 14 Oct', time: '08:12', act: false, unread: true, icon: 'file-text', tone: 'info', link: 'View revision', roles: ['PPIC Planner', 'Company Super Admin'] },
    { id: 7, day: 'Earlier', t: 'Export ready: Daily Delivery 07 Oct', m: 'Export Center · link expires in 24 h', time: 'Yesterday', act: false, unread: false, icon: 'download', tone: 'neutral', link: 'Download', roles: '*' },
  ];
  const mine = () => N.filter(n => n.roles === '*' || n.roles.includes(role));
  const notif = el('section', { class: 'notif', id: 'notif', role: 'dialog', 'aria-label': 'Notifications', hidden: true });
  b.appendChild(notif);
  let nFilter = 'act';
  const bell = topbar.querySelector('#bell');
  function renderBell() {
    const act = mine().filter(n => n.act).length, unread = mine().filter(n => n.unread).length;
    topbar.querySelector('#bellBadge').innerHTML = nb(act);
    bell.setAttribute('aria-label', `Notifications: ${act} need action, ${unread} unread`);
  }
  function renderNotif() {
    const list = mine(), act = list.filter(n => n.act).length, unread = list.filter(n => n.unread).length;
    const shown = list.filter(n => nFilter === 'all' || (nFilter === 'act' ? n.act : n.unread));
    const days = [...new Set(shown.map(n => n.day))];
    notif.innerHTML = `<div class="notif-head"><div><h2>Notifications</h2><div class="sub">${act} need action · ${unread} unread</div></div><button class="icon-btn" type="button" id="notifClose" aria-label="Close notifications">${ic('x')}</button></div>
      <div class="tabs" role="tablist" aria-label="Filter notifications">${[['act', 'Action required', act], ['all', 'All', list.length], ['unread', 'Unread', unread]].map(([k, l, c]) => `<button class="tab" role="tab" type="button" aria-selected="${nFilter === k}" tabindex="${nFilter === k ? 0 : -1}" data-f="${k}">${l} <span class="count">${c}</span></button>`).join('')}</div>
      <ul class="notif-list">${shown.length ? days.map(d => `<li class="notif-day">${d}</li>` + shown.filter(n => n.day === d).map(n => `<li class="notif-item ${n.unread ? 'unread' : ''}"><span class="notif-ico badge-${n.tone}">${ic(n.icon)}</span><div><div class="t">${n.unread ? '<span class="unread-dot" aria-hidden="true"></span><span class="sr-only">Unread:</span>' : ''}<span>${esc(n.t)}</span></div><div class="m">${esc(n.m)}</div><div class="row">${n.act ? '<span class="badge badge-warning">Action required</span>' : '<span class="badge badge-neutral">Information</span>'}<span class="hint">${n.time}</span><a href="#" class="btn-link" data-open="${n.id}">${n.link}</a></div></div>${n.unread ? `<button class="icon-btn mark" type="button" data-read="${n.id}" aria-label="Mark as read: ${esc(n.t)}">${ic('check')}</button>` : '<span></span>'}</li>`).join('')).join('')
        : `<li class="state" style="padding:var(--space-8)"><div class="state-icon">${ic('bell-off', 'icon icon-lg')}</div><h3>Nothing ${nFilter === 'act' ? 'needs your action' : 'unread'}</h3><p>New items appear here as they happen. Push is optional; this inbox is the record.</p></li>`}</ul>
      <div class="notif-foot"><a href="#" class="btn-link">Notification settings</a><button class="btn-link" type="button" id="markAll">Mark all as read</button></div>`;
    notif.querySelector('#notifClose').addEventListener('click', () => closeNotif());
    const tabs = [...notif.querySelectorAll('[role=tab]')];
    tabs.forEach((t, i) => { t.addEventListener('click', () => { nFilter = t.dataset.f; renderNotif(); notif.querySelector(`[data-f="${nFilter}"]`).focus(); }); t.addEventListener('keydown', e => { if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { e.preventDefault(); tabs[(i + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length].click(); } }); });
    notif.querySelectorAll('[data-read]').forEach(x => x.addEventListener('click', () => { N.find(n => n.id == x.dataset.read).unread = false; renderNotif(); renderBell(); notif.querySelector('[role=tab][aria-selected=true]').focus(); }));
    notif.querySelectorAll('[data-open]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); toast('Prototype: deep link re-checks permission and tenant before opening the record.'); }));
    notif.querySelector('#markAll').addEventListener('click', () => { N.forEach(n => n.unread = false); renderNotif(); renderBell(); });
    refreshIcons();
  }
  function openNotif() { closePop(false); renderNotif(); notif.hidden = false; bell.setAttribute('aria-expanded', 'true'); if (isMobile()) lockScroll(true); notif.querySelector('[role=tab][aria-selected=true]').focus(); }
  function closeNotif(restore = true) { if (notif.hidden) return; notif.hidden = true; bell.setAttribute('aria-expanded', 'false'); if (isMobile()) lockScroll(false); restore && bell.focus(); }
  bell.addEventListener('click', e => { e.stopPropagation(); notif.hidden ? openNotif() : closeNotif(); });
  notif.addEventListener('keydown', e => { if (isMobile()) trapFocus(notif, e); });
  document.addEventListener('click', e => { if (!notif.hidden && !notif.contains(e.target) && !bell.contains(e.target)) closeNotif(false); });

  /* ---------- toast ---------- */
  const toastEl = el('div', { class: 'toast', role: 'status', 'aria-live': 'polite', hidden: true });
  b.appendChild(toastEl);
  let tt; function toast(msg) { toastEl.textContent = msg; toastEl.hidden = false; clearTimeout(tt); tt = setTimeout(() => toastEl.hidden = true, 3000); }

  /* ---------- global wiring ---------- */
  topbar.querySelector('#ssbToggle').addEventListener('click', () => {
    if (isOverlaySecondary()) { if (app.classList.contains('secondary-open')) closeSecondary(); else { openSecondary(); setTimeout(() => { const f = ssb.querySelector('.ssb-link[aria-current], .ssb-link'); f && f.focus(); }, 50); } }
    else app.classList.toggle('secondary-collapsed');
  });
  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openCmdk(); return; }
    if (e.key !== 'Escape') return;
    if (!cmdk.hidden) return closeCmdk();
    if (!pop.hidden) return closePop();
    if (!notif.hidden) return closeNotif();
    if (!mnav.hidden) return closeMnav();
    if (app.classList.contains('secondary-open')) return closeSecondary();
  });

  function refreshIcons() { if (window.lucide) window.lucide.createIcons({ attrs: { 'stroke-width': 2 } }); }
  window.EORA = { refreshIcons, toast, trapFocus, lockScroll };
  renderPrimary(); renderSecondary(); renderBell(); refreshIcons();
})();
