/* EORA prototype — shared page behaviours
   - EoraStatusTabs keyboard + panel switching (production: Radix Tabs)
   - EoraFilterBar mobile bottom sheet (same form element; no duplicate filter UI)
   Spec: docs/uiux/EORA-COMPONENT-STANDARDS.md */
(function () {
  document.querySelectorAll('#page [role="tablist"]').forEach(list => {
    const tabs = [...list.querySelectorAll('[role="tab"]')];
    const select = t => {
      tabs.forEach(x => { const on = x === t; x.setAttribute('aria-selected', String(on)); x.tabIndex = on ? 0 : -1; const p = x.getAttribute('aria-controls'); if (p) { const panel = document.getElementById(p); if (panel) panel.hidden = !on; } });
      list.dispatchEvent(new CustomEvent('eora:tab', { detail: t }));
    };
    tabs.forEach((t, i) => {
      t.addEventListener('click', () => select(t));
      t.addEventListener('keydown', e => {
        let n = null;
        if (e.key === 'ArrowRight') n = tabs[(i + 1) % tabs.length];
        if (e.key === 'ArrowLeft') n = tabs[(i - 1 + tabs.length) % tabs.length];
        if (e.key === 'Home') n = tabs[0];
        if (e.key === 'End') n = tabs[tabs.length - 1];
        if (n) { e.preventDefault(); n.focus(); select(n); }
      });
    });
  });

  /* Filter sheet (mobile < 768 px): the segmented filter form becomes a bottom sheet */
  document.querySelectorAll('[data-filter-open]').forEach(btn => {
    const form = document.getElementById(btn.getAttribute('aria-controls'));
    if (!form) return;
    const bd = document.createElement('div'); bd.className = 'backdrop'; bd.hidden = true; bd.style.zIndex = 46; document.body.appendChild(bd);
    const open = () => { form.classList.add('open'); bd.hidden = false; btn.setAttribute('aria-expanded', 'true'); form.setAttribute('role', 'dialog'); form.setAttribute('aria-modal', 'true'); window.EORA && EORA.lockScroll(true); const f = form.querySelector('[data-filter-close]'); f && f.focus(); };
    const close = (restore = true) => { if (!form.classList.contains('open')) return; form.classList.remove('open'); bd.hidden = true; btn.setAttribute('aria-expanded', 'false'); form.removeAttribute('role'); form.removeAttribute('aria-modal'); window.EORA && EORA.lockScroll(false); restore && btn.focus(); };
    btn.addEventListener('click', open);
    bd.addEventListener('click', () => close());
    form.querySelectorAll('[data-filter-close]').forEach(c => c.addEventListener('click', () => close()));
    form.addEventListener('submit', () => close());
    form.addEventListener('keydown', e => { if (!form.classList.contains('open')) return; if (e.key === 'Escape') { e.stopPropagation(); close(); } window.EORA && EORA.trapFocus(form, e); });
    matchMedia('(min-width: 768px)').addEventListener('change', e => { if (e.matches) close(false); });
  });
  if (window.EORA) window.EORA.refreshIcons();
})();
