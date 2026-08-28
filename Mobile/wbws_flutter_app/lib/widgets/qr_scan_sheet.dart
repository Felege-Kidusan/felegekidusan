import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../services/qr_attendance.dart';

/// ════════════════════════════════════════════════════════════
/// QR scan sheet (Phase 8) — full-screen scanner with big Amharic
/// feedback overlays. Event check-in UX: scan → instant unambiguous
/// state (green/amber/red + haptics) → keep scanning.
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

class _QrScanSheetState extends State<QrScanSheet> {
  final MobileScannerController _ctrl = MobileScannerController();
  QrFeedback? _feedback;
  Timer? _feedbackTimer;
  Timer? _cooldown;
  String _lastRaw = '';
  DateTime _lastAt = DateTime.fromMillisecondsSinceEpoch(0);
  bool _busy = false;

  @override
  void dispose() {
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
      // Same code inside the dedupe window → ignore (camera sees the
      // card for many frames; one scan = one decision).
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

    setState(() => _feedback = feedback);
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

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: ClipRRect(
        borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
        child: Stack(
          children: [
            MobileScanner(controller: _ctrl, onDetect: _onDetect),
            // top bar
            Positioned(
              top: 0,
              left: 0,
              right: 0,
              child: Container(
                color: Colors.black87,
                padding:
                    const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                child: Row(
                  children: [
                    const Icon(Icons.qr_code_scanner,
                        color: Colors.white70, size: 22),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(widget.header,
                          style: const TextStyle(
                              color: Colors.white,
                              fontSize: 15,
                              fontWeight: FontWeight.w600),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis),
                    ),
                    IconButton(
                      icon: const Icon(Icons.flash_on, color: Colors.amber),
                      onPressed: () async {
                        try {
                          await _ctrl.toggleTorch();
                        } catch (_) {}
                      },
                    ),
                    IconButton(
                      icon: const Icon(Icons.close, color: Colors.white70),
                      onPressed: () => Navigator.pop(context),
                    ),
                  ],
                ),
              ),
            ),
            // hint at the bottom
            Positioned(
              bottom: 10,
              left: 0,
              right: 0,
              child: Center(
                child: Container(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 14, vertical: 8),
                  decoration: BoxDecoration(
                    color: Colors.black54,
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: const Text(
                    'የአባሉን ክውእር ኮድ ወደ ካሜራው የቅርቡ',
                    style: TextStyle(color: Colors.white, fontSize: 15),
                  ),
                ),
              ),
            ),
            // big Amharic feedback overlay
            if (_feedback != null)
              Positioned.fill(
                child: IgnorePointer(
                  child: Container(
                    color: _color(_feedback!).withOpacity(0.88),
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
                          size: 72,
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
          ],
        ),
      ),
    );
  }
}
