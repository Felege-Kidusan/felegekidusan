/// Save = draft (still editable). Submit = finished (view only).
/// Education can reopen with "needs revision".
class PacketLock {
  static bool isLocked(String? status, {bool flagged = false}) {
    if (flagged) return true;
    switch ((status ?? '').toLowerCase().trim()) {
      case 'submitted':
      case 'approved':
      case 'rejected':
        return true;
      default:
        return false;
    }
  }

  static String label(String? status) {
    switch ((status ?? '').toLowerCase().trim()) {
      case 'submitted':
        return 'Submitted';
      case 'approved':
        return 'Approved';
      case 'rejected':
        return 'Rejected';
      case 'revision_needed':
        return 'Needs revision';
      case 'draft':
      case 'incomplete':
        return 'Draft';
      default:
        return '';
    }
  }

  static String viewOnlyHint(String? status) {
    final label = PacketLock.label(status);
    if (label.isEmpty || label == 'Draft' || label == 'Needs revision') {
      return 'View only. Only Education can change this.';
    }
    return '$label — view only. Only Education can change this.';
  }
}
