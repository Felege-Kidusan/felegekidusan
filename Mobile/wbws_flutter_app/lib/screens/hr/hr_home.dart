import 'package:flutter/material.dart';

import '../../services/api_service.dart';
import '../../utils/theme.dart';
import '../reviews/review_inbox_screen.dart';

/// HR department home (Phase 9) — the department's mobile surface is
/// its review inbox; everything else stays on the web console.
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
      appBar: AppBar(title: const Text('HR Department')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          const Text('HR DEPARTMENT',
              style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w800,
                  letterSpacing: 1)),
          const SizedBox(height: 12),
          Material(
            color: AppTheme.cardLight,
            borderRadius: BorderRadius.circular(14),
            child: InkWell(
              borderRadius: BorderRadius.circular(14),
              onTap: () => Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) => const ReviewInboxScreen(dept: 'hr'))),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(
                        color: AppTheme.primary.withOpacity(0.1),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Icon(Icons.inbox_rounded,
                          color: AppTheme.primary, size: 26),
                    ),
                    const SizedBox(width: 14),
                    const Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('Attendance Reviews',
                              style: TextStyle(
                                  fontSize: 15,
                                  fontWeight: FontWeight.w800)),
                          SizedBox(height: 3),
                          Text(
                              'Approve or return the packets your takers submit.',
                              style: TextStyle(
                                  fontSize: 12,
                                  color: AppTheme.textSecondary)),
                        ],
                      ),
                    ),
                    const Icon(Icons.chevron_right_rounded),
                  ],
                ),
              ),
            ),
          ),
          const SizedBox(height: 20),
          Text('Signed in as ${_api.userName ?? ''}',
              style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
        ],
      ),
    );
  }
}
