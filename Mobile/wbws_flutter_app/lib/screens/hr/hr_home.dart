import 'package:flutter/material.dart';

import '../../services/api_service.dart';
import '../../utils/theme.dart';
import '../../widgets/notification_bell_button.dart';

/// HR department home — RETIRED ATTENDANCE (2026-10-07).
///
/// HR no longer takes or reviews attendance: the department reads
/// combined Education + Mezmur attendance reports on the school web
/// dashboard instead. The mobile review inbox is gone with the
/// workflow it reviewed; this home stays for login, profile and
/// notifications.
class HrDeptHomeScreen extends StatefulWidget {
  const HrDeptHomeScreen({super.key});

  @override
  State<HrDeptHomeScreen> createState() => _HrDeptHomeScreenState();
}

class _HrDeptHomeScreenState extends State<HrDeptHomeScreen> {
  final _api = ApiService();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('HR Department'), actions: [const NotificationBellButton(color: Colors.white),]),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          const Text('HR DEPARTMENT',
              style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w800,
                  letterSpacing: 1)),
          const SizedBox(height: 12),
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
                  Icon(Icons.insights_outlined, color: AppTheme.primary),
                  const SizedBox(width: 10),
                  const Expanded(
                    child: Text('Attendance reports moved to the dashboard',
                        style: TextStyle(fontSize: 15.5, fontWeight: FontWeight.w700)),
                  ),
                ]),
                const SizedBox(height: 10),
                Text(
                  'HR no longer takes or reviews attendance. Combined '
                  'attendance reports from the Education and Mezmur '
                  'departments are on the school web dashboard '
                  '(HR → Attendance Reports). Everything recorded before '
                  'the change stays saved and readable there.',
                  style: TextStyle(fontSize: 12.5, height: 1.55, color: AppTheme.textSecondary),
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),
          Text('Signed in as ${_api.userName}',
              style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
        ],
      ),
    );
  }
}
