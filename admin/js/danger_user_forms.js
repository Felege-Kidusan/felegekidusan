/**
 * Danger confirmation for the user-account forms (super-admin.php Users
 * section and admin/users.php). Requires admin/js/danger_confirm.js.
 *
 *  form#dcDeleteUserForm  — HIGH: password (server) + 3 steps + typed
 *                           DELETE USER (server-checked, users.delete).
 *     data-username / data-fullname / data-role
 *  form#dcUserSaveForm    — MEDIUM when an existing user's role changes or
 *                           the account is deactivated; LOW when re-activated;
 *                           no dialog for other edits or for new users.
 *     data-orig-role / data-orig-active / data-username (edit mode only)
 */
(function () {
  'use strict';
  function label(r) { return String(r || '').replace(/_/g, ' '); }

  function wire() {
    var DC = window.DangerConfirm;
    if (!DC || !DC.guardForm) return;

    var del = document.getElementById('dcDeleteUserForm');
    if (del) DC.guardForm(del, function (f) {
      var u = f.dataset.username || '';
      return {
        tier: 'high',
        title: 'Delete user ' + u + '?',
        object: (f.dataset.fullname || u) + ' (' + label(f.dataset.role) + ')',
        summary: 'The login account is deleted permanently. Set Status to Inactive instead if the person may come back.',
        intent: 'I want to delete this user',
        consequences: [
          'The person can never log in again with this account (web and mobile app).',
          'Their class/subject teaching assignments are removed.',
          'Tasks and notifications addressed to them are detached.'
        ],
        keeps: ['Attendance, marks and history they recorded stay (the author link is cleared).'],
        irreversible: true,
        phrase: 'DELETE USER',
        confirmLabel: 'Delete user'
      };
    });

    var sv = document.getElementById('dcUserSaveForm');
    if (sv && sv.dataset.origRole !== undefined) DC.guardForm(sv, function (f) {
      var role = (f.querySelector('[name=role]') || {}).value;
      var act = String((f.querySelector('[name=is_active]') || {}).value);
      var orig = f.dataset.origRole, u = f.dataset.username || '';
      var roleCh = role !== orig;
      var deact = f.dataset.origActive === '1' && act === '0';
      var react = f.dataset.origActive === '0' && act === '1';
      if (deact) return {
        tier: 'medium', title: 'Deactivate ' + u + '?', summary: 'The account is switched off but not deleted.',
        intent: 'I want to deactivate this account',
        consequences: ['The user can no longer log in on the web dashboard or the mobile app.']
          .concat(roleCh ? ['The role also changes to ' + label(role) + '.'] : []),
        keeps: ['Everything they recorded stays.', 'You can activate the account again later.'],
        phrase: 'DEACTIVATE USER', confirmLabel: 'Deactivate account'
      };
      if (roleCh) return {
        tier: 'medium', title: 'Change the role of ' + u + '?', object: label(orig) + '  \u2192  ' + label(role),
        summary: 'The role decides which dashboard and data this person can open.',
        intent: 'I want to change this role',
        consequences: [
          'On next login the user gets the ' + label(role) + ' dashboard and permissions.',
          'They lose access to everything only the ' + label(orig) + ' role could see or change.'
        ],
        keeps: ['Their recorded history stays.'],
        phrase: 'CHANGE ROLE', confirmLabel: 'Change role'
      };
      if (react) return { tier: 'low', title: 'Activate ' + u + '?', summary: 'The user can log in again.', confirmLabel: 'Activate account' };
      return null;
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wire);
  else wire();
})();
