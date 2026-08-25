import 'package:flutter/material.dart';

/// Momentum physics tuned for FAST, crisp scrolling — the Telegram Android
/// feel: a flick converts immediately into high-speed travel and stops
/// decisively. No floaty tail, no wobble, no animation-like mush.
///
/// Tuning rationale:
///  • [mass] 0.45 + [stiffness] 140 — a light list on a stiff spring reacts
///    instantly to finger velocity and settles fast.
///  • [ratio] 1.1 — over-damped: zero oscillation at rest, zero bounce-back.
///    (The previous under-damped 0.95 tuning made lists feel like they were
///    animating instead of obeying the finger.)
///  • [minFlingVelocity] 30 — even short flicks carry momentum.
///  • [maxFlingVelocity] 13000 — hard throws stay uncapped-feeling.
class SmoothScrollPhysics extends BouncingScrollPhysics {
  const SmoothScrollPhysics({super.parent});

  @override
  SmoothScrollPhysics applyTo(ScrollPhysics? ancestor) =>
      SmoothScrollPhysics(parent: buildParent(ancestor));

  @override
  SpringDescription get spring => SpringDescription.withDampingRatio(
        mass: 0.45,
        stiffness: 140.0,
        ratio: 1.1,
      );

  /// Lower threshold so short taps also trigger the momentum animation.
  @override
  double get minFlingVelocity => 30.0;

  @override
  double get maxFlingVelocity => 13000.0;
}

/// App-wide scroll behavior so every ListView, GridView, CustomScrollView,
/// and SingleChildScrollView inherits the same smooth physics without each
/// screen having to opt in.
///
/// Explicit `physics:` arguments on individual scrollables still win — they
/// wrap this behavior through `applyTo`, so e.g. `AlwaysScrollableScrollPhysics`
/// keeps pull-to-refresh working while delegating momentum to [SmoothScrollPhysics].
class SmoothScrollBehavior extends MaterialScrollBehavior {
  const SmoothScrollBehavior();

  @override
  ScrollPhysics getScrollPhysics(BuildContext context) =>
      const SmoothScrollPhysics();

  // The rubber-band overscroll IS the indicator; the Material glow/stretch
  // would fight it, so it is never built.
  @override
  Widget buildOverscrollIndicator(
      BuildContext context, Widget child, ScrollableDetails details) {
    return child;
  }
}

/// Pre-build distance ahead of the viewport so list rows are laid out before
/// they become visible — prevents hitching when new cards enter during a fast
/// fling. 800 px ≈ one extra screen height on most phones.
const double kListCacheExtent = 800.0;

/// Wraps a list item in a [RepaintBoundary] so the GPU only re-composites
/// that item's layer when it changes, not the entire list on every frame.
///
/// Usage: wrap itemBuilder's return value:
/// ```dart
/// itemBuilder: (context, i) => RepaintBoundaryListItem(
///   child: MyCard(items[i]),
/// ),
/// ```
class RepaintBoundaryListItem extends StatelessWidget {
  final Widget child;
  const RepaintBoundaryListItem({super.key, required this.child});

  @override
  Widget build(BuildContext context) => RepaintBoundary(child: child);
}
