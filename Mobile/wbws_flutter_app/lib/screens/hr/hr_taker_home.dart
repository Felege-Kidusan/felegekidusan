import 'package:flutter/material.dart';

import '../../utils/theme.dart';
import '../../widgets/notification_bell_button.dart';

/// HR Attendance Taker home — RETIREMENT NOTICE (2026-10-07).
///
/// HR no longer takes attendance: the department reads combined
/// Education + Mezmur attendance reports on the school web dashboard
/// instead. Existing taker accounts keep working (login, profile,
/// notifications); the section-attendance screen is gone and the
/// server answers 410 HR_ATTENDANCE_RETIRED to any old write.
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
        title: const Text('HR Department'),
        automaticallyImplyLeading: false,
        actions: [const NotificationBellButton(color: Colors.white),],
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
                  Icon(Icons.event_busy_rounded, color: AppTheme.primary),
                  const SizedBox(width: 10),
                  const Expanded(
                    child: Text('HR attendance was retired',
                        style: TextStyle(fontSize: 15.5, fontWeight: FontWeight.w700)),
                  ),
                ]),
                const SizedBox(height: 10),
                Text(
                  'HR no longer takes attendance. Attendance reports from the '
                  'Education and Mezmur departments are on the school web '
                  'dashboard (HR → Attendance Reports). '
                  'Your account stays for signing in — there is no attendance '
                  'sheet to take anymore.',
                  style: TextStyle(fontSize: 12.5, height: 1.55, color: AppTheme.textSecondary),
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
                Text('What happens to old sheets?',
                    style: TextStyle(
                        fontSize: 13.5,
                        fontWeight: FontWeight.w700,
                        color: AppTheme.textPrimary)),
                const SizedBox(height: 8),
                Text(
                  'Every sheet already recorded stays saved and readable — '
                  'nothing was deleted. Sheets that were still waiting to '
                  'send will show an honest "HR attendance was retired" '
                  'message in Sync instead of failing silently.',
                  style: TextStyle(
                      fontSize: 12.5, height: 1.7, color: AppTheme.textSecondary),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
