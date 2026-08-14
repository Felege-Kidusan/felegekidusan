/**
 * ============================================================
 * Finance Department Dashboard — JavaScript Logic
 * ============================================================
 * 
 * All logic from the original finance_department.php inline <script>.
 * Uses window.APP (CSRF, user, school) and window.api (fetch wrapper)
 * from core.js. No PHP echoes, no hardcoded values.
 * 
 * This file never changes between schools.
 * ============================================================
 */

var Finance = (function() {
    'use strict';

    var APP = window.APP || {};
    var cats = [];
    var members = [];
    var txns = { income: [], expense: [] };

    // API endpoint (uses shim: /backend/api/finance.php → /admin/api_finance.php)
    var API = '/backend/api/finance.php';
    var MEMBERS_API = '/backend/api/members.php';

    // ══════════════════════════════════════════════════════════
    // INIT
    // ══════════════════════════════════════════════════════════

    function init() {
        // Set greeting
        var hour = new Date().getHours();
        var greeting = hour < 12 ? 'Good Morning' : (hour < 17 ? 'Good Afternoon' : 'Good Evening');
        var firstName = (APP.user && APP.user.name) ? APP.user.name.split(' ')[0] : 'Finance Admin';
        var greetEl = document.getElementById('greeting');
        if (greetEl) greetEl.textContent = greeting + ', ' + firstName + ' 💰';

        // Set EC year for fee filter
        var feeYearEl = document.getElementById('feeYear');
        if (feeYearEl) {
            // Approximate Ethiopian year
            var ecYear = new Date().getFullYear() - 8;
            feeYearEl.value = ecYear;
            var feeEcYearEl = document.getElementById('feeEcYear');
            if (feeEcYearEl) feeEcYearEl.value = ecYear;
        }

        // Set dept name in sidebar
        var deptEl = document.querySelector('[data-school-dept-finance]');
        if (deptEl && APP.school && APP.school.depts && APP.school.depts.finance) {
            deptEl.textContent = APP.school.depts.finance.am || '';
        }

        // Check section from URL
        var urlSection = new URLSearchParams(window.location.search).get('section');
        if (urlSection) nav(urlSection);

        // Load initial data
        loadDashboard();
        loadCats();
        loadMembers();
    }

    // ══════════════════════════════════════════════════════════
    // NAVIGATION
    // ══════════════════════════════════════════════════════════

    function nav(sectionName) {
        // Hide all sections
        document.querySelectorAll('.school-section').forEach(function(s) {
            s.classList.remove('active');
        });
        var target = document.getElementById('section-' + sectionName);
        if (target) target.classList.add('active');

        // Update sidebar nav
        document.querySelectorAll('.school-nav-link').forEach(function(b) {
            b.classList.remove('active');
            if (b.getAttribute('data-section') === sectionName) b.classList.add('active');
        });

        // Update mobile nav
        document.querySelectorAll('.school-bottom-nav-btn').forEach(function(b) {
            b.classList.remove('active');
            if (b.getAttribute('data-section') === sectionName) b.classList.add('active');
        });

        // Load section data
        if (sectionName === 'income') loadTxns('income');
        if (sectionName === 'expense') loadTxns('expense');
        if (sectionName === 'fees') loadFees();
        if (sectionName === 'categories') loadCats();
        if (sectionName === 'reports') initReport();

        // Update URL
        var u = new URL(window.location);
        u.searchParams.set('section', sectionName);
        history.replaceState(null, '', u);
    }

    // ══════════════════════════════════════════════════════════
    // DASHBOARD
    // ══════════════════════════════════════════════════════════

    function loadDashboard() {
        // Load stats via API
        window.api.get('finance.php?action=dashboard')
            .then(function(d) {
                if (d.status === 'success' && d.data) {
                    renderStats(d.data);
                    renderRecentTxns(d.data.recent || []);
                }
            })
            .catch(function() {
                // Finance tables might not exist
                var notice = document.getElementById('setup-notice');
                if (notice) notice.style.display = 'block';
            });
    }

    function renderStats(data) {
        var stats = data.stats || {};
        var grid = document.getElementById('stats-grid');
        if (!grid) return;

        grid.innerHTML =
            statCard('fa-sack-dollar', 'success', num(stats.income || 0), 'Total Income (ETB)', 'success') +
            statCard('fa-money-bill-transfer', 'danger', num(stats.expense || 0), 'Total Expense (ETB)', 'danger') +
            statCard('fa-wallet', 'info', num((stats.income || 0) - (stats.expense || 0)), 'Balance', (stats.income || 0) >= (stats.expense || 0) ? 'success' : 'danger') +
            statCard('fa-calendar', 'accent', num(stats.month_in || 0), 'This Month Income') +
            statCard('fa-clock', 'accent2', String(stats.pending || 0), 'Pending') +
            statCard('fa-users', 'info', String(stats.members || 0), 'Active Members');
    }

    function statCard(icon, colorType, value, label, valueColor) {
        var colors = {
            success: { bg: 'var(--school-success-bg)', fg: 'var(--school-success)' },
            danger:  { bg: 'var(--school-danger-bg)',  fg: 'var(--school-danger)' },
            info:    { bg: 'var(--school-info-bg)',    fg: 'var(--school-info)' },
            accent:  { bg: 'var(--school-accent-a10)', fg: 'var(--school-accent)' },
            accent2: { bg: 'var(--school-accent2-a10)', fg: 'var(--school-accent-2)' }
        };
        var c = colors[colorType] || colors.accent;
        var vc = valueColor ? (colors[valueColor] || c).fg : 'var(--school-text-bright)';

        return '<div class="school-stat-card">' +
            '<div class="school-stat-icon" style="background:' + c.bg + ';color:' + c.fg + '"><i class="fa-solid ' + icon + '"></i></div>' +
            '<div class="school-stat-value" style="color:' + vc + '">' + value + '</div>' +
            '<div class="school-stat-label">' + label + '</div>' +
            '</div>';
    }

    function renderRecentTxns(recent) {
        var tb = document.getElementById('recentBody');
        if (!tb) return;
        if (!recent.length) {
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--school-text-dim)">No transactions yet</td></tr>';
            return;
        }
        tb.innerHTML = recent.map(function(t) {
            return '<tr>' +
                '<td style="font-size:.7rem">' + fDate(t.transaction_date) + '</td>' +
                '<td>' + (t.type === 'income' ? '<span class="badge badge-active">Income</span>' : '<span class="badge badge-inactive">Expense</span>') + '</td>' +
                '<td>' + esc(t.category_name || '—') + '</td>' +
                '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis">' + esc(t.description || '—') + '</td>' +
                '<td style="font-weight:600;color:' + (t.type === 'income' ? 'var(--school-success)' : 'var(--school-danger)') + '">' + num(t.amount) + '</td>' +
                '<td>' + statusBadge(t.status) + '</td>' +
                '</tr>';
        }).join('');
    }

    // ══════════════════════════════════════════════════════════
    // TRANSACTIONS (Income / Expense)
    // ══════════════════════════════════════════════════════════

    function loadTxns(type) {
        window.api.get('finance.php?action=transactions&type=' + type)
            .then(function(d) {
                if (d.status === 'success') {
                    txns[type] = d.transactions || [];
                    renderTxns(type);
                }
            });
    }

    function renderTxns(type) {
        var tbId = type === 'income' ? 'incBody' : 'expBody';
        var tb = document.getElementById(tbId);
        if (!tb) return;

        if (!txns[type].length) {
            tb.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--school-text-dim);padding:1.5rem">No ' + type + ' records yet. Click Add to create one.</td></tr>';
            return;
        }

        tb.innerHTML = txns[type].map(function(t) {
            return '<tr>' +
                '<td style="font-size:.7rem">' + fDate(t.transaction_date) + '</td>' +
                '<td>' + esc(t.category_name || '—') + '</td>' +
                (type === 'income' ? '<td>' + esc(t.member_name || '—') + '</td>' : '') +
                '<td style="max-width:180px;overflow:hidden;text-overflow:ellipsis">' + esc(t.description || '—') + '</td>' +
                '<td style="font-weight:600;color:' + (type === 'income' ? 'var(--school-success)' : 'var(--school-danger)') + '">' + num(t.amount) + '</td>' +
                '<td>' + esc(t.payment_method || '—') + '</td>' +
                '<td>' + esc(t.receipt_number || '—') + '</td>' +
                (type === 'expense' ? '<td>' + statusBadge(t.status) + '</td>' : '') +
                '<td><button class="btn-secondary btn-sm" onclick="Finance.deleteTxn(' + t.id + ',\'' + type + '\')" title="Delete"><i class="fa-solid fa-trash" style="color:var(--school-danger)"></i></button></td>' +
                '</tr>';
        }).join('');
    }

    function openAddTxn(type) {
        document.getElementById('txnType').value = type;
        document.getElementById('txnTitle').textContent = 'Add ' + (type === 'income' ? 'Income' : 'Expense');
        document.getElementById('txnDate').value = new Date().toISOString().slice(0, 10);
        document.getElementById('txnMemberWrap').style.display = type === 'income' ? 'block' : 'none';
        populateTxnCats(type);
        modal('txnModal', true);
    }

    function populateTxnCats(type) {
        var sel = document.getElementById('txnCat');
        sel.innerHTML = '<option value="">Select...</option>' +
            cats.filter(function(c) { return c.type === type; })
                .map(function(c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; })
                .join('');
    }

    function saveTxn() {
        var type = document.getElementById('txnType').value;
        var amt = document.getElementById('txnAmt').value;
        if (!amt || parseFloat(amt) <= 0) return toast('Enter amount', 'e');

        window.api.post('finance.php', {
            action: 'add_transaction',
            type: type,
            category_id: document.getElementById('txnCat').value,
            amount: amt,
            description: document.getElementById('txnDesc').value,
            transaction_date: document.getElementById('txnDate').value,
            payment_method: document.getElementById('txnMethod').value,
            receipt_number: document.getElementById('txnReceipt').value,
            member_id: document.getElementById('txnMember').value || ''
        })
        .then(function(d) {
            if (d.status === 'success') {
                toast('Saved!', 's');
                modal('txnModal', false);
                loadTxns(type);
                loadDashboard();
                ['txnAmt', 'txnDesc', 'txnReceipt'].forEach(function(id) {
                    document.getElementById(id).value = '';
                });
            } else {
                toast(d.message || 'Error', 'e');
            }
        })
        .catch(function() { toast('Network error', 'e'); });
    }

    function deleteTxn(id, type) {
        if (!confirm('Delete this transaction?')) return;
        window.api.post('finance.php', { action: 'delete_transaction', id: id })
            .then(function(d) {
                if (d.status === 'success') {
                    toast('Deleted', 's');
                    loadTxns(type);
                    loadDashboard();
                } else {
                    toast(d.message || 'Error', 'e');
                }
            })
            .catch(function() { toast('Error', 'e'); });
    }

    // ══════════════════════════════════════════════════════════
    // CATEGORIES
    // ══════════════════════════════════════════════════════════

    function loadCats() {
        window.api.get('finance.php?action=categories')
            .then(function(d) {
                if (d.status === 'success') {
                    cats = d.categories || [];
                    renderCats();
                }
            });
    }

    function renderCats() {
        var inc = cats.filter(function(c) { return c.type === 'income'; });
        var exp = cats.filter(function(c) { return c.type === 'expense'; });

        document.getElementById('incCats').innerHTML = inc.length ?
            inc.map(function(c) {
                return '<div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid var(--school-border);font-size:.8rem"><span>' + esc(c.name) + '</span><span style="color:var(--school-text-dim);font-size:.7rem">' + esc(c.description || '') + '</span></div>';
            }).join('') :
            '<p style="color:var(--school-text-dim);font-size:.8rem">No categories</p>';

        document.getElementById('expCats').innerHTML = exp.length ?
            exp.map(function(c) {
                return '<div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid var(--school-border);font-size:.8rem"><span>' + esc(c.name) + '</span><span style="color:var(--school-text-dim);font-size:.7rem">' + esc(c.description || '') + '</span></div>';
            }).join('') :
            '<p style="color:var(--school-text-dim);font-size:.8rem">No categories</p>';
    }

    function openCatModal() { modal('catModal', true); }

    function saveCat() {
        var nm = document.getElementById('catName').value.trim();
        if (!nm) return toast('Name required', 'e');

        window.api.post('finance.php', {
            action: 'save_category',
            name: nm,
            type: document.getElementById('catType').value,
            description: document.getElementById('catDesc').value.trim()
        })
        .then(function(d) {
            if (d.status === 'success') {
                toast('Category saved!', 's');
                modal('catModal', false);
                loadCats();
                document.getElementById('catName').value = '';
                document.getElementById('catDesc').value = '';
            } else {
                toast(d.message || 'Error', 'e');
            }
        })
        .catch(function() { toast('Error', 'e'); });
    }

    // ══════════════════════════════════════════════════════════
    // MEMBER FEES
    // ══════════════════════════════════════════════════════════

    function openFeeModal() {
        if (!members.length) loadMembers();
        modal('feeModal', true);
    }

    function loadFees() {
        var st = (document.getElementById('feeStatus') || {}).value || '';
        var yr = (document.getElementById('feeYear') || {}).value || '';

        window.api.get('finance.php?action=member_fees&status=' + st + '&ec_year=' + yr)
            .then(function(d) {
                if (d.status === 'success') renderFees(d.fees || []);
            });
    }

    function renderFees(fees) {
        var tb = document.getElementById('feesBody');
        if (!tb) return;

        if (!fees.length) {
            tb.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--school-text-dim);padding:1.5rem">No fees recorded</td></tr>';
            return;
        }

        tb.innerHTML = fees.map(function(x) {
            var stBadge = x.status === 'paid' ? '<span class="badge badge-active">Paid</span>' :
                          x.status === 'partial' ? '<span class="badge badge-warning">Partial</span>' :
                          '<span class="badge badge-inactive">Unpaid</span>';
            return '<tr>' +
                '<td style="font-weight:600">' + esc(x.student_name || '—') + '</td>' +
                '<td><span class="badge badge-info">' + esc(x.member_code || '—') + '</span></td>' +
                '<td>' + esc(x.fee_type) + '</td>' +
                '<td style="font-weight:600">' + num(x.amount) + '</td>' +
                '<td>' + (x.ec_month || '—') + '</td>' +
                '<td>' + (x.ec_year || '—') + '</td>' +
                '<td>' + stBadge + '</td>' +
                '<td style="font-size:.7rem">' + (x.paid_date ? fDate(x.paid_date) : '—') + '</td>' +
                '</tr>';
        }).join('');
    }

    function saveFee() {
        var member = document.getElementById('feeMember').value;
        var amt = document.getElementById('feeAmt').value;
        if (!member || !amt) return toast('Member and amount required', 'e');

        var status = document.getElementById('feePayStatus').value;
        window.api.post('finance.php', {
            action: 'save_fee',
            member_id: member,
            amount: amt,
            fee_type: document.getElementById('feeType').value,
            ec_month: document.getElementById('feeMonth').value,
            ec_year: document.getElementById('feeEcYear').value,
            status: status,
            paid_date: status === 'paid' ? new Date().toISOString().slice(0, 10) : ''
        })
        .then(function(d) {
            if (d.status === 'success') {
                toast('Fee recorded!', 's');
                modal('feeModal', false);
                loadFees();
            } else {
                toast(d.message || 'Error', 'e');
            }
        })
        .catch(function() { toast('Error', 'e'); });
    }

    // ══════════════════════════════════════════════════════════
    // MEMBERS (for dropdowns)
    // ══════════════════════════════════════════════════════════

    function loadMembers() {
        window.api.get('members.php')
            .then(function(d) {
                if (d.status === 'success') {
                    members = d.members || [];
                    var opts = members.map(function(m) {
                        return '<option value="' + m.id + '">' + esc(m.student_name) + ' (' + esc(m.member_code || '') + ')</option>';
                    }).join('');
                    document.getElementById('txnMember').innerHTML = '<option value="">— None —</option>' + opts;
                    document.getElementById('feeMember').innerHTML = '<option value="">Select member...</option>' + opts;
                }
            });
    }

    // ══════════════════════════════════════════════════════════
    // REPORTS
    // ══════════════════════════════════════════════════════════

    function initReport() {
        var t = new Date();
        var y = new Date(t.getFullYear(), 0, 1);
        document.getElementById('rptFrom').value = y.toISOString().slice(0, 10);
        document.getElementById('rptTo').value = t.toISOString().slice(0, 10);
    }

    function loadReport() {
        var from = document.getElementById('rptFrom').value;
        var to = document.getElementById('rptTo').value;
        if (!from || !to) return toast('Select dates', 'e');

        window.api.get('finance.php?action=report&from=' + from + '&to=' + to)
            .then(function(d) {
                if (d.status === 'success' && d.data) {
                    var dt = d.data;
                    var rptStats = document.getElementById('rptStats');
                    rptStats.innerHTML =
                        statCard('fa-arrow-up', 'success', num(dt.totals?.income || 0), 'Income', 'success') +
                        statCard('fa-arrow-down', 'danger', num(dt.totals?.expense || 0), 'Expense', 'danger') +
                        statCard('fa-wallet', 'info', num((parseFloat(dt.totals?.income || 0) - parseFloat(dt.totals?.expense || 0))), 'Net');

                    if (dt.by_category && dt.by_category.length) {
                        document.getElementById('rptBody').innerHTML = dt.by_category.map(function(c) {
                            return '<tr>' +
                                '<td>' + esc(c.name || '—') + '</td>' +
                                '<td>' + (c.type === 'income' ? '<span class="badge badge-active">Income</span>' : '<span class="badge badge-inactive">Expense</span>') + '</td>' +
                                '<td style="font-weight:600">' + num(c.total) + '</td>' +
                                '<td>' + c.cnt + '</td>' +
                                '</tr>';
                        }).join('');
                        document.getElementById('rptDetail').style.display = 'block';
                    }
                }
            })
            .catch(function() { toast('Error', 'e'); });
    }

    // ══════════════════════════════════════════════════════════
    // EXPORT
    // ══════════════════════════════════════════════════════════

    function exportTxns(type) {
        var data = txns[type] || [];
        if (!data.length) return toast('No data', 'e');

        var h = ['Date', 'Category', 'Description', 'Amount', 'Method', 'Receipt', 'Status'];
        var rows = data.map(function(t) {
            return [fDate(t.transaction_date), t.category_name || '', t.description || '', t.amount, t.payment_method || '', t.receipt_number || '', t.status];
        });
        exportXlsx(h, rows, APP.school.memberPrefix + '_' + type + '_' + new Date().toISOString().slice(0, 10));
    }

    function exportReport() {
        var rows = [];
        document.querySelectorAll('#rptBody tr').forEach(function(tr) {
            rows.push(Array.from(tr.querySelectorAll('td')).map(function(td) { return td.textContent.trim(); }));
        });
        if (!rows.length) return toast('Generate report first', 'e');
        exportXlsx(['Category', 'Type', 'Total', 'Count'], rows, APP.school.memberPrefix + '_Financial_Report');
    }

    function exportXlsx(headers, rows, filename) {
        if (typeof XLSX === 'undefined') return toast('XLSX library not loaded', 'e');
        var ws = XLSX.utils.aoa_to_sheet([headers].concat(rows));
        ws['!cols'] = headers.map(function() { return { wch: 16 }; });
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Data');
        XLSX.writeFile(wb, filename + '.xlsx');
    }

    // ══════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════

    function num(n) {
        return parseFloat(n || 0).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function fDate(d) {
        if (!d) return '—';
        try { return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }); }
        catch (e) { return d; }
    }

    function statusBadge(s) {
        if (s === 'confirmed') return '<span class="badge badge-active">Confirmed</span>';
        if (s === 'pending') return '<span class="badge badge-warning">Pending</span>';
        return '<span class="badge badge-inactive">' + esc(s || '—') + '</span>';
    }

    // ══════════════════════════════════════════════════════════
    // BOOT
    // ══════════════════════════════════════════════════════════

    document.addEventListener('DOMContentLoaded', init);

    // Public API (for onclick handlers in HTML)
    return {
        nav: nav,
        openAddTxn: openAddTxn,
        saveTxn: saveTxn,
        deleteTxn: deleteTxn,
        openFeeModal: openFeeModal,
        loadFees: loadFees,
        saveFee: saveFee,
        openCatModal: openCatModal,
        saveCat: saveCat,
        loadReport: loadReport,
        exportTxns: exportTxns,
        exportReport: exportReport
    };

})();
