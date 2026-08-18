/**
 * Education Assignments — matrix, bulk assign, coverage.
 * Uses window.APP + window.api from core.js. No PHP echoes.
 */
var Asg = (function () {
    'use strict';

    var API = '/admin/api_assignments.php';
    var matrix = { classes: [], subjects: [], class_subjects: {}, cells: {}, homerooms: {}, year: null };
    var picker = { classId: 0, subjectId: 0, homeroom: false, teacherId: 0 };
    var bulkTeacherId = 0;
    var teacherTimer = null;

    function init() {
        var dept = document.querySelector('[data-school-dept-edu]');
        if (dept && window.APP && APP.school && APP.school.depts && APP.school.depts.edu) {
            dept.textContent = APP.school.depts.edu.am || APP.school.depts.edu.en || '';
        }
        var urlSection = new URLSearchParams(window.location.search).get('section');
        if (urlSection) nav(urlSection);

        document.querySelectorAll('.school-nav-link[data-section], .school-bottom-nav-btn[data-section]').forEach(function (btn) {
            btn.addEventListener('click', function () { nav(this.getAttribute('data-section')); });
        });

        var pq = document.getElementById('asgPickerQ');
        if (pq) pq.addEventListener('input', function () { searchPicker(this.value); });
        var bq = document.getElementById('bulkTeacherQ');
        if (bq) bq.addEventListener('input', function () { searchBulkTeacher(this.value); });
        var bs = document.getElementById('bulkSubject');
        if (bs) bs.addEventListener('change', syncBulkRole);

        loadAll();
    }

    function nav(name) {
        if (typeof switchSection === 'function') switchSection(name);
        document.querySelectorAll('.school-nav-link[data-section]').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-section') === name);
        });
        document.querySelectorAll('.school-bottom-nav-btn[data-section]').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-section') === name);
        });
        var u = new URL(window.location);
        u.searchParams.set('section', name);
        history.replaceState(null, '', u);
        if (name === 'coverage') loadCoverage();
        if (name === 'bulk') fillBulkForm();
    }

    function loadAll() {
        window.api.get(API + '?action=matrix').then(function (d) {
            if (d.status !== 'success') return toast(d.message || 'Could not load matrix', 'e');
            matrix = d;
            renderYear();
            renderMatrix();
            fillBulkForm();
        }).catch(function () { toast('Could not load assignments', 'e'); });
        window.api.get(API + '?action=gaps').then(renderGapBanner).catch(function () {});
    }

    function renderYear() {
        var el = document.getElementById('asgYearName');
        if (!el) return;
        el.textContent = (matrix.year && matrix.year.year_name) ? matrix.year.year_name : 'No active year';
    }

    function renderGapBanner(d) {
        var el = document.getElementById('asgGapsBanner');
        if (!el || !d || d.status !== 'success') return;
        var parts = [];
        if ((d.no_homeroom || []).length) parts.push(d.no_homeroom.length + ' class(es) have no Class Teacher');
        if ((d.uncovered_subjects || []).length) parts.push(d.uncovered_subjects.length + ' subject(s) have no teacher');
        if ((d.idle_teachers || []).length) parts.push(d.idle_teachers.length + ' teacher(s) have no assignment');
        if (!parts.length) {
            el.style.display = 'none';
            return;
        }
        el.style.display = 'block';
        el.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + parts.join(' · ')
            + ' <button class="btn-secondary btn-sm" style="margin-left:.5rem" onclick="Asg.nav(\'coverage\')">View</button>';
    }

    function subjectOffered(classId, subjectId) {
        var list = matrix.class_subjects && matrix.class_subjects[classId];
        if (!list || !list.length) return true;
        return list.indexOf(subjectId) !== -1 || list.indexOf(String(subjectId)) !== -1;
    }

    function renderMatrix() {
        var area = document.getElementById('asgMatrixArea');
        if (!area) return;
        var classes = matrix.classes || [];
        var subjects = matrix.subjects || [];
        if (!classes.length) {
            area.innerHTML = '<div class="school-card" style="text-align:center;padding:2rem;color:var(--school-text-dim)">No classes yet. Create classes in Education first.</div>';
            return;
        }
        if (!subjects.length) {
            area.innerHTML = '<div class="school-card" style="text-align:center;padding:2rem;color:var(--school-text-dim)">No subjects yet. Create subjects in Education first.</div>';
            return;
        }

        var html = '<div class="asg-matrix-wrap"><table class="asg-matrix"><thead><tr>';
        html += '<th class="sticky">Class</th><th>Class Teacher</th>';
        subjects.forEach(function (s) {
            html += '<th class="amharic">' + esc(s.subject_name) + '</th>';
        });
        html += '</tr></thead><tbody>';

        classes.forEach(function (c) {
            var home = (matrix.homerooms || {})[c.id] || (matrix.homerooms || {})[String(c.id)];
            html += '<tr><td class="sticky"><strong class="amharic">' + esc(c.class_name) + '</strong>'
                + (c.class_name_en ? '<div style="font-size:.65rem;color:var(--school-text-dim)">' + esc(c.class_name_en) + '</div>' : '')
                + '</td>';
            html += '<td><div class="asg-cell" onclick="Asg.openPicker(' + c.id + ',0,true)">'
                + (home ? chip(home, true) : '<span class="asg-empty">+ Class Teacher</span>')
                + '</div></td>';
            subjects.forEach(function (s) {
                var offered = subjectOffered(c.id, s.id);
                if (!offered) {
                    html += '<td style="opacity:.35"><div class="asg-cell" title="This class does not study this subject"><span class="asg-empty">—</span></div></td>';
                    return;
                }
                var key = c.id + '-' + s.id;
                var list = (matrix.cells && matrix.cells[key]) || [];
                html += '<td><div class="asg-cell" onclick="Asg.openPicker(' + c.id + ',' + s.id + ',false)">';
                if (list.length) {
                    list.forEach(function (t) { html += chip(t, false); });
                } else {
                    html += '<span class="asg-empty">+ Assign</span>';
                }
                html += '</div></td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        area.innerHTML = html;
    }

    function chip(t, isHome) {
        var role = t.role || (isHome ? 'homeroom' : 'primary');
        var cls = role === 'assistant' ? 'asg-chip-a' : 'asg-chip-p';
        var tag = role === 'assistant' ? 'Asst' : (role === 'homeroom' || isHome ? 'CT' : 'Pri');
        return '<span class="asg-chip ' + cls + '" onclick="event.stopPropagation()">'
            + esc(t.full_name || 'Teacher') + ' <small>' + tag + '</small>'
            + (role === 'assistant' ? ' <button title="Make primary" onclick="event.stopPropagation();Asg.makePrimary(' + t.id + ')">★</button>' : '')
            + ' <button title="Remove" onclick="event.stopPropagation();Asg.remove(' + t.id + ')">&times;</button></span>';
    }

    function openPicker(classId, subjectId, homeroom) {
        picker = { classId: classId, subjectId: subjectId, homeroom: !!homeroom, teacherId: 0 };
        var cls = (matrix.classes || []).find(function (c) { return Number(c.id) === Number(classId); });
        var sub = (matrix.subjects || []).find(function (s) { return Number(s.id) === Number(subjectId); });
        document.getElementById('asgPickerTitle').textContent = homeroom ? 'Set Class Teacher' : 'Assign teacher';
        document.getElementById('asgPickerSub').innerHTML = esc(cls ? cls.class_name : 'Class')
            + (homeroom ? ' · Class Teacher (no subject needed)' : ' · <span class="amharic">' + esc(sub ? sub.subject_name : '') + '</span>');
        document.getElementById('asgPickerRoleWrap').style.display = homeroom ? 'none' : 'block';
        document.getElementById('asgPickerQ').value = '';
        document.getElementById('asgPickerHits').innerHTML = '<div style="padding:.75rem;color:var(--school-text-dim);font-size:.8rem">Type a name to search</div>';
        document.getElementById('asgPickerSave').disabled = true;

        var currentHtml = '';
        if (homeroom) {
            var home = (matrix.homerooms || {})[classId];
            if (home) currentHtml = '<div style="font-size:.8rem">Current: ' + chip(home, true) + '</div>';
        } else {
            var list = (matrix.cells && matrix.cells[classId + '-' + subjectId]) || [];
            if (list.length) {
                currentHtml = '<div style="font-size:.8rem">Current: ';
                list.forEach(function (t) { currentHtml += chip(t, false); });
                currentHtml += '</div>';
            }
        }
        document.getElementById('asgPickerCurrent').innerHTML = currentHtml;
        modal('asgPicker', true);
        setTimeout(function () { document.getElementById('asgPickerQ').focus(); }, 50);
        searchPicker('');
    }

    function searchPicker(q) {
        clearTimeout(teacherTimer);
        teacherTimer = setTimeout(function () {
            window.api.get(API + '?action=teachers&q=' + encodeURIComponent(q || '') + '&page=1')
                .then(function (d) {
                    var hits = document.getElementById('asgPickerHits');
                    if (!hits) return;
                    var list = (d.teachers || []);
                    if (!list.length) {
                        hits.innerHTML = '<div style="padding:.75rem;color:var(--school-text-dim);font-size:.8rem">No teachers found</div>';
                        return;
                    }
                    hits.innerHTML = list.map(function (t) {
                        return '<div class="asg-teacher-hit" onclick="Asg.pickTeacher(' + t.id + ', this)">'
                            + '<strong>' + esc(t.full_name) + '</strong>'
                            + (t.username ? ' <span style="color:var(--school-text-dim);font-size:.72rem">@' + esc(t.username) + '</span>' : '')
                            + '</div>';
                    }).join('');
                });
        }, 220);
    }

    function pickTeacher(id, el) {
        picker.teacherId = id;
        document.querySelectorAll('#asgPickerHits .asg-teacher-hit').forEach(function (n) {
            n.style.background = '';
        });
        if (el) el.style.background = 'var(--school-accent-a20)';
        document.getElementById('asgPickerSave').disabled = false;
    }

    function confirmPicker() {
        if (!picker.teacherId) return toast('Pick a teacher', 'e');
        var payload = {
            teacher_id: picker.teacherId,
            class_id: picker.classId
        };
        if (picker.homeroom) {
            window.api.post(API, Object.assign({ action: 'set_homeroom' }, payload))
                .then(afterWrite).catch(function () { toast('Error', 'e'); });
            return;
        }
        payload.action = 'assign';
        payload.subject_id = picker.subjectId;
        payload.role = document.getElementById('asgPickerRole').value || 'primary';
        window.api.post(API, payload).then(afterWrite).catch(function () { toast('Error', 'e'); });
    }

    function afterWrite(d) {
        toast(d.message || (d.status === 'success' ? 'Saved' : 'Error'), d.status === 'success' ? 's' : 'e');
        if (d.status === 'success') {
            modal('asgPicker', false);
            loadAll();
        }
    }

    function remove(assignmentId) {
        if (!confirm('Remove this assignment? Grades already entered will stay.')) return;
        window.api.post(API, { action: 'unassign', assignment_id: assignmentId })
            .then(afterWrite).catch(function () { toast('Error', 'e'); });
    }

    function makePrimary(assignmentId) {
        window.api.post(API, { action: 'set_primary', assignment_id: assignmentId })
            .then(afterWrite).catch(function () { toast('Error', 'e'); });
    }

    function fillBulkForm() {
        var sel = document.getElementById('bulkSubject');
        if (sel && !(sel.options.length > 1 && sel.dataset.ready)) {
            sel.innerHTML = '<option value="">Class Teacher only (no subject)</option>'
                + (matrix.subjects || []).map(function (s) {
                    return '<option value="' + s.id + '">' + esc(s.subject_name) + '</option>';
                }).join('');
            sel.dataset.ready = '1';
        }
        var box = document.getElementById('bulkClasses');
        if (box) {
            box.innerHTML = (matrix.classes || []).map(function (c) {
                return '<label><input type="checkbox" class="bulk-class" value="' + c.id + '"> <span class="amharic">' + esc(c.class_name) + '</span></label>';
            }).join('') || '<span style="color:var(--school-text-dim)">No classes</span>';
        }
        syncBulkRole();
    }

    function syncBulkRole() {
        var sub = document.getElementById('bulkSubject');
        var role = document.getElementById('bulkRole');
        if (!sub || !role) return;
        var homeOnly = !sub.value;
        role.disabled = homeOnly;
        if (homeOnly) role.value = 'primary';
    }

    function searchBulkTeacher(q) {
        clearTimeout(teacherTimer);
        teacherTimer = setTimeout(function () {
            window.api.get(API + '?action=teachers&q=' + encodeURIComponent(q || '') + '&page=1')
                .then(function (d) {
                    var hits = document.getElementById('bulkTeacherHits');
                    var list = d.teachers || [];
                    if (!list.length) { hits.style.display = 'none'; return; }
                    hits.style.display = 'block';
                    hits.innerHTML = list.map(function (t) {
                        return '<div class="asg-teacher-hit" data-id="' + t.id + '" data-name="' + esc(t.full_name) + '">'
                            + '<strong>' + esc(t.full_name) + '</strong></div>';
                    }).join('');
                    hits.querySelectorAll('.asg-teacher-hit').forEach(function (el) {
                        el.addEventListener('click', function () {
                            pickBulkTeacher(parseInt(this.getAttribute('data-id'), 10), this.getAttribute('data-name') || '');
                        });
                    });
                });
        }, 220);
    }

    function pickBulkTeacher(id, name) {
        bulkTeacherId = id;
        document.getElementById('bulkTeacherPicked').innerHTML = 'Selected: <strong>' + esc(name) + '</strong>';
        document.getElementById('bulkTeacherHits').style.display = 'none';
        document.getElementById('bulkTeacherQ').value = name;
    }

    function toggleAllClasses(on) {
        document.querySelectorAll('.bulk-class').forEach(function (cb) { cb.checked = !!on; });
    }

    function saveBulk() {
        if (!bulkTeacherId) return toast('Pick a teacher', 'e');
        var ids = [];
        document.querySelectorAll('.bulk-class:checked').forEach(function (cb) { ids.push(parseInt(cb.value, 10)); });
        if (!ids.length) return toast('Tick at least one class', 'e');
        var subjectId = document.getElementById('bulkSubject').value;
        var role = subjectId ? (document.getElementById('bulkRole').value || 'primary') : 'homeroom';
        window.api.post(API, {
            action: 'assign_bulk',
            teacher_id: bulkTeacherId,
            subject_id: subjectId || '',
            role: role,
            class_ids: JSON.stringify(ids)
        }).then(function (d) {
            toast(d.message || 'Done', d.status === 'success' ? 's' : 'e');
            if (d.status === 'success') loadAll();
        }).catch(function () { toast('Error', 'e'); });
    }

    function loadCoverage() {
        window.api.get(API + '?action=gaps').then(function (d) {
            if (d.status !== 'success') return;
            renderGapBanner(d);
            var h = document.getElementById('gapHomeroom');
            var s = document.getElementById('gapSubjects');
            var i = document.getElementById('gapIdle');
            h.innerHTML = (d.no_homeroom || []).length
                ? d.no_homeroom.map(function (c) {
                    return '<div style="display:flex;justify-content:space-between;align-items:center;padding:.35rem 0;border-bottom:1px solid var(--school-border);font-size:.82rem">'
                        + '<span class="amharic">' + esc(c.class_name) + '</span>'
                        + '<button class="btn-secondary btn-sm" onclick="Asg.openPicker(' + c.class_id + ',0,true)">Assign</button></div>';
                }).join('')
                : '<p style="color:var(--school-success);font-size:.8rem">All classes have a Class Teacher.</p>';
            s.innerHTML = (d.uncovered_subjects || []).length
                ? d.uncovered_subjects.map(function (x) {
                    return '<div style="display:flex;justify-content:space-between;align-items:center;padding:.35rem 0;border-bottom:1px solid var(--school-border);font-size:.82rem">'
                        + '<span><span class="amharic">' + esc(x.class_name) + '</span> · <span class="amharic">' + esc(x.subject_name) + '</span></span>'
                        + '<button class="btn-secondary btn-sm" onclick="Asg.openPicker(' + x.class_id + ',' + x.subject_id + ',false)">Assign</button></div>';
                }).join('')
                : '<p style="color:var(--school-success);font-size:.8rem">Every offered subject has a teacher.</p>';
            i.innerHTML = (d.idle_teachers || []).length
                ? d.idle_teachers.map(function (t) {
                    return '<span class="asg-chip asg-chip-a">' + esc(t.full_name) + '</span>';
                }).join(' ')
                : '<p style="color:var(--school-success);font-size:.8rem">Every teacher has at least one assignment.</p>';
        });
        window.api.get(API + '?action=workload').then(function (d) {
            var box = document.getElementById('asgWorkStats');
            if (!box || d.status !== 'success') return;
            var list = d.teachers || [];
            var withWork = list.filter(function (t) { return t.assignments > 0; }).length;
            box.innerHTML =
                stat(list.length, 'Teachers') +
                stat(withWork, 'Assigned') +
                stat(list.length - withWork, 'Unassigned');
        });
    }

    function stat(n, label) {
        return '<div class="school-stat-card"><div class="school-stat-value">' + n + '</div><div class="school-stat-label">' + label + '</div></div>';
    }

    document.addEventListener('DOMContentLoaded', init);

    return {
        nav: nav,
        loadAll: loadAll,
        openPicker: openPicker,
        pickTeacher: pickTeacher,
        confirmPicker: confirmPicker,
        remove: remove,
        makePrimary: makePrimary,
        pickBulkTeacher: pickBulkTeacher,
        toggleAllClasses: toggleAllClasses,
        saveBulk: saveBulk
    };
})();
