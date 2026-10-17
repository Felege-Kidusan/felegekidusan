/**
 * MemberNav — in-context navigation between member profiles and Family Link.
 *
 * The member profile (info_manage_member.php) is loaded into the dashboard's
 * slide-over sheet via openManageSheet(). Links inside that HTML must not
 * navigate the whole window away from the dashboard; they go through here.
 * Falls back to full-page navigation when no dashboard is present.
 */
(function (window, document) {
  'use strict';

  var stack = [];       // previously viewed member ids (for "Back")
  var current = 0;

  function hasSheet() { return typeof window.openManageSheet === 'function'; }

  function go(fromId, toId) {
    toId = parseInt(toId, 10);
    if (!toId) return false;
    if (hasSheet()) {
      fromId = parseInt(fromId, 10) || current;
      if (fromId && fromId !== toId) stack.push(fromId);
      window.openManageSheet(toId);
      return false;
    }
    window.location.href = '/admin/dashboard.php?member=' + encodeURIComponent(toId);
    return false;
  }

  function back() {
    var prev = stack.pop();
    if (prev && hasSheet()) { window.openManageSheet(prev); return false; }
    if (typeof window.closeManageSheet === 'function') window.closeManageSheet();
    return false;
  }

  /** Called by openManageSheet after the profile HTML is injected. */
  function afterLoad(id) {
    current = parseInt(id, 10) || 0;
    var btn = document.getElementById('mmBackBtn');
    if (btn) {
      if (stack.length) {
        btn.style.display = '';
        var label = btn.querySelector('[data-label]');
        if (label) label.textContent = 'Back to previous member';
      } else {
        btn.style.display = 'none';
      }
    }
  }

  function reset() { stack = []; current = 0; }

  function manageFamily(guardianId) {
    guardianId = parseInt(guardianId, 10);
    if (!guardianId) return false;
    if (window.FamilyLink && typeof window.navigateToSection === 'function' && document.getElementById('section-family')) {
      if (typeof window.closeManageSheet === 'function') window.closeManageSheet();
      window.navigateToSection('family');
      window.FamilyLink.open(guardianId);
      return false;
    }
    window.location.href = '/admin/dashboard.php?section=family&guardian=' + encodeURIComponent(guardianId);
    return false;
  }

  window.MemberNav = { go: go, back: back, afterLoad: afterLoad, reset: reset, manageFamily: manageFamily };
})(window, document);
