import 'dart:async';
import 'package:flutter/material.dart';
import '../utils/theme.dart';

OverlayEntry? _activeConfirm;
OverlayEntry? _activeUndo;

/// Bottom toast with a 5-second circular countdown and an UNDO action
/// (Gmail/Telegram pattern). When the ring empties the toast removes itself
/// and the submission stays locked — exactly one live toast at a time.
void showUndoToast(
  BuildContext context, {
  required String message,
  required Future<void> Function() onUndo,
  int seconds = 5,
}) {
  final OverlayState overlay = Overlay.of(context, rootOverlay: true);
  _activeUndo?.remove();
  late final OverlayEntry entry;
  entry = OverlayEntry(
    builder: (_) => _UndoToast(
      message: message,
      seconds: seconds,
      onUndo: () async {
        if (identical(_activeUndo, entry)) _activeUndo = null;
        if (entry.mounted) entry.remove();
        await onUndo();
      },
      onClose: () {
        if (identical(_activeUndo, entry)) _activeUndo = null;
        if (entry.mounted) entry.remove();
      },
    ),
  );
  _activeUndo = entry;
  overlay.insert(entry);
}

class _UndoToast extends StatefulWidget {
  final String message;
  final int seconds;
  final VoidCallback onUndo;
  final VoidCallback onClose;

  const _UndoToast({
    required this.message,
    required this.seconds,
    required this.onUndo,
    required this.onClose,
  });

  @override
  State<_UndoToast> createState() => _UndoToastState();
}

class _UndoToastState extends State<_UndoToast>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: Duration(seconds: widget.seconds),
    );
    _controller.forward().whenComplete(widget.onClose);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _undo() => widget.onUndo();

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.of(context).padding.bottom;
    return Positioned(
      left: 16,
      right: 16,
      bottom: bottomInset + 24,
      child: IgnorePointer(
        ignoring: false,
        child: Material(
          color: Colors.transparent,
          elevation: 8,
          shadowColor: Colors.black45,
          borderRadius: BorderRadius.circular(14),
          child: Container(
            padding:
                const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
            decoration: BoxDecoration(
              color: const Color(0xFF2B2B2F),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Row(
              children: [
                Expanded(
                  child: Text(widget.message,
                      style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w600,
                          fontSize: 13)),
                ),
                TextButton(
                  onPressed: _undo,
                  style: TextButton.styleFrom(
                    foregroundColor: AppTheme.accent,
                    padding: const EdgeInsets.symmetric(horizontal: 10),
                    minimumSize: Size.zero,
                    tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                  ),
                  child: const Text('UNDO',
                      style: TextStyle(
                          fontWeight: FontWeight.w800, fontSize: 13)),
                ),
                const SizedBox(width: 4),
                AnimatedBuilder(
                  animation: _controller,
                  builder: (_, __) => SizedBox(
                    width: 20,
                    height: 20,
                    child: Stack(
                      alignment: Alignment.center,
                      children: [
                        CircularProgressIndicator(
                          value: 1 - _controller.value,
                          strokeWidth: 2.2,
                          valueColor: const AlwaysStoppedAnimation<Color>(
                              AppTheme.accent),
                          backgroundColor: Colors.white24,
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Telegram-style transient confirmation: a small green pill ("✓ Saved")
/// that floats below the app bar, pops in, and fades away on its own.
/// Non-blocking and duplicate-safe — a rapid second save replaces the
/// first pill instead of stacking.
void showQuickConfirm(
  BuildContext context,
  String message, {
  IconData icon = Icons.check_circle_rounded,
}) {
  final OverlayState overlay = Overlay.of(context, rootOverlay: true);
  _activeConfirm?.remove();
  late final OverlayEntry entry;
  entry = OverlayEntry(
    builder: (_) => _QuickConfirmPill(
      message: message,
      icon: icon,
      onDone: () {
        if (identical(_activeConfirm, entry)) _activeConfirm = null;
        if (entry.mounted) entry.remove();
      },
    ),
  );
  _activeConfirm = entry;
  overlay.insert(entry);
}

class _QuickConfirmPill extends StatefulWidget {
  final String message;
  final IconData icon;
  final VoidCallback onDone;

  const _QuickConfirmPill({
    required this.message,
    required this.icon,
    required this.onDone,
  });

  @override
  State<_QuickConfirmPill> createState() => _QuickConfirmPillState();
}

class _QuickConfirmPillState extends State<_QuickConfirmPill>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  late final Animation<double> _fade;
  late final Animation<double> _scale;
  Timer? _holdTimer;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1150),
    );
    // In fast (180 ms), hold, out (260 ms) — composed on one controller.
    _fade = TweenSequence<double>([
      TweenSequenceItem(tween: Tween(begin: 0.0, end: 1.0)
          .chain(CurveTween(curve: Curves.easeOut)), weight: 16),
      TweenSequenceItem(tween: ConstantTween(1.0), weight: 62),
      TweenSequenceItem(tween: Tween(begin: 1.0, end: 0.0)
          .chain(CurveTween(curve: Curves.easeIn)), weight: 22),
    ]).animate(_controller);
    _scale = TweenSequence<double>([
      TweenSequenceItem(tween: Tween(begin: 0.92, end: 1.0)
          .chain(CurveTween(curve: Curves.easeOutBack)), weight: 16),
      TweenSequenceItem(tween: ConstantTween(1.0), weight: 84),
    ]).animate(_controller);

    _controller.forward();
    _holdTimer = Timer(const Duration(milliseconds: 1250), widget.onDone);
  }

  @override
  void dispose() {
    _holdTimer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final topPadding = MediaQuery.of(context).padding.top;
    return Positioned(
      top: topPadding + kToolbarHeight + 10,
      left: 0,
      right: 0,
      child: IgnorePointer(
        child: FadeTransition(
          opacity: _fade,
          child: ScaleTransition(
            scale: _scale,
            child: Center(
              child: Material(
                color: Colors.transparent,
                elevation: 6,
                shadowColor: Colors.black38,
                borderRadius: BorderRadius.circular(30),
                child: Container(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 18, vertical: 10),
                  decoration: BoxDecoration(
                    color: AppTheme.success,
                    borderRadius: BorderRadius.circular(30),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(widget.icon,
                          size: 18, color: Colors.white),
                      const SizedBox(width: 8),
                      Text(widget.message,
                          style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w700,
                              fontSize: 14)),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
