/**
 * Mobile App v3 — Bulletproof Edition
 * ==========================================
 * - ES5 compatible (works on Android 5+ WebView)
 * - No optional chaining (?.), no nullish coalescing (??)
 * - Offline-first with smart caching
 * - PWA install prompt handling
 * - Comprehensive error handling
 * ==========================================
 */

(function() {
'use strict';

var API = '/admin/api_mobile.php?path=';
var APP_VERSION = 'v3.1';
var CACHE_PREFIX = (window.SCHOOL && window.SCHOOL.cache_prefix) || 'school_';

// ========== SAFE HELPERS (ES5 compatible) ==========
function safe(obj, path, fallback) {
    if (fallback === undefined) fallback = '';
    if (!obj) return fallback;
    var keys = path.split('.');
    var val = obj;
    for (var i = 0; i < keys.length; i++) {
        if (val === null || val === undefined) return fallback;
        val = val[keys[i]];
    }
    return (val !== null && val !== undefined) ? val : fallback;
}

function esc(t) {
    if (!t && t !== 0) return '';
    var d = document.createElement('div');
    d.textContent = String(t);
    return d.innerHTML;
}

function $(id) { return document.getElementById(id); }

function copyObj(target, source) {
    for (var k in source) {
        if (source.hasOwnProperty(k)) target[k] = source[k];
    }
    return target;
}

// ========== STORAGE (safe wrapper) ==========
var store = {
    get: function(key) {
        try { return JSON.parse(localStorage.getItem(CACHE_PREFIX + key)); }
        catch(e) { return null; }
    },
    set: function(key, val) {
        try { localStorage.setItem(CACHE_PREFIX + key, JSON.stringify(val)); }
        catch(e) {}
    },
    del: function(key) {
        try { localStorage.removeItem(CACHE_PREFIX + key); }
        catch(e) {}
    }
};

// ========== ROLE DEFINITIONS ==========
var ROLES = {
    super_admin: {
        tabs: [
            { id:'home', icon:'house', label:'Home' },
            { id:'members', icon:'users', label:'Members' },
            { id:'attendance', icon:'clipboard-check', label:'Attend.' },
            { id:'more', icon:'grip', label:'More' },
            { id:'profile', icon:'circle-user', label:'Me' }
        ],
        features: [
            { id:'register', icon:'user-plus', label:'Register', color:'#059669', web:'/admin/dashboard.php?section=manage' },
            { id:'users', icon:'users-gear', label:'Users', color:'#2563eb', web:'/admin/dashboard.php?section=users' },
            { id:'branding', icon:'palette', label:'Branding', color:'#7c3aed', web:'/admin/dashboard.php?section=branding' },
            { id:'health', icon:'heart-pulse', label:'System', color:'#dc2626', web:'/admin/dashboard.php?section=health' },
            { id:'backup', icon:'database', label:'Backup', color:'#0d9488', web:'/admin/dashboard.php?section=backup' },
            { id:'settings', icon:'gear', label:'Settings', color:'#64748b', web:'/admin/dashboard.php?section=settings' },
            { id:'groups', icon:'layer-group', label:'Groups', color:'#be185d', web:'/admin/groups.php' },
            { id:'reports', icon:'chart-bar', label:'Reports', color:'#ea580c', web:'/admin/dashboard.php?section=reports' }
        ]
    },
    info_dept: {
        tabs: [
            { id:'home', icon:'house', label:'Home' },
            { id:'members', icon:'users', label:'Members' },
            { id:'attendance', icon:'clipboard-check', label:'Attend.' },
            { id:'more', icon:'grip', label:'More' },
            { id:'profile', icon:'circle-user', label:'Me' }
        ],
        features: [
            { id:'register', icon:'user-plus', label:'Register', color:'#059669', web:'/admin/dashboard.php?section=manage' },
            { id:'manage', icon:'pen-to-square', label:'Manage', color:'#7c3aed', web:'/admin/dashboard.php?section=manage' },
            { id:'archive', icon:'box-archive', label:'Archive', color:'#d97706', web:'/admin/dashboard.php?section=archive' },
            { id:'idcards', icon:'id-card', label:'ID Cards', color:'#0d9488', web:'/admin/dashboard.php?section=idcards' },
            { id:'groups', icon:'layer-group', label:'Groups', color:'#be185d', web:'/admin/groups.php' },
            { id:'reports', icon:'chart-bar', label:'Reports', color:'#ea580c', web:'/admin/dashboard.php?section=reports' },
            { id:'attakers', icon:'user-check', label:'Att. Takers', color:'#4f46e5', web:'/admin/dashboard.php?section=attakers' },
            { id:'settings', icon:'gear', label:'Settings', color:'#64748b', web:'/admin/dashboard.php?section=settings' }
        ]
    },
    school_admin: {
        tabs: [
            { id:'home', icon:'house', label:'Home' },
            { id:'members', icon:'users', label:'Members' },
            { id:'attendance', icon:'clipboard-check', label:'Attend.' },
            { id:'more', icon:'grip', label:'More' },
            { id:'profile', icon:'circle-user', label:'Me' }
        ],
        features: [
            { id:'register', icon:'user-plus', label:'Register', color:'#059669', web:'/admin/dashboard.php?section=manage' },
            { id:'manage', icon:'pen-to-square', label:'Manage', color:'#7c3aed', web:'/admin/dashboard.php?section=manage' },
            { id:'users', icon:'users-gear', label:'Users', color:'#2563eb', web:'/admin/dashboard.php?section=users' },
            { id:'branding', icon:'palette', label:'Branding', color:'#7c3aed', web:'/admin/dashboard.php?section=branding' },
            { id:'settings', icon:'gear', label:'Settings', color:'#64748b', web:'/admin/dashboard.php?section=settings' }
        ]
    },
    edu_dept: {
        tabs: [
            { id:'home', icon:'house', label:'Home' },
            { id:'attendance', icon:'clipboard-check', label:'Attend.' },
            { id:'grades', icon:'graduation-cap', label:'Grades' },
            { id:'more', icon:'grip', label:'More' },
            { id:'profile', icon:'circle-user', label:'Me' }
        ],
        features: [
            { id:'teachers', icon:'chalkboard-user', label:'Teachers', color:'#7c3aed', web:'/admin/dashboard.php?section=teachers' },
            { id:'subjects', icon:'book-open', label:'Subjects', color:'#2563eb', web:'/admin/dashboard.php?section=subjects' },
            { id:'enrollment', icon:'user-plus', label:'Enroll', color:'#dc2626', web:'/admin/dashboard.php?section=enrollment' },
            { id:'classes', icon:'school', label:'Classes', color:'#059669', web:'/admin/dashboard.php?section=classes' }
        ]
    },
    teacher: {
        tabs: [
            { id:'home', icon:'house', label:'Home' },
            { id:'attendance', icon:'clipboard-check', label:'Attend.' },
            { id:'grades', icon:'graduation-cap', label:'Grades' },
            { id:'members', icon:'users', label:'Students' },
            { id:'profile', icon:'circle-user', label:'Me' }
        ],
        features: []
    },
    attendance_taker: {
        tabs: [
            { id:'home', icon:'house', label:'Home' },
            { id:'attendance', icon:'clipboard-check', label:'Attend.' },
            { id:'profile', icon:'circle-user', label:'Me' }
        ],
        features: []
    },
    finance_dept: null,
    material_dept: null
};
// Aliases
ROLES.finance_dept = ROLES.info_dept;
ROLES.material_dept = ROLES.info_dept;

// ========== APP ==========
var app = {
    user: store.get('user'),
    token: store.get('token') || '',
    page: 'home',
    attStudents: [],
    membersPage: 1,
    membersFilter: '',
    membersSearch: '',
    searchTimer: null,
    installPrompt: null,
    isOnline: navigator.onLine,
    offlineQueue: store.get('offline_queue') || [],

    // ===== INIT =====
    init: function() {
        var self = this;

        // Capture PWA install prompt
        window.addEventListener('beforeinstallprompt', function(e) {
            e.preventDefault();
            self.installPrompt = e;
        });

        // Connectivity
        window.addEventListener('online', function() { self.isOnline = true; self.updateConn(); self.syncOffline(); });
        window.addEventListener('offline', function() { self.isOnline = false; self.updateConn(); });

        // Register service worker
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/app/sw.js').catch(function() {});
        }

        // Boot or show login
        if (this.user && this.token) {
            this.boot();
        } else {
            this.showLogin();
        }

        // Hide splash
        setTimeout(function() {
            var sp = $('splash');
            if (sp) { sp.classList.add('out'); setTimeout(function(){ sp.style.display='none'; }, 500); }
        }, 600);
    },

    // ===== AUTH =====
    showLogin: function() {
        var root = $('app-root');
        if (root) root.classList.remove('ready');

        var hasInstall = !!this.installPrompt;
        var savedUser = store.get('last_user') || '';

        var html = '<div id="login-view">' +
            '<div class="l-box">' +
                '<div class="l-head">' +
                    '<div class="l-logo"><svg viewBox="0 0 48 48" fill="none"><path d="M24 4L8 14v20l16 10 16-10V14L24 4z" fill="rgba(167,243,208,.2)" stroke="#6ee7b7" stroke-width="2"/><text x="24" y="30" text-anchor="middle" font-family="Arial" font-size="18" font-weight="800" fill="#6ee7b7">W</text></svg></div>' +
                    '<h1>' + (window.SCHOOL ? window.SCHOOL.short_name : 'School') + '</h1>' +
                    '<p class="am">' + (window.SCHOOL ? window.SCHOOL.name_am : '') + '</p>' +
                '</div>' +
                '<div class="l-err" id="lerr"></div>' +
                '<div class="l-field"><label>Username or Email</label><div class="l-inp"><i class="fa-solid fa-user"></i><input type="text" id="lu" placeholder="Enter username" autocomplete="username" value="' + esc(savedUser) + '"></div></div>' +
                '<div class="l-field"><label>Password</label><div class="l-inp"><i class="fa-solid fa-lock"></i><input type="password" id="lp" placeholder="Enter password" autocomplete="current-password"></div></div>' +
                '<label class="l-remember"><input type="checkbox" id="lrem" ' + (savedUser ? 'checked' : '') + '> Remember username</label>' +
                '<button class="l-btn" id="lbtn"><span class="l-btn-txt">Sign In</span><div class="spinner"></div></button>' +
                (hasInstall ? '<div class="l-install"><button class="l-install-btn" id="installBtn"><i class="fa-solid fa-download"></i> Install App</button></div>' : '') +
                '<p class="l-foot">' + (window.SCHOOL ? window.SCHOOL.developer : '') + ' &middot; ' + APP_VERSION + '</p>' +
            '</div>' +
        '</div>';

        $('app-root').innerHTML = html;
        $('app-root').classList.add('ready');

        // Bind events safely
        var self = this;
        setTimeout(function() {
            var btn = $('lbtn');
            if (btn) btn.addEventListener('click', function() { self.doLogin(); });
            var pw = $('lp');
            if (pw) pw.addEventListener('keyup', function(e) { if(e.key === 'Enter' || e.keyCode === 13) self.doLogin(); });
            var ib = $('installBtn');
            if (ib) ib.addEventListener('click', function() { self.promptInstall(); });
            // Focus username if empty, else password
            var lu = $('lu');
            if (lu && !lu.value) lu.focus();
            else if (pw) pw.focus();
        }, 100);
    },

    doLogin: function() {
        var u = ($('lu') || {}).value;
        var p = ($('lp') || {}).value;
        u = u ? u.trim() : '';
        if (!u || !p) { this.lErr('Enter username and password'); return; }

        var btn = $('lbtn');
        if (btn) btn.classList.add('loading');
        if (btn) btn.disabled = true;
        var errEl = $('lerr');
        if (errEl) errEl.classList.remove('show');

        var self = this;
        this.apiCall('auth/login', 'POST', {username: u, password: p})
            .then(function(r) {
                if (r.status === 'success' && r.token) {
                    self.token = r.token;
                    self.user = r.user;
                    store.set('token', r.token);
                    store.set('user', r.user);

                    // Remember username
                    var rem = $('lrem');
                    if (rem && rem.checked) store.set('last_user', u);
                    else store.del('last_user');

                    self.boot();
                    self.createWebSession();
                } else {
                    self.lErr(r.message || 'Login failed');
                }
            })
            .catch(function(e) {
                self.lErr('Connection error. Check your internet.');
            })
            .finally(function() {
                if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
            });
    },

    lErr: function(m) {
        var el = $('lerr');
        if (el) { el.textContent = m; el.classList.add('show'); }
    },

    logout: function() {
        store.del('token');
        store.del('user');
        store.del('kpis_cache');
        this.token = '';
        this.user = null;
        this.showLogin();
    },

    // ===== BOOT =====
    boot: function() {
        var role = safe(this.user, 'role', 'info_dept').replace(/_/g, ' ');
        var name = safe(this.user, 'full_name', 'User');

        $('app-root').innerHTML =
            '<div class="hdr">' +
                '<button class="hdr-back" id="hdr-back"><i class="fa-solid fa-arrow-left"></i></button>' +
                '<h1 id="hdr-title">' + (window.SCHOOL ? window.SCHOOL.short_name : 'School') + '</h1>' +
                '<div class="hdr-actions">' +
                    '<button class="hdr-btn hdr-conn ' + (this.isOnline ? 'on' : 'off') + '" id="hdr-conn"><i class="fa-solid fa-' + (this.isOnline ? 'wifi' : 'wifi-slash') + '"></i></button>' +
                '</div>' +
            '</div>' +
            '<div id="offline-bar"></div>' +
            '<div id="page"></div>' +
            '<nav id="nav"></nav>';

        $('app-root').classList.add('ready');

        var self = this;
        var backBtn = $('hdr-back');
        if (backBtn) backBtn.addEventListener('click', function() { self.go('home'); });

        this.buildNav();
        this.go('home');
        this.updateConn();

        // Hide splash
        var sp = $('splash');
        if (sp) { sp.classList.add('out'); setTimeout(function(){ sp.style.display='none'; }, 500); }
    },

    // ===== ROLE CONFIG =====
    getRole: function() { return safe(this.user, 'role', 'info_dept'); },
    getCfg: function() { return ROLES[this.getRole()] || ROLES.info_dept; },

    // ===== NAVIGATION =====
    buildNav: function() {
        var nav = $('nav');
        if (!nav) return;
        var tabs = this.getCfg().tabs;
        var self = this;
        var html = '';
        for (var i = 0; i < tabs.length; i++) {
            var t = tabs[i];
            html += '<button class="n-tab' + (this.page === t.id ? ' on' : '') + '" data-tab="' + t.id + '">' +
                '<i class="fa-solid fa-' + t.icon + '"></i><span>' + t.label + '</span></button>';
        }
        nav.innerHTML = html;

        // Bind tab clicks
        var btns = nav.querySelectorAll('.n-tab');
        for (var j = 0; j < btns.length; j++) {
            btns[j].addEventListener('click', function() {
                self.go(this.getAttribute('data-tab'));
            });
        }
    },

    go: function(page) {
        this.page = page;
        this.buildNav();
        var back = $('hdr-back');
        if (back) back.style.display = 'none';
        var title = $('hdr-title');
        var p = $('page');
        if (p) p.scrollTop = 0;

        switch(page) {
            case 'home': if(title)title.textContent=(window.SCHOOL?window.SCHOOL.short_name:'School'); this.renderHome(); break;
            case 'members': if(title)title.textContent='Members'; this.renderMembers(); break;
            case 'attendance': if(title)title.textContent='Attendance'; this.renderAttendance(); break;
            case 'grades': if(title)title.textContent='Grades'; this.renderGrades(); break;
            case 'more': if(title)title.textContent='More'; this.renderMore(); break;
            case 'profile': if(title)title.textContent='Profile'; this.renderProfile(); break;
            default: if(title)title.textContent=(window.SCHOOL?window.SCHOOL.short_name:'School'); this.renderHome();
        }
    },

    // ===== HOME =====
    renderHome: function() {
        var nm = safe(this.user, 'full_name', 'User').split(' ')[0];
        var role = safe(this.user, 'role', '').replace(/_/g, ' ');
        var initial = safe(this.user, 'full_name', 'U').charAt(0);
        var feats = this.getCfg().features;

        // Time-based greeting
        var hr = new Date().getHours();
        var greeting = hr < 12 ? 'Good morning' : (hr < 17 ? 'Good afternoon' : 'Good evening');

        var fhtml = '';
        if (feats.length) {
            fhtml = '<h3 class="stit"><i class="fa-solid fa-bolt"></i> Quick Access</h3><div class="fgrid">';
            for (var i = 0; i < feats.length; i++) {
                var f = feats[i];
                fhtml += '<button class="fc" data-web="' + esc(f.web || '') + '" data-label="' + esc(f.label) + '">' +
                    '<div class="fc-i" style="background:' + f.color + '"><i class="fa-solid fa-' + f.icon + '"></i></div>' +
                    '<span>' + f.label + '</span></button>';
            }
            fhtml += '</div>';
        }

        $('page').innerHTML =
            '<div class="anim-in">' +
            '<div class="welc">' +
                '<div class="w-av">' + esc(initial) + '</div>' +
                '<div class="w-text"><h2>' + esc(greeting) + ', ' + esc(nm) + '</h2>' +
                '<p class="w-role">' + esc(role) + '</p></div>' +
            '</div>' +
            '<div class="kgrid" id="kpis">' +
                '<div class="ldp"></div><div class="ldp"></div><div class="ldp"></div>' +
            '</div>' +
            fhtml +
            '<button class="fc" id="fullDashBtn" style="width:100%;flex-direction:row;justify-content:center;gap:10px;padding:14px">' +
                '<i class="fa-solid fa-expand" style="color:#059669"></i>' +
                '<span style="font-size:13px;color:#0f172a;font-weight:600">Open Full Dashboard</span>' +
            '</button>' +
            '</div>';

        // Bind feature button clicks
        var self = this;
        var fcs = $('page').querySelectorAll('.fc[data-web]');
        for (var j = 0; j < fcs.length; j++) {
            fcs[j].addEventListener('click', function() {
                var web = this.getAttribute('data-web');
                var label = this.getAttribute('data-label');
                if (web) self.openWeb(label, web);
            });
        }

        var fdb = $('fullDashBtn');
        if (fdb) fdb.addEventListener('click', function() { self.openWeb('Dashboard', '/admin/dashboard.php'); });

        // Load KPIs
        this.loadKPIs();

        // Show cached KPIs immediately
        var cached = store.get('kpis_cache');
        if (cached) {
            var kEl = $('kpis');
            if (kEl) kEl.innerHTML = cached;
        }
    },

    loadKPIs: function() {
        var self = this;
        this.apiCall('data/dashboard')
            .then(function(r) {
                if (r.status === 'success') {
                    var m = r.data.members || {};
                    var a = r.data.today_attendance || {};
                    var cc = safe(r.data, 'classes_count', 0);
                    var html =
                        '<div class="kpi k-g anim-scale"><div class="kv">' + (m.total || 0) + '</div><div class="kl">Members</div></div>' +
                        '<div class="kpi k-b anim-scale"><div class="kv">' + (m.active || 0) + '</div><div class="kl">Active</div></div>' +
                        '<div class="kpi k-o anim-scale"><div class="kv">' + (m.warning || 0) + '</div><div class="kl">Warning</div></div>' +
                        '<div class="kpi k-p anim-scale"><div class="kv">' + (a.present_cnt || 0) + '</div><div class="kl">Present</div></div>' +
                        '<div class="kpi k-r anim-scale"><div class="kv">' + (a.absent_cnt || 0) + '</div><div class="kl">Absent</div></div>' +
                        '<div class="kpi k-t anim-scale"><div class="kv">' + cc + '</div><div class="kl">Classes</div></div>';
                    var kEl = $('kpis');
                    if (kEl) kEl.innerHTML = html;
                    store.set('kpis_cache', html);
                }
            })
            .catch(function() {});
    },

    // ===== MEMBERS =====
    renderMembers: function() {
        $('page').innerHTML =
            '<div class="anim-in">' +
            '<div class="sbar"><i class="fa-solid fa-search"></i><input type="text" id="msearch" placeholder="Search members..."><button class="s-clr" id="mclear">&times;</button></div>' +
            '<div class="chips" id="mchips">' +
                '<button class="chip on" data-filter="">All</button>' +
                '<button class="chip" data-filter="active">Active</button>' +
                '<button class="chip" data-filter="warning">Warning</button>' +
                '<button class="chip" data-filter="inactive">Inactive</button>' +
            '</div>' +
            '<div id="mcount" style="font-size:11px;color:#64748b;margin-bottom:6px;font-weight:600"></div>' +
            '<div id="mlist"><div class="ldp"></div><div class="ldp"></div><div class="ldp"></div></div>' +
            '<div id="mmore" style="text-align:center;padding:12px;display:none"><button class="chip on" id="loadMoreBtn" style="background:#059669;color:#fff;border-color:#059669">Load More</button></div>' +
            '</div>';

        this.membersPage = 1;
        this.membersFilter = '';
        this.membersSearch = '';

        var self = this;

        // Search input
        var si = $('msearch');
        if (si) si.addEventListener('input', function() {
            clearTimeout(self.searchTimer);
            self.searchTimer = setTimeout(function() {
                self.membersSearch = si.value;
                self.membersPage = 1;
                self.loadMembers();
            }, 350);
        });

        // Clear search
        var mc = $('mclear');
        if (mc) mc.addEventListener('click', function() { si.value = ''; self.membersSearch = ''; self.membersPage = 1; self.loadMembers(); });

        // Filter chips
        var chips = $('mchips').querySelectorAll('.chip');
        for (var i = 0; i < chips.length; i++) {
            chips[i].addEventListener('click', function() {
                for (var j = 0; j < chips.length; j++) chips[j].classList.remove('on');
                this.classList.add('on');
                self.membersFilter = this.getAttribute('data-filter');
                self.membersPage = 1;
                self.loadMembers();
            });
        }

        // Load more
        var lm = $('loadMoreBtn');
        if (lm) lm.addEventListener('click', function() { self.membersPage++; self.loadMembers(true); });

        this.loadMembers();
    },

    loadMembers: function(append) {
        var list = $('mlist');
        if (!append && list) list.innerHTML = '<div class="ldp"></div><div class="ldp"></div>';

        var self = this;
        this.apiCall('data/members&page=' + this.membersPage + '&search=' + encodeURIComponent(this.membersSearch) + '&status=' + this.membersFilter)
            .then(function(r) {
                if (r.status === 'success') {
                    var html = '';
                    var members = r.members || [];
                    for (var i = 0; i < members.length; i++) {
                        var m = members[i];
                        var g = (m.gender || '').toLowerCase() === 'female' ? 'f' : 'm';
                        var init = (m.student_name || '?').charAt(0).toUpperCase();
                        var st = m.status || 'active';
                        var badgeCls = st === 'active' ? 'badge-green' : (st === 'warning' ? 'badge-yellow' : 'badge-red');
                        html += '<div class="mc"><div class="mc-av ' + g + '">' + init + '</div>' +
                            '<div class="mc-info"><div class="mc-name">' + esc(m.student_name) + ' ' + esc(m.father_name || '') + '</div>' +
                            '<div class="mc-sub"><code>' + esc(m.member_code || '—') + '</code>' +
                            '<span class="badge ' + badgeCls + '">' + st + '</span></div></div></div>';
                    }
                    if (append) { if(list) list.innerHTML += html; }
                    else { if(list) list.innerHTML = html || '<div class="empty"><i class="fa-solid fa-users-slash"></i><p>No members found</p></div>'; }

                    var mc = $('mcount');
                    if (mc) mc.textContent = (r.total || 0) + ' members found';
                    var mm = $('mmore');
                    if (mm) mm.style.display = (self.membersPage < (r.pages || 1)) ? 'block' : 'none';
                }
            })
            .catch(function() {
                if (list) list.innerHTML = '<div class="empty"><i class="fa-solid fa-wifi-slash"></i><p>Could not load members. Check your connection.</p></div>';
            });
    },

    // ===== ATTENDANCE =====
    renderAttendance: function() {
        var self = this;
        var today = new Date().toISOString().split('T')[0];

        $('page').innerHTML =
            '<div class="anim-in">' +
            '<div class="fm-section"><div class="fm-row">' +
                '<div class="fm-field"><label>Class</label><select class="fm-input" id="att-cls"><option value="">Loading...</option></select></div>' +
                '<div class="fm-field"><label>Date</label><input type="date" class="fm-input" id="att-date" value="' + today + '"></div>' +
            '</div></div>' +
            '<div class="qbar" id="att-qbar" style="display:none">' +
                '<button class="chip" data-mark="present" style="border-color:#a7f3d0;color:#059669"><i class="fa-solid fa-check-double"></i> All Present</button>' +
                '<button class="chip" data-mark="absent" style="border-color:#fecaca;color:#dc2626"><i class="fa-solid fa-xmark"></i> All Absent</button>' +
                '<button class="chip" data-mark="late" style="border-color:#fde68a;color:#d97706"><i class="fa-solid fa-clock"></i> All Late</button>' +
            '</div>' +
            '<div class="sum" id="att-sum" style="display:none"></div>' +
            '<div id="att-list"><div class="empty"><i class="fa-solid fa-clipboard-check"></i><p>Select a class to take attendance</p></div></div>' +
            '</div>';

        // Load classes
        this.apiCall('data/classes')
            .then(function(r) {
                var sel = $('att-cls');
                if (!sel) return;
                var opts = '<option value="">Select Class...</option>';
                var classes = (r.status === 'success') ? (r.classes || []) : [];
                for (var i = 0; i < classes.length; i++) {
                    var c = classes[i];
                    var en = c.class_name_en ? ' (' + esc(c.class_name_en) + ')' : '';
                    opts += '<option value="' + c.id + '">' + esc(c.class_name) + en + '</option>';
                }
                sel.innerHTML = opts;
            })
            .catch(function() {
                var sel = $('att-cls');
                if (sel) sel.innerHTML = '<option value="">Error loading classes</option>';
            });

        // Bind events
        var cls = $('att-cls');
        var dt = $('att-date');
        if (cls) cls.addEventListener('change', function() { self.loadAtt(); });
        if (dt) dt.addEventListener('change', function() { self.loadAtt(); });

        // Mark all buttons
        var marks = $('att-qbar');
        if (marks) {
            var mbtns = marks.querySelectorAll('.chip');
            for (var i = 0; i < mbtns.length; i++) {
                mbtns[i].addEventListener('click', function() {
                    self.markAllAtt(this.getAttribute('data-mark'));
                });
            }
        }
    },

    loadAtt: function() {
        var cls = ($('att-cls') || {}).value;
        var date = ($('att-date') || {}).value;
        if (!cls) {
            $('att-list').innerHTML = '<div class="empty"><i class="fa-solid fa-clipboard-check"></i><p>Select a class</p></div>';
            $('att-qbar').style.display = 'none';
            $('att-sum').style.display = 'none';
            return;
        }

        $('att-list').innerHTML = '<div class="ldp"></div><div class="ldp"></div>';

        var self = this;
        this.apiCall('attendance/get&class_id=' + cls + '&date=' + date)
            .then(function(r) {
                if (r.status === 'success') {
                    var students = r.students || [];
                    self.attStudents = [];
                    for (var i = 0; i < students.length; i++) {
                        var s = copyObj({}, students[i]);
                        s._status = s.att_status || 'present';
                        self.attStudents.push(s);
                    }
                    $('att-qbar').style.display = 'flex';
                    $('att-sum').style.display = 'flex';
                    self.renderAttList();
                }
            })
            .catch(function() {
                $('att-list').innerHTML = '<div class="empty"><i class="fa-solid fa-wifi-slash"></i><p>Failed to load</p></div>';
            });
    },

    renderAttList: function() {
        var list = $('att-list');
        if (!this.attStudents.length) {
            list.innerHTML = '<div class="empty"><i class="fa-solid fa-users-slash"></i><p>No students enrolled in this class</p></div>';
            return;
        }

        var html = '';
        for (var i = 0; i < this.attStudents.length; i++) {
            var s = this.attStudents[i];
            var g = (s.gender || '').toLowerCase() === 'female' ? 'f' : 'm';
            var st = s._status || 'present';
            html += '<div class="ac"><div class="ac-l"><div class="mc-av ' + g + '">' + (s.student_name || '?')[0] + '</div>' +
                '<div class="ac-info"><div class="ac-name">' + esc(s.student_name) + ' ' + esc(s.father_name || '') + '</div>' +
                '<div class="ac-code">' + esc(s.member_code || '') + '</div></div></div>' +
                '<div class="ac-btns">' +
                '<button class="ab prs' + (st==='present'?' on':'') + '" data-idx="' + i + '" data-st="present">&#10003;</button>' +
                '<button class="ab abs' + (st==='absent'?' on':'') + '" data-idx="' + i + '" data-st="absent">&#10007;</button>' +
                '<button class="ab lat' + (st==='late'?' on':'') + '" data-idx="' + i + '" data-st="late">L</button>' +
                '</div></div>';
        }
        html += '<button class="fab" id="saveAttBtn"><i class="fa-solid fa-floppy-disk"></i> Save Attendance</button>';
        list.innerHTML = html;

        // Bind attendance buttons
        var self = this;
        var abBtns = list.querySelectorAll('.ab');
        for (var j = 0; j < abBtns.length; j++) {
            abBtns[j].addEventListener('click', function() {
                var idx = parseInt(this.getAttribute('data-idx'));
                var st = this.getAttribute('data-st');
                self.attStudents[idx]._status = st;
                self.renderAttList();
            });
        }

        var saveBtn = $('saveAttBtn');
        if (saveBtn) saveBtn.addEventListener('click', function() { self.saveAtt(); });

        this.updateAttSum();
    },

    markAllAtt: function(st) {
        for (var i = 0; i < this.attStudents.length; i++) {
            this.attStudents[i]._status = st;
        }
        this.renderAttList();
    },

    updateAttSum: function() {
        var p=0, a=0, l=0;
        for (var i = 0; i < this.attStudents.length; i++) {
            var s = this.attStudents[i]._status;
            if (s==='present') p++; else if (s==='absent') a++; else if (s==='late') l++;
        }
        var el = $('att-sum');
        if (el) el.innerHTML = '<span class="sc sc-g">&#10003; ' + p + '</span><span class="sc sc-r">&#10007; ' + a + '</span><span class="sc sc-y">L ' + l + '</span><span class="sc sc-gr">Total ' + this.attStudents.length + '</span>';
    },

    saveAtt: function() {
        var cls = ($('att-cls') || {}).value;
        var date = ($('att-date') || {}).value;
        if (!cls) return;

        var records = [];
        for (var i = 0; i < this.attStudents.length; i++) {
            var s = this.attStudents[i];
            records.push({ member_id: s.member_id || s.id, status: s._status || 'present', note: '' });
        }

        var saveBtn = $('saveAttBtn');
        if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<div class="spinner" style="display:inline-block;width:18px;height:18px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:sp-spin .6s linear infinite"></div> Saving...'; }

        var self = this;

        if (!this.isOnline) {
            // Queue for offline sync
            this.offlineQueue.push({ type: 'attendance', data: { class_id: parseInt(cls), date: date, records: records }, ts: Date.now() });
            store.set('offline_queue', this.offlineQueue);
            self.toast('Saved offline — will sync when connected', 'warning');
            if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Attendance'; }
            return;
        }

        this.apiCall('attendance/save', 'POST', { class_id: parseInt(cls), date: date, records: records })
            .then(function(r) {
                self.toast(r.message || 'Saved!', r.status === 'success' ? 'success' : 'error');
            })
            .catch(function() { self.toast('Error saving attendance', 'error'); })
            .finally(function() {
                if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Attendance'; }
            });
    },

    // ===== GRADES =====
    renderGrades: function() {
        var self = this;
        $('page').innerHTML =
            '<div class="anim-in"><div class="fm-section" style="text-align:center;padding:32px 20px">' +
            '<i class="fa-solid fa-graduation-cap" style="font-size:36px;color:#059669;opacity:.5;margin-bottom:12px;display:block"></i>' +
            '<h3 style="font-size:15px;font-weight:700;margin-bottom:6px">Grade Management</h3>' +
            '<p style="font-size:12px;color:#64748b;margin-bottom:16px">Enter and manage student grades</p>' +
            '<button class="btn-primary" id="openGradesBtn" style="width:auto;display:inline-flex;padding:12px 28px"><i class="fa-solid fa-expand"></i> Open Grades</button>' +
            '</div></div>';
        var gb = $('openGradesBtn');
        if (gb) gb.addEventListener('click', function() { self.openWeb('Grades', '/admin/dashboard.php?section=grades'); });
    },

    // ===== MORE =====
    renderMore: function() {
        var feats = this.getCfg().features.slice();
        feats.push({ id:'fullweb', icon:'expand', label:'Full Dashboard', color:'#1e293b', web:'/admin/dashboard.php' });

        var html = '<div class="anim-in"><h3 class="stit" style="margin-top:4px"><i class="fa-solid fa-grid-2"></i> All Features</h3>' +
            '<div class="fgrid" style="grid-template-columns:repeat(3,1fr);gap:10px">';
        for (var i = 0; i < feats.length; i++) {
            var f = feats[i];
            html += '<button class="fc" data-web="' + esc(f.web || '/admin/dashboard.php') + '" data-label="' + esc(f.label) + '" style="padding:18px 8px">' +
                '<div class="fc-i" style="background:' + f.color + '"><i class="fa-solid fa-' + f.icon + '"></i></div>' +
                '<span>' + f.label + '</span></button>';
        }
        html += '</div>';

        // App info section
        html += '<div class="card card-pad" style="margin-top:8px">' +
            '<h3 class="stit"><i class="fa-solid fa-info-circle"></i> App Info</h3>' +
            '<div class="prof-row"><span>Version</span><span>' + APP_VERSION + '</span></div>' +
            '<div class="prof-row"><span>Connection</span><span style="color:' + (this.isOnline ? '#059669' : '#dc2626') + '">' + (this.isOnline ? '● Online' : '● Offline') + '</span></div>' +
            '<div class="prof-row"><span>Offline Queue</span><span>' + this.offlineQueue.length + ' items</span></div>' +
            '</div></div>';

        $('page').innerHTML = html;

        // Bind buttons
        var self = this;
        var fcs = $('page').querySelectorAll('.fc[data-web]');
        for (var j = 0; j < fcs.length; j++) {
            fcs[j].addEventListener('click', function() {
                self.openWeb(this.getAttribute('data-label'), this.getAttribute('data-web'));
            });
        }
    },

    // ===== PROFILE =====
    renderProfile: function() {
        var u = this.user || {};
        var role = safe(u, 'role', '').replace(/_/g, ' ');
        var initial = safe(u, 'full_name', 'U').charAt(0);

        var html = '<div class="anim-in">' +
            '<div class="prof-hdr"><div class="prof-av">' + esc(initial) + '</div>' +
            '<h2>' + esc(safe(u, 'full_name', 'User')) + '</h2><p class="p-role">' + esc(role) + '</p></div>' +
            '<div class="card card-pad">' +
                '<h3 class="stit">Account Details</h3>' +
                '<div class="prof-row"><span>Username</span><span>' + esc(safe(u, 'username', '—')) + '</span></div>' +
                '<div class="prof-row"><span>Email</span><span>' + esc(safe(u, 'email', '—')) + '</span></div>' +
                '<div class="prof-row"><span>Role</span><span style="text-transform:capitalize">' + esc(role) + '</span></div>' +
                '<div class="prof-row"><span>Status</span><span style="color:#059669">● Active</span></div>' +
            '</div>' +
            '<div class="card card-pad">' +
                '<h3 class="stit">App Settings</h3>' +
                '<div class="prof-row"><span>Connection</span><span style="color:' + (this.isOnline ? '#059669' : '#dc2626') + '">' + (this.isOnline ? '● Online' : '● Offline') + '</span></div>' +
                '<div class="prof-row"><span>Server</span><span>' + (window.SCHOOL ? window.SCHOOL.domain : location.host) + '</span></div>' +
                '<div class="prof-row"><span>App Version</span><span>' + APP_VERSION + '</span></div>' +
            '</div>';

        // Install button if available
        if (this.installPrompt) {
            html += '<button class="btn-primary" id="installAppBtn" style="margin-bottom:10px"><i class="fa-solid fa-download"></i> Install App</button>';
        }

        html += '<button class="btn-out" id="logoutBtn"><i class="fa-solid fa-right-from-bracket"></i> Sign Out</button></div>';

        $('page').innerHTML = html;

        var self = this;
        var lb = $('logoutBtn');
        if (lb) lb.addEventListener('click', function() {
            if (confirm('Sign out of ' + (window.SCHOOL ? window.SCHOOL.short_name : 'app') + '?')) self.logout();
        });

        var ib = $('installAppBtn');
        if (ib) ib.addEventListener('click', function() { self.promptInstall(); });
    },

    // ===== WEBVIEW =====
    openWeb: function(title, url) {
        var self = this;
        this.createWebSession().then(function() {
            var wv = document.createElement('div');
            wv.className = 'wv';
            wv.innerHTML = '<div class="wv-hdr"><button id="wv-close"><i class="fa-solid fa-arrow-left"></i></button><h2>' + esc(title) + '</h2>' +
                '<button id="wv-reload" style="color:#64748b"><i class="fa-solid fa-rotate"></i></button></div>' +
                '<iframe src="' + url + '" allow="camera;microphone"></iframe>';
            document.body.appendChild(wv);

            // Bind close
            wv.querySelector('#wv-close').addEventListener('click', function() { wv.remove(); });
            wv.querySelector('#wv-reload').addEventListener('click', function() {
                var iframe = wv.querySelector('iframe');
                if (iframe) iframe.src = iframe.src;
            });
        });
    },

    // ===== API HELPERS =====
    apiCall: function(path, method, body) {
        method = method || 'GET';
        var opts = {
            method: method,
            headers: { 'Authorization': 'Bearer ' + this.token }
        };
        if (method === 'POST' && body) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }

        return fetch(API + path, opts)
            .then(function(resp) {
                return resp.text().then(function(text) {
                    try { return JSON.parse(text); }
                    catch(e) { return { status: 'error', message: 'Server error' }; }
                });
            });
    },

    createWebSession: function() {
        if (!this.token) return Promise.resolve();
        return fetch('/admin/app_session.php?token=' + encodeURIComponent(this.token), { credentials: 'same-origin' })
            .catch(function() {});
    },

    // ===== CONNECTIVITY =====
    updateConn: function() {
        var el = $('hdr-conn');
        if (el) {
            el.className = 'hdr-btn hdr-conn ' + (this.isOnline ? 'on' : 'off');
            el.innerHTML = '<i class="fa-solid fa-' + (this.isOnline ? 'wifi' : 'wifi-slash') + '"></i>';
        }
        var ob = $('offline-bar');
        if (ob) {
            ob.innerHTML = this.isOnline ? '' : '<div class="offline-bar"><i class="fa-solid fa-wifi-slash"></i> You\'re offline — some features may be limited</div>';
        }
    },

    // ===== OFFLINE SYNC =====
    syncOffline: function() {
        if (!this.isOnline || !this.offlineQueue.length) return;
        var self = this;
        var items = this.offlineQueue.slice();
        this.offlineQueue = [];
        store.set('offline_queue', []);

        this.apiCall('sync/push', 'POST', { items: items })
            .then(function(r) {
                if (r.status === 'success') {
                    self.toast('Offline data synced!', 'success');
                }
            })
            .catch(function() {
                // Put items back in queue
                self.offlineQueue = items.concat(self.offlineQueue);
                store.set('offline_queue', self.offlineQueue);
            });
    },

    // ===== INSTALL PROMPT =====
    promptInstall: function() {
        if (!this.installPrompt) {
            this.toast('Open in your browser to install', 'info');
            return;
        }
        this.installPrompt.prompt();
        var self = this;
        this.installPrompt.userChoice.then(function(result) {
            if (result.outcome === 'accepted') {
                self.toast('App installed!', 'success');
            }
            self.installPrompt = null;
        });
    },

    // ===== TOAST =====
    toast: function(msg, type) {
        type = type || 'info';
        var tc = $('toast-c');
        if (!tc) return;
        var t = document.createElement('div');
        var cls = type === 'success' ? 't-ok' : (type === 'error' ? 't-err' : (type === 'warning' ? 't-warn' : 't-info'));
        var icon = type === 'success' ? 'circle-check' : (type === 'error' ? 'circle-xmark' : (type === 'warning' ? 'triangle-exclamation' : 'circle-info'));
        t.className = 'toast ' + cls;
        t.innerHTML = '<i class="fa-solid fa-' + icon + '"></i><span>' + esc(msg) + '</span>';
        tc.appendChild(t);
        setTimeout(function() {
            t.classList.add('out');
            setTimeout(function() { t.remove(); }, 300);
        }, 3500);
    }
};

// ===== BOOT =====
window.app = app;
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { app.init(); });
} else {
    app.init();
}

})();
