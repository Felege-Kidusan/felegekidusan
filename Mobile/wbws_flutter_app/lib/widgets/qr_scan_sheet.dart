import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../services/qr_attendance.dart';

/// ════════════════════════════════════════════════════════════
/// QR scan sheet (Phase 8) — full-screen scanner, big Amharic
/// feedback, one-hand-friendly controls.
///
/// UX decisions (Material/Apple HIG + scanner-app conventions):
///  - every control ≥ 48dp; the two primary controls (torch, close)
///    are 64dp circles in the BOTTOM thumb zone, not cornered icons;
///  - an animated corner-bracket viewfinder shows where to aim and
///    pulses while the camera is live (Google-Lens affordance);
///  - outcomes flash as full-screen 30sp Amharic overlays with a
///    scale-in animation and distinct haptics; the line never stops.
///
/// The widget is pure UI: business rules live in the calling screen's
/// [onScan] (roster membership, duplicates, lock) which returns a
/// [QrFeedback]. Decoding is 100% on-device (ML Kit) — works offline.
/// ════════════════════════════════════════════════════════════
class QrScanSheet extends StatefulWidget {
  /// Resolves a raw scan and applies the department's rules.
  final Future<QrFeedback> Function(String? raw) onScan;
  final String header;

  const QrScanSheet({super.key, required this.onScan, required this.header});

  static Future<void> open(
    BuildContext context, {
    required Future<QrFeedback> Function(String? raw) onScan,
    required String header,
  }) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.black,
      builder: (_) => QrScanSheet(onScan: onScan, header: header),
    );
  }

  @override
  State<QrScanSheet> createState() => _QrScanSheetState();
}

