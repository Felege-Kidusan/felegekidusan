/*!
 * DangerConfirm — shared confirmation dialog for risky actions (FKSS / SSMS).
 *
 * Owner decisions 2026-10-21 (docs/planning/DANGER_CONFIRMATION_RESEARCH_PLAN_2026-10-21.md §8):
 *   HIGH + MEDIUM : 3 clicks + typed phrase
 *                   Step 1  intent        → "I want to …"
 *                   Step 2  consequences  → "I have read and understand these effects"
 *                   Step 3  type phrase   → final button enabled only on a match
 *   LOW           : one simple, specific dialog
 *
 * Patterns follow GitHub (delete repository), GitLab Pajamas, AWS Cloudscape,
 * Primer ConfirmationDialog and NN/G: specific title, verb+object buttons,
 * Cancel gets initial focus, Esc cancels, Enter never triggers the
 * destructive button, double-submit guard, focus returns to the trigger.
 *
 * API
 *   DangerConfirm.ask(opts) → Promise<false | {phrase, reason, choice, result}>
 *     tier          'high' | 'medium' | 'low'           (default 'medium')
 *     title         short specific question  e.g. "Remove Monday from 3ኛ ክፍል?"
 *     object        optional object name shown in bold
 *     summary       one-sentence description of the action
 *     intent        step-1 button label      e.g. "I want to remove this day"
 *     consequences  string[] | () => Promise<string[]>   (live counts)
 *     keeps         string[]  what will NOT happen  (reassurance)
 *     irreversible  bool  → "This cannot be undone."
 *     phrase        typed phrase (plain English capitals), e.g. "REMOVE DAY"
 *     confirmLabel  final button label       e.g. "Remove Monday"
 *     choice        {label, options:[{value,label}], value}  optional select
 *     reason        {label, required, placeholder, maxLength}  optional textarea
 *     onConfirm     optional async (res) => any. Runs while the dialog shows
 *                   "Working…". Throw (or return {error:'msg'}) to keep the
 *                   dialog open with the message; resolve to close.
 *   DangerConfirm.normalize(str)  → phrase comparison form
 *   DangerConfirm.isOpen()
 */
