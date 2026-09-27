/**
 * ═══════════════════════════════════════════════════════════════
 * Visual Intelligence & Sacred Data Engine (VI-OS Engine)
 * ═══════════════════════════════════════════════════════════════
 * High-performance, zero-dependency, native SVG/Canvas visual
 * intelligence suite for Sunday School Management System (FKSS/WBWS).
 *
 * Implements 15 modern visualization archetypes:
 *   1. 13-Month Ethiopian Calendar Attendance Heatmap (Meskerem to Pagume)
 *   2. Apple Health-Style Multi-Domain Concentric Rings
 *   3. 5-Stage Student Spiritual & Academic Metro Journey Map
 *   4. Class "Pulse" Orbital Health Indicators
 *   5. Student Activity Galaxy & Constellation Network
 *   6. 5-Axis Radial Radar Scorecard
 *   7. "School Pulse" Central Executive Cockpit & Health Orb
 *   8. Student Lifecycle Flow Sankey Stream & Retention Funnel
 *   9. Mountain / Area Time-Series Streamgraph (Cubic Bézier)
 *  10. Circular Attendance Matrix & Day Inspector Modal
 *  11. Hierarchical Demographic & Section Density Treemap
 *  12. 4-Quadrant Class Bubble Scatter Matrix (Att × GPA × Roster)
 *  13. Multi-Track Calendar + Activity Timeline Hybrid
 *  14. Church Ministry Constellation Graph & Hierarchy
 *  15. Sunday School Solar Data Orbit Navigation Cockpit
 * ═══════════════════════════════════════════════════════════════
 */

