'use strict';
    (function () {
      const sortBar = document.getElementById('scan-sort');
      const tbody = document.querySelector('#scan-results tbody');
      if (!sortBar || !tbody) return;
      const rows = Array.from(tbody.querySelectorAll('tr'));
      const applySort = (mode) => {
        const sorted = rows.slice().sort((a, b) => {
          if (mode === 'entry') {
            return Number(a.getAttribute('data-entry-order')) - Number(b.getAttribute('data-entry-order'));
          }
          const sa = Number(a.getAttribute('data-score') || -1);
          const sb = Number(b.getAttribute('data-score') || -1);
          if (sa !== sb) return sb - sa;
          return Number(a.getAttribute('data-orig') || 0) - Number(b.getAttribute('data-orig') || 0);
        });
        sorted.forEach((tr) => tbody.appendChild(tr));
      };
      const status = document.getElementById('scan-sort-status');
      applySort('entry');
      sortBar.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-sort]');
        if (!btn) return;
        const mode = btn.getAttribute('data-sort') || 'score';
        sortBar.querySelectorAll('[data-sort]').forEach((c) => {
          c.classList.remove('is-active');
          c.setAttribute('aria-pressed', 'false');
        });
        btn.classList.add('is-active');
        btn.setAttribute('aria-pressed', 'true');
        const before = Array.from(tbody.querySelectorAll('tr'));
        applySort(mode);
        const after = Array.from(tbody.querySelectorAll('tr'));
        const same = before.every((row, i) => row === after[i]);
        if (status) status.textContent = '현재 정렬: ' + (mode === 'score' ? '점수 높은 순' : '지정가 계획·관찰순') + (same ? ' · 현재 결과의 순서가 같습니다.' : ' · 순서 변경 완료');
      });
    })();