(function (global) {
  'use strict';
  if (global.DangerConfirm && global.DangerConfirm.__v) return;

  var CSS = [
    '.dcx-back{position:fixed;inset:0;z-index:2147483000;background:rgba(15,23,42,.62);display:flex;align-items:center;justify-content:center;padding:16px;animation:dcxFade .12s ease-out}',
    '@keyframes dcxFade{from{opacity:0}to{opacity:1}}',
    '.dcx{background:#fff;color:#0f172a;width:100%;max-width:520px;max-height:calc(100vh - 32px);overflow:auto;border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,.35);border-top:6px solid #dc2626;font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans Ethiopic","Nyala",sans-serif;text-align:left}',
    '.dcx.dcx-medium{border-top-color:#d97706}.dcx.dcx-low{border-top-color:#64748b}',
    '.dcx-hd{display:flex;gap:12px;align-items:flex-start;padding:18px 20px 6px}',
    '.dcx-ic{flex:0 0 40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#fee2e2;color:#b91c1c}',
    '.dcx-medium .dcx-ic{background:#fef3c7;color:#b45309}.dcx-low .dcx-ic{background:#f1f5f9;color:#475569}',
    '.dcx-ic svg{width:22px;height:22px}',
    '.dcx-tt{margin:0;font-size:17px;font-weight:700;line-height:1.35;color:#0f172a;word-break:break-word}',
    '.dcx-step{display:block;font-size:11px;font-weight:700;letter-spacing:.04em;color:#64748b;margin-bottom:2px}',
    '.dcx-bd{padding:6px 20px 4px 72px}',
    '.dcx-bd p{margin:0 0 10px}',
    '.dcx-obj{font-weight:700;color:#0f172a;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:1px 6px}',
    '.dcx-list{margin:0 0 10px;padding:0;list-style:none}',
    '.dcx-list li{position:relative;padding:6px 8px 6px 28px;margin:0 0 6px;border-radius:8px;background:#fef2f2;color:#7f1d1d}',
    '.dcx-medium .dcx-list li{background:#fffbeb;color:#78350f}.dcx-low .dcx-list li{background:#f8fafc;color:#334155}',
    '.dcx-list li:before{content:"!";position:absolute;left:9px;top:6px;font-weight:800}',
    '.dcx-keeps li{background:#f0fdf4 !important;color:#14532d !important}.dcx-keeps li:before{content:"\\2713" !important}',
    '.dcx-irr{font-weight:700;color:#b91c1c}.dcx-medium .dcx-irr{color:#b45309}',
    '.dcx-lbl{display:block;font-weight:600;margin:6px 0 4px}',
    '.dcx-in,.dcx-sel,.dcx-ta{width:100%;box-sizing:border-box;border:1.5px solid #cbd5e1;border-radius:8px;padding:9px 10px;font:inherit;color:#0f172a;background:#fff}',
    '.dcx-in{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.04em}',
    '.dcx-in:focus,.dcx-sel:focus,.dcx-ta:focus{outline:3px solid rgba(220,38,38,.25);border-color:#dc2626}',
    '.dcx-in.dcx-ok{border-color:#16a34a}',
    '.dcx-ta{min-height:64px;resize:vertical}',
    '.dcx-hint{font-size:12px;color:#64748b;margin:4px 0 0}',
    '.dcx-err{display:none;margin:8px 0 0;padding:8px 10px;border-radius:8px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;font-weight:600}',
    '.dcx-err.dcx-show{display:block}',
    '.dcx-load{color:#64748b;font-style:italic}',
    '.dcx-ft{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;padding:14px 20px 18px}',
    '.dcx-btn{appearance:none;border:1.5px solid #cbd5e1;background:#fff;color:#0f172a;border-radius:9px;padding:9px 14px;font:inherit;font-weight:700;cursor:pointer;min-height:40px}',
    '.dcx-btn:focus-visible{outline:3px solid rgba(37,99,235,.45);outline-offset:2px}',
    '.dcx-btn:hover{background:#f8fafc}',
    '.dcx-btn-go{border-color:#dc2626;color:#b91c1c}',
    '.dcx-medium .dcx-btn-go{border-color:#d97706;color:#92400e}',
    '.dcx-btn-danger{background:#dc2626;border-color:#dc2626;color:#fff}',
    '.dcx-btn-danger:hover{background:#b91c1c}',
    '.dcx-medium .dcx-btn-danger{background:#d97706;border-color:#d97706}',
    '.dcx-medium .dcx-btn-danger:hover{background:#b45309}',
    '.dcx-low .dcx-btn-danger{background:#334155;border-color:#334155}',
    '.dcx-btn[disabled]{opacity:.45;cursor:not-allowed}',
    '.dcx-dots{display:flex;gap:6px;margin:4px 0 0}',
    '.dcx-dots i{width:22px;height:4px;border-radius:2px;background:#e2e8f0}',
    '.dcx-dots i.on{background:#dc2626}.dcx-medium .dcx-dots i.on{background:#d97706}',
    '@media (max-width:480px){.dcx-back{align-items:flex-end;padding:0}.dcx{max-width:none;border-radius:14px 14px 0 0;max-height:92vh}.dcx-bd{padding-left:20px}.dcx-ft{flex-direction:column-reverse}.dcx-btn{width:100%}}'
  ].join('\n');

  var ICON = {
    high: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    low: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
  };

  var open = false;
  var seq = 0;

  function injectCss() {
    if (document.getElementById('dcx-style')) return;
    var st = document.createElement('style');
    st.id = 'dcx-style';
    st.textContent = CSS;
    (document.head || document.documentElement).appendChild(st);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function normalize(s) {
    return String(s == null ? '' : s).replace(/\s+/g, ' ').trim().toUpperCase();
  }

  function listHtml(items, cls) {
    if (!items || !items.length) return '';
    return '<ul class="dcx-list ' + (cls || '') + '">' + items.map(function (t) {
      return '<li>' + esc(t) + '</li>';
    }).join('') + '</ul>';
  }

  function ask(opts) {
    opts = opts || {};
    if (open) return Promise.resolve(false);           // never stack dialogs
    injectCss();
    var tier = (opts.tier === 'high' || opts.tier === 'low') ? opts.tier : 'medium';
    var steps = tier === 'low' ? 1 : 3;
    var phrase = normalize(opts.phrase || (tier === 'low' ? '' : 'CONFIRM'));
    var id = 'dcx' + (++seq);
    var prevFocus = document.activeElement;
    var step = 1;
    var busy = false;
    var consequences = Array.isArray(opts.consequences) ? opts.consequences : null;
    var consequenceErr = false;
    var state = { reason: '', choice: opts.choice ? String(opts.choice.value == null ? '' : opts.choice.value) : '' };

    open = true;
    var back = document.createElement('div');
    back.className = 'dcx-back';
    back.setAttribute('data-dcx', '1');
    var box = document.createElement('div');
    box.className = 'dcx dcx-' + tier;
    box.setAttribute('role', 'alertdialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-labelledby', id + '-t');
    box.setAttribute('aria-describedby', id + '-d');
    back.appendChild(box);

    return new Promise(function (resolve) {
      function close(val) {
        if (!back.parentNode) return;
        document.removeEventListener('keydown', onKey, true);
        back.parentNode.removeChild(back);
        open = false;
        try { if (prevFocus && prevFocus.focus && document.contains(prevFocus)) prevFocus.focus(); } catch (e) { /* ignore */ }
        resolve(val);
      }

      function stepLabel() {
        if (steps === 1) return '';
        return '<span class="dcx-step">STEP ' + step + ' OF ' + steps + '</span>';
      }
      function dots() {
        if (steps === 1) return '';
        var h = '<div class="dcx-dots" aria-hidden="true">';
        for (var i = 1; i <= steps; i++) h += '<i class="' + (i <= step ? 'on' : '') + '"></i>';
        return h + '</div>';
      }
      function header() {
        return '<div class="dcx-hd"><div class="dcx-ic">' + (tier === 'low' ? ICON.low : ICON.high) + '</div>'
          + '<div>' + stepLabel() + '<h2 class="dcx-tt" id="' + id + '-t">' + esc(opts.title || 'Confirm this action') + '</h2>' + dots() + '</div></div>';
      }
      function summaryHtml() {
        var h = '';
        if (opts.summary) h += '<p>' + esc(opts.summary) + '</p>';
        if (opts.object) h += '<p><span class="dcx-obj">' + esc(opts.object) + '</span></p>';
        return h;
      }
      function consequencesHtml() {
        var h = '';
        if (consequences === null) h += '<p class="dcx-load">Checking what will be affected…</p>';
        else h += listHtml(consequences);
        if (consequenceErr) h += '<p class="dcx-hint">The exact numbers could not be loaded. The effects above still apply.</p>';
        h += listHtml(opts.keeps, 'dcx-keeps');
        if (opts.irreversible) h += '<p class="dcx-irr">This cannot be undone.</p>';
        return h;
      }
      function extrasHtml() {
        var h = '';
        if (opts.choice && opts.choice.options) {
          h += '<label class="dcx-lbl" for="' + id + '-c">' + esc(opts.choice.label || 'Choose') + '</label>'
            + '<select class="dcx-sel" id="' + id + '-c">' + opts.choice.options.map(function (o) {
              var v = String(o.value);
              return '<option value="' + esc(v) + '"' + (v === state.choice ? ' selected' : '') + '>' + esc(o.label) + '</option>';
            }).join('') + '</select>';
        }
        if (opts.reason) {
          h += '<label class="dcx-lbl" for="' + id + '-r">' + esc(opts.reason.label || 'Reason') + (opts.reason.required ? ' (required)' : ' (optional)') + '</label>'
            + '<textarea class="dcx-ta" id="' + id + '-r" maxlength="' + (opts.reason.maxLength || 500) + '" placeholder="' + esc(opts.reason.placeholder || '') + '">' + esc(state.reason) + '</textarea>';
        }
        return h;
      }

      function render() {
        var body = '', foot = '';
        var cancel = '<button type="button" class="dcx-btn" data-dcx-act="cancel">Cancel</button>';
        if (steps === 1) {
          body = summaryHtml() + consequencesHtml() + extrasHtml();
          foot = cancel + '<button type="button" class="dcx-btn dcx-btn-danger" data-dcx-act="final">' + esc(opts.confirmLabel || 'Confirm') + '</button>';
        } else if (step === 1) {
          body = summaryHtml() + '<p>Please read the next step carefully before you continue.</p>';
          foot = cancel + '<button type="button" class="dcx-btn dcx-btn-go" data-dcx-act="next">' + esc(opts.intent || 'I want to continue') + '</button>';
        } else if (step === 2) {
          body = '<p><strong>What will happen:</strong></p>' + consequencesHtml();
          foot = cancel + '<button type="button" class="dcx-btn dcx-btn-go" data-dcx-act="next"' + (consequences === null ? ' disabled' : '') + '>I have read and understand these effects</button>';
        } else {
          body = (opts.object ? '<p><span class="dcx-obj">' + esc(opts.object) + '</span></p>' : '')
            + extrasHtml()
            + '<label class="dcx-lbl" for="' + id + '-i">To confirm, type <span class="dcx-obj">' + esc(phrase) + '</span> below</label>'
            + '<input class="dcx-in" id="' + id + '-i" type="text" autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" aria-describedby="' + id + '-h">'
            + '<p class="dcx-hint" id="' + id + '-h">The button stays disabled until you type the words exactly.</p>';
          foot = cancel + '<button type="button" class="dcx-btn dcx-btn-danger" data-dcx-act="final" disabled>' + esc(opts.confirmLabel || 'Confirm') + '</button>';
        }
        box.innerHTML = header() + '<div class="dcx-bd" id="' + id + '-d">' + body + '<div class="dcx-err" role="alert"></div></div><div class="dcx-ft">' + foot + '</div>';
        wire();
      }

      function q(sel) { return box.querySelector(sel); }
      function finalBtn() { return q('[data-dcx-act="final"]'); }

      function phraseOk() {
        if (steps === 1) return true;
        var inp = q('#' + id + '-i');
        return !!inp && normalize(inp.value) === phrase;
      }
      function reasonOk() {
        return !(opts.reason && opts.reason.required) || state.reason.trim().length > 0;
      }
      function refreshFinal() {
        var b = finalBtn();
        if (!b) return;
        var ok = phraseOk() && reasonOk();
        if (step === steps) b.disabled = !ok || busy;
        var inp = q('#' + id + '-i');
        if (inp) inp.classList.toggle('dcx-ok', phraseOk());
      }
      function showErr(msg) {
        var e = q('.dcx-err');
        if (!e) return;
        e.textContent = msg || '';
        e.classList.toggle('dcx-show', !!msg);
      }

      function wire() {
        var r = q('#' + id + '-r');
        if (r) r.addEventListener('input', function () { state.reason = r.value; refreshFinal(); });
        var c = q('#' + id + '-c');
        if (c) c.addEventListener('change', function () { state.choice = c.value; });
        var inp = q('#' + id + '-i');
        if (inp) {
          inp.addEventListener('input', refreshFinal);
          inp.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') ev.preventDefault(); });
        }
        Array.prototype.forEach.call(box.querySelectorAll('[data-dcx-act]'), function (b) {
          b.addEventListener('click', function () { act(b.getAttribute('data-dcx-act')); });
        });
        refreshFinal();
        // Focus: Cancel by default (Primer rule for danger dialogs); the
        // typing field on the last step.
        setTimeout(function () {
          var f = (step === steps && steps > 1) ? (q('#' + id + '-c') || q('#' + id + '-i')) : q('[data-dcx-act="cancel"]');
          if (f) f.focus();
        }, 0);
      }

      function act(a) {
        if (busy) return;
        if (a === 'cancel') { close(false); return; }
        if (a === 'next') {
          if (step === 2 && consequences === null) return;
          step++;
          render();
          return;
        }
        if (a === 'final') {
          if (!phraseOk() || !reasonOk()) { refreshFinal(); return; }
          var res = { phrase: phrase, reason: state.reason.trim(), choice: state.choice };
          if (typeof opts.onConfirm !== 'function') { close(res); return; }
          busy = true;
          showErr('');
          var b = finalBtn();
          var label = b ? b.textContent : '';
          if (b) { b.disabled = true; b.textContent = 'Working…'; }
          Array.prototype.forEach.call(box.querySelectorAll('[data-dcx-act="cancel"]'), function (x) { x.disabled = true; });
          Promise.resolve().then(function () { return opts.onConfirm(res); }).then(function (out) {
            if (out && typeof out === 'object' && out.error) throw new Error(out.error);
            res.result = out;
            busy = false;
            close(res);
          }).catch(function (err) {
            busy = false;
            if (b) b.textContent = label;
            Array.prototype.forEach.call(box.querySelectorAll('[data-dcx-act="cancel"]'), function (x) { x.disabled = false; });
            showErr((err && err.message) || 'Something went wrong. Nothing was changed.');
            refreshFinal();
          });
        }
      }

      function onKey(ev) {
        if (!back.parentNode) return;
        if (ev.key === 'Escape') {
          ev.preventDefault(); ev.stopPropagation();
          if (!busy) close(false);
          return;
        }
        if (ev.key === 'Tab') {               // keep focus inside the dialog
          var f = Array.prototype.filter.call(box.querySelectorAll('button,input,select,textarea,[tabindex]'), function (x) {
            return !x.disabled && x.offsetParent !== null;
          });
          if (!f.length) return;
          var first = f[0], last = f[f.length - 1];
          if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
          else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
        }
        if (ev.key === 'Enter' && document.activeElement && document.activeElement.getAttribute
            && document.activeElement.getAttribute('data-dcx-act') === 'final') {
          ev.preventDefault();                 // never destroy on Enter
        }
      }

      back.addEventListener('mousedown', function (ev) { if (ev.target === back && !busy) close(false); });
      document.addEventListener('keydown', onKey, true);
      document.body.appendChild(back);
      render();

      if (typeof opts.consequences === 'function') {
        Promise.resolve().then(function () { return opts.consequences(); }).then(function (list) {
          consequences = Array.isArray(list) ? list : [];
        }).catch(function () {
          consequences = Array.isArray(opts.fallbackConsequences) ? opts.fallbackConsequences : [];
          consequenceErr = true;
        }).then(function () {
          if (back.parentNode && !busy) { if (step === 2 || steps === 1) render(); }
        });
      }
    });
  }

  /**
   * guardForm(form, optsOrBuilder) — protect a classic HTML <form> post.
   * On submit the dialog runs first; when confirmed, the typed phrase is
   * added as a hidden "confirm_phrase" field (and "dc_reason" when a reason
   * was asked) and the form is submitted for real. optsOrBuilder may be a
   * function(form) returning the options, or null when no confirmation is
   * needed for this particular submit (e.g. nothing risky changed).
   */
  function guardForm(form, build) {
    if (!form || form.__dcGuard) return;
    form.__dcGuard = true;
    form.addEventListener('submit', function (ev) {
      if (form.__dcOk) { form.__dcOk = false; return; }
      var opts = typeof build === 'function' ? build(form) : build;
      if (!opts) return;
      ev.preventDefault();
      ev.stopImmediatePropagation();
      ask(opts).then(function (r) {
        if (!r) return;
        function hidden(name, val) {
          var h = form.querySelector('input[type=hidden][name="' + name + '"]');
          if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = name; form.appendChild(h); }
          h.value = val;
        }
        if (r.phrase) hidden('confirm_phrase', r.phrase);
        if (r.reason) hidden('dc_reason', r.reason);
        form.__dcOk = true;
        if (typeof form.requestSubmit === 'function') form.requestSubmit(); else { form.__dcOk = false; form.submit(); }
      });
    }, true);
  }

  global.DangerConfirm = {
    __v: '20261022dc',
    ask: ask,
    guardForm: guardForm,
    normalize: normalize,
    isOpen: function () { return open; }
  };
})(window);
