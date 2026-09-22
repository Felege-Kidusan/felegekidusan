/* ============================================================
   WBWS Account Settings — shared runtime ("My Account").
   ONE runtime per page, zero dependencies on the host page's
   JavaScript. Hardened transport: EVERY call is same-origin and
   carries the X-CSRF-TOKEN header — the contract guard whose
   absence silently broke the legacy settings module (see
   docs/audits/ACCOUNT_SELF_SERVICE_RESEARCH.md, finding F2).
   Server policy (PasswordPolicy.php) is authoritative; the
   client checklist mirrors it only for fast feedback.
   ============================================================ */
(function () {
    'use strict';

    if (window.WBAccount) { return; } // one runtime per page

    var panel = null, scrim = null, csrf = '', apiBase = '';
    var originalEmail = '';
    var lastFocus = null;

    /* ── helpers ─────────────────────────────────────────────────── */

    function qs(sel) { return panel ? panel.querySelector(sel) : null; }

    function toast(msg, ok) {
        var t = qs('[data-wba-toast]');
        if (!t) { return; }
        t.textContent = msg || '';
        t.classList.toggle('wba-err', ok === false);
        t.hidden = !msg;
        if (msg) {
            window.clearTimeout(toast._h);
            toast._h = window.setTimeout(function () { t.hidden = true; }, 4500);
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
     *   2. always the X-CSRF-TOKEN header (config.php:544 reads
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

    /* ── open / close ────────────────────────────────────────────── */

    function openPanel() {
        if (!panel) { return; }
        lastFocus = document.activeElement;
        panel.hidden = false;
        if (scrim) { scrim.hidden = false; }
        var trigger = document.querySelector('[data-wba-open]');
        if (trigger) { trigger.setAttribute('aria-expanded', 'true'); }
        switchTab('profile');
        loadProfile();
        toast('');
        window.setTimeout(function () {
            var first = qs('[data-wba-close]');
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

    /* ── tabs ────────────────────────────────────────────────────── */

    function switchTab(name) {
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

    /* ── profile ─────────────────────────────────────────────────── */

    function put(sel, value) {
        var el = qs(sel);
        if (el) { el.textContent = value || '—'; }
    }

    function loadProfile() {
        api('profile_get').then(function (d) {
            if (!d || d.status !== 'success' || !d.user) {
                toast((d && d.message) || 'Could not load your profile.', false);
                return;
            }
            var u = d.user;
            put('[data-wba-username]', u.username);
            put('[data-wba-role]', String(u.role || '').replace(/_/g, ' ').toUpperCase());
            put('[data-wba-email]', u.email);
            put('[data-wba-phone]', u.phone);
            put('[data-wba-created]', formatWhen(u.created_at));
            put('[data-wba-lastlogin]', u.last_login ? formatWhen(u.last_login) : 'Never');
            put('[data-wba-logins]', String(d.login_count != null ? d.login_count : '—'));

            var nameIn = qs('[data-wba-input="full_name"]');
            var emailIn = qs('[data-wba-input="email"]');
            var phoneIn = qs('[data-wba-input="phone"]');
            if (nameIn) { nameIn.value = u.full_name || ''; }
            if (emailIn) { emailIn.value = u.email || ''; }
            if (phoneIn) { phoneIn.value = u.phone || ''; }
            originalEmail = String(u.email || '');

            var avatar = qs('[data-wba-avatar]');
            if (avatar && u.full_name) { avatar.textContent = u.full_name.charAt(0).toUpperCase(); }
            var nameEl = qs('[data-wba-name]');
            if (nameEl && u.full_name) { nameEl.textContent = u.full_name; }
        }).catch(function (e) { toast(e.message, false); });
    }

    function maybeStepUp() {
        var emailIn = qs('[data-wba-input="email"]');
        var stepUp = qs('[data-wba-stepup]');
        var hint = qs('[data-wba-email-hint]');
        var changed = emailIn && String(emailIn.value).trim() !== originalEmail;
        if (stepUp) { stepUp.hidden = !changed; }
        if (hint) { hint.hidden = !changed; }
        return !!changed;
    }

    function saveProfile(form) {
        var val = function (name) {
            var el = qs('[data-wba-input="' + name + '"]');
            return el ? String(el.value).trim() : '';
        };
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
            if (!cur) { toast('Enter your current password to change your email.', false); return; }
            body.current_password = cur;
        }

        setBusy(form, true);
        api('profile_update', { body: body }).then(function (d) {
            setBusy(form, false);
            toast(d.message || (d.status === 'success' ? 'Saved.' : 'Could not save.'), d.status === 'success');
            if (d.status === 'success') {
                originalEmail = email;
                var curIn = qs('[data-wba-input="current_password"]');
                if (curIn) { curIn.value = ''; }
                maybeStepUp();
                put('[data-wba-email]', email);
                put('[data-wba-phone]', phone);
                put('[data-wba-name]', fullName);
                var avatar = qs('[data-wba-avatar]');
                if (avatar) { avatar.textContent = fullName.charAt(0).toUpperCase(); }
                loadActivity();
            }
        }).catch(function (e) { setBusy(form, false); toast(e.message, false); });
    }

    /* ── password ────────────────────────────────────────────────── */

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
        var reqs = panel.querySelectorAll('[data-wba-reqs] [data-req]');
        for (var i = 0; i < reqs.length; i++) {
            reqs[i].classList.toggle('ok', !!tests[reqs[i].getAttribute('data-req')]);
        }
        // heuristic strength: length class + variety − penalties
        var score = 0;
        if (pw.length >= 12) { score += 1; }
        if (pw.length >= 16) { score += 1; }
        if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) { score += 1; }
        if (/\d/.test(pw)) { score += 1; }
        if (/[^A-Za-z0-9]/.test(pw)) { score += 1; }
        if (/(.)\1{2,}/.test(pw)) { score -= 1; }
        if (/(?:0123|1234|2345|3456|4567|5678|6789|abcd|bcde|cdef|qwer|asdf)/i.test(pw)) { score -= 1; }
        score = Math.max(0, Math.min(4, score));
        return { tests: tests, score: score };
    }

    function paintMeter(pw, current) {
        var meter = qs('[data-wba-meter]');
        if (!meter) { return; }
        var r = evaluatePassword(pw, current);
        var colors = ['#dc2626', '#ea580c', '#ca8a04', '#16a34a', '#047857'];
        meter.style.width = (pw ? (25 + r.score * 18.75) : 0) + '%';
        meter.style.backgroundColor = pw ? colors[r.score] : 'transparent';
    }

    function changePassword(form) {
        var val = function (name) {
            var el = qs('[data-wba-input="' + name + '"]');
            return el ? String(el.value) : '';
        };
        var current = val('pwd_current');
        var next = val('pwd_new');
        var confirmPw = val('pwd_confirm');

        if (!current || !next || !confirmPw) { toast('All password fields are required.', false); return; }
        if (next !== confirmPw) { toast('New passwords do not match.', false); return; }
        var checks = evaluatePassword(next, current);
        if (!checks.tests.len) { toast('Password must be at least 12 characters.', false); return; }
        if (!checks.tests.bytes72) { toast('Password is too long (maximum 72 bytes).', false); return; }
        if (!checks.tests.common) { toast('Choose a less common password.', false); return; }
        if (!checks.tests.different) { toast('New password must be different from current.', false); return; }

        setBusy(form, true);
        api('password_change', {
            body: { current_password: current, new_password: next, confirm_password: confirmPw }
        }).then(function (d) {
            setBusy(form, false);
            var ok = d && d.status === 'success';
            toast(d.message || (ok ? 'Password changed.' : 'Could not change password.'), ok);
            if (ok) {
                ['pwd_current', 'pwd_new', 'pwd_confirm'].forEach(function (n) {
                    var el = qs('[data-wba-input="' + n + '"]');
                    if (el) { el.value = ''; }
                });
                paintMeter('', '');
                loadActivity();
            }
        }).catch(function (e) { setBusy(form, false); toast(e.message, false); });
    }

    /* ── activity + devices ──────────────────────────────────────── */

    function loadActivity() {
        var list = qs('[data-wba-timeline]');
        if (!list) { return; }
        list.textContent = '';
        var loading = document.createElement('li');
        loading.className = 'wba-tl-loading';
        loading.textContent = 'Loading…';
        list.appendChild(loading);

        api('account_activity').then(function (d) {
            list.textContent = '';
            if (!d || d.status !== 'success') {
                var err = document.createElement('li');
                err.className = 'wba-tl-empty';
                err.textContent = (d && d.message) || 'Could not load activity.';
                list.appendChild(err);
                return;
            }
            var events = d.events || [];
            if (!events.length) {
                var empty = document.createElement('li');
                empty.className = 'wba-tl-empty';
                empty.textContent = 'No recorded activity yet.';
                list.appendChild(empty);
                return;
            }
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
        }).catch(function (e) {
            list.textContent = '';
            var err = document.createElement('li');
            err.className = 'wba-tl-empty';
            err.textContent = e.message;
            list.appendChild(err);
        });
    }

    function signOutDevices() {
        if (!window.confirm('Sign this account out of every mobile device? Each device must sign in again.')) { return; }
        var btn = qs('[data-wba-signout]');
        if (btn) { btn.disabled = true; }
        api('signout_devices', { body: {} }).then(function (d) {
            if (btn) { btn.disabled = false; }
            toast(d.message || (d.status === 'success' ? 'Mobile devices signed out.' : 'Could not sign out devices.'), d.status === 'success');
            loadActivity();
        }).catch(function (e) {
            if (btn) { btn.disabled = false; }
            toast(e.message, false);
        });
    }

    /* ── wiring ──────────────────────────────────────────────────── */

    function bind() {
        // triggers (component may be rendered at several placements)
        var triggers = document.querySelectorAll('[data-wba-open]');
        for (var i = 0; i < triggers.length; i++) {
            triggers[i].addEventListener('click', openPanel);
        }
        if (!panel) { return; }

        var closers = panel.querySelectorAll('[data-wba-close]');
        for (var c = 0; c < closers.length; c++) { closers[c].addEventListener('click', closePanel); }
        if (scrim) { scrim.addEventListener('click', closePanel); }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && panel && !panel.hidden) { closePanel(); }
        });

        var tabs = panel.querySelectorAll('[data-wba-tab]');
        for (var t = 0; t < tabs.length; t++) {
            tabs[t].addEventListener('click', function (ev) {
                switchTab(ev.currentTarget.getAttribute('data-wba-tab'));
            });
        }

        var profileForm = qs('[data-wba-form="profile"]');
        if (profileForm) {
            profileForm.addEventListener('submit', function (e) { e.preventDefault(); saveProfile(profileForm); });
            var emailIn = qs('[data-wba-input="email"]');
            if (emailIn) {
                emailIn.addEventListener('input', maybeStepUp);
                emailIn.addEventListener('change', maybeStepUp);
            }
        }

        var pwdForm = qs('[data-wba-form="password"]');
        if (pwdForm) {
            pwdForm.addEventListener('submit', function (e) { e.preventDefault(); changePassword(pwdForm); });
            var newIn = qs('[data-wba-input="pwd_new"]');
            if (newIn) {
                newIn.addEventListener('input', function () {
                    paintMeter(String(newIn.value), String(qs('[data-wba-input="pwd_current"]').value));
                });
            }
        }

        // show/hide toggles
        var eyes = panel.querySelectorAll('[data-wba-eye]');
        for (var ey = 0; ey < eyes.length; ey++) {
            eyes[ey].addEventListener('click', function (ev) {
                var btn = ev.currentTarget;
                var input = btn.parentElement ? btn.parentElement.querySelector('input') : null;
                if (!input) { return; }
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                var icon = btn.querySelector('i');
                if (icon) {
                    icon.className = show ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
                }
                btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            });
        }

        var signoutBtn = qs('[data-wba-signout]');
        if (signoutBtn) { signoutBtn.addEventListener('click', signOutDevices); }
    }

    function init() {
        panel = document.querySelector('[data-wba-panel]');
        scrim = document.querySelector('[data-wba-scrim]');
        if (!panel) { return; }
        // Re-parent to <body>: the component may be rendered inside a host
        // container that is display:none on some breakpoints (desktop/mobile
        // headers). A position:fixed modal cannot escape display:none, so —
        // same hardening philosophy as the notification panel's fixed
        // positioning — we guarantee it lives directly under <body>.
        try {
            if (panel.parentElement !== document.body) { document.body.appendChild(panel); }
            if (scrim && scrim.parentElement !== document.body) { document.body.appendChild(scrim); }
        } catch (e) { /* keep host-native placement */ }
        csrf = panel.getAttribute('data-csrf') || '';
        apiBase = panel.getAttribute('data-api') || '/admin/api_settings.php';
        if (!csrf) {
            // Contract failure made loud, not silent: a missing token means
            // every write would 403 — exactly the legacy bug class.
            console.error('[WBAccount] panel rendered without a CSRF token; writes are disabled.');
        }
        bind();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.WBAccount = { open: openPanel, close: closePanel, toast: toast };
})();
