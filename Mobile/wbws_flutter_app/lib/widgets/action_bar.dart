import 'package:flutter/material.dart';
import '../utils/theme.dart';

/// Gold Save + maroon Submit, sitting above the bottom tabs
/// so a teacher can reach them with a thumb.
class TeacherActionBar extends StatelessWidget {
  final String saveLabel;
  final String submitLabel;
  final VoidCallback? onSave;
  final VoidCallback? onSubmit;
  final bool busy;
  final String? hint;

  const TeacherActionBar({
    super.key,
    this.saveLabel = 'Save',
    this.submitLabel = 'Submit',
    this.onSave,
    this.onSubmit,
    this.busy = false,
    this.hint,
  });

  @override
  Widget build(BuildContext context) {
    return Material(
      elevation: 8,
      color: Colors.white,
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (hint != null && hint!.isNotEmpty) ...[
                Text(hint!,
                    style: TextStyle(fontSize: 11, color: AppTheme.textSecondary),
                    textAlign: TextAlign.center),
                const SizedBox(height: 8),
              ],
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: busy ? null : onSave,
                      style: OutlinedButton.styleFrom(
                        foregroundColor: AppTheme.primary,
                        side: const BorderSide(color: AppTheme.primary, width: 1.4),
                        padding: const EdgeInsets.symmetric(vertical: 14),
                      ),
                      child: busy
                          ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                          : Text(saveLabel, style: const TextStyle(fontWeight: FontWeight.w700)),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    flex: 2,
                    child: ElevatedButton(
                      onPressed: busy ? null : onSubmit,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: AppTheme.accent,
                        foregroundColor: AppTheme.primaryDark,
                        padding: const EdgeInsets.symmetric(vertical: 14),
                      ),
                      child: busy
                          ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                          : Text(submitLabel, style: const TextStyle(fontWeight: FontWeight.w800)),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
