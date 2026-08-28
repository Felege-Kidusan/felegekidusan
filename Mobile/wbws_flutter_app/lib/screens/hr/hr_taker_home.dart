import 'package:flutter/material.dart';

import '../../utils/theme.dart';

/// HR Attendance Taker home (department-owned taker).
///
/// Phase A landing: confirms the account and points at the workflow.
/// Phase B replaces the guidance card with the live section-sheet
/// entry points (HR attendance is section-based, like mezmur, and is
/// never combined with other departments' data).
class HrTakerHomeScreen extends StatefulWidget {
  const HrTakerHomeScreen({super.key});
  @override
  State<HrTakerHomeScreen> createState() => _HrTakerHomeScreenState();
}

class _HrTakerHomeScreenState extends State<HrTakerHomeScreen> {
  void refresh() {}

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.bgLight,
      appBar: AppBar(
        title: const Text('HR Attendance'),
        automaticallyImplyLeading: false,
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Container(
            padding: const EdgeInsets.all(18),
            decoration: BoxDecoration(
              color: AppTheme.cardLight,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: AppTheme.borderLight),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(children: [
                  Icon(Icons.people_outline_rounded, color: AppTheme.primary),
                  const SizedBox(width: 10),
                  const Expanded(
                    child: Text('HR section attendance',
                        style: TextStyle(fontSize: 15.5, fontWeight: FontWeight.w700)),
                  ),
                ]),
                const SizedBox(height: 10),
                Text(
                  'Your account belongs to the HR department only. HR takes '
                  'section-based attendance with its own takers — the data is '
                  'never combined with Education or Mezmur.',
                  style: TextStyle(fontSize: 12.5, height: 1.55, color: AppTheme.textSecondary),
                ),
                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AppTheme.warning.withOpacity(0.1),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Row(children: [
                    Icon(Icons.hourglass_top_rounded, size: 18, color: AppTheme.warning),
                    const SizedBox(width: 10),
                    const Expanded(
                      child: Text(
                        'The HR section sheet is being prepared for this app. '
                        'Until it ships, record HR attendance from the web console.',
                        style: TextStyle(fontSize: 12, height: 1.5),
                      ),
                    ),
                  ]),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
