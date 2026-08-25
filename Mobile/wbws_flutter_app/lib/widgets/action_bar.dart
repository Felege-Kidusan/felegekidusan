import 'package:flutter/foundation.dart' show ValueListenable;
import 'package:flutter/material.dart';
import '../utils/theme.dart';

/// Everyday Save (primary) + rare Submit (secondary).
/// Thumb-reach bar above the tabs — one meaning each, no duplicate Save.
///
/// [saveEnabled] drives WhatsApp-style state visibility: when the sheet has
/// no unsaved changes the Save button is grayed out, and lights up the
/// moment anything changes.
class TeacherActionBar extends StatelessWidget {
  final String saveLabel;
  final String submitLabel;
  final VoidCallback? onSave;
  final VoidCallback? onSubmit;
  final bool busy;

  /// Live dirty-flag; null means "always enabled".
  final ValueListenable<bool>? saveEnabled;
  final String? hint;

  const TeacherActionBar({
    super.key,
    this.saveLabel = 'Save',
    this.submitLabel = 'Submit',
    this.onSave,
    this.onSubmit,
    this.busy = false,
    this.saveEnabled,
    this.hint,
  });

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white,
      child: Container(
        decoration: const BoxDecoration(
          border: Border(top: BorderSide(color: Color(0xFFE8E8E8))),
        ),
        child: SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Row(
                  children: [
                    Expanded(
                      flex: 5,
                      child: SizedBox(
                        height: 48,
                        child: saveEnabled == null
                            ? _saveButton(context)
                            : ValueListenableBuilder<bool>(
                                valueListenable: saveEnabled!,
                                builder: (_, ok, __) =>
                                    _saveButton(context, enabled: ok),
                              ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      flex: 4,
                      child: SizedBox(
                        height: 48,
                        child: OutlinedButton(
                          onPressed: busy ? null : onSubmit,
                          style: OutlinedButton.styleFrom(
                            foregroundColor: AppTheme.primary,
                            side: const BorderSide(
                                color: AppTheme.primary, width: 1.3),
                            shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(14)),
                          ),
                          child: Text(submitLabel,
                              style: const TextStyle(
                                  fontWeight: FontWeight.w700, fontSize: 15)),
                        ),
                      ),
                    ),
                  ],
                ),
                if (hint != null && hint!.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Text(hint!,
                      style: TextStyle(
                          fontSize: 11, color: AppTheme.textSecondary, height: 1.3),
                      textAlign: TextAlign.center),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _saveButton(BuildContext context, {bool enabled = true}) {
    return FilledButton(
      onPressed: (busy || !enabled) ? null : onSave,
      style: FilledButton.styleFrom(
        backgroundColor:
            enabled ? AppTheme.primary : AppTheme.textSecondary.withOpacity(0.35),
        foregroundColor: Colors.white,
        disabledBackgroundColor: AppTheme.textSecondary.withOpacity(0.30),
        disabledForegroundColor: Colors.white70,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        elevation: 0,
      ),
      child: busy
          ? const SizedBox(
              width: 18,
              height: 18,
              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
          : Text(saveLabel,
              style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
    );
  }
}

/// Persistent at-a-glance confirmation shown while a packet is submitted
/// and locked — replaces hiding the action bar so the status is always
/// visible on the screen itself.
class SubmittedBar extends StatelessWidget {
  final String label;
  const SubmittedBar({super.key, this.label = 'Submitted'});

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white,
      child: Container(
        decoration: const BoxDecoration(
          border: Border(top: BorderSide(color: Color(0xFFE8E8E8))),
        ),
        child: SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Icon(Icons.check_circle_rounded,
                    size: 20, color: AppTheme.success),
                const SizedBox(width: 8),
                Text(label,
                    style: const TextStyle(
                        color: AppTheme.success,
                        fontWeight: FontWeight.w800,
                        fontSize: 15)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
