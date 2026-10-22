import 'package:flutter/material.dart';

/// "Did not take" (amber) / "Not entered yet" (grey) — same colours as the
/// web (#fef3c7 / #92400e). Owner 2026-10-22: a blank after submit means the
/// student did not take the test; it is shown, never counted.
class ScoreStateChip extends StatelessWidget {
  const ScoreStateChip({super.key, required this.notTaken, this.large = false});

  final bool notTaken;
  final bool large;

  static const Color amberBg = Color(0xFFFEF3C7);
  static const Color amberFg = Color(0xFF92400E);

  @override
  Widget build(BuildContext context) {
    final bg = notTaken ? amberBg : const Color(0xFFF3F4F6);
    final fg = notTaken ? amberFg : const Color(0xFF6B7280);
    return Container(
      padding: EdgeInsets.symmetric(horizontal: large ? 14 : 8, vertical: large ? 6 : 3),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)),
      child: Text(
        notTaken ? 'Did not take' : 'Not entered yet',
        style: TextStyle(fontSize: large ? 16 : 11, fontWeight: FontWeight.w700, color: fg),
      ),
    );
  }
}
