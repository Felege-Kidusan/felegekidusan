/*
 * FKSS score limit — live guard for mark-entry boxes (2026-10-22, owner).
 *
 * "If the department sets a test out of 10, the teacher must not be able to
 * enter more than 10 — limit it live." Every score box on the web uses this:
 *
 *   FKSSScoreLimit.bind(container, '.grade-input');  // live guard
 *   FKSSScoreLimit.problems(container, '.grade-input'); // [] or invalid list
 *   FKSSScoreLimit.isValid(text, max)
 *
 * A box's limit is its `max` attribute (or data-max). A keystroke or paste
 * that would make the value larger than the max, negative, or give more than
 * 2 decimals is undone on the spot and a small hint says "Max is 10".
 * Blank is always allowed (blank = no score; after submit = Did not take).
 * The server enforces the same rule (422, nothing saved) — this is the
 * friendly first line, not the only one.
 */
(function (global) {
  'use strict';

  var RX = /^\d{1,4}(\.\d{0,2})?$/;      // while typing: "9." is fine
  var RX_FINAL = /^\d{1,4}(\.\d{1,2})?$/; // when saving: "9." is not

  function maxOf(el) {
    var m = parseFloat(el.getAttribute('max') || el.getAttribute('data-max') || '');
    return isFinite(m) && m > 0 ? m : null;
  }
  function label(n) {
    return String(Math.round(n * 100) / 100);
  }
  function isValid(text, max, final) {
    var t = String(text == null ? '' : text).trim();
    if (t === '') return true;
    if (!(final ? RX_FINAL : RX).test(t)) return false;
    var v = parseFloat(t);
    if (!isFinite(v) || v < 0) return false;
    return max == null || v <= max + 1e-9;
  }

  var hintEl = null, hintTimer = null, hintOwner = null;
  function hideHint() {
    clearTimeout(hintTimer);
    hintOwner = null;
    if (hintEl) hintEl.style.display = 'none';
  }
  // The hint is position:fixed — never leave it floating over another screen
  // or a modal: hide it when its box loses focus, or on scroll / resize.
  try {
    document.addEventListener('focusout', function (e) { if (hintOwner && e.target === hintOwner) hideHint(); }, true);
    window.addEventListener('scroll', function () { if (hintOwner) hideHint(); }, true);
    window.addEventListener('resize', function () { if (hintOwner) hideHint(); });
  } catch (_) {}
  function hint(el, msg) {
    try {
      if (!hintEl) {
        hintEl = document.createElement('div');
        hintEl.className = 'fk-score-hint';
        hintEl.setAttribute('role', 'status');
        hintEl.setAttribute('aria-live', 'polite');
        hintEl.style.cssText = 'position:fixed;z-index:100000;background:#991b1b;color:#fff;font:600 12px/1.3 system-ui,sans-serif;' +
          'padding:5px 9px;border-radius:7px;box-shadow:0 4px 14px rgba(0,0,0,.18);pointer-events:none;white-space:nowrap;display:none';
        document.body.appendChild(hintEl);
      }
      var r = el.getBoundingClientRect();
      hintEl.textContent = msg;
      hintEl.style.left = Math.max(6, r.left) + 'px';
      hintEl.style.top = (r.bottom + 6) + 'px';
      hintEl.style.display = 'block';
      clearTimeout(hintTimer);
      hintOwner = el;
      hintTimer = setTimeout(hideHint, 1800);
      el.classList.add('fk-score-refused');
      setTimeout(function () { el.classList.remove('fk-score-refused'); }, 600);
    } catch (_) {}
  }

  function onKeydown(e) {
    var el = e.target;
    if (!el || !el.matches || !el.matches('[data-fk-score]')) return;
    // number inputs accept e / E / + / - — none are valid in a score.
    if (e.key === 'e' || e.key === 'E' || e.key === '+' || e.key === '-') {
      e.preventDefault();
      var mx = maxOf(el);
      hint(el, mx != null ? 'Numbers only, 0 to ' + label(mx) : 'Numbers only');
    }
  }
  function onFocus(e) {
    var el = e.target;
    if (!el || !el.matches || !el.matches('[data-fk-score]')) return;
    if (isValid(el.value, maxOf(el), false)) el.dataset.fkLast = el.value;
  }
  function onInput(e) {
    var el = e.target;
    if (!el || !el.matches || !el.matches('[data-fk-score]')) return;
    // Mid-typing states the browser cannot parse yet (e.g. "9." in some
    // engines) report badInput with value '' — let the user keep typing.
    if (el.validity && el.validity.badInput) return;
    var max = maxOf(el), v = el.value;
    if (isValid(v, max, false)) {
      el.dataset.fkLast = v;
      el.classList.remove('fk-score-bad');
      el.removeAttribute('aria-invalid');
      return;
    }
    var prev = el.dataset.fkLast || '';
    el.value = isValid(prev, max, false) ? prev : '';
    var t = String(v).trim(), num = parseFloat(t);
    if (max != null && isFinite(num) && num > max) hint(el, 'Max is ' + label(max));
    else if (/\.\d{3,}/.test(t)) hint(el, 'Up to 2 decimals');
    else hint(el, max != null ? 'Use a number from 0 to ' + label(max) : 'Use a number');
    // This listener runs in the capture phase, so the box's own oninput
    // (live counters) runs after it and already sees the corrected value.
  }

  var bound = typeof WeakSet === 'function' ? new WeakSet() : null;
  function bind(container, selector) {
    var root = container || document;
    var boxes = root.querySelectorAll(selector || 'input[type="number"]');
    boxes.forEach(function (el) {
      el.setAttribute('data-fk-score', '1');
      if (!el.getAttribute('inputmode')) el.setAttribute('inputmode', 'decimal');
      if (isValid(el.value, maxOf(el), true)) el.dataset.fkLast = el.value;
      else el.classList.add('fk-score-bad');
    });
    var host = root === document ? document : root;
    if (bound && bound.has(host)) return boxes.length;
    if (bound) bound.add(host);
    host.addEventListener('keydown', onKeydown, true);
    host.addEventListener('focusin', onFocus, true);
    host.addEventListener('input', onInput, true);
    return boxes.length;
  }

  /** Invalid boxes right now (old/pasted values the live guard never saw). */
  function problems(container, selector) {
    var out = [];
    (container || document).querySelectorAll(selector || '[data-fk-score]').forEach(function (el) {
      var max = maxOf(el);
      var bad = (el.validity && el.validity.badInput) || !isValid(el.value, max, true);
      el.classList.toggle('fk-score-bad', !!bad);
      if (bad) { el.setAttribute('aria-invalid', 'true'); out.push({ el: el, value: el.value, max: max }); }
      else el.removeAttribute('aria-invalid');
    });
    return out;
  }

  function problemMessage(list) {
    if (!list || !list.length) return '';
    var max = list[0].max;
    return list.length + (list.length === 1 ? ' score is' : ' scores are') + ' not valid' +
      (max != null ? ' (allowed: 0 to ' + label(max) + ', up to 2 decimals)' : '') +
      '. Fix the boxes marked in red. Nothing was sent.';
  }

  // One tiny stylesheet so every screen looks the same.
  try {
    if (!document.getElementById('fk-score-style')) {
      var st = document.createElement('style');
      st.id = 'fk-score-style';
      st.textContent = '.fk-score-bad{border-color:#dc2626!important;background:#fef2f2!important;box-shadow:0 0 0 2px rgba(220,38,38,.18)!important}' +
        '.fk-score-refused{animation:fkScoreShake .3s linear;border-color:#dc2626!important}' +
        '@keyframes fkScoreShake{0%,100%{transform:translateX(0)}25%{transform:translateX(-3px)}75%{transform:translateX(3px)}}';
      (document.head || document.documentElement).appendChild(st);
    }
  } catch (_) {}

  global.FKSSScoreLimit = { bind: bind, problems: problems, problemMessage: problemMessage, isValid: isValid, label: label };
})(window);
