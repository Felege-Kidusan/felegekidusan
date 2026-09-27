/**
 * ============================================================================
 * Education Analytics, Reporting & Intelligence Hub (Production-Grade)
 * ============================================================================
 * Centralized UI controller for the Education Department Command Center.
 *
 * Workspaces:
 * 1. Student Academic & Attendance Intelligence (Scatter, Distributions, Waveforms)
 * 2. Teacher Assessment & Exam Submission Governance (Mid/Final Submitted vs Missing)
 * 3. Class Benchmarks & Subject Competency Spider (League table, Pass rates)
 * 4. Executive Reporting & Multi-Format Intelligence Pack (PDF, Excel, PNG)
 *
 * @author Sunday School Management System (FKSS / WBWS)
 * @version 1.0.0 (2026 Production Build)
 * ============================================================================
 */
(function (global) {
  'use strict';

  function esc(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
  }

  class EducationAnalyticsHub {
    constructor(options = {}) {
      this.options = Object.assign({
        apiEndpoint: '/admin/api_education.php?action=get_education_hub',
        containerId: 'sec-analytics'
      }, options);

      this.data = {
        macro_stats: {},
        students: [],
        class_benchmarks: [],
        exam_governance: { summary: {}, audit_matrix: [] },
        subject_benchmarks: [],
        triage_roster: [],
        brand: {}
      };

      this.activeTab = 'student_intel';
      this.charts = { scatter: null, distribution: null, waveform: null, radar: null };
      this._debounceTimer = null;
    }

    /**
     * Check if Chart.js is loaded.
     */
    hasChart() {
      return typeof Chart !== 'undefined' && typeof Chart.register === 'function';
    }

    /**
     * Build query string from filter inputs in the analytics hub.
     */
    buildQs() {
      const cEl = document.getElementById('hubFilterClass');
      const yEl = document.getElementById('hubFilterYear');
      const tEl = document.getElementById('hubFilterTerm');
      const gEl = document.getElementById('hubFilterGender');
      const lEl = document.getElementById('hubFilterLetter');
      const minGEl = document.getElementById('hubFilterMinGrade');
      const maxGEl = document.getElementById('hubFilterMaxGrade');
      const minAEl = document.getElementById('hubFilterMinAtt');
      const maxAEl = document.getElementById('hubFilterMaxAtt');
      const sEl = document.getElementById('hubFilterSearch');
      const sortEl = document.getElementById('hubFilterSort');

      let q = '';
      if (cEl && cEl.value) q += `&class_id=${encodeURIComponent(cEl.value)}`;
      if (yEl && yEl.value) q += `&year_id=${encodeURIComponent(yEl.value)}`;
      if (tEl && tEl.value && tEl.value !== '0') q += `&term_id=${encodeURIComponent(tEl.value)}`;
      if (gEl && gEl.value && gEl.value !== 'all') q += `&gender=${encodeURIComponent(gEl.value)}`;
      if (lEl && lEl.value && lEl.value !== 'all') q += `&grade_letter=${encodeURIComponent(lEl.value)}`;
      if (minGEl && minGEl.value !== '') q += `&min_grade=${encodeURIComponent(minGEl.value)}`;
      if (maxGEl && maxGEl.value !== '') q += `&max_grade=${encodeURIComponent(maxGEl.value)}`;
      if (minAEl && minAEl.value !== '') q += `&min_attendance=${encodeURIComponent(minAEl.value)}`;
      if (maxAEl && maxAEl.value !== '') q += `&max_attendance=${encodeURIComponent(maxAEl.value)}`;
      if (sEl && sEl.value.trim() !== '') q += `&search=${encodeURIComponent(sEl.value.trim())}`;
      if (sortEl && sortEl.value) q += `&sort=${encodeURIComponent(sortEl.value)}`;

      return q;
    }

    /**
     * Load full analytics dataset from backend.
     */
    async load() {
      const loader = document.getElementById('hubMainLoading');
      if (loader) loader.style.display = 'flex';

      try {
        const url = `${this.options.apiEndpoint}${this.buildQs()}`;
        const res = await (typeof getAPI === 'function' ? getAPI(url) : fetch(url).then(r => r.json()));

        if (res.status !== 'success') {
          if (typeof toast === 'function') toast(res.message || 'Could not load analytics data.', 'err');
          return;
        }

        this.data = res;
        this.renderAll();
      } catch (err) {
        console.error('EducationAnalyticsHub fetch error:', err);
        if (typeof toast === 'function') toast('Network error loading intelligence hub.', 'err');
      } finally {
        if (loader) loader.style.display = 'none';
      }
    }

    /**
     * Debounced live search/filter trigger.
     */
    triggerFilter() {
      clearTimeout(this._debounceTimer);
      this._debounceTimer = setTimeout(() => {
        this.load();
      }, 250);
    }

    /**
     * Switch active workspace tab.
     * @param {'student_intel'|'teacher_governance'|'class_benchmarks'|'executive_reports'} tab
     */
    switchTab(tab) {
      this.activeTab = tab;
      const tabs = ['student_intel', 'teacher_governance', 'class_benchmarks', 'executive_reports'];
      tabs.forEach(t => {
        const btn = document.getElementById(`hubTabBtn_${t}`);
        const view = document.getElementById(`hubTabView_${t}`);
        if (btn) btn.className = 'tbn' + (t === tab ? ' act' : '');
        if (view) view.style.display = (t === tab ? 'block' : 'none');
      });

      // Redraw charts if switching to tabs with canvas elements
      setTimeout(() => {
        this.resizeCharts();
      }, 50);
    }

    /**
     * Render all components in the active dataset.
     */
    renderAll() {
      this.renderKPIs();
      this.renderStudentIntel();
      this.renderTeacherGovernance();
      this.renderClassBenchmarks();
      this.renderExecutiveReportsTab();
      this.renderCharts();
    }

    /**
     * Render Top Executive KPI Scorecards.
     */
    renderKPIs() {
      const container = document.getElementById('hubKpiRow');
      if (!container) return;

      const s = this.data.macro_stats || {};
      const gov = (this.data.exam_governance && this.data.exam_governance.summary) || {};

      const total = s.total || 0;
      const avgG = s.avg_grade != null ? Number(s.avg_grade).toFixed(1) : '0';
      const avgA = s.avg_attendance != null ? Number(s.avg_attendance).toFixed(1) : '0';
      const delivery = gov.delivery_rate != null ? gov.delivery_rate : 0;
      const atRiskTot = (s.at_risk_grade || 0) + (s.at_risk_att || 0);

      container.innerHTML = `
        <div class="crd" style="padding:.85rem 1rem;border-left:4px solid #6366f1;box-shadow:0 1px 3px rgba(0,0,0,.04)">
          <div style="display:flex;justify-content:space-between;align-items:flex-start">
            <div>
              <div style="font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase">Cohort Size</div>
              <div class="amharic" style="font-size:.62rem;color:#94a3b8">አጠቃላይ ተማሪዎች</div>
            </div>
            <span style="width:30px;height:30px;border-radius:8px;background:#eef2ff;color:#6366f1;display:inline-flex;align-items:center;justify-content:center;font-size:.85rem"><i class="fa-solid fa-users"></i></span>
          </div>
          <div style="font-size:1.5rem;font-weight:800;color:#1e293b;line-height:1.2;margin-top:2px">${total.toLocaleString()}</div>
          <div style="font-size:.68rem;color:#64748b;margin-top:2px">${s.graded_count || 0} graded · ${s.high_achievers || 0} high achievers</div>
        </div>

        <div class="crd" style="padding:.85rem 1rem;border-left:4px solid #0284c7;box-shadow:0 1px 3px rgba(0,0,0,.04)">
          <div style="display:flex;justify-content:space-between;align-items:flex-start">
            <div>
              <div style="font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase">School Grade Mean</div>
              <div class="amharic" style="font-size:.62rem;color:#94a3b8">አማካኝ የውጤት መጠን</div>
            </div>
            <span style="width:30px;height:30px;border-radius:8px;background:#f0f9ff;color:#0284c7;display:inline-flex;align-items:center;justify-content:center;font-size:.85rem"><i class="fa-solid fa-graduation-cap"></i></span>
          </div>
          <div style="font-size:1.5rem;font-weight:800;color:#1e293b;line-height:1.2;margin-top:2px">${avgG}%</div>
          <div style="font-size:.68rem;color:#64748b;margin-top:2px">Median: ${s.median_grade || '—'}% · Spread: ±${s.stdev_grade || '0'}%</div>
        </div>

        <div class="crd" style="padding:.85rem 1rem;border-left:4px solid #059669;box-shadow:0 1px 3px rgba(0,0,0,.04)">
          <div style="display:flex;justify-content:space-between;align-items:flex-start">
            <div>
              <div style="font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase">Attendance Regularity</div>
              <div class="amharic" style="font-size:.62rem;color:#94a3b8">አማካኝ የተማሪዎች ክትትል</div>
            </div>
            <span style="width:30px;height:30px;border-radius:8px;background:#ecfdf5;color:#059669;display:inline-flex;align-items:center;justify-content:center;font-size:.85rem"><i class="fa-solid fa-calendar-check"></i></span>
          </div>
          <div style="font-size:1.5rem;font-weight:800;color:#1e293b;line-height:1.2;margin-top:2px">${avgA}%</div>
          <div style="font-size:.68rem;color:#64748b;margin-top:2px">${s.recorded_att_students || 0} tracked (${s.unrecorded_att_students || 0} untracked)</div>
        </div>

        <div class="crd" style="padding:.85rem 1rem;border-left:4px solid #d97706;box-shadow:0 1px 3px rgba(0,0,0,.04)">
          <div style="display:flex;justify-content:space-between;align-items:flex-start">
            <div>
              <div style="font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase">Exam Delivery Rate</div>
              <div class="amharic" style="font-size:.62rem;color:#94a3b8">የመምህራን ፈተና አቀራረብ</div>
            </div>
            <span style="width:30px;height:30px;border-radius:8px;background:#fffbeb;color:#d97706;display:inline-flex;align-items:center;justify-content:center;font-size:.85rem"><i class="fa-solid fa-clipboard-check"></i></span>
          </div>
          <div style="font-size:1.5rem;font-weight:800;color:#1e293b;line-height:1.2;margin-top:2px">${delivery}%</div>
          <div style="font-size:.68rem;color:#64748b;margin-top:2px">${(gov.approved_count || 0) + (gov.submitted_count || 0)} / ${gov.total_planned_assessments || 0} submitted · <strong style="color:#dc2626">${gov.missing_count || 0} missing</strong></div>
        </div>
      `;
    }

    /**
     * Render Workspace 1: Student Academic & Attendance Intelligence.
     */
    renderStudentIntel() {
      const tbody = document.getElementById('hubStudentTableBody');
      const countEl = document.getElementById('hubStudentCountBadge');
      const students = this.data.students || [];

      if (countEl) countEl.textContent = students.length;
      if (!tbody) return;

      if (!students.length) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:2rem;color:#94a3b8">No matching students found under the active filter bounds.</td></tr>';
        return;
      }

      const gc = { A: '#047857', B: '#0369a1', C: '#b45309', D: '#c2410c', F: '#b91c1c' };
      tbody.innerHTML = students.map(s => {
        const pct = s.overall_average ?? s.avg_percentage;
        const attR = s.attendance_rate != null ? Number(s.attendance_rate) : 0;
        const tDays = Number(s.total_days) || 0;
        const pDays = Number(s.present_days) || 0;
        const aDays = Number(s.absent_days) || 0;
        const lDays = Number(s.late_days) || 0;
        const eDays = Number(s.excused_days) || 0;
        const hasAtt = tDays > 0;
        const isMale = (s.gender === 'male');

        return `
          <tr>
            <td style="font-weight:700;color:#64748b">${s.filter_rank || '—'}</td>
            <td style="font-weight:600;font-size:.82rem">
              <div>${esc(s.student_name || '')} ${esc(s.father_name || '')}</div>
              ${s.christian_name ? `<div style="font-size:.65rem;color:#94a3b8"><i class="fa-solid fa-cross" style="font-size:.55rem"></i> ${esc(s.christian_name)}</div>` : ''}
            </td>
            <td><code style="font-size:.7rem;background:#f1f5f9;padding:2px 6px;border-radius:4px">${esc(s.member_code || '—')}</code></td>
            <td><span class="amharic" style="font-weight:600;font-size:.78rem">${esc(s.class_name || '—')}</span></td>
            <td><span class="chip ${isMale ? 'chip-info' : 'chip-success'}" style="font-size:.65rem">${isMale ? 'M' : 'F'}</span></td>
            <td>
              <div style="display:flex;align-items:center;gap:.4rem">
                <span style="font-weight:700;font-size:.88rem;color:#1e293b">${pct != null ? Number(pct).toFixed(1) + '%' : '—'}</span>
                <span style="display:inline-flex;width:22px;height:22px;border-radius:50%;align-items:center;justify-content:center;font-weight:700;font-size:.62rem;color:#fff;background:${gc[s.grade_letter] || '#94a3b8'}">${s.grade_letter || '—'}</span>
              </div>
            </td>
            <td style="min-width:140px">
              ${hasAtt ? `
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2px">
                  <span style="font-weight:700;font-size:.8rem;color:${attR >= 80 ? '#047857' : (attR >= 60 ? '#d97706' : '#b91c1c')}">${attR}%</span>
                  <span style="font-size:.65rem;color:#64748b">${pDays + lDays}/${tDays} days</span>
                </div>
                <div style="height:4px;background:#e2e8f0;border-radius:99px;overflow:hidden">
                  <div style="height:100%;border-radius:99px;background:${attR >= 80 ? '#047857' : (attR >= 60 ? '#d97706' : '#b91c1c')};width:${Math.min(100, attR)}%"></div>
                </div>
              ` : '<span style="font-size:.7rem;color:#94a3b8">— (Untracked)</span>'}
            </td>
            <td class="no-print">
              <button class="btn btn-o btn-xs" type="button" onclick="viewStudentReportFromFilter(${s.id}, ${s.class_id})"><i class="fa-solid fa-file-lines"></i> Report</button>
            </td>
          </tr>
        `;
      }).join('');
    }

    /**
     * Render Workspace 2: Teacher Assessment & Exam Submission Governance.
     */
    renderTeacherGovernance() {
      const tbody = document.getElementById('hubGovernanceTableBody');
      const gov = this.data.exam_governance || {};
      const audit = gov.audit_matrix || [];
      const sum = gov.summary || {};

      const plannedEl = document.getElementById('govStatPlanned');
      const approvedEl = document.getElementById('govStatApproved');
      const submittedEl = document.getElementById('govStatSubmitted');
      const missingEl = document.getElementById('govStatMissing');

      if (plannedEl) plannedEl.textContent = sum.total_planned_assessments || 0;
      if (approvedEl) approvedEl.textContent = sum.approved_count || 0;
      if (submittedEl) submittedEl.textContent = sum.submitted_count || 0;
      if (missingEl) missingEl.textContent = sum.missing_count || 0;

      if (!tbody) return;

      if (!audit.length) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:2rem;color:#94a3b8">No teacher assignments or assessments found.</td></tr>';
        return;
      }

      tbody.innerHTML = audit.map(row => {
        const isMissing = (row.status === 'missing');
        const isApproved = (row.status === 'approved');
        const isSubmitted = (row.status === 'submitted');

        return `
          <tr style="${isMissing ? 'background:#fff8f8;' : ''}">
            <td style="font-weight:600;font-size:.82rem" class="amharic">${esc(row.class_name)}</td>
            <td style="font-weight:600;font-size:.82rem" class="amharic">${esc(row.subject_name)}</td>
            <td style="font-weight:600;color:#1e293b">${esc(row.teacher_name)}</td>
            <td>
              <div style="font-weight:600;font-size:.8rem">${esc(row.assessment_title)}</div>
              <div style="font-size:.65rem;color:#64748b">Weight: ${row.weight}% · Max: ${row.max_score} pts</div>
            </td>
            <td>
              <span class="badge ${isApproved ? 'badge-ok' : (isSubmitted ? 'badge-info' : (row.status === 'draft' ? 'badge-warn' : 'badge-err'))}">
                ${esc(row.status_label)}
              </span>
            </td>
            <td style="font-weight:700;font-size:.85rem;color:#1e293b">${row.average_score !== null ? row.average_score + ' pts' : '—'}</td>
            <td style="font-size:.72rem;color:#64748b">${row.student_count > 0 ? row.student_count + ' students' : '—'}</td>
            <td class="no-print">
              ${row.submission_id > 0 ? `<button class="btn btn-o btn-xs" type="button" onclick="openReviewModal(${row.submission_id})"><i class="fa-solid fa-eye"></i> Review</button>` : '<span style="font-size:.7rem;color:#dc2626;font-weight:600">Pending Marklist</span>'}
            </td>
          </tr>
        `;
      }).join('');
    }

    /**
     * Render Workspace 3: Class Benchmarks & League Table.
     */
    renderClassBenchmarks() {
      const tbody = document.getElementById('hubLeagueTableBody');
      const list = this.data.class_benchmarks || [];
      if (!tbody) return;

      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:2rem;color:#94a3b8">No class benchmark data available.</td></tr>';
        return;
      }

      tbody.innerHTML = list.map(c => `
        <tr>
          <td style="text-align:center;font-weight:700;color:#64748b">
            <span style="display:inline-flex;width:26px;height:26px;border-radius:50%;align-items:center;justify-content:center;background:${c.class_rank === 1 ? '#F0C000;color:#600000;font-weight:800' : '#f1f5f9;color:#64748b'}">${c.class_rank}</span>
          </td>
          <td style="font-weight:700;font-size:.85rem" class="amharic">
            ${esc(c.class_name)}
            ${c.class_name_en ? `<span style="font-size:.7rem;font-weight:normal;color:#94a3b8">(${esc(c.class_name_en)})</span>` : ''}
          </td>
          <td style="font-size:.8rem">${c.total_students} (${c.graded_students} graded)</td>
          <td style="font-weight:700;font-size:.88rem;color:#1e293b">${c.average_grade !== null ? Number(c.average_grade).toFixed(1) + '%' : '—'}</td>
          <td style="font-weight:600;font-size:.82rem;color:${(c.pass_rate || 0) >= 75 ? '#047857' : ((c.pass_rate || 0) >= 50 ? '#d97706' : '#dc2626')}">${c.pass_rate !== null ? c.pass_rate + '%' : '—'}</td>
          <td style="font-weight:600;font-size:.82rem">${c.average_attendance !== null ? c.average_attendance + '%' : '—'}</td>
          <td>
            <div style="display:flex;align-items:center;gap:.4rem">
              <div style="flex:1;height:5px;background:#e2e8f0;border-radius:99px;overflow:hidden">
                <div style="height:100%;border-radius:99px;background:${c.curriculum_recorded_pct >= 80 ? '#059669' : (c.curriculum_recorded_pct >= 50 ? '#d97706' : '#dc2626')};width:${c.curriculum_recorded_pct}%"></div>
              </div>
              <span style="font-size:.7rem;font-weight:700">${c.curriculum_recorded_pct}%</span>
            </div>
          </td>
          <td class="no-print">
            <button class="btn btn-o btn-xs" type="button" onclick="hubDrillIntoClass(${c.class_id})"><i class="fa-solid fa-arrow-right"></i> Drill Down</button>
          </td>
        </tr>
      `).join('');
    }

    /**
     * Render Workspace 4: Executive Reports & Exports Tab.
     */
    renderExecutiveReportsTab() {
      const container = document.getElementById('hubReportsSummaryBlock');
      if (!container) return;

      const s = this.data.macro_stats || {};
      const gov = (this.data.exam_governance && this.data.exam_governance.summary) || {};
      const triage = this.data.triage_roster || [];

      container.innerHTML = `
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:1.25rem;margin-bottom:1rem">
          <div style="font-weight:700;font-size:1rem;color:#1e293b;margin-bottom:.5rem"><i class="fa-solid fa-file-shield" style="color:#600000"></i> Executive Brief Generation Ready</div>
          <p style="font-size:.78rem;color:#475569;line-height:1.5;margin:0 0 1rem">
            The active academic dataset contains <strong>${s.total || 0}</strong> analyzed students across <strong>${(this.data.class_benchmarks || []).length}</strong> classes with an overall grade average of <strong>${s.avg_grade || 0}%</strong> and exam delivery completeness of <strong>${gov.delivery_rate || 0}%</strong>.
            <strong>${triage.length}</strong> students have been identified for academic or attendance triage intervention.
          </p>
          <div style="display:flex;gap:.75rem;flex-wrap:wrap">
            <a href="/admin/export_executive_report_pdf.php?${this.buildQs().replace(/^&/, '')}" target="_blank" class="btn btn-p" style="background:#600000;color:#fff">
              <i class="fa-solid fa-file-pdf"></i> Download Official 5-Page PDF Report
            </a>
            <a href="/admin/export_executive_report_excel.php?${this.buildQs().replace(/^&/, '')}" class="btn btn-s" style="background:#059669;color:#fff">
              <i class="fa-solid fa-file-excel"></i> Download Multi-Sheet Excel Workbook (.xlsx)
            </a>
            <button class="btn btn-o" type="button" onclick="window.print()">
              <i class="fa-solid fa-print"></i> Print Executive Dashboard
            </button>
          </div>
        </div>
      `;
    }

    /**
     * Render Visual Charts via local Chart.js runtime.
     */
    renderCharts() {
      if (!this.hasChart()) return;

      // 1. Scatter Chart
      const scatterCanvas = document.getElementById('hubScatterChart');
      if (scatterCanvas) {
        if (this.charts.scatter) this.charts.scatter.destroy();
        const students = this.data.students || [];
        const scatterData = students.map(s => ({
          x: (s.total_days || 0) > 0 ? Number(s.attendance_rate || 0) : -5,
          y: s.overall_average != null ? Number(s.overall_average) : (s.avg_percentage != null ? Number(s.avg_percentage) : 0),
          student: s,
          hasAtt: (s.total_days || 0) > 0
        }));

        const gc = { A: '#047857', B: '#0369a1', C: '#b45309', D: '#c2410c', F: '#b91c1c' };
        this.charts.scatter = new Chart(scatterCanvas.getContext('2d'), {
          type: 'scatter',
          data: {
            datasets: [{
              label: 'Students',
              data: scatterData,
              backgroundColor: ctx => (ctx.raw ? (gc[ctx.raw.student.grade_letter] || '#64748b') : '#64748b'),
              pointRadius: ctx => (ctx.raw && ctx.raw.hasAtt ? 6 : 4),
              pointHoverRadius: 9
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  label: item => {
                    const st = item.raw.student;
                    return `${st.student_name} ${st.father_name} (${st.class_name}): ${item.raw.y}% Grade · ${item.raw.hasAtt ? item.raw.x + '% Att' : 'Untracked'}`;
                  }
                }
              }
            },
            scales: {
              x: { min: -10, max: 100, title: { display: true, text: 'Attendance Rate (%)', font: { size: 10 } } },
              y: { min: 0, max: 100, title: { display: true, text: 'Grade Average (%)', font: { size: 10 } } }
            }
          }
        });
      }

      // 2. Frequency Distribution Histogram
      const distCanvas = document.getElementById('hubDistChart');
      if (distCanvas) {
        if (this.charts.distribution) this.charts.distribution.destroy();
        const s = this.data.macro_stats || {};
        const gBins = s.grade_bins || { '<50': 0, '50-59': 0, '60-69': 0, '70-79': 0, '80-89': 0, '90-100': 0 };
        const aBins = s.att_bins || { '<50': 0, '50-59': 0, '60-69': 0, '70-79': 0, '80-89': 0, '90-100': 0 };

        this.charts.distribution = new Chart(distCanvas.getContext('2d'), {
          type: 'bar',
          data: {
            labels: ['<50% (F)', '50–59% (D)', '60–69% (D+)', '70–79% (C)', '80–89% (B)', '90–100% (A)'],
            datasets: [
              {
                label: 'Grade Scores',
                data: Object.values(gBins),
                backgroundColor: ['#b91c1c', '#c2410c', '#b45309', '#0369a1', '#0284c7', '#047857'],
                borderRadius: 4
              },
              {
                label: 'Attendance Rates',
                data: Object.values(aBins),
                backgroundColor: 'rgba(99, 102, 241, 0.45)',
                borderColor: '#6366f1',
                borderWidth: 1.5,
                borderRadius: 4
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top', labels: { boxWidth: 10, font: { size: 10 } } } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
          }
        });
      }

      // 3. Subject Competency Spider / Radar
      const radarCanvas = document.getElementById('hubRadarChart');
      if (radarCanvas) {
        if (this.charts.radar) this.charts.radar.destroy();
        const benchmarks = this.data.subject_benchmarks || [];
        const labels = benchmarks.map(b => b.subject || 'Subject');
        const values = benchmarks.map(b => b.average || 0);

        this.charts.radar = new Chart(radarCanvas.getContext('2d'), {
          type: 'radar',
          data: {
            labels: labels.length ? labels : ['No Subjects'],
            datasets: [{
              label: 'Subject Mean',
              data: values.length ? values : [0],
              backgroundColor: 'rgba(124, 58, 237, 0.25)',
              borderColor: '#7c3aed',
              borderWidth: 2,
              pointBackgroundColor: '#7c3aed'
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { r: { min: 0, max: 100, ticks: { stepSize: 20, font: { size: 8 } } } }
          }
        });
      }
    }

    /**
     * Resize all active charts without layout jumps.
     */
    resizeCharts() {
      Object.values(this.charts).forEach(c => {
        if (c && typeof c.resize === 'function') c.resize();
      });
    }
  }

  global.EducationAnalyticsHub = EducationAnalyticsHub;
})(typeof window !== 'undefined' ? window : this);