class _QrScanSheetState extends State<QrScanSheet>
    with SingleTickerProviderStateMixin {
  final MobileScannerController _ctrl = MobileScannerController();
  late final AnimationController _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1100),
    lowerBound: 0.35,
    upperBound: 1.0,
  )..repeat(reverse: true);

  QrFeedback? _feedback;
  int _feedbackKey = 0;
  Timer? _feedbackTimer;
  Timer? _cooldown;
  String _lastRaw = '';
  DateTime _lastAt = DateTime.fromMillisecondsSinceEpoch(0);
  bool _busy = false;
  bool _torch = false;

  @override
  void dispose() {
    _pulse.dispose();
    _feedbackTimer?.cancel();
    _cooldown?.cancel();
    _ctrl.dispose();
    super.dispose();
  }

  void _onDetect(BarcodeCapture capture) {
    if (_busy) return;
    for (final b in capture.barcodes) {
      final raw = b.rawValue ?? '';
      if (raw.isEmpty) continue;
      final now = DateTime.now();
      // Same code inside the dedupe window → ignore (the camera sees
      // a card for many frames; one scan = one decision).
      if (raw == _lastRaw &&
          now.difference(_lastAt) < const Duration(milliseconds: 2500)) {
        continue;
      }
      _lastRaw = raw;
      _lastAt = now;
      _resolve(raw);
      return;
    }
  }

  Future<void> _resolve(String raw) async {
    _busy = true;
    final feedback = await widget.onScan(raw);
    _cooldown?.cancel();
    _cooldown = Timer(const Duration(milliseconds: 600), () {
      if (mounted) setState(() => _busy = false);
    });
    if (!mounted) return;

    if (feedback.isSuccess) {
      HapticFeedback.mediumImpact();
    } else if (feedback.isWarning) {
      HapticFeedback.selectionClick();
    } else {
      HapticFeedback.vibrate();
    }

    setState(() {
      _feedback = feedback;
      _feedbackKey++;
    });
    _feedbackTimer?.cancel();
    _feedbackTimer = Timer(const Duration(milliseconds: 2200), () {
      if (mounted) setState(() => _feedback = null);
    });
  }

  Color _color(QrFeedback f) {
    if (f.isSuccess) return const Color(0xFF16A34A);
    if (f.isWarning) return const Color(0xFFD97706);
    return const Color(0xFFDC2626);
  }

  Future<void> _toggleTorch() async {
    try {
      await _ctrl.toggleTorch();
      setState(() => _torch = !_torch);
      HapticFeedback.lightImpact();
    } catch (_) {}
  }

  /// 64dp circle in the bottom thumb zone (≥48dp rule, one-hand use).
  Widget _thumbButton({
    required IconData icon,
    required String label,
    required bool active,
    required VoidCallback onTap,
  }) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: onTap,
      child: SizedBox(
        width: 84,
        height: 84,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            AnimatedContainer(
              duration: const Duration(milliseconds: 180),
              curve: Curves.easeOut,
              width: 64,
              height: 64,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: active ? Colors.amber : Colors.white12,
                border: Border.all(
                    color: active ? Colors.amber : Colors.white38, width: 2),
              ),
              child: Icon(icon,
                  size: 30, color: active ? Colors.black : Colors.white),
            ),
            const SizedBox(height: 6),
            Text(label,
                style: const TextStyle(
                    color: Colors.white70,
                    fontSize: 12,
                    fontWeight: FontWeight.w600)),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: ClipRRect(
        borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
        child: Stack(
          children: [
            MobileScanner(controller: _ctrl, onDetect: _onDetect),

            // animated corner-bracket viewfinder
            Center(
              child: AnimatedBuilder(
                animation: _pulse,
                builder: (context, _) => CustomPaint(
                  size: const Size(260, 260),
                  painter: _CornerPainter(_pulse.value),
                ),
              ),
            ),

            // slim top title pill
            Positioned(
              top: 8,
              left: 16,
              right: 16,
              child: Center(
                child: Container(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 16, vertical: 8),
                  decoration: BoxDecoration(
                    color: Colors.black54,
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.qr_code_scanner,
                          color: Colors.white70, size: 18),
                      const SizedBox(width: 8),
                      Flexible(
                        child: Text(widget.header,
                            style: const TextStyle(
                                color: Colors.white,
                                fontSize: 14,
                                fontWeight: FontWeight.w600),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis),
                      ),
                    ],
                  ),
                ),
              ),
            ),

            // bottom thumb zone: TORCH + CLOSE, both 64dp circles
            Positioned(
              bottom: 0,
              left: 0,
              right: 0,
              child: Container(
                color: Colors.black87,
                padding: const EdgeInsets.fromLTRB(24, 14, 24, 18),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceAround,
                  children: [
                    _thumbButton(
                      icon: _torch ? Icons.flash_on : Icons.flash_off,
                      label: 'ብርሃን',
                      active: _torch,
                      onTap: _toggleTorch,
                    ),
                    const Flexible(
                      child: Padding(
                        padding: EdgeInsets.symmetric(horizontal: 8),
                        child: Text('የአባሉን ክውእር ኮድ ወደ ካሜራው የቅርቡ',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                                color: Colors.white60, fontSize: 13)),
                      ),
                    ),
                    _thumbButton(
                      icon: Icons.close,
                      label: 'ዝጋ',
                      active: false,
                      onTap: () => Navigator.pop(context),
                    ),
                  ],
                ),
              ),
            ),

            // big Amharic feedback overlay with scale-in
            if (_feedback != null)
              Positioned.fill(
                child: IgnorePointer(
                  child: TweenAnimationBuilder<double>(
                    key: ValueKey(_feedbackKey),
                    tween: Tween(begin: 0.86, end: 1),
                    duration: const Duration(milliseconds: 200),
                    curve: Curves.easeOutCubic,
                    builder: (context, v, child) =>
                        Transform.scale(scale: v, child: child),
                    child: Container(
                      color: _color(_feedback!).withOpacity(0.9),
                      alignment: Alignment.center,
                      padding: const EdgeInsets.all(28),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            _feedback!.isSuccess
                                ? Icons.check_circle
                                : (_feedback!.isWarning
                                    ? Icons.replay
                                    : Icons.error),
                            color: Colors.white,
                            size: 84,
                          ),
                          const SizedBox(height: 16),
                          Text(
                            _feedback!.title,
                            textAlign: TextAlign.center,
                            style: const TextStyle(
                                color: Colors.white,
                                fontSize: 30,
                                fontWeight: FontWeight.w800,
                                height: 1.25),
                          ),
                          const SizedBox(height: 12),
                          Text(
                            _feedback!.sub,
                            textAlign: TextAlign.center,
                            style: const TextStyle(
                                color: Colors.white,
                                fontSize: 17,
                                fontWeight: FontWeight.w500,
                                height: 1.5),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// Pulsing corner brackets — the universal "aim here" affordance.
class _CornerPainter extends CustomPainter {
  final double pulse; // 0..1
  _CornerPainter(this.pulse);

  @override
  void paint(Canvas canvas, Size size) {
    const r = 14.0; // corner radius arm
    final paint = Paint()
      ..color = Colors.white.withOpacity(0.45 + 0.55 * pulse)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 5
      ..strokeCap = StrokeCap.round;

    final w = size.width;
    final h = size.height;
    final p = Path();
    // top-left
    p.moveTo(0, r);
    p.lineTo(0, 0);
    p.lineTo(r, 0);
    // top-right
    p.moveTo(w - r, 0);
    p.lineTo(w, 0);
    p.lineTo(w, r);
    // bottom-right
    p.moveTo(w, h - r);
    p.lineTo(w, h);
    p.lineTo(w - r, h);
    // bottom-left
    p.moveTo(r, h);
    p.lineTo(0, h);
    p.lineTo(0, h - r);
    canvas.drawPath(p, paint);
  }

  @override
  bool shouldRepaint(_CornerPainter old) => old.pulse != pulse;
}
