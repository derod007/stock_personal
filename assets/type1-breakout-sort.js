'use strict';
(() => {
  const nav = document.getElementById('breakout-sort');
  if (!nav) return;
  nav.addEventListener('click', event => {
    const link = event.target.closest('a[data-sort]');
    if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    const mode = link.dataset.sort;
    for (const body of document.querySelectorAll('.type1 tbody')) {
      const rows = [...body.querySelectorAll('tr[data-near]')];
      rows.sort((a,b) => Number(a.dataset[mode]) - Number(b.dataset[mode]));
      rows.forEach(row => body.appendChild(row));
    }
    nav.querySelectorAll('a').forEach(a => {
      if (a === link) a.setAttribute('aria-current','true');
      else a.removeAttribute('aria-current');
    });
    const field = document.querySelector('.type1 input[name=sort]');
    if (field) field.value = mode;
    const refresh = document.querySelector('[data-sort-refresh]');
    if (refresh) {
      const url = new URL(refresh.href); url.searchParams.set('sort',mode); refresh.href=url.href;
    }
    history.replaceState(history.state,'',link.href);
  });
})();
