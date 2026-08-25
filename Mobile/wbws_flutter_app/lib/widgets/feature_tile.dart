import 'package:flutter/material.dart';
import '../utils/theme.dart';

/// A grid tile for feature/action grids. Used in More screen and quick actions.
class FeatureTile extends StatelessWidget {
  final String label;
  final IconData icon;
  final Color color;
  final VoidCallback? onTap;
  final String? badge;
  final bool enabled;

  const FeatureTile({
    super.key,
    required this.label,
    required this.icon,
    required this.color,
    this.onTap,
    this.badge,
    this.enabled = true,
  });

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: enabled ? 1.0 : 0.5,
      child: Material(
        color: AppTheme.cardLight,
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          onTap: enabled ? onTap : null,
          borderRadius: BorderRadius.circular(14),
            child: Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: AppTheme.borderLight, width: 0.8),
              ),
              child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Stack(
                  clipBehavior: Clip.none,
                  children: [
                    Container(
                      width: 44,
                      height: 44,
                      decoration: BoxDecoration(
                        color: color.withOpacity(0.12),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Icon(icon, color: color, size: 22),
                    ),
                    if (badge != null)
                      Positioned(
                        top: -4,
                        right: -4,
                        child: Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 5, vertical: 1),
                          decoration: BoxDecoration(
                            color: AppTheme.danger,
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: Text(badge!,
                              style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: 9,
                                  fontWeight: FontWeight.w700)),
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 10),
                Text(label,
                    style: const TextStyle(
                        fontSize: 12, fontWeight: FontWeight.w500),
                    textAlign: TextAlign.center,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis),
                if (!enabled) ...[
                  const SizedBox(height: 2),
                  Text('Coming soon',
                      style: TextStyle(
                          fontSize: 9, color: AppTheme.textSecondary)),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// A section header with optional action button
class SectionHeader extends StatelessWidget {
  final String title;
  final String? trailing;
  final VoidCallback? onAction;

  const SectionHeader({
    super.key,
    required this.title,
    this.trailing,
    this.onAction,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(title,
              style:
                  const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
          if (trailing != null)
            GestureDetector(
              onTap: onAction,
              child: Text(trailing!,
                  style: TextStyle(
                      fontSize: 12,
                      color: AppTheme.primary,
                      fontWeight: FontWeight.w500)),
            ),
        ],
      ),
    );
  }
}


