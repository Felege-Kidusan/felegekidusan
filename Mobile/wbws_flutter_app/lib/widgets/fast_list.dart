import 'package:flutter/material.dart';

/// Telegram-style data-list row system.
///
/// What big apps do differently from a `Card` per row:
///  - ZERO elevation/shadow layers — a blurred shadow is one of the most
///    expensive paint operations and every visible row pays for it each frame.
///  - NO ink ripple inside rows — ripple registration/painting competes with
///    the fling. Rows use a plain opaque GestureDetector instead.
///  - FLAT colored containers with a hairline divider — one paint op per row.
///  - ZEBRA striping gives strong row contrast so names stay readable while
///    the list moves fast.
///
/// Pair with `itemExtent` on the ListView when rows are fixed-height: the
/// scrolling machinery then skips child measuring entirely
/// (SliverFixedExtentList) — the single biggest scroll-layout win in Flutter.
class FastListRow extends StatelessWidget {
  const FastListRow({
    super.key,
    required this.index,
    required this.child,
    this.height,
    this.onTap,
    this.padding = const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
  });

  /// Position in the list — drives zebra striping.
  final int index;
  final double? height;
  final Widget child;
  final VoidCallback? onTap;
  final EdgeInsetsGeometry padding;

  static const Color evenColor = Color(0xFFFFFFFF);
  static const Color oddColor = Color(0xFFF2F4F7);
  static const Color dividerColor = Color(0xFFE2E6EB);

  @override
  Widget build(BuildContext context) {
    final Widget row = Container(
      height: height,
      padding: padding,
      decoration: BoxDecoration(
        color: index.isEven ? evenColor : oddColor,
        border: const Border(
          bottom: BorderSide(color: dividerColor, width: 0.8),
        ),
      ),
      child: child,
    );
    if (onTap == null) return row;
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: onTap,
      child: row,
    );
  }
}

/// Fixed extent used with `ListView.builder(itemExtent: kFastRowHeight)` so
/// the viewport can layout rows without measuring them.
const double kFastRowHeight = 72;
