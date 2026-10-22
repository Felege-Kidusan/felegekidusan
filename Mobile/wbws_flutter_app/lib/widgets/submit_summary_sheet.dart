import 'package:flutter/material.dart';

import '../utils/theme.dart';
import 'score_state_chip.dart';

/// 1.7.3 submit summary (owner 2026-10-22): before a mark list is submitted
/// the teacher sees "47 scored · 3 Did not take: names…" and chooses
/// Submit or Go back. A blank box after submit means the student did not
/// take the test — this makes that visible before it happens.
///
/// Returns true only when the teacher taps Submit.
Future<bool> showSubmitSummarySheet(
  BuildContext context, {
  required String assessmentName,
  required int scored,
  required List<String> blankNames,
}) async {
  final ok = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
    builder: (ctx) => SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 18, 20, 16),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Submit marks?', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          const SizedBox(height: 4),
          Text(assessmentName, style: const TextStyle(fontSize: 13, color: AppTheme.textSecondary)),
          const SizedBox(height: 14),
          Text.rich(
            TextSpan(children: [
              TextSpan(text: '$scored scored', style: const TextStyle(fontWeight: FontWeight.w800, color: AppTheme.success)),
              if (blankNames.isNotEmpty) ...[
                const TextSpan(text: '  ·  '),
                TextSpan(
                    text: '${blankNames.length} Did not take',
                    style: const TextStyle(fontWeight: FontWeight.w800, color: ScoreStateChip.amberFg)),
              ],
            ]),
            key: const Key('submit-summary-counts'),
            style: const TextStyle(fontSize: 15),
          ),
          if (blankNames.isNotEmpty) ...[
            const SizedBox(height: 10),
            Container(
              width: double.infinity,
              constraints: const BoxConstraints(maxHeight: 180),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: ScoreStateChip.amberBg, borderRadius: BorderRadius.circular(10)),
              child: SingleChildScrollView(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(blankNames.join(', '),
                      key: const Key('submit-summary-names'),
                      style: const TextStyle(fontSize: 13, color: ScoreStateChip.amberFg, fontWeight: FontWeight.w600)),
                  const SizedBox(height: 6),
                  const Text('Leave a box empty ONLY if the student did not take the test.',
                      style: TextStyle(fontSize: 12, color: ScoreStateChip.amberFg)),
                ]),
              ),
            ),
          ],
          const SizedBox(height: 16),
          Row(children: [
            Expanded(
              child: OutlinedButton(
                key: const Key('submit-summary-back'),
                onPressed: () => Navigator.pop(ctx, false),
                child: const Text('Go back'),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: ElevatedButton(
                key: const Key('submit-summary-submit'),
                style: ElevatedButton.styleFrom(backgroundColor: AppTheme.primary, foregroundColor: Colors.white),
                onPressed: () => Navigator.pop(ctx, true),
                child: const Text('Submit'),
              ),
            ),
          ]),
        ]),
      ),
    ),
  );
  return ok == true;
}
