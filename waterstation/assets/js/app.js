(function () {
  // Confirmation dialogs on delete/cancel forms.
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (ev.defaultPrevented) return;
    var msg = f.getAttribute && f.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { ev.preventDefault(); return; }
    // Loading state + prevent double submit.
    var btns = f.querySelectorAll('button[type=submit], button:not([type])');
    btns.forEach(function (b) { if (!b.hasAttribute('data-no-loading')) { b.classList.add('btn-loading'); b.setAttribute('aria-busy', 'true'); } });
    setTimeout(function () { btns.forEach(function (b) { b.classList.remove('btn-loading'); }); }, 8000);
  });

  // Click-to-sort for tables with class "sortable-table" (sorts the rows on the current page).
  document.querySelectorAll('table.sortable-table').forEach(function (table) {
    var heads = table.querySelectorAll('thead th.sortable');
    heads.forEach(function (th, idx) {
      th.addEventListener('click', function () {
        var col = Array.prototype.indexOf.call(th.parentNode.children, th);
        var dir = th.getAttribute('data-dir') === 'asc' ? 'desc' : 'asc';
        heads.forEach(function (h) { h.removeAttribute('data-dir'); });
        th.setAttribute('data-dir', dir);
        var tbody = table.tBodies[0];
        var rows = Array.prototype.slice.call(tbody.rows);
        rows.sort(function (a, b) {
          var x = (a.cells[col].getAttribute('data-sort') || a.cells[col].innerText).trim();
          var y = (b.cells[col].getAttribute('data-sort') || b.cells[col].innerText).trim();
          var nx = parseFloat(x.replace(/[^0-9.\-]/g, '')), ny = parseFloat(y.replace(/[^0-9.\-]/g, ''));
          var r = (!isNaN(nx) && !isNaN(ny) && /\d/.test(x) && /\d/.test(y)) ? nx - ny : x.localeCompare(y);
          return dir === 'asc' ? r : -r;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
      });
    });
  });
})();
