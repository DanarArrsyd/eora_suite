/* EORA prototype — shared page behaviours (EoraStatusTabs keyboard + panel switching).
   Production: Radix Tabs via shadcn `tabs` wrapped as EoraStatusTabs. */
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
        const k = e.key; let n = null;
        if (k === 'ArrowRight') n = tabs[(i + 1) % tabs.length];
        if (k === 'ArrowLeft') n = tabs[(i - 1 + tabs.length) % tabs.length];
        if (k === 'Home') n = tabs[0];
        if (k === 'End') n = tabs[tabs.length - 1];
        if (n) { e.preventDefault(); n.focus(); select(n); }
      });
    });
  });
  if (window.EORA) window.EORA.refreshIcons();
})();
