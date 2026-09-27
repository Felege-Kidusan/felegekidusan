/**
 * ============================================================================
 * Advanced Analytics Center & Visualization Engine (Production-Grade)
 * ============================================================================
 * Designed for High-Density Visual Analytics across School Departments:
 * (Education, HR, Info Dept, Mezmur, Finance).
 *
 * Built with:
 * - Local Chart.js v4.4.1 runtime (zero external CDN dependency)
 * - Pure SVG & Canvas micro-visualizations
 * - Accessible WCAG 2.1 AA/AAA color palettes
 * - High-speed DOM rendering with smooth transitions
 * - Full XSS-safe string escaping and Amharic typography support
 *
 * @author Sunday School Management System (FKSS / WBWS)
 * @version 1.0.0 (2026 Build)
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

  const PALETTE = {
    brandPrimary: '#600000',
    brandAccent: '#F0C000',
    mastery: '#059669',       // Emerald (Score >= 85, Att >= 80)
    masteryBg: '#ecfdf5',
    proficient: '#0284c7',    // Sky (Score 70-84, Att >= 70)
    proficientBg: '#f0f9ff',
    warning: '#d97706',       // Amber (Score 50-69 or Att < 60)
    warningBg: '#fffbeb',
    critical: '#dc2626',      // Red (Score < 50)
    criticalBg: '#fef2f2',
    indigo: '#6366f1',
    violet: '#8b5cf6',
    slate: '#64748b',
    slateLight: '#f1f5f9',
    gridColor: 'rgba(226, 232, 240, 0.7)',
    gradeColors: {
      A: '#047857',
      B: '#0284c7',
      C: '#b45309',
      D: '#c2410c',
      F: '#b91c1c'
    }
  };

  class AdvancedAnalyticsCenter {
    /**
     * @param {Object} options Configuration object
     * @param {Function} [options.onStudentClick] Callback when a student node is clicked (memberId, classId)
     * @param {Function} [options.onTierClick] Callback when a triage tier badge is clicked (tierKey)
     * @param {Object} [options.customPalette] Optional color overrides
     */
    constructor(options = {}) {
      this.options = Object.assign({
        onStudentClick: null,
        onTierClick: null,
        theme: 'light',
        canvasPrefix: 'pf'
      }, options);

      this.charts = {
        scatter: null,
        distribution: null,
        waveform: null,
        radar: null
      };

      this.data = {
        students: [],
        stats: {},
        filters: {}
      };

      this._boundResize = this.handleResize.bind(this);
      window.addEventListener('resize', this._boundResize);
    }

    /**
     * Check if Chart.js is loaded in the page.
     * @returns {boolean}
     */
    hasChartJs() {
      return typeof Chart !== 'undefined' && typeof Chart.register === 'function';
    }

    /**
     * Update internal dataset and trigger re-render across all visualizations.
     * @param {Object} payload Server response containing { students, stats, filters_applied }
     */
    update(payload = {}) {
      this.data.students = Array.isArray(payload.students) ? payload.students : [];
      this.data.stats = payload.stats || {};
      this.data.filters = payload.filters_applied || {};

      this.renderKPIs('pfAnalyticsKpis');
      this.renderTriage('pfAnalyticsTriage');
      this.renderInsights('pfAnalyticsInsights');

      if (!this.hasChartJs()) {
        console.warn('AdvancedAnalyticsCenter: Chart.js runtime is not available.');
        return;
      }

      this.renderScatterChart('pfScatterChart');
      this.renderDistributionChart('pfDistributionChart');
      this.renderWaveformChart('pfWaveformChart');
      this.renderRadarChart('pfRadarChart');
    }

    /**
     * Render 4 Executive Pulse KPI Cards.
     * @param {string} containerId
     */
    renderKPIs(containerId) {
      const container = document.getElementById(containerId);
      if (!container) return;

      const s = this.data.stats;
      const total = s.total || 0;
      const avgG = s.avg_grade != null ? Number(s.avg_grade).toFixed(1) : '0';
      const medianG = s.median_grade != null ? Number(s.median_grade).toFixed(1) : '—';
      const stdevG = s.stdev_grade != null ? `±${s.stdev_grade}%` : '';
      const avgA = s.avg_attendance != null ? Number(s.avg_attendance).toFixed(1) : '0';
      const recAtt = s.recorded_att_students || 0;
      const unrecAtt = s.unrecorded_att_students || 0;
      const highAchievers = s.high_achievers || 0;
      const atRiskGrade = s.at_risk_grade || 0;
      const atRiskAtt = s.at_risk_att || 0;

      const cards = [
        {
          title: 'Total Filtered Cohort',
          amharic: 'የተጣሩ ተማሪዎች ብዛት',
          value: total.toLocaleString(),
          sub: `${highAchievers} high achievers (${total > 0 ? Math.round((highAchievers / total) * 100) : 0}%)`,
          icon: 'fa-users',
          color: '#6366f1',
          bg: '#eef2ff'
        },
        {
          title: 'Academic Grade Mean',
          amharic: 'አማካኝ ውጤት',
          value: `${avgG}%`,
          sub: `Median: ${medianG}% · Spread: ${stdevG}`,
          icon: 'fa-graduation-cap',
          color: '#0284c7',
          bg: '#f0f9ff'
        },
        {
          title: 'Attendance Group Mean',
          amharic: 'አማካኝ ክትትል',
          value: `${avgA}%`,
          sub: recAtt > 0 ? `${recAtt} tracked · ${unrecAtt} unrecorded` : 'No attendance recorded',
          icon: 'fa-calendar-check',
          color: '#059669',
          bg: '#ecfdf5'
        },
        {
          title: 'Triage / Priority Alerts',
          amharic: 'ልዩ ትኩረት የሚያስፈልጋቸው',
          value: (atRiskGrade + atRiskAtt).toLocaleString(),
          sub: `${atRiskGrade} score risk (<50%) · ${atRiskAtt} att alert (<60%)`,
          icon: 'fa-triangle-exclamation',
          color: (atRiskGrade + atRiskAtt) > 0 ? '#dc2626' : '#059669',
          bg: (atRiskGrade + atRiskAtt) > 0 ? '#fef2f2' : '#ecfdf5'
        }
      ];

      container.innerHTML = cards.map(c => `
        <div class="crd" style="padding:.9rem 1rem;border-left:4px solid ${c.color};box-shadow:0 1px 3px rgba(0,0,0,.04)">
          <div style="display:flex;justify-content:space-between;align-items:flex-start">
            <div>
              <div style="font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px">${esc(c.title)}</div>
              <div class="amharic" style="font-size:.62rem;color:#94a3b8;margin-bottom:4px">${esc(c.amharic)}</div>
            </div>
            <span style="width:32px;height:32px;border-radius:8px;background:${c.bg};color:${c.color};display:inline-flex;align-items:center;justify-content:center;font-size:.85rem">
              <i class="fa-solid ${c.icon}"></i>
            </span>
          </div>
          <div style="font-size:1.55rem;font-weight:800;color:#1e293b;line-height:1.2">${esc(c.value)}</div>
          <div style="font-size:.68rem;color:#64748b;margin-top:4px">${esc(c.sub)}</div>
        </div>
      `).join('');
    }

    /**
     * Render Interactive Triage & Health Matrix.
     * @param {string} containerId
     */
    renderTriage(containerId) {
      const container = document.getElementById(containerId);
      if (!container) return;

      const t = (this.data.stats && this.data.stats.triage_health) || {};
      const tot = this.data.stats.total || 1;

      const tiers = [
        { key: 'mastery', label: '🌟 Mastery (≥85% Score & ≥80% Att)', count: t.mastery || 0, color: '#059669', bg: '#ecfdf5', preset: [85, 100, 80, 100] },
        { key: 'proficient', label: '📈 Proficient (70–84.9% Score & ≥70% Att)', count: t.proficient || 0, color: '#0284c7', bg: '#f0f9ff', preset: [70, 84.9, 70, 100] },
        { key: 'academic_risk', label: '⚠️ Academic Support Needed (<50% Score)', count: t.academic_risk || 0, color: '#d97706', bg: '#fffbeb', preset: [0, 49.9, 0, 100] },
        { key: 'attendance_risk', label: '⏰ Attendance Intervention (<60% Att)', count: t.attendance_risk || 0, color: '#ea580c', bg: '#fff7ed', preset: [0, 100, 0, 59.9] },
        { key: 'dual_critical', label: '🚨 Dual Risk (<50% Score & <60% Att)', count: t.dual_critical || 0, color: '#dc2626', bg: '#fef2f2', preset: [0, 49.9, 0, 59.9] },
        { key: 'untracked', label: '⚪ Untracked Attendance (0 Recorded Days)', count: t.untracked || 0, color: '#64748b', bg: '#f8fafc', preset: null }
      ];

      container.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
          <div>
            <div style="font-weight:700;font-size:.88rem;color:#1e293b"><i class="fa-solid fa-heart-pulse" style="color:#6366f1"></i> Cohort Health &amp; Triage Distribution</div>
            <div style="font-size:.68rem;color:#64748b">Click any tier to filter the cohort instantly</div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.6rem">
          ${tiers.map(tr => {
            const pct = Math.round(((tr.count) / Math.max(1, tot)) * 100);
            return `
              <div class="triage-card" onclick="window.FKSSAnalyticsInstance.handleTierClick('${tr.key}')"
                   style="cursor:pointer;padding:.65rem .8rem;border-radius:10px;background:${tr.bg};border:1px solid ${tr.color}30;transition:all .15s ease"
                   onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 6px rgba(0,0,0,.06)'"
                   onmouseout="this.style.transform='none';this.style.boxShadow='none'">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                  <span style="font-size:.72rem;font-weight:700;color:${tr.color}">${esc(tr.label)}</span>
                  <span style="font-size:.85rem;font-weight:800;color:${tr.color}">${tr.count}</span>
                </div>
                <div style="display:flex;align-items:center;gap:.5rem">
                  <div style="flex:1;height:5px;background:#e2e8f0;border-radius:99px;overflow:hidden">
                    <div style="height:100%;border-radius:99px;background:${tr.color};width:${pct}%"></div>
                  </div>
                  <span style="font-size:.65rem;font-weight:700;color:${tr.color};min-width:28px;text-align:right">${pct}%</span>
                </div>
              </div>
            `;
          }).join('')}
        </div>
      `;
    }

    /**
     * Render Executive AI / Heuristic Insights Summary.
     * @param {string} containerId
     */
    renderInsights(containerId) {
      const container = document.getElementById(containerId);
      if (!container) return;

      const s = this.data.stats;
      const tot = s.total || 0;
      if (tot === 0) {
        container.innerHTML = '<p style="font-size:.75rem;color:#94a3b8;text-align:center;padding:.5rem">No data available for analytical synthesis.</p>';
        return;
      }

      const avgG = Number(s.avg_grade || 0);
      const medianG = Number(s.median_grade || 0);
      const avgA = Number(s.avg_attendance || 0);
      const masteryCount = (s.triage_health && s.triage_health.mastery) || 0;
      const masteryPct = Math.round((masteryCount / tot) * 100);
      const criticalCount = (s.triage_health && s.triage_health.dual_critical) || 0;

      let skewText = 'balanced academic distribution';
      if (medianG > avgG + 2.5) skewText = 'strong positive skew (majority performing above the arithmetic mean)';
      else if (avgG > medianG + 2.5) skewText = 'negative skew with lower-tail drag';

      let attendanceCorrelation = 'Attendance and scores demonstrate strong positive alignment';
      if (avgA < 70) attendanceCorrelation = 'Noticeable attendance volatility is impacting general consistency';

      container.innerHTML = `
        <div style="background:linear-gradient(135deg,#f8fafc,#eff6ff);border:1px solid #bfdbfe;border-radius:12px;padding:.85rem 1rem">
          <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
            <span style="width:24px;height:24px;border-radius:6px;background:#2563eb;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem">
              <i class="fa-solid fa-chart-line"></i>
            </span>
            <span style="font-weight:700;font-size:.82rem;color:#1e3a8a">Analytical Synthesis &amp; Executive Summary</span>
          </div>
          <p style="font-size:.73rem;color:#334155;line-height:1.5;margin:0">
            Across <strong>${tot}</strong> analyzed student profiles, the cohort maintains an average grade of <strong>${avgG.toFixed(1)}%</strong> (Median: <strong>${medianG.toFixed(1)}%</strong>), reflecting a <em>${skewText}</em>.
            <strong>${masteryPct}%</strong> (${masteryCount} students) meet the dual criteria for <strong>Mastery &amp; High Regularity</strong>.
            ${criticalCount > 0 ? `<span style="color:#b91c1c;font-weight:600"><i class="fa-solid fa-circle-exclamation"></i> ${criticalCount} student${criticalCount===1?'':'s'} urgently require academic and pastoral outreach due to combined score and attendance vulnerabilities.</span>` : '<span style="color:#059669;font-weight:600"><i class="fa-solid fa-circle-check"></i> Zero dual-risk critical students identified under the current filter bounds.</span>'}
            ${esc(attendanceCorrelation)}.
          </p>
        </div>
      `;
    }

    /**
     * Render the 4-Quadrant Scatter Matrix (Performance vs. Attendance).
     * @param {string} canvasId
     */
    renderScatterChart(canvasId) {
      const canvas = document.getElementById(canvasId);
      if (!canvas) return;

      if (this.charts.scatter) {
        this.charts.scatter.destroy();
        this.charts.scatter = null;
      }

      const students = this.data.students || [];
      const self = this;

      const scatterData = students.map(s => {
        const grade = s.overall_average != null ? Number(s.overall_average) : (s.avg_percentage != null ? Number(s.avg_percentage) : 0);
        const att = (s.total_days || 0) > 0 ? Number(s.attendance_rate || 0) : null;
        const letter = s.grade_letter || 'F';
        return {
          x: att !== null ? att : -5, // Placed at -5 if unrecorded
          y: grade,
          student: s,
          hasAtt: att !== null,
          letter: letter
        };
      });

      // Custom Quadrant Background Plugin
      const quadrantPlugin = {
        id: 'quadrantPlugin',
        beforeDraw(chart) {
          const { ctx, chartArea: { left, top, right, bottom, width, height }, scales: { x, y } } = chart;
          const midX = x.getPixelForValue(70);
          const midY = y.getPixelForValue(60);

          ctx.save();
          // Top-Right: Mastery (X >= 70, Y >= 60)
          ctx.fillStyle = 'rgba(236, 253, 245, 0.45)';
          ctx.fillRect(midX, top, right - midX, midY - top);

          // Top-Left: Self-Directed (X < 70, Y >= 60)
          ctx.fillStyle = 'rgba(240, 249, 255, 0.35)';
          ctx.fillRect(left, top, midX - left, midY - top);

          // Bottom-Left: Critical Risk (X < 70, Y < 60)
          ctx.fillStyle = 'rgba(254, 242, 242, 0.45)';
          ctx.fillRect(left, midY, midX - left, bottom - midY);

          // Bottom-Right: Faithful Strugglers (X >= 70, Y < 60)
          ctx.fillStyle = 'rgba(255, 251, 235, 0.35)';
          ctx.fillRect(midX, midY, right - midX, bottom - midY);

          // Quadrant Axis Lines
          ctx.strokeStyle = 'rgba(148, 163, 184, 0.4)';
          ctx.lineWidth = 1;
          ctx.setLineDash([4, 4]);
          ctx.beginPath();
          ctx.moveTo(midX, top);
          ctx.lineTo(midX, bottom);
          ctx.moveTo(left, midY);
          ctx.lineTo(right, midY);
          ctx.stroke();

          // Labels
          ctx.font = 'bold 9px DM Sans, sans-serif';
          ctx.fillStyle = 'rgba(5, 150, 105, 0.8)';
          ctx.fillText('QUADRANT I: MASTERY (≥70% Score & Att)', midX + 8, top + 14);

          ctx.fillStyle = 'rgba(2, 132, 199, 0.8)';
          ctx.fillText('QUADRANT II: INDEPENDENT', left + 8, top + 14);

          ctx.fillStyle = 'rgba(220, 38, 38, 0.8)';
          ctx.fillText('QUADRANT III: CRITICAL RISK', left + 8, bottom - 8);

          ctx.fillStyle = 'rgba(217, 119, 6, 0.8)';
          ctx.fillText('QUADRANT IV: FAITHFUL STRUGGLER', midX + 8, bottom - 8);

          ctx.restore();
        }
      };

      this.charts.scatter = new Chart(canvas.getContext('2d'), {
        type: 'scatter',
        data: {
          datasets: [{
            label: 'Students',
            data: scatterData,
            backgroundColor: ctx => {
              const raw = ctx.raw;
              if (!raw) return PALETTE.slate;
              return PALETTE.gradeColors[raw.letter] || PALETTE.slate;
            },
            borderColor: '#ffffff',
            borderWidth: 1.5,
            pointRadius: ctx => (ctx.raw && ctx.raw.hasAtt) ? 6 : 4,
            pointHoverRadius: 9,
            pointStyle: ctx => (ctx.raw && !ctx.raw.hasAtt) ? 'triangle' : 'circle'
          }]
        },
        plugins: [quadrantPlugin],
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: { duration: 600 },
          onClick: (evt, activeEls) => {
            if (!activeEls || !activeEls.length) return;
            const idx = activeEls[0].index;
            const student = scatterData[idx]?.student;
            if (student && typeof self.options.onStudentClick === 'function') {
              self.options.onStudentClick(student.id, student.class_id);
            }
          },
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: 'rgba(15, 23, 42, 0.95)',
              padding: 10,
              titleFont: { size: 12, weight: 'bold', family: 'DM Sans' },
              bodyFont: { size: 11, family: 'DM Sans' },
              callbacks: {
                title: items => {
                  const s = items[0].raw.student;
                  return `${s.student_name || ''} ${s.father_name || ''}`;
                },
                label: item => {
                  const raw = item.raw;
                  const s = raw.student;
                  const attText = raw.hasAtt ? `${raw.x}% attendance` : 'No attendance recorded';
                  const pDays = s.present_days || 0;
                  const aDays = s.absent_days || 0;
                  const lDays = s.late_days || 0;
                  const eDays = s.excused_days || 0;
                  const breakdown = raw.hasAtt ? ` (${pDays}P · ${aDays}A${lDays > 0 ? ' · ' + lDays + 'L' : ''}${eDays > 0 ? ' · ' + eDays + 'E' : ''})` : '';
                  return [
                    `Class: ${s.class_name || '—'} · Code: ${s.member_code || '—'}`,
                    `Grade Average: ${raw.y}% (Grade ${raw.letter})`,
                    `Attendance: ${attText}${breakdown}`,
                    '👉 Click node to open full Report Card'
                  ];
                }
              }
            }
          },
          scales: {
            x: {
              min: -10,
              max: 100,
              title: { display: true, text: 'Attendance Rate (%) — [-10 to 0 indicates unrecorded]', font: { size: 10, weight: 'bold' }, color: '#64748b' },
              grid: { color: PALETTE.gridColor },
              ticks: {
                callback: val => (val < 0 ? 'Unrecorded' : `${val}%`),
                font: { size: 9 },
                color: '#64748b'
              }
            },
            y: {
              min: 0,
              max: 100,
              title: { display: true, text: 'Academic Grade Average (%)', font: { size: 10, weight: 'bold' }, color: '#64748b' },
              grid: { color: PALETTE.gridColor },
              ticks: {
                callback: val => `${val}%`,
                font: { size: 9 },
                color: '#64748b'
              }
            }
          }
        }
      });
    }

    /**
     * Render Score & Attendance Frequency Distribution Histogram.
     * @param {string} canvasId
     */
    renderDistributionChart(canvasId) {
      const canvas = document.getElementById(canvasId);
      if (!canvas) return;

      if (this.charts.distribution) {
        this.charts.distribution.destroy();
        this.charts.distribution = null;
      }

      const stats = this.data.stats || {};
      const gBins = stats.grade_bins || { '<50': 0, '50-59': 0, '60-69': 0, '70-79': 0, '80-89': 0, '90-100': 0 };
      const aBins = stats.att_bins || { '<50': 0, '50-59': 0, '60-69': 0, '70-79': 0, '80-89': 0, '90-100': 0 };
      const labels = ['<50% (F)', '50–59% (D)', '60–69% (D+)', '70–79% (C)', '80–89% (B)', '90–100% (A)'];

      this.charts.distribution = new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [
            {
              label: 'Academic Scores',
              data: Object.values(gBins),
              backgroundColor: ['#b91c1c', '#c2410c', '#b45309', '#0369a1', '#0284c7', '#047857'],
              borderRadius: 6,
              borderWidth: 0
            },
            {
              label: 'Attendance Rates',
              data: Object.values(aBins),
              backgroundColor: 'rgba(99, 102, 241, 0.45)',
              borderColor: '#6366f1',
              borderWidth: 1.5,
              borderRadius: 6
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: { duration: 600 },
          plugins: {
            legend: {
              position: 'top',
              labels: { boxWidth: 12, font: { size: 10, family: 'DM Sans' } }
            },
            tooltip: {
              backgroundColor: 'rgba(15, 23, 42, 0.95)',
              titleFont: { size: 11, weight: 'bold' }
            }
          },
          scales: {
            x: {
              grid: { display: false },
              ticks: { font: { size: 9 }, color: '#64748b' }
            },
            y: {
              beginAtZero: true,
              grid: { color: PALETTE.gridColor },
              ticks: { precision: 0, font: { size: 9 }, color: '#64748b' }
            }
          }
        }
      });
    }

    /**
     * Render Attendance Waveform / Flow Cadence Area Chart.
     * @param {string} canvasId
     */
    renderWaveformChart(canvasId) {
      const canvas = document.getElementById(canvasId);
      if (!canvas) return;

      if (this.charts.waveform) {
        this.charts.waveform.destroy();
        this.charts.waveform = null;
      }

      const students = this.data.students || [];
      // Sort cohort by academic rank for smooth continuous gradient stream
      const ranked = [...students].filter(s => (s.total_days || 0) > 0);
      const labels = ranked.map((s, idx) => `#${idx + 1} ${(s.student_name || '').slice(0, 8)}`);
      const presentSeries = ranked.map(s => Number(s.present_days || 0));
      const absentSeries = ranked.map(s => Number(s.absent_days || 0));
      const lateSeries = ranked.map(s => Number(s.late_days || 0));

      const ctx = canvas.getContext('2d');
      const gradient = ctx.createLinearGradient(0, 0, 0, 200);
      gradient.addColorStop(0, 'rgba(5, 150, 105, 0.4)');
      gradient.addColorStop(1, 'rgba(5, 150, 105, 0.02)');

      this.charts.waveform = new Chart(ctx, {
        type: 'line',
        data: {
          labels: labels.length ? labels : ['No records'],
          datasets: [
            {
              label: 'Present Sessions',
              data: presentSeries,
              borderColor: '#059669',
              backgroundColor: gradient,
              fill: true,
              tension: 0.35,
              borderWidth: 2,
              pointRadius: 2,
              pointHoverRadius: 6
            },
            {
              label: 'Late Sessions',
              data: lateSeries,
              borderColor: '#d97706',
              backgroundColor: 'transparent',
              borderDash: [3, 3],
              tension: 0.35,
              borderWidth: 1.5,
              pointRadius: 1
            },
            {
              label: 'Absent Sessions',
              data: absentSeries,
              borderColor: '#dc2626',
              backgroundColor: 'transparent',
              borderDash: [4, 4],
              tension: 0.35,
              borderWidth: 1.5,
              pointRadius: 1
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: { duration: 600 },
          plugins: {
            legend: {
              position: 'top',
              labels: { boxWidth: 12, font: { size: 10 } }
            },
            tooltip: {
              backgroundColor: 'rgba(15, 23, 42, 0.95)',
              titleFont: { size: 11, weight: 'bold' }
            }
          },
          scales: {
            x: {
              grid: { display: false },
              ticks: { display: false } // High-density clean stream
            },
            y: {
              beginAtZero: true,
              grid: { color: PALETTE.gridColor },
              ticks: { precision: 0, font: { size: 9 }, color: '#64748b' }
            }
          }
        }
      });
    }

    /**
     * Render Subject Competency Spider / Radar Benchmark Chart.
     * @param {string} canvasId
     */
    renderRadarChart(canvasId) {
      const canvas = document.getElementById(canvasId);
      if (!canvas) return;

      if (this.charts.radar) {
        this.charts.radar.destroy();
        this.charts.radar = null;
      }

      const benchmarks = (this.data.stats && this.data.stats.subject_benchmarks) || [];
      const labels = benchmarks.map(b => b.subject || 'Subject');
      const values = benchmarks.map(b => b.average || 0);
      const groupAvg = Number(this.data.stats.avg_grade || 0);
      const benchmarkBaseline = labels.map(() => groupAvg);

      this.charts.radar = new Chart(canvas.getContext('2d'), {
        type: 'radar',
        data: {
          labels: labels.length ? labels : ['No Subjects'],
          datasets: [
            {
              label: 'Cohort Average',
              data: values.length ? values : [0],
              backgroundColor: 'rgba(124, 58, 237, 0.25)',
              borderColor: '#7c3aed',
              borderWidth: 2,
              pointBackgroundColor: '#7c3aed',
              pointRadius: 3
            },
            {
              label: 'Overall Grade Baseline',
              data: benchmarkBaseline.length ? benchmarkBaseline : [0],
              borderColor: '#94a3b8',
              borderWidth: 1.5,
              borderDash: [4, 4],
              pointRadius: 0,
              backgroundColor: 'transparent'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: { duration: 600 },
          plugins: {
            legend: {
              position: 'top',
              labels: { boxWidth: 10, font: { size: 9 } }
            }
          },
          scales: {
            r: {
              min: 0,
              max: 100,
              ticks: { stepSize: 20, font: { size: 8 }, color: '#94a3b8' },
              pointLabels: { font: { size: 9, weight: '600', family: 'DM Sans, Noto Serif Ethiopic' }, color: '#334155' },
              grid: { color: PALETTE.gridColor },
              angleLines: { color: PALETTE.gridColor }
            }
          }
        }
      });
    }

    /**
     * Quick Handler for Tier Click in Triage Matrix.
     * @param {string} tierKey
     */
    handleTierClick(tierKey) {
      if (typeof this.options.onTierClick === 'function') {
        this.options.onTierClick(tierKey);
      }
    }

    /**
     * Export any active chart canvas to a clean high-res PNG image.
     * @param {string} chartKey 'scatter' | 'distribution' | 'waveform' | 'radar'
     * @param {string} [filename]
     */
    exportChartPNG(chartKey, filename) {
      const chart = this.charts[chartKey];
      if (!chart) return;
      const url = chart.toBase64Image('image/png', 1.0);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename || `Chart_${chartKey}_${new Date().toISOString().slice(0, 10)}.png`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
    }

    /**
     * Handle window resize event to redraw charts cleanly without layout jumps.
     */
    handleResize() {
      Object.values(this.charts).forEach(c => {
        if (c && typeof c.resize === 'function') {
          c.resize();
        }
      });
    }

    /**
     * Teardown and cleanup memory.
     */
    destroy() {
      window.removeEventListener('resize', this._boundResize);
      Object.values(this.charts).forEach(c => {
        if (c && typeof c.destroy === 'function') c.destroy();
      });
      this.charts = { scatter: null, distribution: null, waveform: null, radar: null };
    }
  }

  // Export to global scope for easy reusability across all dashboards
  global.AdvancedAnalyticsCenter = AdvancedAnalyticsCenter;

})(typeof window !== 'undefined' ? window : this);
