/**
 * GuardianPicker — shared typeahead for linking an EXISTING guardian.
 * Usage: GuardianPicker.attach(inputEl, listEl, function(guardian){...})
 * Talks to /admin/api_guardians.php?action=search (read roles only).
 */
(function (window, document) {
  'use strict';

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  var GuardianPicker = {
    attach: function (input, listEl, onSelect) {
      var timer = null;
      var hide = function () { setTimeout(function () { listEl.innerHTML = ''; listEl.style.display = 'none'; }, 180); };
      var show = function () { listEl.style.display = 'block'; };

      input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = input.value.trim();
        if (q.length < 2) { hide(); return; }
        timer = setTimeout(function () {
          fetch('/admin/api_guardians.php?action=search&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
              if (!d || d.status !== 'success' || !d.guardians.length) {
                listEl.innerHTML = '<div style="padding:.5rem .75rem;font-size:.75rem;color:#94a3b8">No existing guardian matches — a new one will be created on save.</div>';
                show();
                return;
              }
              listEl.innerHTML = d.guardians.map(function (g) {
                var kids = (g.children || []).map(function (k) { return esc(k.student_name) + (k.current_section ? ' (' + esc(k.current_section) + ')' : ''); }).join(', ');
                return '<button type="button" data-gid="' + (int_guard(g.id)) + '" style="display:block;width:100%;text-align:left;padding:.5rem .75rem;border:0;background:transparent;cursor:pointer;border-bottom:1px solid rgba(148,163,184,.15)">' +
                  '<div style="font-weight:700;font-size:.8rem;color:#e2e8f0">' + esc(g.display_name) + '</div>' +
                  '<div style="font-size:.7rem;color:#94a3b8">' + esc(g.phone_primary || 'no phone') + (kids ? ' · children: ' + kids : ' · no linked children yet') + '</div>' +
                  '</button>';
              }).join('');
              show();
              Array.prototype.forEach.call(listEl.querySelectorAll('button[data-gid]'), function (btn) {
                btn.addEventListener('click', function () {
                  var gid = parseInt(btn.getAttribute('data-gid'), 10);
                  var g = d.guardians.filter(function (x) { return parseInt(x.id, 10) === gid; })[0] || null;
                  listEl.innerHTML = ''; hide();
                  if (g && onSelect) onSelect(g);
                });
              });
            })
            .catch(function () { hide(); });
        }, 250);
      });
      input.addEventListener('blur', hide);
    }
  };

  function int_guard(v) { return parseInt(v, 10) || 0; }

  window.GuardianPicker = GuardianPicker;
})(window, document);
