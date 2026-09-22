/* ============================================================
   WBWS Account Settings — shared runtime ("My Account"). v2
   ONE runtime per page, zero dependencies on the host page's
   JavaScript. Binds ANY number of roots: the modal panel and/or
   inline sidebar sections (v1.2 sidebar parity). Data loads
   lazily the first time any root becomes visible — however the
   host router reveals it (data-sec, data-section, showSection,
   ?section= URL restore, or self-route) — via MutationObserver,
   so no dashboard needs custom JS.
   Hardened transport: EVERY call is same-origin and carries the
   X-CSRF-TOKEN header — the contract guard whose absence silently
   broke the legacy settings module (see
   docs/audits/ACCOUNT_SELF_SERVICE_RESEARCH.md, finding F2).
   Server policy (PasswordPolicy.php) is authoritative; the
   client checklist mirrors it only for fast feedback.
   ============================================================ */
(function () {
    'use strict';

    if (window.WBAccount) { return; } // one runtime per page

    var roots = [];            // [panel?, section?, ...]
    var panel = null, scrim = null;
    var csrf = '', apiBase = '';
    var originalEmail = '';
    var lastFocus = null;
    var dataLoaded = false;

    /* ── helpers ─────────────────────────────────────────────────── */

    function qa(sel) {
        var out = [];
        for (var i = 0; i < roots.length; i++) {
            var found = roots[i].querySelectorAll(sel);
            for (var j = 0; j < found.length; j++) { out.push(found[j]); }
        }
        return out;
    }

    function toast(msg, ok) {
        var toasts = qa('[data-wba-toast]');
        for (var i = 0; i < toasts.length; i++) {
            var t = toasts[i];
            t.textContent = msg || '';
            t.classList.toggle('wba-err', ok === false);
            t.hidden = !msg;
        }
        if (msg && toast._h) { window.clearTimeout(toast._h); }
        if (msg) {
            toast._h = window.setTimeout(function () {
                for (var k = 0; k < toasts.length; k++) { toasts[k].hidden = true; }
            }, 4500);
        }
    }

    function formatWhen(value) {
        if (!value) { return '—'; }
        try {
            if (window.WBWSCalendar && typeof window.WBWSCalendar.formatDate === 'function') {
                return window.WBWSCalendar.formatDate(value, 'medium');
            }
        } catch (e) { /* fall through */ }
        var d = new Date(String(value).replace(' ', 'T'));
        return isNaN(d.getTime()) ? String(value) : d.toLocaleString();
    }

    /**
     * The one fetch wrapper. Contract rules:
     *   1. always credentials:'same-origin' (session cookie auth);
     *   2. always the X-CSRF-TOKEN header (config.php reads
     *      $_POST or this header — JSON bodies populate neither);
     *   3. non-JSON responses are contract errors, surfaced loudly.
     */
    function api(action, opts) {
        opts = opts || {};
        var url = apiBase + '?action=' + encodeURIComponent(action);
        return fetch(url, {
            method: opts.body ? 'POST' : 'GET',
            credentials: 'same-origin',
            headers: opts.body
                ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }
                : undefined,
            body: opts.body ? JSON.stringify(opts.body) : undefined
        }).then(function (r) {
            return r.text().then(function (text) {
                var data;
                try { data = text ? JSON.parse(text) : {}; } catch (e) { data = null; }
                if (data === null) {
                    throw new Error('Server returned a non-JSON response (HTTP ' + r.status + '). Refresh the page and try again.');
                }
                if (!r.ok && data && data.status !== 'error') {
                    data = { status: 'error', message: data.message || ('HTTP ' + r.status) };
                }
                return data;
            });
        });
    }

    function setBusy(form, busy) {
        var btn = form ? form.querySelector('button[type="submit"]') : null;
        if (btn) { btn.disabled = !!busy; }
    }

    /* ── lazy data loading (router-agnostic) ─────────────────────── */

    function rootVisible(el) {
        return !!(el.offsetParent !== null || (el.getClientRects && el.getClientRects().length));
    }

    function ensureLoaded() {
        if (dataLoaded) { return; }
        dataLoaded = true;
        loadProfile();
        loadActivity();
    }

    function observeSection(sec) {
        // v1.2.1 fix: host routers toggle visibility on the section's
        // WRAPPER (e.g. #sec-account gets .act, #section-account loses
        // [hidden]) — the section's own attributes never change, so an
        // observer on `sec` alone never fires. Observe the WHOLE ancestor
        // chain instead: any class/hidden/style change above the section
        // triggers a cheap visibility re-check. ~8 observers max per page.
        if (!window.MutationObserver) { return; }
        var moCallback = function () {
            if (rootVisible(sec)) { ensureLoaded(); }
        };
        var ancestor = sec.parentElement;
        var hops = 0;
        while (ancestor && hops < 10) {
            try {
                var mo = new MutationObserver(moCallback);
                mo.observe(ancestor, { attributes: true, attributeFilter: ['class', 'hidden', 'style'] });
            } catch (e) { /* never block the host page */ }
            ancestor = ancestor.parentElement;
            hops += 1;
        }
    }

    /* ── modal open / close ──────────────────────────────────────── */

    function openPanel() {
        if (!panel) { return; }
        lastFocus = document.activeElement;
        panel.hidden = false;
        if (scrim) { scrim.hidden = false; }
        var trigger = document.querySelector('[data-wba-open]');
        if (trigger) { trigger.setAttribute('aria-expanded', 'true'); }
        switchTab('profile');
        ensureLoaded();
        toast('');
        window.setTimeout(function () {
            var first = panel.querySelector('[data-wba-close]');
            if (first) { first.focus(); }
        }, 0);
    }

    function closePanel() {
        if (!panel) { return; }
        panel.hidden = true;
        if (scrim) { scrim.hidden = true; }
        var trigger = document.querySelector('[data-wba-open]');
        if (trigger) { trigger.setAttribute('aria-expanded', 'false'); }
        if (lastFocus && typeof lastFocus.focus === 'function') { lastFocus.focus(); }
    }

    /* ── modal tabs (panel only — sections have no tabs) ─────────── */

    function switchTab(name) {
        if (!panel) { return; }
        var tabs = panel.querySelectorAll('[data-wba-tab]');
        for (var i = 0; i < tabs.length; i++) {
            var on = tabs[i].getAttribute('data-wba-tab') === name;
            tabs[i].classList.toggle('is-active', on);
            tabs[i].setAttribute('aria-selected', on ? 'true' : 'false');
        }
        var panes = panel.querySelectorAll('[data-wba-pane]');
        for (var j = 0; j < panes.length; j++) {
            var active = panes[j].getAttribute('data-wba-pane') === name;
            panes[j].classList.toggle('is-active', active);
            panes[j].hidden = !active;
        }
        if (name === 'activity') { loadActivity(); }
    }

    /* ── profile (fills every root) ──────────────────────────────── */

    function putAll(sel, value) {
        var els = qa(sel);
        for (var i = 0; i < els.length; i++) { els[i].textContent = value || '—'; }
    }

    function loadProfile() {
        api('profile_get').then(function (d) {
            if (!d || d.status !== 'success' || !d.user) {
                toast((d && d.message) || 'Could not load your profile.', false);
                return;
            }
            var u = d.user;
            putAll('[data-wba-username]', u.username);
            putAll('[data-wba-role]', String(u.role || '').replace(/_/g, ' ').toUpperCase());
            putAll('[data-wba-email]', u.email);
            putAll('[data-wba-phone]', u.phone);
            putAll('[data-wba-created]', formatWhen(u.created_at));
            putAll('[data-wba-lastlogin]', u.last_login ? formatWhen(u.last_login) : 'Never');
            putAll('[data-wba-logins]', String(d.login_count != null ? d.login_count : '—'));

            var inputs = qa('[data-wba-input="full_name"]');
            var emails = qa('[data-wba-input="email"]');
            var phones = qa('[data-wba-input="phone"]');
            for (var i = 0; i < inputs.length; i++) { inputs[i].value = u.full_name || ''; }
            for (var e2 = 0; e2 < emails.length; e2++) { emails[e2].value = u.email || ''; }
            for (var p2 = 0; p2 < phones.length; p2++) { phones[p2].value = u.phone || ''; }
            originalEmail = String(u.email || '');

            var avatars = qa('[data-wba-avatar]');
            for (var a = 0; a < avatars.length; a++) {
                if (u.full_name) { avatars[a].textContent = u.full_name.charAt(0).toUpperCase(); }
            }
            var names = qa('[data-wba-name]');
            for (var n = 0; n < names.length; n++) { if (u.full_name) { names[n].textContent = u.full_name; } }
        }).catch(function (e) { toast(e.message, false); });
    }

    function maybeStepUp(root) {
        var emailIn = root.querySelector('[data-wba-input="email"]');
        var stepUp = root.querySelector('[data-wba-stepup]');
        var hint = root.querySelector('[data-wba-email-hint]');
        var changed = emailIn && String(emailIn.value).trim() !== originalEmail;
        if (stepUp) { stepUp.hidden = !changed; }
        if (hint) { hint.hidden = !changed; }
        return !!changed;
    }

    function saveProfile(root, form) {
        function val(name) {
            var el = form.querySelector('[data-wba-input="' + name + '"]');
            return el ? String(el.value).trim() : '';
        }
        var fullName = val('full_name');
        var email = val('email');
        var phone = val('phone');

        if (!fullName) { toast('Full name is required.', false); return; }
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { toast('Enter a valid email address.', false); return; }

        var body = { full_name: fullName, email: email };
        if (phone) { body.phone = phone; }

        var emailChanged = email !== originalEmail;
        if (emailChanged) {
            var cur = val('current_password');
            if (!cur) {
                maybeStepUp(root);
                var stepEl = root.querySelector('[data-wba-input="current_password"]');
                if (stepEl) { stepEl.focus(); }
                toast('Enter your current password to change your email.', false);
                return;
            }
            body.current_password = cur;
        }

        setBusy(form, true);
        api('profile_update', { body: body }).then(function (d) {
            setBusy(form, false);
            toast(d.message || (d.status === 'success' ? 'Saved.' : 'Could not save.'), d.status === 'success');
            if (d.status === 'success') {
                // refresh every root from the server (single source of truth)
                loadProfile();
                loadActivity();
            }
        }).catch(function (e) { setBusy(form, false); toast(e.message, false); });
    }

    /* ── password (per root) ─────────────────────────────────────── */

    var COMMON = ['123456789012', 'adminadmin12', 'changeme1234', 'letmeinletmein',
        'password1234', 'qwertyuiop12', '123456789abc', 'aaaaaaaaaaaa',
        'abcdefghijkl', 'facebook1234', 'ethiopia1234', 'password12'];

    function byteLength(s) {
        try { return new TextEncoder().encode(s).length; } catch (e) { return s.length; }
    }

    function evaluatePassword(pw, current) {
        var tests = {
            len: pw.length >= 12,
            bytes72: byteLength(pw) <= 72,
            common: pw.length > 0 && COMMON.indexOf(pw.toLowerCase()) === -1,
            different: pw.length > 0 && pw !== current
        };
        return tests;
    }

    function paintMeter(root, pw, current) {
        var meter = root.querySelector('[data-wba-meter]');
        if (meter) {
            var score = 0;
            if (pw.length >= 12) { score += 1; }
            if (pw.length >= 16) { score += 1; }
            if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) { score += 1; }
            if (/\d/.test(pw)) { score += 1; }
            if (/[^A-Za-z0-9]/.test(pw)) { score += 1; }
            if (/(.)\1{2,}/.test(pw)) { score -= 1; }
            if (/(?:0123|1234|2345|3456|4567|5678|6789|abcd|bcde|cdef|qwer|asdf)/i.test(pw)) { score -= 1; }
            score = Math.max(0, Math.min(4, score));
            var colors = ['#dc2626', '#ea580c', '#ca8a04', '#16a34a', '#047857'];
            meter.style.width = (pw ? (25 + score * 18.75) : 0) + '%';
            meter.style.backgroundColor = pw ? colors[score] : 'transparent';
        }
        var reqs = root.querySelectorAll('[data-wba-reqs] [data-req]');
        for (var i = 0; i < reqs.length; i++) {
            var tests = evaluatePassword(pw, current);
            reqs[i].classList.toggle('ok', !!tests[reqs[i].getAttribute('data-req')]);
        }
    }

    function changePassword(root, form) {
        function val(name) {
            var el = form.querySelector('[data-wba-input="' + name + '"]');
            return el ? String(el.value) : '';
        }
        var current = val('pwd_current');
        var next = val('pwd_new');
        var confirmPw = val('pwd_confirm');

        if (!current || !next || !confirmPw) { toast('All password fields are required.', false); return; }
        if (next !== confirmPw) { toast('New passwords do not match.', false); return; }
        var checks = evaluatePassword(next, current);
        if (!checks.len) { toast('Password must be at least 12 characters.', false); return; }
        if (!checks.bytes72) { toast('Password is too long (maximum 72 bytes).', false); return; }
        if (!checks.common) { toast('Choose a less common password.', false); return; }
        if (!checks.different) { toast('New password must be different from current.', false); return; }

        setBusy(form, true);
        api('password_change', {
            body: { current_password: current, new_password: next, confirm_password: confirmPw }
        }).then(function (d) {
            setBusy(form, false);
            var ok = d && d.status === 'success';
            toast(d.message || (ok ? 'Password changed.' : 'Could not change password.'), ok);
            if (ok) {
                ['pwd_current', 'pwd_new', 'pwd_confirm'].forEach(function (n) {
                    var el = form.querySelector('[data-wba-input="' + n + '"]');
                    if (el) { el.value = ''; }
                });
                paintMeter(root, '', '');
                loadActivity();
            }
        }).catch(function (e) { setBusy(form, false); toast(e.message, false); });
    }

    /* ── activity + devices (every root) ─────────────────────────── */

    function loadActivity() {
        var lists = qa('[data-wba-timeline]');
        if (!lists.length) { return; }
        lists.forEach(function (list) {
            list.textContent = '';
            var loading = document.createElement('li');
            loading.className = 'wba-tl-loading';
            loading.textContent = 'Loading…';
            list.appendChild(loading);
        });

        api('account_activity').then(function (d) {
            lists.forEach(function (list) { list.textContent = ''; });
            if (!d || d.status !== 'success') {
                lists.forEach(function (list) {
                    var err = document.createElement('li');
                    err.className = 'wba-tl-empty';
                    err.textContent = (d && d.message) || 'Could not load activity.';
                    list.appendChild(err);
                });
                return;
            }
            var events = d.events || [];
            if (!events.length) {
                lists.forEach(function (list) {
                    var empty = document.createElement('li');
                    empty.className = 'wba-tl-empty';
                    empty.textContent = 'No recorded activity yet.';
                    list.appendChild(empty);
                });
                return;
            }
            lists.forEach(function (list) {
                list.textContent = '';
                events.forEach(function (ev) {
                    var li = document.createElement('li');
                    var what = document.createElement('span');
                    what.className = 'wba-tl-what';
                    var b = document.createElement('b');
                    b.textContent = ev.action || 'Event';
                    what.appendChild(b);
                    if (ev.details) {
                        var small = document.createElement('small');
                        small.textContent = ' — ' + ev.details;
                        what.appendChild(small);
                    }
                    var when = document.createElement('span');
                    when.className = 'wba-tl-when';
                    when.textContent = formatWhen(ev.created_at);
                    li.appendChild(what);
                    li.appendChild(when);
                    list.appendChild(li);
                });
            });
        }).catch(function (e) {
            lists.forEach(function (list) {
                list.textContent = '';
                var err = document.createElement('li');
                err.className = 'wba-tl-empty';
                err.textContent = e.message;
                list.appendChild(err);
            });
        });
    }

    function signOutDevices() {
        if (!window.confirm('Sign this account out of every mobile device? Each device must sign in again.')) { return; }
        var btns = qa('[data-wba-signout]');
        btns.forEach(function (b) { b.disabled = true; });
        api('signout_devices', { body: {} }).then(function (d) {
            btns.forEach(function (b) { b.disabled = false; });
            toast(d.message || (d.status === 'success' ? 'Mobile devices signed out.' : 'Could not sign out devices.'), d.status === 'success');
            loadActivity();
        }).catch(function (e) {
            btns.forEach(function (b) { b.disabled = false; });
            toast(e.message, false);
        });
    }

    /* ── per-root wiring ─────────────────────────────────────────── */

    function bindRoot(root) {
        var profileForm = root.querySelector('[data-wba-form="profile"]');
        if (profileForm) {
            profileForm.addEventListener('submit', function (e) {
                e.preventDefault();
                saveProfile(root, profileForm);
            });
            var emailIn = profileForm.querySelector('[data-wba-input="email"]');
            if (emailIn) {
                var handler = function () { maybeStepUp(root); };
                emailIn.addEventListener('input', handler);
                emailIn.addEventListener('change', handler);
            }
        }

        var pwdForm = root.querySelector('[data-wba-form="password"]');
        if (pwdForm) {
            pwdForm.addEventListener('submit', function (e) {
                e.preventDefault();
                changePassword(root, pwdForm);
            });
            var newIn = pwdForm.querySelector('[data-wba-input="pwd_new"]');
            var curIn = pwdForm.querySelector('[data-wba-input="pwd_current"]');
            if (newIn) {
                newIn.addEventListener('input', function () {
                    paintMeter(root, String(newIn.value), curIn ? String(curIn.value) : '');
                });
            }
        }

        var eyes = root.querySelectorAll('[data-wba-eye]');
        for (var i = 0; i < eyes.length; i++) {
            eyes[i].addEventListener('click', function (ev) {
                var btn = ev.currentTarget;
                var input = btn.parentElement ? btn.parentElement.querySelector('input') : null;
                if (!input) { return; }
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                var icon = btn.querySelector('i');
                if (icon) { icon.className = show ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye'; }
                btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            });
        }

        var signoutBtn = root.querySelector('[data-wba-signout]');
        if (signoutBtn) { signoutBtn.addEventListener('click', signOutDevices); }
    }

    /* ── init ────────────────────────────────────────────────────── */

    function adoptPanel() {
        panel = document.querySelector('[data-wba-panel]');
        scrim = document.querySelector('[data-wba-scrim]');
        if (!panel) { return; }
        // Re-parent to <body>: the modal may be rendered inside a host
        // container that is display:none on some breakpoints. A
        // position:fixed modal cannot escape display:none.
        try {
            if (panel.parentElement !== document.body) { document.body.appendChild(panel); }
            if (scrim && scrim.parentElement !== document.body) { document.body.appendChild(scrim); }
        } catch (e) { /* keep host-native placement */ }
        roots.push(panel);
    }

    function init() {
        adoptPanel();

        var sections = document.querySelectorAll('[data-wba-section]');
        for (var i = 0; i < sections.length; i++) {
            var sec = sections[i];
            roots.push(sec);
            observeSection(sec);
            // already visible at load (e.g. ?section=account URL restore
            // or a visible-by-default page)
            if (rootVisible(sec)) { ensureLoaded(); }
        }

        if (!roots.length) { return; }

        // CSRF/endpoint: prefer the panel's, else the first section's.
        var csrfHolder = panel || roots[roots.length - 1];
        csrf = csrfHolder.getAttribute('data-csrf') || '';
        apiBase = csrfHolder.getAttribute('data-api') || '/admin/api_settings.php';
        if (!csrf) {
            // Contract failure made loud, not silent: a missing token means
            // every write would 403 — exactly the legacy bug class.
            console.error('[WBAccount] no CSRF token found; writes are disabled.');
        }

        roots.forEach(bindRoot);

        // modal triggers
        var triggers = document.querySelectorAll('[data-wba-open]');
        for (var t = 0; t < triggers.length; t++) { triggers[t].addEventListener('click', openPanel); }
        if (panel) {
            var closers = panel.querySelectorAll('[data-wba-close]');
            for (var c = 0; c < closers.length; c++) { closers[c].addEventListener('click', closePanel); }
            if (scrim) { scrim.addEventListener('click', closePanel); }
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && panel && !panel.hidden) { closePanel(); }
            });
        }

        // sidebar nav items pointing at the section: coexists with the
        // host router's own handler; we only ensure data + scroll.
        document.addEventListener('click', function (ev) {
            var nav = ev.target.closest ? ev.target.closest('[data-wba-nav]') : null;
            if (!nav) { return; }
            window.setTimeout(function () {
                for (var s = 0; s < sections.length; s++) {
                    var sec = sections[s];
                    if (sec.hasAttribute('data-wba-selfroute')) {
                        // card pages with no host router: toggle ourselves
                        sec.hidden = !sec.hidden;
                    }
                    if (rootVisible(sec)) {
                        ensureLoaded();
                        try { sec.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) { /* older */ }
                    }
                }
            }, 0);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.WBAccount = { open: openPanel, close: closePanel, toast: toast, ensureLoaded: ensureLoaded };
})();
