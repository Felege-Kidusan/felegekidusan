import 'package:flutter/material.dart';
import '../utils/theme.dart';

/// Shown for work that stays on the website (create teacher, finance, etc.).
class UseWebsiteNote extends StatelessWidget {
  final String title;
  final String body;

  const UseWebsiteNote({
    super.key,
    this.title = 'Use the website for this',
    this.body =
        'This job stays on the FKSS website so student records stay in one place.',
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.computer_rounded, color: AppTheme.primary, size: 22),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title,
                      style: const TextStyle(
                          fontSize: 14, fontWeight: FontWeight.w700)),
                  const SizedBox(height: 4),
                  Text(body,
                      style: TextStyle(
                          fontSize: 12,
                          color: AppTheme.textSecondary,
                          height: 1.4)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
