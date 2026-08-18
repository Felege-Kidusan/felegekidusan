/**
 * Education Teachers — one screen: person + login + class assignment.
 */
var Teachers = (function () {
    'use strict';

    var TAPI = '/admin/api_teachers.php';
    var AAPI = '/admin/api_assignments.php';
    var catalog = { classes: [], subjects: [] };
    var currentId = 0;
    var memberId = 0;
    var searchTimer = null;
    var existingAssign = [];

    function init() {
        var dept = document.querySelector('[data-school-dept-edu]');
        if (dept && window.APP && APP.school && APP.school.depts && APP.school.depts.edu) {
            dept.textContent = APP.school.depts.edu.am || APP.school.depts.edu.en || '';
        }
        var q = document.getElementById('teacherQ');
        if (q) q.addEventListener('input', function () { loadList(this.value); });
        var mq = document.getElementById('memberQ');
        if (mq) mq.addEventListener('input', function () { searchMembers(this.value); });
        document.querySelectorAll('input[name="teachRole"]').forEach(function (r) {
            r.addEventListener('change', syncRoleUi);
        });
        document.getElementById('roleChoice').addEventListener('click', function (e) {
            var lab = e.target.closest('label');
            if (!lab) return;
            document.querySelectorAll('#roleChoice label').forEach(function (l) { l.classList.remove('active'); });
            lab.classList.add('active');
        });
        loadCatalog();
        loadList('');
        if (new URLSearchParams(window.location.search).get('new') === '1') {
            startNew();
        }
    }

    function loadCatalog() {
        window.api.get(TAPI + '?action=get_available_for_assignment').then(function (d) {
            if (d.status !== 'success') return;
            catalog.classes = d.classes || [];
            catalog.subjects = d.subjects || [];
            fillCatalogSelects();
        }).catch(function () {});
    }

    function fillCatalogSelects() {
        var sub = document.getElementById('subjectId');
        if (sub) {
            sub.innerHTML = '<option value="">— Select subject —</option>' +
                catalog.subjects.map(function (s) {
                    return '<option value="' + s.id + '">' + esc(s.subject_name) + '</option>';
                }).join('');
        }
        var grid = document.getElementById('classGrid');
        if (grid) {
            grid.innerHTML = catalog.classes.map(function (c) {
                return '<label><input type="checkbox" class="cls-cb" value="' + c.id + '"> <span class="amharic">' + esc(c.class_name) + '</span></label>';
            }).join('') || '<span style="color:var(--school-text-dim)">No classes yet</span>';
        }
        var home = document.getElementById('homeroomClass');
        if (home) {
            home.innerHTML = '<option value="">— Select class —</option>' +
                catalog.classes.map(function (c) {
                    return '<option value="' + c.id + '">' + esc(c.class_name) + '</option>';
                }).join('');
        }
    }

    function loadList(q) {
        var url = TAPI + '?action=get_teachers&limit=80';
        if (q && q.trim()) url += '&q=' + encodeURIComponent(q.trim());
        window.api.get(url).then(function (d) {
            var box = document.getElementById('teacherList');
            if (!box) return;
            var list = d.teachers || [];
            if (!list.length) {
                box.innerHTML = '<div class="school-card" style="text-align:center;padding:2rem;color:var(--school-text-dim)">No teachers yet. Click Add Teacher.</div>';
                return;
            }
            box.innerHTML = list.map(function (t) {
                var initial = (t.full_name || '?').charAt(0).toUpperCase();
                var asg = (t.assigned_classes || 0) + ' class' + ((t.assigned_classes || 0) === 1 ? '' : 'es');
                return '<button type="button" class="t-card" onclick="Teachers.edit(' + t.id + ')">'
                    + '<div class="t-av">' + esc(initial) + '</div>'
                    + '<div style="flex:1;min-width:0">'
                    + '<div style="font-weight:700">' + esc(t.full_name) + '</div>'
                    + '<div class="t-meta">@' + esc(t.username || '') + (t.member_code ? ' · ' + esc(t.member_code) : '') + ' · ' + asg + '</div>'
                    + '</div>'
                    + '<span class="badge ' + (t.is_active == 1 ? 'badge-active' : 'badge-inactive') + '">' + (t.is_active == 1 ? 'Active' : 'Off') + '</span>'
                    + '</button>';
            }).join('');
        }).catch(function () {
            toast('Could not load teachers', 'e');
        });
    }

    function showList() {
        document.getElementById('view-list').style.display = 'block';
        document.getElementById('view-form').style.display = 'none';
        loadList(document.getElementById('teacherQ').value || '');
    }

    function showForm() {
        document.getElementById('view-list').style.display = 'none';
        document.getElementById('view-form').style.display = 'block';
        window.scrollTo(0, 0);
    }

    function startNew() {
        currentId = 0;
        memberId = 0;
        existingAssign = [];
        document.getElementById('formTitle').textContent = 'Add Teacher';
        document.getElementById('fullName').value = '';
        document.getElementById('username').value = '';
        document.getElementById('email').value = '';
        document.getElementById('password').value = '';
        document.getElementById('passwordLabel').textContent = 'Password *';
        document.getElementById('passwordHint').textContent = 'They will sign in with this password.';
        document.getElementById('memberQ').value = '';
        document.getElementById('memberHits').style.display = 'none';
        document.getElementById('memberPicked').textContent = 'Not linked — you can still type a name below.';
        document.getElementById('subjectId').value = '';
        document.querySelector('input[name="teachRole"][value="regular"]').checked = true;
        document.querySelectorAll('#roleChoice label').forEach(function (l) { l.classList.remove('active'); });
        document.querySelector('#roleChoice label').classList.add('active');
        toggleClasses(false);
        document.getElementById('homeroomClass').value = '';
        document.getElementById('currentAssign').innerHTML = '';
        syncRoleUi();
        showForm();
    }

    function edit(id) {
        window.api.get(TAPI + '?action=get_teacher&teacher_id=' + id).then(function (d) {
            if (d.status !== 'success' || !d.teacher) return toast(d.message || 'Not found', 'e');
            var t = d.teacher;
            currentId = t.id;
            memberId = t.member_id ? parseInt(t.member_id, 10) : 0;
            existingAssign = t.assignments || [];
            document.getElementById('formTitle').textContent = 'Edit Teacher';
            document.getElementById('fullName').value = t.full_name || '';
            document.getElementById('username').value = t.username || '';
            document.getElementById('email').value = t.email || '';
            document.getElementById('password').value = '';
            document.getElementById('passwordLabel').textContent = 'New password (leave blank to keep)';
            document.getElementById('passwordHint').textContent = 'Only fill this if you want to change their login password.';
            document.getElementById('memberQ').value = '';
            document.getElementById('memberHits').style.display = 'none';
            document.getElementById('memberPicked').textContent = memberId
                ? ('Linked to member ' + (t.member_code || ('#' + memberId)))
                : 'Not linked to a member record.';
            renderExisting();
            syncRoleUi();
            showForm();
        }).catch(function () { toast('Could not load teacher', 'e'); });
    }

    function renderExisting() {
        var box = document.getElementById('currentAssign');
        if (!existingAssign.length) {
            box.innerHTML = '';
            return;
        }
        box.innerHTML = '<div class="t-meta" style="margin-bottom:.4rem">Already assigned this year</div><div class="t-chips">' +
            existingAssign.map(function (a) {
                var label = esc(a.class_name || '') + (a.subject_name ? ' · ' + esc(a.subject_name) : '');
                return '<span class="asg-chip">' + label +
                    ' <button type="button" title="Remove" onclick="Teachers.removeAssign(' + a.id + ')">&times;</button></span>';
            }).join('') + '</div>';
    }

    function removeAssign(assignmentId) {
        if (!confirm('Remove this class assignment? Their login stays. Grades already entered stay.')) return;
        window.api.post(TAPI, { action: 'remove_assignment', assignment_id: assignmentId }).then(function (d) {
            toast(d.message || 'Removed', d.status === 'success' ? 's' : 'e');
            if (d.status === 'success' && currentId) edit(currentId);
        }).catch(function () { toast('Error', 'e'); });
    }

    function searchMembers(q) {
        clearTimeout(searchTimer);
        var hits = document.getElementById('memberHits');
        if (!q || q.trim().length < 1) {
            hits.style.display = 'none';
            return;
        }
        searchTimer = setTimeout(function () {
            window.api.get(TAPI + '?action=search_members_for_teacher&q=' + encodeURIComponent(q.trim()))
                .then(function (d) {
                    var list = d.members || [];
                    if (!list.length) {
                        hits.style.display = 'block';
                        hits.innerHTML = '<div class="t-hit" style="cursor:default;color:var(--school-text-dim)">No members found</div>';
                        return;
                    }
                    hits.style.display = 'block';
                    hits.innerHTML = list.map(function (m) {
                        return '<div class="t-hit" data-id="' + m.id + '" data-name="' + esc(m.student_name || '') + '" data-code="' + esc(m.member_code || '') + '">'
                            + '<strong>' + esc(m.student_name || '') + '</strong> '
                            + esc(m.father_name || '') +
                            (m.member_code ? ' <span class="t-meta">' + esc(m.member_code) + '</span>' : '') +
                            '</div>';
                    }).join('');
                    hits.querySelectorAll('.t-hit[data-id]').forEach(function (el) {
                        el.addEventListener('click', function () {
                            pickMember(parseInt(this.getAttribute('data-id'), 10), this.getAttribute('data-name') || '', this.getAttribute('data-code') || '');
                        });
                    });
                });
        }, 220);
    }

    function pickMember(id, name, code) {
        memberId = id;
        document.getElementById('fullName').value = name;
        document.getElementById('memberHits').style.display = 'none';
        document.getElementById('memberPicked').textContent = 'Linked to ' + name + (code ? ' (' + code + ')' : '');
        if (!document.getElementById('username').value) {
            var slug = (code || name).toLowerCase().replace(/[^a-z0-9]+/g, '').slice(0, 16);
            if (slug) document.getElementById('username').value = slug;
        }
    }

    function syncRoleUi() {
        var role = (document.querySelector('input[name="teachRole"]:checked') || {}).value || 'regular';
        document.getElementById('subjectWrap').style.display = role === 'homeroom' ? 'none' : 'block';
        document.getElementById('homeroomWrap').style.display = role === 'both' ? 'block' : 'none';
    }

    function toggleClasses(on) {
        document.querySelectorAll('.cls-cb').forEach(function (cb) { cb.checked = !!on; });
    }

    function selectedClassIds() {
        var ids = [];
        document.querySelectorAll('.cls-cb:checked').forEach(function (cb) { ids.push(parseInt(cb.value, 10)); });
        return ids;
    }

    function save() {
        var name = document.getElementById('fullName').value.trim();
        var user = document.getElementById('username').value.trim();
        var pass = document.getElementById('password').value;
        if (!name || !user) return toast('Full name and username are required', 'e');
        if (!currentId && pass.length < 4) return toast('Set a password so they can log in', 'e');

        var role = (document.querySelector('input[name="teachRole"]:checked') || {}).value || 'regular';
        var classIds = selectedClassIds();
        var subjectId = document.getElementById('subjectId').value;
        var duties = [];

        if (classIds.length) {
            if (role === 'homeroom') {
                duties.push({ type: 'homeroom', class_ids: classIds });
            } else if (role === 'regular') {
                if (!subjectId) return toast('Pick a subject for a regular teacher', 'e');
                duties.push({ type: 'regular', subject_id: subjectId, class_ids: classIds, role: 'primary' });
            } else {
                if (!subjectId) return toast('Pick a subject', 'e');
                duties.push({ type: 'regular', subject_id: subjectId, class_ids: classIds, role: 'primary' });
                var home = parseInt(document.getElementById('homeroomClass').value || '0', 10);
                if (home) duties.push({ type: 'homeroom', class_ids: [home] });
            }
        }

        var btn = document.getElementById('saveBtn');
        btn.disabled = true;
        window.api.post(TAPI, {
            action: 'save_teacher_bundle',
            teacher_id: currentId || '',
            member_id: memberId || '',
            full_name: name,
            username: user,
            email: document.getElementById('email').value.trim(),
            password: pass,
            duties: JSON.stringify(duties)
        }).then(function (d) {
            toast(d.message || (d.status === 'success' ? 'Saved' : 'Error'), d.status === 'success' ? 's' : 'e');
            if (d.status === 'success') {
                if (d.teacher_id) {
                    currentId = d.teacher_id;
                    document.getElementById('password').value = '';
                    edit(d.teacher_id);
                } else {
                    showList();
                }
            }
        }).catch(function () {
            toast('Could not save', 'e');
        }).then(function () {
            btn.disabled = false;
        });
    }

    document.addEventListener('DOMContentLoaded', init);

    return {
        startNew: startNew,
        showList: showList,
        edit: edit,
        save: save,
        toggleClasses: toggleClasses,
        removeAssign: removeAssign
    };
})();
