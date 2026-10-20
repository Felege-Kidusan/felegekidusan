/**
 * SchoolCalendarWidget — class-day date status banner + save guard (P2).
 * Consumes the `date_status` object returned by the attendance read APIs
 * ({ok, code, label, detail, is_makeup}) and explains, before anyone taps
 * save, why a date can or cannot take attendance (owner rules 2026-10-20).
 */
(function () {
  'use strict';
  var STYLE = ''
    + '.scw-banner{border-radius:10px;padding:9px 12px;font-size:13px;line-height:1.5;margin:8px 0;display:flex;gap:8px;align-items:flex-start}'
    + '.scw-banner .scw-ico{font-size:15px;line-height:1.3}'
    + '.scw-ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}'
    + '.scw-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412}'
    + '.scw-bad{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}'
    + '.scw-info{background:#f0f9ff;border:1px solid #bae6fd;color:#0c4a6e}';
  if (!document.getElementById('scw-style')) {
    var st = document.createElement('style');
    st.id = 'scw-style';
    st.textContent = STYLE;
    document.head.appendChild(st);
  }

  var MESSAGES = {
    OK: ['ok', '✓', 'This is a class day — attendance can be recorded.'],
    FUTURE_DATE: ['bad', '🔒', 'Attendance opens on the class day. Future dates cannot be recorded.'],
    BACKDATE_LIMIT: ['bad', '🔒', 'This date is too far in the past. Attendance can be recorded up to 45 days back.'],
    NOT_CONFIGURED: ['bad', '⚙', 'Education has not set this class’s working days yet, so attendance is blocked for it.'],
    NOT_CLASS_DAY: ['warn', '⬜', 'This is not a class day for this class — the timetable has no lessons on this weekday.'],
    OUT_OF_YEAR: ['bad', '🔒', 'This date is outside the active academic year.'],
    HOLIDAY: ['warn', '🟣', 'Holiday — no classes, no attendance.'],
    DAY_OFF: ['warn', '🔵', 'Day Off — classes do not hold today.']
  };

  function render(bannerId, status) {
    var el = typeof bannerId === 'string' ? document.getElementById(bannerId) : bannerId;
    if (!el) return;
    if (!status) { el.innerHTML = ''; el.style.display = 'none'; return; }
    var key = status.ok ? 'OK' : (status.code || 'FUTURE_DATE');
    if (status.ok && status.is_makeup) key = 'OK';
    var m = MESSAGES[key] || ['warn', '•', status.detail || ''];
    var cls = m[0], ico = m[1], text = m[2];
    if (status.detail && key !== 'OK') text = status.detail;
    if (status.ok && status.is_makeup) { cls = 'info'; ico = '⚜'; text = 'Makeup class day — attendance can be recorded for the makeup class.'; }
    else if (status.ok && status.label) { text = text + ' (' + status.label + ')'; }
    el.className = 'scw-banner scw-' + cls;
    el.style.display = '';
    el.innerHTML = '<span class="scw-ico">' + ico + '</span><span>' + String(text).replace(/[<>&]/g, function (c) {
      return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c];
    }) + '</span>';
    window.__scDateStatus = status;
  }

  function allowSave(showToast) {
    var s = window.__scDateStatus;
    if (s && s.ok === false) {
      if (typeof showToast === 'function') showToast(s.detail || 'Attendance cannot be recorded for this date.', 'error');
      return false;
    }
    return true;
  }

  window.SchoolCalendarWidget = { render: render, allowSave: allowSave };
})();
