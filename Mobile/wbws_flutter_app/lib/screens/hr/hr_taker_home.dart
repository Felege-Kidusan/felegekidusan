import 'package:flutter/material.dart';

import '../../services/app_nav.dart';
import '../../utils/theme.dart';

/// HR Attendance Taker home (department-owned taker).
///
/// Phase B: HR attendance is section-based (like mezmur) and lives in
/// its own Attendance tab. HR data is never combined with Education
/// or Mezmur — separate takers, separate tables, separate reports.
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
                const SizedBox(height: 14),
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton.icon(
                    onPressed: () => AppNav().openHrAttendance(),
                    style: ElevatedButton.styleFrom(
                      padding: const EdgeInsets.symmetric(vertical: 13),
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12)),
                    ),
                    icon: const Icon(Icons.fact_check_outlined, size: 19),
                    label: const Text('Take HR attendance',
                        style: TextStyle(
                            fontSize: 14, fontWeight: FontWeight.w700)),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: AppTheme.cardLight,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: AppTheme.borderLight),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('How it works',
                    style: TextStyle(
                        fontSize: 13.5,
                        fontWeight: FontWeight.w700,
                        color: AppTheme.textPrimary)),
                const SizedBox(height: 8),
                Text(
                  '1. Pick a section and an Ethiopian date.\n'
                  '2. Mark every member Present / Absent / Late / Excused.\n'
                  '3. Save keeps a draft on this phone; Submit sends the sheet to the HR department.\n'
                  '4. If HR returns the sheet with a note, you can correct it and resubmit.',
                  style: TextStyle(
                      fontSize: 12.5,
                      height: 1.7,
                      color: AppTheme.textSecondary),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
