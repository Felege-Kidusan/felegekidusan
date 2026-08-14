/**
 * ============================================================
 * WBSS Core JS — Shared across ALL dashboards, ALL schools
 * ============================================================
 * 
 * Reads everything from window.APP (set by base.php).
 * No school-specific code here. No PHP echoes.
 * 
 * Provides:
 *   window.api.get(endpoint)       → fetch GET with CSRF
 *   window.api.post(endpoint, data) → fetch POST with CSRF
 *   window.toast(msg, type)         → show toast notification
 *   window.esc(text)                → escape HTML
 *   window.fmtDate(dateStr)         → format date
 *   window.fmtNum(num)              → format number with commas
 *   window.modal(id, show)          → toggle modal
 *   window.switchSection(name)      → switch nav section
 * ============================================================
 */

(function() {
    'use strict';

    var APP = window.APP || {};

    // ── Determine API base path ─────────────────────────────
    // New path: /backend/api/   Legacy path: /admin/api_
    // During migration both work; prefer new path if available
    var apiBase = APP.api || '/backend/api/';
    var apiLegacy = APP.apiLegacy || '/admin/';

    // ══════════════════════════════════════════════════════════
    // API WRAPPER — Auto-adds CSRF, credentials, error handling
    // ══════════════════════════════════════════════════════════

    window.api = {
        /**
         * GET request to backend API
         * @param {string} endpoint - e.g. 'attendance.php?action=get_class_attendance&class_id=5'
         * @returns {Promise<Object>} parsed JSON response
         */
        get: function(endpoint) {
            var url = _resolveUrl(endpoint);
            return fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': APP.csrf || '',
                    'Accept': 'application/json'
                }
            })
            .then(_handleResponse)
            .catch(_handleError);
        },

        /**
         * POST request to backend API
         * @param {string} endpoint - e.g. 'finance.php'
         * @param {Object|FormData} data - key/value pairs or FormData
         * @returns {Promise<Object>} parsed JSON response
         */
        post: function(endpoint, data) {
            var url = _resolveUrl(endpoint);
            var fd;

            if (data instanceof FormData) {
                fd = data;
            } else {
                fd = new FormData();
                if (data && typeof data === 'object') {
                    Object.keys(data).forEach(function(k) {
                        if (data[k] !== undefined && data[k] !== null) {
                            fd.append(k, data[k]);
                        }
                    });
                }
            }

            // Always append CSRF
            if (!fd.has('csrf_token')) {
                fd.append('csrf_token', APP.csrf || '');
            }

            return fetch(url, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(_handleResponse)
            .catch(_handleError);
        },

        /**
         * POST with JSON body (for API v1 endpoints)
         */
        postJson: function(endpoint, data) {
            var url = _resolveUrl(endpoint);
            return fetch(url, {
                method: 'POST',
                body: JSON.stringify(data || {}),
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': APP.csrf || '',
                    'Accept': 'application/json'
                }
            })
            .then(_handleResponse)
            .catch(_handleError);
        }
    };

    /**
     * Resolve endpoint to full URL
     * Handles: 'finance.php' → '/backend/api/finance.php'
     *          'api_finance.php' → '/admin/api_finance.php' (legacy)
     *          '/admin/...' → as-is (absolute)
     *          '/backend/...' → as-is (absolute)
     */
    function _resolveUrl(endpoint) {
        // Already absolute
        if (endpoint.charAt(0) === '/') return endpoint;
        // Legacy admin API pattern
        if (endpoint.indexOf('api_') === 0) return apiLegacy + endpoint;
        // Relative to /admin/ (backend files like backend/groups_api.php)
        if (endpoint.indexOf('backend/') === 0) return apiLegacy + endpoint;
        // New backend API
        return apiBase + endpoint;
    }

    function _handleResponse(r) {
        if (!r.ok) {
            return r.text().then(function(txt) {
                var msg = 'Server error (' + r.status + ')';
                try { var j = JSON.parse(txt); msg = j.message || msg; } catch(e) {}
                throw new Error(msg);
            });
        }
        return r.text().then(function(txt) {
            try { return JSON.parse(txt); }
            catch(e) {
                console.error('API response parse error:', txt.substring(0, 300));
                throw new Error('Invalid server response');
            }
        });
    }

    function _handleError(err) {
        console.error('API Error:', err);
        throw err;
    }

    // ══════════════════════════════════════════════════════════
    // TOAST NOTIFICATIONS — styled by theme CSS
    // ══════════════════════════════════════════════════════════

    window.toast = function(msg, type) {
        var el = document.createElement('div');
        el.className = 'school-toast school-toast-' + (type || 's');
        el.textContent = msg || '';

        // Stack toasts if multiple
        var existing = document.querySelectorAll('.school-toast');
        var offset = 24;
        for (var i = 0; i < existing.length; i++) {
            offset += existing[i].offsetHeight + 8;
        }
        el.style.bottom = offset + 'px';

        document.body.appendChild(el);
        // Trigger animation
        setTimeout(function() { el.classList.add('show'); }, 10);
        // Auto-remove
        setTimeout(function() {
            el.classList.remove('show');
            setTimeout(function() { el.remove(); }, 300);
        }, 3500);
    };

    // ══════════════════════════════════════════════════════════
    // UTILITY FUNCTIONS
    // ══════════════════════════════════════════════════════════

    /** Escape HTML to prevent XSS */
    window.esc = function(text) {
        if (text === null || text === undefined) return '';
        var d = document.createElement('div');
        d.textContent = String(text);
        return d.innerHTML;
    };

    /** Format date string to human-readable */
    window.fmtDate = function(dateStr) {
        if (!dateStr) return '—';
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        // Show Ethiopian to the user (Gregorian stays the stored value).
        if (typeof WBWSCalendar !== 'undefined') return WBWSCalendar.formatDate(dateStr, 'medium');
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    };

    /** Format number with commas */
    window.fmtNum = function(num) {
        if (num === null || num === undefined) return '0';
        return Number(num).toLocaleString();
    };

    /** Get member photo URL (handles relative paths from DB) */
    window.memberPhotoUrl = function(path) {
        if (!path) return '';
        // DB stores: uploads/members/photos/... or /admin/uploads/members/photos/...
        if (path.indexOf('/') === 0) return path;
        if (path.indexOf('uploads/') === 0) return '/uploads/' + path.replace(/^uploads\//, '');
        return '/uploads/members/photos/' + path;
    };

    /** Get theme color from CSS variable (for Chart.js etc) */
    window.themeColor = function(varName) {
        return getComputedStyle(document.documentElement)
            .getPropertyValue(varName).trim();
    };

    /** Common chart colors from theme */
    window.chartColors = function() {
        return {
            primary:  themeColor('--school-primary')    || '#047857',
            light:    themeColor('--school-primary-light') || '#16a34a',
            accent:   themeColor('--school-accent')     || '#06b6d4',
            accent2:  themeColor('--school-accent-2')   || '#8b5cf6',
            success:  themeColor('--school-success')    || '#22c55e',
            warning:  themeColor('--school-warning')    || '#f59e0b',
            danger:   themeColor('--school-danger')     || '#ef4444',
            info:     themeColor('--school-info')        || '#3b82f6',
            // Array for chart datasets
            palette: [
                themeColor('--school-accent')    || '#06b6d4',
                themeColor('--school-accent-2')  || '#8b5cf6',
                themeColor('--school-success')   || '#22c55e',
                themeColor('--school-warning')   || '#f59e0b',
                themeColor('--school-danger')    || '#ef4444',
                themeColor('--school-primary')   || '#047857',
                themeColor('--school-info')      || '#3b82f6',
                '#ec4899', '#f97316', '#84cc16'
            ]
        };
    };

    // ══════════════════════════════════════════════════════════
    // SECTION / TAB SWITCHING
    // ══════════════════════════════════════════════════════════

    /** Switch dashboard sections (sidebar nav) */
    window.switchSection = function(sectionName) {
        // Hide all sections
        document.querySelectorAll('.school-section, .section').forEach(function(el) {
            el.classList.remove('active');
        });
        // Show target
        var target = document.getElementById('section-' + sectionName);
        if (target) target.classList.add('active');

        // Update nav links
        document.querySelectorAll('.school-nav-link, .nav-link').forEach(function(btn) {
            btn.classList.remove('active');
            if (btn.getAttribute('data-section') === sectionName) {
                btn.classList.add('active');
            }
        });

        // Update mobile nav
        document.querySelectorAll('.school-bottom-nav-btn').forEach(function(btn) {
            btn.classList.remove('active');
            if (btn.getAttribute('data-section') === sectionName) {
                btn.classList.add('active');
            }
        });

        // Save last active section
        try { sessionStorage.setItem('lastSection', sectionName); } catch(e) {}
    };

    // ══════════════════════════════════════════════════════════
    // MODAL HELPER
    // ══════════════════════════════════════════════════════════

    /** Show/hide a modal by ID */
    window.modal = function(id, show) {
        var el = document.getElementById(id);
        if (!el) return;
        if (show === undefined) show = !el.classList.contains('show');
        if (show) {
            el.classList.add('show');
            el.style.display = 'flex';
        } else {
            el.classList.remove('show');
            el.style.display = 'none';
        }
    };

    // ══════════════════════════════════════════════════════════
    // SCHOOL IDENTITY — Apply to DOM elements
    // ══════════════════════════════════════════════════════════

    document.addEventListener('DOMContentLoaded', function() {
        var school = APP.school || {};

        // Set school name in elements with data-school attributes
        _setAll('[data-school-name]', school.name);
        _setAll('[data-school-short]', school.nameShort);
        _setAll('[data-school-name-am]', school.nameAm);
        _setAll('[data-school-short-am]', school.nameShortAm);
        _setAll('[data-school-tagline]', school.tagline);
        _setAll('[data-admin-title]', school.adminTitle);
        _setAll('[data-user-name]', (APP.user || {}).name);
        _setAll('[data-user-role]', _roleLabel((APP.user || {}).role || APP.role));
        _setAll('[data-user-initials]', (APP.user || {}).initials);
        _setAll('[data-today]', APP.today);

        // Set school logos
        document.querySelectorAll('[data-school-logo]').forEach(function(el) {
            if (el.tagName === 'IMG') {
                el.src = school.logo || '';
                el.onerror = function() { this.style.display = 'none'; };
            } else {
                el.style.backgroundImage = 'url(' + (school.logo || '') + ')';
            }
        });

        // Hide features not enabled
        if (school.features) {
            Object.keys(school.features).forEach(function(f) {
                if (!school.features[f]) {
                    document.querySelectorAll('[data-feature="' + f + '"]').forEach(function(el) {
                        el.style.display = 'none';
                    });
                }
            });
        }

        // Wire up nav links
        document.querySelectorAll('.school-nav-link[data-section], .nav-link[data-section]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                switchSection(this.getAttribute('data-section'));
            });
        });

        // Restore last section
        try {
            var last = sessionStorage.getItem('lastSection');
            if (last && document.getElementById('section-' + last)) {
                switchSection(last);
            }
        } catch(e) {}
    });

    // Helper: set textContent on all matching elements
    function _setAll(selector, value) {
        if (!value) return;
        document.querySelectorAll(selector).forEach(function(el) {
            el.textContent = value;
        });
    }

    // Helper: human-readable role label
    function _roleLabel(role) {
        var labels = {
            'super_admin':    'Super Admin',
            'school_admin':   'School Admin',
            'info_dept':      (APP.school && APP.school.depts && APP.school.depts.info) ? APP.school.depts.info.en : 'Information Dept',
            'edu_dept':       (APP.school && APP.school.depts && APP.school.depts.edu) ? APP.school.depts.edu.en : 'Education Dept',
            'finance_dept':   (APP.school && APP.school.depts && APP.school.depts.finance) ? APP.school.depts.finance.en : 'Finance Dept',
            'material_dept':  (APP.school && APP.school.depts && APP.school.depts.material) ? APP.school.depts.material.en : 'Material Dept',
            'teacher':        'Teacher',
            'attendance_taker': 'Attendance'
        };
        return labels[role] || (role || '').replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
    }

    // ══════════════════════════════════════════════════════════
    // THEME TOGGLE — Light / Dark mode
    // ══════════════════════════════════════════════════════════

    /** Toggle light/dark mode, save to localStorage */
    window.toggleTheme = function() {
        var isLight = document.body.classList.toggle('light-mode');
        try { localStorage.setItem('school_theme_mode', isLight ? 'light' : 'dark'); } catch(e) {}
        _updateThemeLabel(isLight);
    };

    /** Apply saved theme on page load (call ASAP to prevent flash) */
    function _applyStoredTheme() {
        try {
            var saved = localStorage.getItem('school_theme_mode');
            if (saved === 'light') {
                document.body.classList.add('light-mode');
                _updateThemeLabel(true);
            }
        } catch(e) {}
    }

    function _updateThemeLabel(isLight) {
        var label = document.getElementById('themeLabel');
        if (label) label.textContent = isLight ? 'Light Mode' : 'Dark Mode';
    }

    // Apply immediately (don't wait for DOMContentLoaded to avoid flash)
    _applyStoredTheme();

    // ══════════════════════════════════════════════════════════
    // LEGACY COMPATIBILITY — Bridge old patterns to new
    // ══════════════════════════════════════════════════════════

    // Old dashboards use CSRF_TOKEN or CSRF directly
    if (!window.CSRF_TOKEN) window.CSRF_TOKEN = APP.csrf || '';
    if (!window.CSRF) window.CSRF = APP.csrf || '';

    // Old dashboards call postAPI(fd) — provide a wrapper
    if (!window.postAPI) {
        window.postAPI = function(fd) {
            // Old pattern: postAPI sends to the current page's API
            fd.append('csrf_token', APP.csrf);
            return fetch(window._legacyApiUrl || '/backend/api/', {
                method: 'POST', body: fd, credentials: 'same-origin'
            }).then(function(r) { return r.json(); });
        };
    }

    // escapeHtml alias (used by notification_bell.php)
    if (!window.escapeHtml) window.escapeHtml = window.esc;

})();
