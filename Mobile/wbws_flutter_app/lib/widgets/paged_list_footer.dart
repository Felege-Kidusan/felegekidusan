import 'package:flutter/material.dart';

import '../utils/theme.dart';

/// Phase 3 paging: the one footer every capped mobile list uses.
///
///   Showing 50 of 440 members
///   [ Load more (390 left) ]        or        ✓ All 440 shown
///
/// [total] null = the server did not say (old server) — the footer then
/// shows only the count on screen and offers Load more while [hasMore].
class PagedListFooter extends StatelessWidget {
  final int shown;
  final int? total;
  final bool loading;
  final bool hasMore;
  final String noun;
  final VoidCallback? onLoadMore;

  const PagedListFooter({
    super.key,
    required this.shown,
    required this.total,
    required this.noun,
    this.loading = false,
    bool? hasMore,
    this.onLoadMore,
  }) : hasMore = hasMore ?? (total != null && shown < total);

  /// Remaining rows, never negative; null when the total is unknown.
  int? get left => total == null ? null : (total! - shown).clamp(0, total!);

  /// "Showing 50 of 440 members" — pure, unit-tested.
  static String label(int shown, int? total, String noun) {
    if (total == null) return 'Showing $shown $noun';
    final s = shown > total ? total : shown;
    return 'Showing $s of $total $noun';
  }

  @override
  Widget build(BuildContext context) {
    final more = hasMore && onLoadMore != null;
    return Padding(
      key: const ValueKey('paged-list-footer'),
      padding: const EdgeInsets.fromLTRB(4, 10, 4, 4),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            label(shown, total, noun),
            style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary),
          ),
          const SizedBox(height: 6),
          if (more)
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                key: const ValueKey('paged-list-load-more'),
                onPressed: loading ? null : onLoadMore,
                icon: loading
                    ? const SizedBox(
                        width: 14,
                        height: 14,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.expand_more, size: 18),
                label: Text(loading
                    ? 'Loading…'
                    : (left != null ? 'Load more ($left left)' : 'Load more')),
                style: OutlinedButton.styleFrom(
                  foregroundColor: AppTheme.primary,
                  side: const BorderSide(color: AppTheme.borderLight),
                ),
              ),
            )
          else if (total != null && shown > 0)
            Text(
              '✓ All $total shown',
              key: const ValueKey('paged-list-all-shown'),
              style: const TextStyle(
                  fontSize: 12,
                  color: AppTheme.success,
                  fontWeight: FontWeight.w600),
            ),
        ],
      ),
    );
  }
}
