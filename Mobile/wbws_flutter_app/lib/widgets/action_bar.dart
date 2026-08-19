import 'package:flutter/material.dart';
import '../utils/theme.dart';

/// Everyday Save (primary) + rare Submit (secondary).
/// Thumb-reach bar above the tabs — one meaning each, no duplicate Save.
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
                        child: FilledButton(
                          onPressed: busy ? null : onSave,
                          style: FilledButton.styleFrom(
                            backgroundColor: AppTheme.primary,
                            foregroundColor: Colors.white,
                            disabledBackgroundColor:
                                AppTheme.primary.withOpacity(0.45),
                            shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(14)),
                            elevation: 0,
                          ),
                          child: busy
                              ? const SizedBox(
                                  width: 18,
                                  height: 18,
                                  child: CircularProgressIndicator(
                                      strokeWidth: 2, color: Colors.white))
                              : Text(saveLabel,
                                  style: const TextStyle(
                                      fontWeight: FontWeight.w700,
                                      fontSize: 16)),
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
}
