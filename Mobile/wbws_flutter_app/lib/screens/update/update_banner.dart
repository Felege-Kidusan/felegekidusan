import 'package:flutter/material.dart';
import '../../services/app_update_service.dart';
import '../../utils/theme.dart';
import '../../utils/transitions.dart';
import 'update_screen.dart';

/// P65: the optional-update banner — the quiet half of the update UX.
///
/// Forced updates (min_build / force_update) take over the whole app at
/// the root (main.dart). Normal releases appear here: a slim band at the
/// top of the shell until the user updates — or dismisses it for this
/// session (it returns on the next launch, Telegram-style).
///
/// Rebuilds itself from [AppUpdateService.revision] the moment the
/// launch/resume server check completes, so the shell never needs a
/// full rebuild for it (a whole-shell rebuild visibly hitched the
/// kept-alive tabs).
class UpdateBanner extends StatefulWidget {
  const UpdateBanner({super.key});

  @override
  State<UpdateBanner> createState() => _UpdateBannerState();
}

class _UpdateBannerState extends State<UpdateBanner> {
  bool _dismissed = false;

  @override
  Widget build(BuildContext context) {
    final svc = AppUpdateService();
    return ValueListenableBuilder<int>(
      valueListenable: svc.revision,
      builder: (context, _, __) {
        final cfg = svc.config;
        if (_dismissed || !svc.decision.optional || cfg == null) {
          return const SizedBox.shrink();
        }
        return TweenAnimationBuilder<double>(
          tween: Tween(begin: 0, end: 1),
          duration: const Duration(milliseconds: 250),
          curve: Curves.easeOut,
          builder: (context, t, child) => Opacity(
            opacity: t,
            child: Transform.translate(
              offset: Offset(0, -8 * (1 - t)),
              child: child,
            ),
          ),
          child: Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 2),
            decoration: BoxDecoration(
              color: AppTheme.primary.withOpacity(0.10),
              border: Border(
                bottom:
                    BorderSide(color: AppTheme.primary.withOpacity(0.25)),
              ),
            ),
            child: SafeArea(
              bottom: false,
              minimum: const EdgeInsets.symmetric(vertical: 4),
              child: Row(
                children: [
                  const Icon(Icons.system_update_alt_rounded,
                      size: 18, color: AppTheme.primary),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'New FKSS version ${cfg.latestVersion} is available',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          fontSize: 13, fontWeight: FontWeight.w600),
                    ),
                  ),
                  TextButton(
                    onPressed: () => context
                        .pushSmooth(const UpdateScreen(blocking: false)),
                    style: TextButton.styleFrom(
                      foregroundColor: AppTheme.primary,
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                      minimumSize: const Size(0, 32),
                      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    ),
                    child: const Text('Update',
                        style: TextStyle(fontWeight: FontWeight.w700)),
                  ),
                  InkWell(
                    onTap: () => setState(() => _dismissed = true),
                    borderRadius: BorderRadius.circular(16),
                    child: const Padding(
                      padding: EdgeInsets.all(6),
                      child: Icon(Icons.close_rounded,
                          size: 16, color: AppTheme.textSecondary),
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}
