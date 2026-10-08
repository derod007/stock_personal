/* Progressive enhancement only: no sorting, fetching or trading state changes. */
(() => {
  'use strict';
  document.querySelectorAll('.paper-app table, #scan-results').forEach((table, i) => {
    let wrap = table.parentElement;
    if (!wrap.classList.contains('scan-table-wrap')) {
      const box = document.createElement('div');
      box.className = 'scan-table-wrap';
      wrap.insertBefore(box, table);
      box.appendChild(table);
      wrap = box;
    }
    wrap.tabIndex = 0;
    wrap.setAttribute('role', 'region');
    wrap.setAttribute('aria-label', '결과 표 ' + (i + 1) + ' · 좌우 스크롤 가능');
    if (!table.classList.contains('paper-kv') && table.querySelectorAll('thead th').length > 4) {
      const hint = document.createElement('p');
      hint.className = 'table-scroll-hint';
      hint.textContent = '표가 잘리면 좌우로 밀어 확인하세요. 키보드는 표 선택 후 방향키를 사용하세요.';
      wrap.before(hint);
    }
  });
  const heading = document.querySelector('.paper-app .page-heading');
  const sections = document.querySelectorAll('.paper-app main h2');
  if (heading && sections.length > 1) {
    const nav = document.createElement('nav');
    nav.className = 'page-sections';
    nav.setAttribute('aria-label', '이 화면에서 바로 이동');
    sections.forEach((section, i) => {
      if (!section.id) section.id = 'read-section-' + i;
      const link = document.createElement('a');
      link.href = '#' + section.id;
      link.textContent = section.textContent;
      nav.appendChild(link);
    });
    heading.after(nav);
  }
})();
