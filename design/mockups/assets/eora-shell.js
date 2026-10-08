/* =====================================================================
   EORA Suite — EoraAppShell prototype renderer
   Renders EoraPrimarySidebar, EoraSecondarySidebar, EoraTopbar (breadcrumb, search, company,
   plant, optional context, notification center, profile) from window.EORA_NAV.
   Page contract (on <body>):
     data-module="ppic"  data-item="control-tower"
     data-crumbs="PPIC / Planning|Control Tower"
     data-context="date,shift"   (optional topbar context selectors)
     data-proto="text for prototype banner"
   The page's <main id="page"> is moved into the workspace.
   Spec: docs/uiux/EORA-NAVIGATION-ARCHITECTURE.md, EORA-COMPONENT-STANDARDS.md
   ===================================================================== */
(function () {
  const NAV = window.EORA_NAV;
  const b = document.body;
  const ROLE_MODULES = {
    'PPIC Planner': ['dashboard', 'demand', 'ppic', 'materials', 'production', 'quality', 'inventory', 'warehouse', 'delivery', 'traceability', 'analytics'],
    'Quality Inspector': ['dashboard', 'quality', 'traceability'],
    'Materials PIC': ['dashboard', 'materials', 'inventory', 'traceability'],
    'Company Super Admin': NAV.modules.map(m => m.id),
  };
  let role = 'PPIC Planner';
  let activeModule = b.dataset.module || 'dashboard';
  const activeItem = b.dataset.item || '';
  const crumbs = (b.dataset.crumbs || '').split('|');
  const context = (b.dataset.context || '').split(',').filter(Boolean);

  const el = (tag, attrs = {}, html = '') => { const e = document.createElement(tag); for (const [k, v] of Object.entries(attrs)) { if (v === false || v == null) continue; e.setAttribute(k, v === true ? '' : v); } if (html) e.innerHTML = html; return e; };
  const ic = (name, cls = 'icon') => `<i data-lucide="${name}" class="${cls}" aria-hidden="true"></i>`;
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const visibleModules = () => NAV.modules.filter(m => ROLE_MODULES[role].includes(m.id));
  const attnCount = m => m.groups.flatMap(g => g.items).filter(i => i.attn && i.release !== 'V2').reduce((a, i) => a + (i.count || 0), 0);

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

  /* ---------- tooltip ---------- */
  const tip = el('div', { class: 'tooltip', role: 'tooltip', id: 'eora-tip', hidden: true });
  b.appendChild(tip);
  const showTip = (target, text) => { tip.textContent = text; tip.hidden = false; const r = target.getBoundingClientRect(); tip.style.left = (r.right + 10) + 'px'; tip.style.top = (r.top + r.height / 2 - tip.offsetHeight / 2) + 'px'; target.setAttribute('aria-describedby', 'eora-tip'); };
  const hideTip = t => { tip.hidden = true; t && t.removeAttribute('aria-describedby'); };

  /* ---------- primary sidebar ---------- */
  function renderPrimary() {
    const mods = visibleModules();
    const top = mods.filter(m => !m.pinBottom), bottom = mods.filter(m => m.pinBottom);
    const item = m => { const n = attnCount(m); return `<li><button class="psb-item" type="button" data-mod="${m.id}" aria-label="${esc(m.label)}${n ? `, ${n} items need attention` : ''}" ${m.id === activeModule ? 'aria-current="true"' : ''}>${ic(m.icon)}${n ? `<span class="psb-dot" aria-hidden="true">${n > 9 ? '9+' : n}</span>` : ''}</button></li>`; };
    psb.innerHTML = `<div class="psb-logo" aria-label="EORA Suite" title="EORA Suite">E</div><ul class="psb-list">${top.map(item).join('')}</ul><div class="psb-spacer"></div><ul class="psb-list">${bottom.map(item).join('')}</ul>`;
    const btns = [...psb.querySelectorAll('.psb-item')];
    btns.forEach((bt, i) => {
      const m = NAV.modules.find(x => x.id === bt.dataset.mod);
      bt.addEventListener('mouseenter', () => showTip(bt, m.label));
      bt.addEventListener('mouseleave', () => hideTip(bt));
      bt.addEventListener('focus', () => showTip(bt, m.label));
      bt.addEventListener('blur', () => hideTip(bt));
      bt.addEventListener('click', () => { activeModule = m.id; renderPrimary(); renderSecondary(); app.classList.add('secondary-open'); app.classList.remove('secondary-collapsed'); psb.querySelector(`[data-mod="${m.id}"]`).focus(); });
      bt.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); btns[(i + (e.key === 'ArrowDown' ? 1 : -1) + btns.length) % btns.length].focus(); }
        if (e.key === 'ArrowRight') { e.preventDefault(); const f = ssb.querySelector('.ssb-link, .ssb-group-btn'); f && f.focus(); }
      });
    });
    refreshIcons();
  }

  /* ---------- secondary sidebar ---------- */
  function renderSecondary() {
    const m = NAV.modules.find(x => x.id === activeModule);
    const groups = m.groups.map((g, gi) => {
      const items = g.items.filter(i => i.release !== 'V2');
      if (!items.length) return '';
      const gid = `g-${m.id}-${gi}`;
      return `<div class="ssb-group"><button class="ssb-group-btn" type="button" aria-expanded="true" aria-controls="${gid}"><span>${esc(g.label)}</span>${ic('chevron-down')}</button>
        <ul class="ssb-items" id="${gid}">${items.map(i => `<li><a class="ssb-link" href="${i.href || '#'}" ${i.id === activeItem && m.id === b.dataset.module ? 'aria-current="page"' : ''} data-proto-link="${i.href ? '' : '1'}">${ic(i.icon)}<span>${esc(i.label)}</span>${i.decision ? `<span class="ssb-tag" title="Awaiting decision ${i.decision}">${i.decision}</span>` : (i.count != null ? `<span class="ssb-count ${i.attn ? 'attn' : ''}" aria-label="${i.count}${i.attn ? ' need attention' : ' records'}">${i.count}</span>` : '')}</a></li>`).join('')}</ul></div>`;
    }).join('');
    ssb.innerHTML = `<div class="ssb-head"><span class="ssb-title">${ic('menu')}${esc(m.label)}</span><button class="icon-btn" type="button" id="ssbCollapse" aria-label="Collapse module menu">${ic('panel-left-close')}</button></div><div class="ssb-scroll">${groups}</div>`;
    ssb.querySelectorAll('.ssb-group-btn').forEach(gb => gb.addEventListener('click', () => { const open = gb.getAttribute('aria-expanded') === 'true'; gb.setAttribute('aria-expanded', String(!open)); document.getElementById(gb.getAttribute('aria-controls')).hidden = open; }));
    ssb.querySelector('#ssbCollapse').addEventListener('click', () => { if (matchMedia('(max-width: 1279px)').matches) { app.classList.remove('secondary-open', 'mobile-nav-open'); } else { app.classList.add('secondary-collapsed'); } });
    ssb.querySelectorAll('[data-proto-link="1"]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); toast('Prototype: this menu item has no mockup page yet.'); }));
    refreshIcons();
  }

  /* ---------- topbar ---------- */
  const ctxHtml = {
    period: `<button class="ctx-select hide-mobile" type="button" aria-label="Production date 08 Oct 2026, all shifts">${ic('calendar')}<span class="ctx-2"><span class="ctx-k">Production date</span><span>08 Oct 2026 · All shifts</span></span>${ic('chevron-down')}</button>`,
    warehouse: `<button class="ctx-select hide-mobile" type="button" aria-label="Warehouse RM-WH1">${ic('warehouse')}<span class="ctx-2"><span class="ctx-k">Warehouse</span><span>RM-WH1</span></span>${ic('chevron-down')}</button>`,
  };
  topbar.innerHTML = `
    <button class="icon-btn only-mobile" type="button" id="mnav" aria-label="Open navigation">${ic('menu', 'icon icon-lg')}</button>
    <button class="icon-btn hide-mobile" type="button" id="ssbToggle" aria-label="Toggle module menu">${ic('panel-left')}</button>
    <nav class="crumbs" aria-label="Breadcrumb">${crumbs.map((c, i) => i === crumbs.length - 1 ? `<span aria-current="page">${esc(c)}</span>` : `<a href="#">${esc(c)}</a>${ic('chevron-right')}`).join('')}</nav>
    <div class="top-spacer"></div>
    <label class="gsearch hide-mobile" for="gsearch">${ic('search')}<input id="gsearch" type="search" placeholder="Search PO, WO, lot, part…" autocomplete="off" aria-describedby="gsearch-hint"><span class="kbd" aria-hidden="true">Ctrl K</span></label>
    <span id="gsearch-hint" class="sr-only">Results only include records you are permitted to view.</span>
    <button class="icon-btn only-mobile" type="button" aria-label="Search">${ic('search', 'icon icon-lg')}</button>
    ${context.map(c => ctxHtml[c] || '').join('')}
    <button class="ctx-select ws" type="button" aria-label="Workspace: PT Sample Manufaktur, Plant A Cikarang. Change company or plant">${ic('building-2')}<span class="ctx-2"><span class="ctx-k val">PT Sample Manufaktur</span><span>Plant A · Cikarang</span></span>${ic('chevrons-up-down')}</button>
    <button class="icon-btn" type="button" id="bell" aria-haspopup="dialog" aria-expanded="false" aria-controls="notif" aria-label="Notifications, 4 unread">${ic('bell', 'icon icon-lg')}<span class="badge-count" aria-hidden="true">4</span></button>
    <div style="position:relative">
      <button class="user-btn" type="button" id="userBtn" aria-haspopup="menu" aria-expanded="false" aria-controls="userMenu"><span class="avatar" aria-hidden="true">RP</span><span class="user-meta"><b>R. Pratama</b><span id="roleLbl">${role}</span></span>${ic('chevron-down')}</button>
      <div class="popover" id="userMenu" role="menu" hidden style="right:0;top:42px;width:240px">
        <div style="padding:8px 12px 4px;font-size:12px;color:var(--color-text-secondary)">View navigation as role (prototype RBAC demo)</div>
        ${Object.keys(ROLE_MODULES).map(r => `<button class="menu-item" role="menuitemradio" aria-checked="${r === role}" data-role="${r}">${ic(r === role ? 'check' : 'user')}${r}</button>`).join('')}
        <div class="menu-sep"></div>
        <button class="menu-item" role="menuitem">${ic('user')}Profile & devices</button>
        <button class="menu-item" role="menuitem">${ic('bell-ring')}Notification preferences</button>
        <button class="menu-item" role="menuitem">${ic('log-out')}Sign out</button>
      </div>
    </div>`;

  /* ---------- notification center ---------- */
  const notif = el('section', { class: 'notif', id: 'notif', role: 'dialog', 'aria-label': 'Notifications', hidden: true });
  const N = [
    { t: 'Approval needed: stock adjustment ADJ-0092', m: 'Inventory · −4.200 kg at WC-PRESS1 · requested by D. Lestari', time: '09:32', act: true, unread: true, icon: 'stamp', tone: 'warning', link: 'Review request' },
    { t: 'Variance case RC-0041 assigned to your team', m: 'Reconciliation · RUN-1182-S1 · GYV 47 pcs (2.78%)', time: '09:05', act: true, unread: true, icon: 'search-check', tone: 'warning', link: 'Open case' },
    { t: 'QC released 300 pcs · lot O-1180-02', m: 'Quality → FG Warehouse · awaiting FG receipt', time: '08:51', act: false, unread: true, icon: 'badge-check', tone: 'success', link: 'View lot' },
    { t: 'Customer PO-24-0931 revised (rev 2)', m: 'Demand · line 1 due date 12 → 14 Oct', time: '08:12', act: false, unread: true, icon: 'file-text', tone: 'info', link: 'View revision' },
    { t: 'Export ready: Daily Delivery 07 Oct', m: 'Export Center · link expires in 24 h', time: 'Yesterday', act: false, unread: false, icon: 'download', tone: 'neutral', link: 'Download' },
  ];
  notif.innerHTML = `<div class="notif-head"><h2>Notifications</h2><button class="icon-btn" type="button" id="notifClose" aria-label="Close notifications">${ic('x')}</button></div>
    <div class="tabs" role="tablist"><button class="tab" role="tab" aria-selected="true" data-f="all">All <span class="count">5</span></button><button class="tab" role="tab" aria-selected="false" data-f="act">Action required <span class="count">2</span></button><button class="tab" role="tab" aria-selected="false" data-f="unread">Unread <span class="count">4</span></button></div>
    <ul class="notif-list">${N.map(n => `<li class="notif-item ${n.unread ? 'unread' : ''}" data-act="${n.act}" data-unread="${n.unread}" tabindex="0"><span class="notif-ico badge-${n.tone}">${ic(n.icon)}</span><div><div class="t">${n.unread ? '<span class="unread-dot" aria-label="Unread"></span> ' : ''}${esc(n.t)}</div><div class="m">${esc(n.m)}</div><div class="row">${n.act ? '<span class="badge badge-warning">Action required</span>' : '<span class="badge badge-neutral">Information</span>'}<span class="hint">${n.time}</span><span class="top-spacer"></span><a href="#" class="btn-link">${n.link}</a></div></div></li>`).join('')}</ul>
    <div class="notif-foot"><span>Opening an item re-checks your access.</span><button class="btn-link" type="button">Mark all as read</button></div>`;
  b.appendChild(notif);

  /* ---------- toast ---------- */
  const toastEl = el('div', { role: 'status', 'aria-live': 'polite', style: 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:var(--color-text-primary);color:#fff;padding:10px 16px;border-radius:8px;font-size:13px;z-index:90;box-shadow:var(--shadow-popover)', hidden: true });
  b.appendChild(toastEl);
  let tt; function toast(msg) { toastEl.textContent = msg; toastEl.hidden = false; clearTimeout(tt); tt = setTimeout(() => toastEl.hidden = true, 2600); }

  /* ---------- wiring ---------- */
  const bell = topbar.querySelector('#bell');
  const setNotif = open => { notif.hidden = !open; bell.setAttribute('aria-expanded', String(open)); (open ? notif.querySelector('[role=tab]') : bell).focus(); };
  bell.addEventListener('click', () => setNotif(notif.hidden));
  notif.querySelector('#notifClose').addEventListener('click', () => setNotif(false));
  notif.querySelectorAll('[role=tab]').forEach(t => t.addEventListener('click', () => {
    notif.querySelectorAll('[role=tab]').forEach(x => x.setAttribute('aria-selected', 'false')); t.setAttribute('aria-selected', 'true');
    notif.querySelectorAll('.notif-item').forEach(li => { li.hidden = (t.dataset.f === 'act' && li.dataset.act !== 'true') || (t.dataset.f === 'unread' && li.dataset.unread !== 'true'); });
  }));
  notif.querySelectorAll('.notif-item a').forEach(a => a.addEventListener('click', e => { e.preventDefault(); toast('Prototype: deep link would open the record after a server permission check.'); }));

  const userBtn = topbar.querySelector('#userBtn'), userMenu = topbar.querySelector('#userMenu');
  userBtn.addEventListener('click', () => { const open = userMenu.hidden; userMenu.hidden = !open; userBtn.setAttribute('aria-expanded', String(open)); if (open) userMenu.querySelector('.menu-item').focus(); });
  userMenu.querySelectorAll('[data-role]').forEach(r => r.addEventListener('click', () => {
    role = r.dataset.role; if (!ROLE_MODULES[role].includes(activeModule)) activeModule = ROLE_MODULES[role][0];
    userMenu.hidden = true; userBtn.setAttribute('aria-expanded', 'false');
    topbar.querySelector('#roleLbl').textContent = role;
    userMenu.querySelectorAll('[data-role]').forEach(x => { x.setAttribute('aria-checked', String(x.dataset.role === role)); x.innerHTML = ic(x.dataset.role === role ? 'check' : 'user') + x.dataset.role; });
    renderPrimary(); renderSecondary(); toast(`Navigation filtered for ${role}. Server still enforces every permission.`);
  }));

  topbar.querySelector('#ssbToggle').addEventListener('click', () => {
    if (matchMedia('(max-width: 1279px)').matches) app.classList.toggle('secondary-open');
    else app.classList.toggle('secondary-collapsed');
  });
  topbar.querySelector('#mnav').addEventListener('click', () => app.classList.toggle('mobile-nav-open'));
  workspace.addEventListener('click', () => { app.classList.remove('secondary-open', 'mobile-nav-open'); });

  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); const s = document.getElementById('gsearch'); s && s.focus(); }
    if (e.key === 'Escape') { if (!notif.hidden) setNotif(false); if (!userMenu.hidden) { userMenu.hidden = true; userBtn.focus(); } app.classList.remove('secondary-open', 'mobile-nav-open'); }
  });
  document.addEventListener('click', e => { if (!userMenu.hidden && !userMenu.contains(e.target) && !userBtn.contains(e.target)) { userMenu.hidden = true; userBtn.setAttribute('aria-expanded', 'false'); } });

  function refreshIcons() { if (window.lucide) window.lucide.createIcons({ attrs: { 'stroke-width': 2 } }); }
  window.EORA = { refreshIcons, toast };
  renderPrimary(); renderSecondary(); refreshIcons();
})();