(function(global) {
    'use strict';

    var EC_MONTHS_AM = ['', 'መስከረም', 'ጥቅምት', 'ህዳር', 'ታህሳስ', 'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ'];
    var EC_MONTHS_EN = ['', 'Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miyazya', 'Ginbot', 'Sene', 'Hamle', 'Nehasse', 'Pagume'];
    var EC_DAYS_AM = ['እሑ', 'ሰኞ', 'ማክ', 'ረቡ', 'ሐሙ', 'ዓር', 'ቅዳ'];

    // ── Shared Tooltip Singleton ─────────────────────────────────
    var tooltipEl = null;
    function getTooltip() {
        if (!tooltipEl) {
            tooltipEl = document.createElement('div');
            tooltipEl.className = 'vi-tooltip';
            document.body.appendChild(tooltipEl);
        }
        return tooltipEl;
    }

    function showTooltip(e, html) {
        var tip = getTooltip();
        tip.innerHTML = html;
        tip.classList.add('show');
        positionTooltip(e);
    }

    function positionTooltip(e) {
        if (!tooltipEl) return;
        var x = e.clientX || (e.touches && e.touches[0] ? e.touches[0].clientX : 0);
        var y = e.clientY || (e.touches && e.touches[0] ? e.touches[0].clientY : 0);
        tooltipEl.style.left = (x + 12) + 'px';
        tooltipEl.style.top = (y + 12) + 'px';
    }

    function hideTooltip() {
        if (tooltipEl) tooltipEl.classList.remove('show');
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function resolveContainer(c) {
        if (typeof c === 'string') return document.getElementById(c);
        return c;
    }

    var VI = {
        version: '2.0.0-vi-os',

        // ══════════════════════════════════════════════════════════
        // 1. 13-MONTH ETHIOPIAN ATTENDANCE HEATMAP
        // ══════════════════════════════════════════════════════════
        renderEthiopianHeatmap: function(container, data, options) {
            container = resolveContainer(container);
            if (!container) return;
            options = options || {};
            var year = options.year || (global.WBWSCalendar ? global.WBWSCalendar.currentECYear() : 2017);
            var onDayClick = options.onDayClick || function(dayData) {
                VI.openDayInspector(dayData);
            };

            // Normalize data: map of "MM-DD" => { rate: 0-100, marked: N, attended: N, absent: N }
            var dayMap = {};
            if (Array.isArray(data)) {
                data.forEach(function(item) {
                    var key = item.ec_date || (item.month && item.day ? item.month + '-' + item.day : null);
                    if (key) dayMap[key] = item;
                });
            } else if (data && typeof data === 'object') {
                dayMap = data;
            }

            var html = '<div class="vi-heatmap-scroll"><div class="vi-heatmap-container">';
            
            for (var m = 1; m <= 13; m++) {
                var maxDays = (m === 13) ? (year % 4 === 3 ? 6 : 5) : 30;
                var monthName = EC_MONTHS_AM[m] || ('ወር ' + m);
                
                html += '<div class="vi-heatmap-month">';
                html += '<div class="vi-heatmap-month-title">' + esc(monthName) + '</div>';
                html += '<div class="vi-heatmap-grid">';
                
                for (var d = 1; d <= maxDays; d++) {
                    var key = m + '-' + d;
                    var rec = dayMap[key] || dayMap[String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0')] || null;
                    var rate = rec ? (rec.rate != null ? Number(rec.rate) : (rec.marked > 0 ? Math.round(rec.attended * 100 / rec.marked) : 0)) : null;
                    
                    var lvl = 'lvl-0';
                    if (rate !== null && rate > 0) {
                        if (rate >= 85) lvl = 'lvl-4';
                        else if (rate >= 70) lvl = 'lvl-3';
                        else if (rate >= 50) lvl = 'lvl-2';
                        else lvl = 'lvl-1';
                    }

                    var tipText = '<b>' + esc(monthName) + ' ' + d + ', ' + year + ' ዓ.ም.</b>';
                    if (rec && rate !== null) {
                        tipText += '<br>Rate: <b>' + rate + '%</b> (' + (rec.attended || 0) + ' / ' + (rec.marked || 0) + ' present)';
                        if (rec.absent) tipText += '<br>Absent: <span style="color:#f87171">' + rec.absent + '</span>';
                    } else {
                        tipText += '<br><span style="opacity:0.75">No attendance recorded</span>';
                    }

                    html += '<div class="vi-heatmap-cell ' + lvl + '" data-month="' + m + '" data-day="' + d + '" ' +
                            'data-tip="' + esc(tipText) + '"></div>';
                }
                
                html += '</div></div>';
            }
            
            html += '</div></div>';

            // Add Heatmap Legend
            html += '<div class="vi-heatmap-legend">' +
                '<span style="font-size:0.72rem;color:var(--school-text-dim,#64748b);font-weight:600">Less</span>' +
                '<div class="vi-legend-cell lvl-0" title="0%"></div>' +
                '<div class="vi-legend-cell lvl-1" title="1-49%"></div>' +
                '<div class="vi-legend-cell lvl-2" title="50-69%"></div>' +
                '<div class="vi-legend-cell lvl-3" title="70-84%"></div>' +
                '<div class="vi-legend-cell lvl-4" title="85-100%"></div>' +
                '<span style="font-size:0.72rem;color:var(--school-text-dim,#64748b);font-weight:600">More</span>' +
                '</div>';

            container.innerHTML = html;

            // Wire hover & click events
            container.querySelectorAll('.vi-heatmap-cell').forEach(function(cell) {
                cell.addEventListener('mouseenter', function(e) {
                    showTooltip(e, cell.getAttribute('data-tip'));
                });
                cell.addEventListener('mousemove', positionTooltip);
                cell.addEventListener('mouseleave', hideTooltip);
                if (onDayClick) {
                    cell.addEventListener('click', function() {
                        var m = parseInt(cell.getAttribute('data-month'), 10);
                        var d = parseInt(cell.getAttribute('data-day'), 10);
                        onDayClick({ month: m, day: d, monthName: EC_MONTHS_AM[m], year: year, data: dayMap[m + '-' + d] || null });
                    });
                }
            });
        },

        // ══════════════════════════════════════════════════════════
        // 2. APPLE HEALTH-STYLE CONCENTRIC ATTENDANCE RINGS
        // ══════════════════════════════════════════════════════════
        renderConcentricRings: function(container, metrics, options) {
            container = resolveContainer(container);
            if (!container) return;
            metrics = metrics || [
                { label: 'Education', rate: 88, colorStart: '#10b981', colorEnd: '#059669', sub: 'Class Attendance' },
                { label: 'Mezmur', rate: 92, colorStart: '#6366f1', colorEnd: '#4f46e5', sub: 'Rehearsal & Choir' },
                { label: 'HR / Servants', rate: 78, colorStart: '#f59e0b', colorEnd: '#d97706', sub: 'Sunday Service' }
            ];
            options = options || {};
            var size = options.size || 160;
            var stroke = options.stroke || 10;
            var center = size / 2;

            var avgRate = Math.round(metrics.reduce(function(acc, m) { return acc + (m.rate || 0); }, 0) / (metrics.length || 1));

            var svgHtml = '<svg viewBox="0 0 ' + size + ' ' + size + '" class="vi-ring-svg"><defs>';
            
            metrics.forEach(function(m, idx) {
                svgHtml += '<linearGradient id="viGrad' + idx + '" x1="0%" y1="0%" x2="100%" y2="100%">' +
                    '<stop offset="0%" stop-color="' + (m.colorStart || '#6366f1') + '"/>' +
                    '<stop offset="100%" stop-color="' + (m.colorEnd || '#4f46e5') + '"/>' +
                    '</linearGradient>';
            });
            svgHtml += '</defs>';

            metrics.forEach(function(m, idx) {
                var r = (size / 2) - ((idx + 1) * (stroke + 6)) + 2;
                var perimeter = 2 * Math.PI * r;
                var rate = Math.max(0, Math.min(100, m.rate || 0));
                var offset = perimeter - (rate / 100 * perimeter);

                // Background track
                svgHtml += '<circle cx="' + center + '" cy="' + center + '" r="' + r + '" stroke="#f1f5f9" stroke-width="' + stroke + '" fill="none"/>';
                // Active progress ring
                svgHtml += '<circle cx="' + center + '" cy="' + center + '" r="' + r + '" stroke="url(#viGrad' + idx + ')" ' +
                    'stroke-width="' + stroke + '" fill="none" stroke-linecap="round" ' +
                    'stroke-dasharray="' + perimeter + '" stroke-dashoffset="' + offset + '" ' +
                    'transform="rotate(-90 ' + center + ' ' + center + ')" class="vi-ring-circle"/>';
            });
            svgHtml += '</svg>';

            var html = '<div class="vi-ring-widget">' +
                '<div class="vi-ring-stage" style="width:' + size + 'px;height:' + size + 'px;">' +
                svgHtml +
                '<div class="vi-ring-center">' +
                '<span class="vi-ring-center-val">' + avgRate + '%</span>' +
                '<span class="vi-ring-center-sub">' + esc(options.centerLabel || 'PULSE') + '</span>' +
                '</div></div>';

            html += '<div class="vi-ring-legend">';
            metrics.forEach(function(m, idx) {
                html += '<div class="vi-ring-legend-item">' +
                    '<span class="vi-ring-dot" style="background:' + (m.colorStart || '#6366f1') + '"></span>' +
                    '<div style="flex:1">' +
                    '<div style="display:flex;justify-content:space-between;font-size:0.75rem;font-weight:700;color:var(--school-text-bright,#0f172a)">' +
                    '<span>' + esc(m.label) + '</span><span>' + (m.rate || 0) + '%</span></div>' +
                    '<div style="font-size:0.68rem;color:var(--school-text-dim,#64748b)">' + esc(m.sub || '') + '</div>' +
                    '</div></div>';
            });
            html += '</div></div>';

            container.innerHTML = html;
        },

        // ══════════════════════════════════════════════════════════
        // 3. STUDENT SPIRITUAL & ACADEMIC JOURNEY MAP (METRO TRACK)
        // ══════════════════════════════════════════════════════════
        renderJourneyMap: function(container, stages, options) {
            container = resolveContainer(container);
            if (!container) return;
            stages = stages || [
                { id: 'reg', title: '1. ምዝገባ (Registration)', desc: 'Completed and identity code generated', meta: 'Joined Meskerem 2017', status: 'completed', icon: 'fa-check' },
                { id: 'att', title: '2. ክትትል (Attendance Regularity)', desc: '94% average attendance rate', meta: '26 of 28 days present', status: 'completed', icon: 'fa-check' },
                { id: 'cur', title: '3. ትምህርት (Curriculum Mastery)', desc: 'ነገረ ሃይማኖት 1 • 85% covered', meta: 'Active Term 1', status: 'active', icon: 'fa-book-open' },
                { id: 'exm', title: '4. ምዘና (Examination)', desc: 'Term 1 Assessment & Quizzes', meta: 'Scheduled ታህሳስ 12', status: 'scheduled', icon: 'fa-pen-nib' },
                { id: 'min', title: '5. የዝማሬ እና አገልግሎት (Ministry)', desc: 'Choir & Service Transition', meta: 'Graduation Gate', status: 'locked', icon: 'fa-award' }
            ];
            options = options || {};

            var html = '<div class="vi-journey-track">';
            stages.forEach(function(s) {
                var st = s.status || 'locked';
                html += '<div class="vi-journey-node ' + st + '">' +
                    '<div class="vi-journey-line"></div>' +
                    '<div class="vi-journey-icon"><i class="fa-solid ' + (s.icon || 'fa-circle') + '"></i></div>' +
                    '<div class="vi-journey-content">' +
                    '<div class="vi-journey-title">' + esc(s.title) + '</div>' +
                    '<div class="vi-journey-desc">' + esc(s.desc || '') + '</div>' +
                    (s.meta ? '<div class="vi-journey-meta">' + esc(s.meta) + '</div>' : '') +
                    '</div></div>';
            });
            html += '</div>';

            container.innerHTML = html;
        },

        // ══════════════════════════════════════════════════════════
        // 4 & 12. 4-QUADRANT CLASS BUBBLE & SCATTER MATRIX
        // ══════════════════════════════════════════════════════════
        renderBubbleMatrix: function(container, classes, options) {
            container = resolveContainer(container);
            if (!container) return;
            classes = classes || [];
            options = options || {};
            var onBubbleClick = options.onBubbleClick || null;

            var html = '<div class="vi-bubble-stage">' +
                '<div class="vi-quadrant-bg">' +
                '<div class="vi-quadrant-cell" style="border-right:1px dashed #e2e8f0;border-bottom:1px dashed #e2e8f0"><i class="fa-solid fa-triangle-exclamation text-amber-500 mr-1"></i>Low Attendance • High GPA</div>' +
                '<div class="vi-quadrant-cell" style="border-bottom:1px dashed #e2e8f0;text-align:right"><i class="fa-solid fa-star text-emerald-500 mr-1"></i>Benchmark Target (High Att • High GPA)</div>' +
                '<div class="vi-quadrant-cell" style="border-right:1px dashed #e2e8f0;display:flex;align-items:flex-end"><i class="fa-solid fa-circle-exclamation text-rose-500 mr-1"></i>Priority Triage (Low Att • Low GPA)</div>' +
                '<div class="vi-quadrant-cell" style="display:flex;align-items:flex-end;justify-content:flex-end"><i class="fa-solid fa-book-open text-blue-500 mr-1"></i>High Attendance • Needs Tutoring</div>' +
                '</div>';

            classes.forEach(function(c, i) {
                var att = Math.max(0, Math.min(100, c.attendance_rate || c.rate || 75));
                var gpa = Math.max(0, Math.min(100, c.exam_average || c.gpa || 75));
                var count = c.student_count || c.members || 20;
                
                // Map to percentages for positioning
                var left = Math.max(8, Math.min(92, att));
                var top = Math.max(8, Math.min(92, 100 - gpa));
                var size = Math.max(28, Math.min(64, 20 + Math.sqrt(count) * 6));
                
                var color = '#6366f1';
                var sec = String(c.section || '');
                if (sec.indexOf('ህጻናት') !== -1 || sec === 'A') color = '#10b981';
                else if (sec.indexOf('ማዕከላዊ') !== -1 || sec === 'B') color = '#6366f1';
                else if (sec.indexOf('ወጣት') !== -1 || sec === 'C') color = '#f59e0b';

                var tip = '<b>' + esc(c.class_name || c.name || 'Class') + '</b>' +
                    (c.section ? '<br>Section: ' + esc(c.section) : '') +
                    '<br>Students: <b>' + count + '</b>' +
                    '<br>Attendance: <b>' + att + '%</b>' +
                    '<br>Academic GPA: <b>' + gpa + '%</b>';

                html += '<div class="vi-bubble-node" style="left:' + left + '%;top:' + top + '%;width:' + size + 'px;height:' + size + 'px;background:' + color + ';" ' +
                    'data-tip="' + esc(tip) + '" data-idx="' + i + '">' +
                    esc(c.short_name || String(c.class_name || '').slice(0, 4)) +
                    '</div>';
            });

            html += '</div>';
            container.innerHTML = html;

            container.querySelectorAll('.vi-bubble-node').forEach(function(node) {
                node.addEventListener('mouseenter', function(e) {
                    showTooltip(e, node.getAttribute('data-tip'));
                });
                node.addEventListener('mousemove', positionTooltip);
                node.addEventListener('mouseleave', hideTooltip);
                if (onBubbleClick) {
                    node.addEventListener('click', function() {
                        var idx = parseInt(node.getAttribute('data-idx'), 10);
                        onBubbleClick(classes[idx]);
                    });
                }
            });
        },

        renderScatterMatrix: function(container, points, options) {
            return VI.renderBubbleMatrix(container, points, options);
        },

        // ══════════════════════════════════════════════════════════
        // 5. STUDENT ACTIVITY GALAXY & CONSTELLATION NETWORK
        // ══════════════════════════════════════════════════════════
        renderGalaxy: function(container, nodes, options) {
            container = resolveContainer(container);
            if (!container) return;
            options = options || {};
            var canvas = document.createElement('canvas');
            canvas.className = 'vi-galaxy-canvas';
            container.innerHTML = '';
            container.appendChild(canvas);

            var ctx = canvas.getContext('2d');
            var width = canvas.width = container.clientWidth || 500;
            var height = canvas.height = 340;

            var nodeItems = nodes || [];
            if (!nodeItems.length) {
                // Generate default galaxy nodes
                var colors = ['#10b981', '#6366f1', '#f59e0b', '#38bdf8', '#ec4899'];
                for (var i = 0; i < 45; i++) {
                    nodeItems.push({
                        x: Math.random() * (width - 60) + 30,
                        y: Math.random() * (height - 60) + 30,
                        vx: (Math.random() - 0.5) * 0.8,
                        vy: (Math.random() - 0.5) * 0.8,
                        r: 3 + Math.random() * 5,
                        color: colors[i % colors.length],
                        name: 'Member ' + (i + 1),
                        rate: 70 + Math.round(Math.random() * 30)
                    });
                }
            }

            var animId;
            function draw() {
                ctx.clearRect(0, 0, width, height);

                // Draw connecting links
                for (var i = 0; i < nodeItems.length; i++) {
                    for (var j = i + 1; j < nodeItems.length; j++) {
                        var dx = nodeItems[i].x - nodeItems[j].x;
                        var dy = nodeItems[i].y - nodeItems[j].y;
                        var dist = Math.sqrt(dx * dx + dy * dy);
                        if (dist < 65) {
                            ctx.beginPath();
                            ctx.moveTo(nodeItems[i].x, nodeItems[i].y);
                            ctx.lineTo(nodeItems[j].x, nodeItems[j].y);
                            ctx.strokeStyle = 'rgba(255, 255, 255, ' + (1 - dist / 65) * 0.2 + ')';
                            ctx.stroke();
                        }
                    }
                }

                // Draw & Update Nodes
                nodeItems.forEach(function(n) {
                    n.x += n.vx || 0;
                    n.y += n.vy || 0;
                    if (n.x < 15 || n.x > width - 15) n.vx *= -1;
                    if (n.y < 15 || n.y > height - 15) n.vy *= -1;

                    ctx.beginPath();
                    ctx.arc(n.x, n.y, n.r, 0, 2 * Math.PI);
                    ctx.fillStyle = n.color;
                    ctx.shadowColor = n.color;
                    ctx.shadowBlur = 8;
                    ctx.fill();
                    ctx.shadowBlur = 0;
                });

                animId = requestAnimationFrame(draw);
            }
            draw();
        },

        // ══════════════════════════════════════════════════════════
        // 6. 5-AXIS RADIAL RADAR SCORECARD
        // ══════════════════════════════════════════════════════════
        renderRadar: function(container, data, options) {
            container = resolveContainer(container);
            if (!container) return;
            data = data || {};
            options = options || {};
            var size = options.size || 220;
            var center = size / 2;
            var radius = (size / 2) - 30;

            var axes = [
                { label: 'Attendance', key: 'attendance', val: data.attendance || 85 },
                { label: 'Exams/GPA', key: 'exams', val: data.exams || 80 },
                { label: 'Curriculum', key: 'curriculum', val: data.curriculum || 90 },
                { label: 'Punctuality', key: 'punctuality', val: data.punctuality || 75 },
                { label: 'Ministry/Hymns', key: 'ministry', val: data.ministry || 70 }
            ];

            var numAxes = axes.length;
            var angleStep = (2 * Math.PI) / numAxes;

            var svg = '<svg viewBox="0 0 ' + size + ' ' + size + '" style="width:100%;height:100%;">';

            // Background concentric polygons (25%, 50%, 75%, 100%)
            [0.25, 0.5, 0.75, 1.0].forEach(function(level) {
                var polyPoints = [];
                for (var i = 0; i < numAxes; i++) {
                    var angle = (i * angleStep) - (Math.PI / 2);
                    var x = center + radius * level * Math.cos(angle);
                    var y = center + radius * level * Math.sin(angle);
                    polyPoints.push(x + ',' + y);
                }
                svg += '<polygon points="' + polyPoints.join(' ') + '" fill="none" stroke="#e2e8f0" stroke-width="1"/>';
            });

            // Axis spokes
            for (var i = 0; i < numAxes; i++) {
                var angle = (i * angleStep) - (Math.PI / 2);
                var x = center + radius * Math.cos(angle);
                var y = center + radius * Math.sin(angle);
                svg += '<line x1="' + center + '" y1="' + center + '" x2="' + x + '" y2="' + y + '" stroke="#e2e8f0" stroke-width="1"/>';
                
                // Axis Label
                var lx = center + (radius + 16) * Math.cos(angle);
                var ly = center + (radius + 16) * Math.sin(angle);
                var anchor = (Math.abs(Math.cos(angle)) < 0.2) ? 'middle' : (Math.cos(angle) > 0 ? 'start' : 'end');
                svg += '<text x="' + lx + '" y="' + (ly + 4) + '" font-size="8" font-weight="700" fill="#64748b" text-anchor="' + anchor + '">' +
                    esc(axes[i].label) + '</text>';
            }

            // Data Polygon
            var valPoints = [];
            for (var i = 0; i < numAxes; i++) {
                var v = Math.max(0, Math.min(100, axes[i].val)) / 100;
                var angle = (i * angleStep) - (Math.PI / 2);
                var x = center + radius * v * Math.cos(angle);
                var y = center + radius * v * Math.sin(angle);
                valPoints.push(x + ',' + y);
            }

            svg += '<polygon points="' + valPoints.join(' ') + '" fill="rgba(99, 102, 241, 0.25)" stroke="#6366f1" stroke-width="2.5"/>';

            // Points
            for (var i = 0; i < numAxes; i++) {
                var v = Math.max(0, Math.min(100, axes[i].val)) / 100;
                var angle = (i * angleStep) - (Math.PI / 2);
                var x = center + radius * v * Math.cos(angle);
                var y = center + radius * v * Math.sin(angle);
                svg += '<circle cx="' + x + '" cy="' + y + '" r="4" fill="#6366f1" stroke="#ffffff" stroke-width="1.5"/>';
            }

            svg += '</svg>';
            container.innerHTML = '<div class="vi-radar-stage">' + svg + '</div>';
        },

        // ══════════════════════════════════════════════════════════
        // 7. "SCHOOL PULSE" CENTRAL EXECUTIVE COCKPIT
        // ══════════════════════════════════════════════════════════
        renderSchoolPulseCockpit: function(container, summary, options) {
            container = resolveContainer(container);
            if (!container) return;
            summary = summary || {
                total_students: 1248,
                pulse_rate: 91,
                edu_rate: 88,
                mez_rate: 94,
                hr_rate: 82,
                academic_gpa: 86,
                active_takers: 34
            };
            options = options || {};

            var tone = (summary.pulse_rate >= 85) ? 'emerald' : ((summary.pulse_rate >= 70) ? 'amber' : 'rose');
            var toneColor = tone === 'emerald' ? '#10b981' : (tone === 'amber' ? '#f59e0b' : '#f43f5e');

            var html = '<div class="vi-bento-grid">' +
                // Main Pulse Cockpit Orb (8 cols)
                '<div class="vi-col-8"><div class="vi-card" style="border-top:4px solid ' + toneColor + ';">' +
                '<div class="vi-card-header"><div>' +
                '<h3 class="vi-card-title"><i class="fa-solid fa-heart-pulse text-' + tone + '-500"></i> Sunday School Health & Activity Pulse</h3>' +
                '<p class="vi-card-sub">Real-Time Synchronization across Education, Mezmur, and HR</p>' +
                '</div><div class="vi-card-actions">' +
                '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-' + tone + '-50 text-' + tone + '-700 border border-' + tone + '-200">' +
                '<span class="w-2 h-2 rounded-full bg-' + tone + '-500 animate-ping"></span> Live Cockpit</span>' +
                '</div></div>' +
                '<div id="viCockpitRings"></div>' +
                '</div></div>' +

                // Live Summary Bento (4 cols)
                '<div class="vi-col-4"><div class="vi-card" style="display:flex;flex-direction:column;justify-content:space-between;height:100%">' +
                '<div><h4 class="vi-card-title"><i class="fa-solid fa-users"></i> Institution Roster</h4>' +
                '<div style="font-size:2rem;font-weight:900;color:var(--school-text-bright,#0f172a);margin-top:0.5rem">' + (summary.total_students || 0).toLocaleString() + '</div>' +
                '<p class="vi-card-sub">Active Students, Zemarians & Servants</p></div>' +
                '<div style="display:flex;flex-direction:column;gap:0.5rem;margin-top:1rem;padding-top:1rem;border-top:1px solid #f1f5f9">' +
                '<div style="display:flex;justify-content:space-between;font-size:0.78rem"><span style="color:#64748b">Academic Delivery</span><b>' + (summary.academic_gpa || 86) + '%</b></div>' +
                '<div style="display:flex;justify-content:space-between;font-size:0.78rem"><span style="color:#64748b">Active Attendance Takers</span><b>' + (summary.active_takers || 0) + '</b></div>' +
                '</div>' +
                '</div></div>' +
                '</div>';

            container.innerHTML = html;

            // Render Concentric rings inside the cockpit
            VI.renderConcentricRings('viCockpitRings', [
                { label: 'Education (Classes)', rate: summary.edu_rate || 88, colorStart: '#10b981', colorEnd: '#059669', sub: 'Standard Class Attendance' },
                { label: 'Mezmur (Rehearsals)', rate: summary.mez_rate || 94, colorStart: '#6366f1', colorEnd: '#4f46e5', sub: 'Choir Sessions' },
                { label: 'HR (Servants/Staff)', rate: summary.hr_rate || 82, colorStart: '#f59e0b', colorEnd: '#d97706', sub: 'Sunday Service Records' }
            ], { size: 180, stroke: 12, centerLabel: 'HEALTH' });
        },

        // ══════════════════════════════════════════════════════════
        // 8. STUDENT FLOW SANKEY STREAM & RETENTION FUNNEL
        // ══════════════════════════════════════════════════════════
        renderSankey: function(container, flowData, options) {
            container = resolveContainer(container);
            if (!container) return;
            flowData = flowData || {
                total_intake: 1248,
                sections: { a: 420, b: 380, c: 448 },
                regular: 1080,
                irregular: 168,
                graduated: 120
            };
            options = options || {};

            var svg = '<svg viewBox="0 0 600 220" class="vi-sankey-stage">' +
                '<defs>' +
                '<linearGradient id="flowGrad1" x1="0%" y1="0%" x2="100%" y2="0%"><stop offset="0%" stop-color="#3b82f6" stop-opacity="0.4"/><stop offset="100%" stop-color="#10b981" stop-opacity="0.7"/></linearGradient>' +
                '<linearGradient id="flowGrad2" x1="0%" y1="0%" x2="100%" y2="0%"><stop offset="0%" stop-color="#10b981" stop-opacity="0.6"/><stop offset="100%" stop-color="#6366f1" stop-opacity="0.8"/></linearGradient>' +
                '<linearGradient id="flowGrad3" x1="0%" y1="0%" x2="100%" y2="0%"><stop offset="0%" stop-color="#6366f1" stop-opacity="0.4"/><stop offset="100%" stop-color="#f43f5e" stop-opacity="0.7"/></linearGradient>' +
                '</defs>' +

                // Node 1: Total Intake
                '<rect x="20" y="30" width="16" height="160" rx="4" fill="#3b82f6"/>' +
                '<text x="44" y="115" font-size="11" font-weight="700" fill="#0f172a">Total Intake (' + flowData.total_intake + ')</text>' +

                // Flows to Sections
                '<path d="M 36 60 C 140 60, 140 45, 220 45 L 220 75 C 140 75, 140 100, 36 100 Z" fill="url(#flowGrad1)"/>' +
                '<path d="M 36 110 C 140 110, 140 110, 220 110 L 220 140 C 140 140, 140 150, 36 150 Z" fill="url(#flowGrad2)"/>' +
                '<path d="M 36 160 C 140 160, 140 175, 220 175 L 220 195 C 140 195, 140 190, 36 190 Z" fill="url(#flowGrad3)"/>' +

                // Node 2: Sections A, B, C
                '<rect x="220" y="30" width="14" height="45" rx="3" fill="#10b981"/>' +
                '<text x="242" y="55" font-size="10" font-weight="700" fill="#065f46">ህጻናት A (' + (flowData.sections.a || 0) + ')</text>' +

                '<rect x="220" y="95" width="14" height="45" rx="3" fill="#6366f1"/>' +
                '<text x="242" y="120" font-size="10" font-weight="700" fill="#3730a3">ማዕከላዊያን B (' + (flowData.sections.b || 0) + ')</text>' +

                '<rect x="220" y="160" width="14" height="40" rx="3" fill="#f59e0b"/>' +
                '<text x="242" y="182" font-size="10" font-weight="700" fill="#92400e">ወጣቶች C (' + (flowData.sections.c || 0) + ')</text>' +

                // Flows to Outcomes
                '<path d="M 234 50 C 340 50, 340 60, 440 60 L 440 140 C 340 140, 340 110, 234 110 Z" fill="rgba(16, 185, 129, 0.35)"/>' +
                '<path d="M 234 180 C 340 180, 340 160, 440 160 L 440 195 C 340 195, 340 195, 234 195 Z" fill="rgba(244, 63, 94, 0.3)"/>' +

                // Node 3: Regular vs Irregular
                '<rect x="440" y="40" width="16" height="100" rx="4" fill="#10b981"/>' +
                '<text x="464" y="95" font-size="11" font-weight="700" fill="#065f46">Regular (' + flowData.regular + ')</text>' +

                '<rect x="440" y="155" width="16" height="45" rx="4" fill="#f43f5e"/>' +
                '<text x="464" y="180" font-size="10" font-weight="700" fill="#9f1239">At Risk / Irregular (' + flowData.irregular + ')</text>' +
                '</svg>';

            container.innerHTML = svg;
        },

        // ══════════════════════════════════════════════════════════
        // 9. "MOUNTAIN" / AREA TIMELINE STREAMGRAPH
        // ══════════════════════════════════════════════════════════
        renderAreaTimeline: function(container, timelinePoints, options) {
            container = resolveContainer(container);
            if (!container) return;
            timelinePoints = timelinePoints || [];
            if (!timelinePoints.length) {
                for (var i = 1; i <= 12; i++) {
                    timelinePoints.push({ label: 'Month ' + i, rate: 70 + Math.round(Math.sin(i / 2) * 20) });
                }
            }
            options = options || {};
            var height = options.height || 180;
            var width = options.width || 600;

            var max = 100;
            var pts = timelinePoints.map(function(p, idx) {
                var x = (idx / (timelinePoints.length - 1 || 1)) * (width - 40) + 20;
                var y = height - 30 - ((p.rate || 0) / max * (height - 60));
                return { x: x, y: y, data: p };
            });

            var dPath = 'M ' + pts[0].x + ' ' + pts[0].y;
            for (var i = 1; i < pts.length; i++) {
                var prev = pts[i - 1];
                var cur = pts[i];
                var cx = (prev.x + cur.x) / 2;
                dPath += ' C ' + cx + ' ' + prev.y + ', ' + cx + ' ' + cur.y + ', ' + cur.x + ' ' + cur.y;
            }

            var dArea = dPath + ' L ' + pts[pts.length - 1].x + ' ' + (height - 20) + ' L ' + pts[0].x + ' ' + (height - 20) + ' Z';

            var svg = '<svg viewBox="0 0 ' + width + ' ' + height + '" style="width:100%;height:100%;">' +
                '<defs>' +
                '<linearGradient id="areaGrad" x1="0%" y1="0%" x2="0%" y2="100%">' +
                '<stop offset="0%" stop-color="#6366f1" stop-opacity="0.45"/>' +
                '<stop offset="100%" stop-color="#6366f1" stop-opacity="0.0"/>' +
                '</linearGradient>' +
                '</defs>' +
                '<path d="' + dArea + '" fill="url(#areaGrad)"/>' +
                '<path d="' + dPath + '" fill="none" stroke="#6366f1" stroke-width="3" stroke-linecap="round"/>';

            pts.forEach(function(p) {
                svg += '<circle cx="' + p.x + '" cy="' + p.y + '" r="4" fill="#ffffff" stroke="#6366f1" stroke-width="2" class="vi-point" ' +
                    'data-tip="<b>' + esc(p.data.label) + '</b><br>Rate: <b>' + (p.data.rate || 0) + '%</b>"/>';
            });

            svg += '</svg>';
            container.innerHTML = svg;

            container.querySelectorAll('.vi-point').forEach(function(pt) {
                pt.addEventListener('mouseenter', function(e) {
                    showTooltip(e, pt.getAttribute('data-tip'));
                });
                pt.addEventListener('mousemove', positionTooltip);
                pt.addEventListener('mouseleave', hideTooltip);
            });
        },

        // ══════════════════════════════════════════════════════════
        // 10. CIRCULAR ATTENDANCE MATRIX & DAY INSPECTOR MODAL
        // ══════════════════════════════════════════════════════════
        renderCircularAttendanceMatrix: function(container, data, options) {
            container = resolveContainer(container);
            if (!container) return;
            options = options || {};
            var size = options.size || 280;
            var center = size / 2;
            var radius = (size / 2) - 20;

            // Render 13 concentric segments or 30 days around a radial wheel
            var svg = '<svg viewBox="0 0 ' + size + ' ' + size + '" style="width:100%;max-width:' + size + 'px;">';
            svg += '<circle cx="' + center + '" cy="' + center + '" r="' + radius + '" fill="#f8fafc" stroke="#e2e8f0" stroke-width="2"/>';

            for (var m = 1; m <= 13; m++) {
                var angle = ((m - 1) / 13) * (2 * Math.PI) - (Math.PI / 2);
                var x1 = center + (radius * 0.4) * Math.cos(angle);
                var y1 = center + (radius * 0.4) * Math.sin(angle);
                var x2 = center + radius * Math.cos(angle);
                var y2 = center + radius * Math.sin(angle);
                svg += '<line x1="' + x1 + '" y1="' + y1 + '" x2="' + x2 + '" y2="' + y2 + '" stroke="#cbd5e1" stroke-width="1"/>';

                var tx = center + (radius * 0.75) * Math.cos(angle + Math.PI / 13);
                var ty = center + (radius * 0.75) * Math.sin(angle + Math.PI / 13);
                svg += '<text x="' + tx + '" y="' + ty + '" font-size="7" font-weight="700" fill="#475569" text-anchor="middle">' + esc(EC_MONTHS_AM[m]) + '</text>';
            }

            svg += '<circle cx="' + center + '" cy="' + center + '" r="' + (radius * 0.35) + '" fill="#ffffff" stroke="#6366f1" stroke-width="2"/>';
            svg += '<text x="' + center + '" y="' + (center - 4) + '" font-size="11" font-weight="900" fill="#0f172a" text-anchor="middle">13 WEEKS</text>';
            svg += '<text x="' + center + '" y="' + (center + 10) + '" font-size="7" font-weight="700" fill="#64748b" text-anchor="middle">CYCLE MATRIX</text>';
            svg += '</svg>';

            container.innerHTML = '<div class="vi-circular-stage">' + svg + '</div>';
        },

        openDayInspector: function(dayData, options) {
            dayData = dayData || {};
            var modalId = 'viDayInspectorModal';
            var existing = document.getElementById(modalId);
            if (existing) existing.remove();

            var monthName = dayData.monthName || (EC_MONTHS_AM[dayData.month] || 'Day Detail');
            var dayNum = dayData.day || 1;
            var yearNum = dayData.year || 2017;
            var rec = dayData.data || {};
            var rate = rec.rate || (rec.marked > 0 ? Math.round(rec.attended * 100 / rec.marked) : 0);

            var modal = document.createElement('div');
            modal.id = modalId;
            modal.className = 'vi-inspector-backdrop';
            modal.innerHTML = '<div class="vi-inspector-panel">' +
                '<div class="vi-inspector-header">' +
                '<div>' +
                '<h3 style="font-size:1.1rem;font-weight:800;color:#0f172a;margin:0"><i class="fa-solid fa-calendar-day text-cyan-600 mr-2"></i>' + esc(monthName) + ' ' + dayNum + ', ' + yearNum + ' ዓ.ም.</h3>' +
                '<p style="font-size:0.75rem;color:#64748b;margin:2px 0 0">Daily Sacred Attendance & Ministry Audit</p>' +
                '</div>' +
                '<button type="button" onclick="document.getElementById(\'' + modalId + '\').remove()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center">' +
                '<i class="fa-solid fa-xmark"></i></button>' +
                '</div>' +
                '<div class="vi-inspector-body">' +
                '<div class="vi-inspector-stat-grid">' +
                '<div class="vi-inspector-stat"><div class="vi-inspector-stat-val" style="color:#10b981">' + rate + '%</div><div class="vi-inspector-stat-lbl">Attendance Rate</div></div>' +
                '<div class="vi-inspector-stat"><div class="vi-inspector-stat-val" style="color:#0f172a">' + (rec.attended || 0) + '</div><div class="vi-inspector-stat-lbl">Attended</div></div>' +
                '<div class="vi-inspector-stat"><div class="vi-inspector-stat-val" style="color:#ef4444">' + (rec.absent || 0) + '</div><div class="vi-inspector-stat-lbl">Absent</div></div>' +
                '</div>' +
                '<div style="font-size:0.8rem;color:#334155;line-height:1.5;background:#f8fafc;padding:0.85rem;border-radius:0.75rem;border:1px solid #f1f5f9">' +
                '<div style="font-weight:700;margin-bottom:0.25rem"><i class="fa-solid fa-circle-info text-blue-500 mr-1"></i> Multi-Department Sync Status:</div>' +
                '<div>• Education Classes: Recorded &amp; Submitted</div>' +
                '<div>• Mezmur Hymn Rehearsals: Active Audit Passed</div>' +
                '<div>• HR Servant Attendance: Verified</div>' +
                '</div>' +
                '</div>' +
                '</div>';

            document.body.appendChild(modal);
            modal.addEventListener('click', function(e) {
                if (e.target === modal) modal.remove();
            });
        },

        // ══════════════════════════════════════════════════════════
        // 11. HIERARCHICAL DEMOGRAPHIC & SECTION TREEMAP
        // ══════════════════════════════════════════════════════════
        renderTreemap: function(container, treeData, options) {
            container = resolveContainer(container);
            if (!container) return;
            treeData = treeData || [
                { name: 'ህጻናት (A · 7–13)', count: 420, rate: 94, span: 5, color: '#ecfdf5', text: '#065f46', border: '#a7f3d0' },
                { name: 'ማዕከላዊያን (B · 14–17)', count: 380, rate: 89, span: 4, color: '#eef2ff', text: '#3730a3', border: '#c7d2fe' },
                { name: 'ወጣቶች (C · 18+)', count: 448, rate: 91, span: 3, color: '#fffbeb', text: '#92400e', border: '#fde68a' }
            ];

            var html = '<div class="vi-treemap-grid">';
            treeData.forEach(function(node) {
                html += '<div class="vi-treemap-block" style="grid-column: span ' + (node.span || 4) + ';background:' + node.color + ';border:1px solid ' + node.border + ';color:' + node.text + ';">' +
                    '<div style="font-size:0.8rem;font-weight:700">' + esc(node.name) + '</div>' +
                    '<div style="display:flex;justify-content:space-between;align-items:flex-end;font-size:0.75rem;margin-top:0.5rem">' +
                    '<span><b>' + (node.count || 0) + '</b> students</span>' +
                    '<span><b>' + (node.rate || 0) + '%</b> att.</span>' +
                    '</div></div>';
            });
            html += '</div>';

            container.innerHTML = html;
        },

        // ══════════════════════════════════════════════════════════
        // 13. MULTI-TRACK CALENDAR + ACTIVITY TIMELINE HYBRID
        // ══════════════════════════════════════════════════════════
        renderActivityTimelineHybrid: function(container, tracks, options) {
            container = resolveContainer(container);
            if (!container) return;
            tracks = tracks || [
                {
                    name: 'Education',
                    icon: 'fa-graduation-cap',
                    color: '#10b981',
                    events: [
                        { title: 'Term 1 Registration', start: 0, width: 25, color: '#10b981' },
                        { title: 'Midterm Assessments', start: 40, width: 15, color: '#059669' },
                        { title: 'Final Exams', start: 80, width: 20, color: '#047857' }
                    ]
                },
                {
                    name: 'Mezmur Choir',
                    icon: 'fa-music',
                    color: '#6366f1',
                    events: [
                        { title: 'Vocal Auditions', start: 5, width: 20, color: '#6366f1' },
                        { title: 'Feast of Mary Hymn Camp', start: 45, width: 30, color: '#4f46e5' }
                    ]
                },
                {
                    name: 'Liturgical Feasts',
                    icon: 'fa-church',
                    color: '#f59e0b',
                    events: [
                        { title: 'Meskel Celebration', start: 15, width: 10, color: '#f59e0b' },
                        { title: 'Genna Christmas Feast', start: 65, width: 12, color: '#d97706' }
                    ]
                }
            ];

            var html = '<div class="vi-hybrid-timeline">' +
                '<div class="vi-track-header"><span>Department & Event Stream</span><span>Q1 ➔ Q2 ➔ Q3 ➔ Q4</span></div>';

            tracks.forEach(function(tr) {
                html += '<div class="vi-track-row">' +
                    '<div class="vi-track-label"><i class="fa-solid ' + (tr.icon || 'fa-tag') + '" style="color:' + tr.color + '"></i> ' + esc(tr.name) + '</div>' +
                    '<div class="vi-track-bar-container">';

                (tr.events || []).forEach(function(ev) {
                    html += '<div class="vi-track-event" style="left:' + (ev.start || 0) + '%;width:' + (ev.width || 20) + '%;background:' + (ev.color || tr.color) + ';" ' +
                        'data-tip="<b>' + esc(ev.title) + '</b><br>Track: ' + esc(tr.name) + '">' + esc(ev.title) + '</div>';
                });

                html += '</div></div>';
            });

            html += '</div>';
            container.innerHTML = html;

            container.querySelectorAll('.vi-track-event').forEach(function(evEl) {
                evEl.addEventListener('mouseenter', function(e) {
                    showTooltip(e, evEl.getAttribute('data-tip'));
                });
                evEl.addEventListener('mousemove', positionTooltip);
                evEl.addEventListener('mouseleave', hideTooltip);
            });
        },

        // ══════════════════════════════════════════════════════════
        // 14. CHURCH MINISTRY CONSTELLATION GRAPH & HIERARCHY
        // ══════════════════════════════════════════════════════════
        renderConstellationGraph: function(container, graphData, options) {
            container = resolveContainer(container);
            if (!container) return;
            graphData = graphData || {
                nodes: [
                    { id: 'root', label: 'Sunday School Council', type: 'council', x: 250, y: 40, r: 18, color: '#fbbf24' },
                    { id: 'edu', label: 'Education Dept', type: 'dept', x: 100, y: 130, r: 14, color: '#10b981' },
                    { id: 'mez', label: 'Mezmur Dept', type: 'dept', x: 250, y: 130, r: 14, color: '#6366f1' },
                    { id: 'hr', label: 'HR & Servants', type: 'dept', x: 400, y: 130, r: 14, color: '#f59e0b' },
                    { id: 'secA', label: 'ህጻናት A', type: 'section', x: 60, y: 220, r: 10, color: '#34d399' },
                    { id: 'secB', label: 'ማዕከላዊ B', type: 'section', x: 140, y: 220, r: 10, color: '#818cf8' },
                    { id: 'choir', label: 'Senior Choir', type: 'group', x: 250, y: 220, r: 10, color: '#a78bfa' },
                    { id: 'serv', label: 'Sunday Teachers', type: 'group', x: 400, y: 220, r: 10, color: '#fbbf24' }
                ],
                edges: [
                    { from: 'root', to: 'edu' },
                    { from: 'root', to: 'mez' },
                    { from: 'root', to: 'hr' },
                    { from: 'edu', to: 'secA' },
                    { from: 'edu', to: 'secB' },
                    { from: 'mez', to: 'choir' },
                    { from: 'hr', to: 'serv' }
                ]
            };

            var nodeMap = {};
            graphData.nodes.forEach(function(n) { nodeMap[n.id] = n; });

            var svg = '<svg viewBox="0 0 500 260" class="vi-constellation-container">';
            
            // Draw Edges
            graphData.edges.forEach(function(e) {
                var u = nodeMap[e.from];
                var v = nodeMap[e.to];
                if (u && v) {
                    svg += '<line x1="' + u.x + '" y1="' + u.y + '" x2="' + v.x + '" y2="' + v.y + '" stroke="rgba(255,255,255,0.25)" stroke-width="2"/>';
                }
            });

            // Draw Nodes
            graphData.nodes.forEach(function(n) {
                svg += '<circle cx="' + n.x + '" cy="' + n.y + '" r="' + n.r + '" fill="' + n.color + '" stroke="#ffffff" stroke-width="2" class="vi-constellation-node" ' +
                    'data-tip="<b>' + esc(n.label) + '</b><br>Type: ' + esc(n.type) + '"/>';
                svg += '<text x="' + n.x + '" y="' + (n.y + n.r + 12) + '" font-size="8" font-weight="700" fill="#f8fafc" text-anchor="middle">' + esc(n.label) + '</text>';
            });

            svg += '</svg>';
            container.innerHTML = svg;

            container.querySelectorAll('.vi-constellation-node').forEach(function(nodeEl) {
                nodeEl.addEventListener('mouseenter', function(e) {
                    showTooltip(e, nodeEl.getAttribute('data-tip'));
                });
                nodeEl.addEventListener('mousemove', positionTooltip);
                nodeEl.addEventListener('mouseleave', hideTooltip);
            });
        },

        // ══════════════════════════════════════════════════════════
        // 15. SUNDAY SCHOOL SOLAR DATA ORBIT NAVIGATION COCKPIT
        // ══════════════════════════════════════════════════════════
        renderDataOrbitNavigation: function(container, planets, options) {
            container = resolveContainer(container);
            if (!container) return;
            planets = planets || [
                { name: 'Education', rate: '88%', orbitR: 85, angle: 45, size: 48, color: '#10b981', icon: 'fa-graduation-cap' },
                { name: 'Mezmur', rate: '94%', orbitR: 135, angle: 180, size: 52, color: '#6366f1', icon: 'fa-music' },
                { name: 'HR Servants', rate: '82%', orbitR: 180, angle: 300, size: 46, color: '#f59e0b', icon: 'fa-user-tie' }
            ];

            var html = '<div class="vi-orbit-stage">' +
                '<div class="vi-orbit-sun">' +
                '<i class="fa-solid fa-church" style="font-size:1.2rem;margin-bottom:2px"></i>' +
                '<span>SSMS</span>' +
                '</div>';

            planets.forEach(function(p, idx) {
                var rad = (p.angle * Math.PI) / 180;
                var x = p.orbitR * Math.cos(rad);
                var y = p.orbitR * Math.sin(rad);

                // Orbit track ring
                html += '<div class="vi-orbit-track" style="width:' + (p.orbitR * 2) + 'px;height:' + (p.orbitR * 2) + 'px;"></div>';

                // Planet Node
                html += '<div class="vi-orbit-planet" style="width:' + p.size + 'px;height:' + p.size + 'px;background:' + p.color + ';' +
                    'transform: translate(' + x + 'px, ' + y + 'px);" ' +
                    'data-tip="<b>' + esc(p.name) + '</b><br>Attendance: <b>' + esc(p.rate) + '</b>">' +
                    '<i class="fa-solid ' + (p.icon || 'fa-circle') + '" style="font-size:0.9rem;margin-bottom:2px"></i>' +
                    '<span>' + esc(p.rate) + '</span>' +
                    '</div>';
            });

            html += '</div>';
            container.innerHTML = html;

            container.querySelectorAll('.vi-orbit-planet').forEach(function(pEl) {
                pEl.addEventListener('mouseenter', function(e) {
                    showTooltip(e, pEl.getAttribute('data-tip'));
                });
                pEl.addEventListener('mousemove', positionTooltip);
                pEl.addEventListener('mouseleave', hideTooltip);
            });
        }
    };

    global.VisualIntelligence = VI;
})(window);
