/**
 * Super Admin shell — one page at a time.
 * Branding, calendar, and forms stay in the dashboard PHP.
 */
(function () {
  'use strict';

  var ALLOWED = {
    overview: 1, users: 1, departments: 1, health: 1, settings: 1,
    branding: 1, logs: 1, backup: 1, syshealth: 1
  };

  function panels() {
    return document.querySelectorAll('main.main .content > section.section');
  }

  function showPanel(id) {
    panels().forEach(function (el) {
      var on = el.id === 'section-' + id;
      el.classList.toggle('active', on);
      if (on) {
        el.removeAttribute('hidden');
      } else {
        el.setAttribute('hidden', '');
      }
    });
  }

  window.switchSection = function (id) {
    if (!ALLOWED[id]) return;
    showPanel(id);
    document.querySelectorAll('.nav-link, .mobile-nav-btn, .wbws-bnav-btn').forEach(function (b) {
      b.classList.remove('active');
    });
    document.querySelectorAll('[data-section="' + id + '"]').forEach(function (b) {
      b.classList.add('active');
    });
    document.body.classList.toggle('branding-on', id === 'branding');
    var pane = document.querySelector('main.main .content');
    if (pane) pane.scrollTop = 0;
    if (window.history && history.replaceState) {
      history.replaceState(null, '', '?section=' + encodeURIComponent(id));
    }
  };

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-section]');
    if (!btn) return;
    var id = btn.getAttribute('data-section');
    if (!id || !ALLOWED[id]) return;
    if (btn.tagName === 'A') return;
    e.preventDefault();
    window.switchSection(id);
  });

  document.querySelectorAll('[data-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var inp = this.parentElement.querySelector('input');
      if (!inp) return;
      inp.type = inp.type === 'password' ? 'text' : 'password';
      this.textContent = inp.type === 'password' ? 'Show' : 'Hide';
    });
  });

  document.querySelectorAll('.health-tab[data-htab]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.health-tab').forEach(function (t) { t.classList.remove('active'); });
      document.querySelectorAll('.health-panel').forEach(function (p) { p.classList.remove('active'); });
      this.classList.add('active');
      var panel = document.getElementById('htab-' + this.dataset.htab);
      if (panel) panel.classList.add('active');
    });
  });

  document.querySelectorAll('.sys-tab[data-stab]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.sys-tab').forEach(function (t) { t.classList.remove('active'); });
      document.querySelectorAll('.sys-panel').forEach(function (p) { p.classList.remove('active'); });
      this.classList.add('active');
      var panel = document.getElementById('stab-' + this.dataset.stab);
      if (panel) panel.classList.add('active');
    });
  });

  var boot = window.SA_BOOT || {};
  var start = ALLOWED[boot.section] ? boot.section : 'overview';
  showPanel(start);
})();
