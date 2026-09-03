#!/usr/bin/env bash
# analyze_flutter.sh — run the REAL Flutter analyzer over the mobile app.
#
# Why this exists (P27b, 2026-09-01): the mobile "search not working"
# report was closed only after a full `flutter analyze` proved 0 errors /
# 0 warnings — brace-balancing alone is NOT verification. The sandbox
# has ~2 GB RAM, so the Flutter tool's first compile gets OOM-killed
# unless swap is added first.
#
# The SDK install lives outside the workspace (/root/flutter) and does
# NOT survive sandbox restarts — re-run this script as-is; it is
# idempotent and skips finished steps.
set -euo pipefail

FLUTTER_DIR=/root/flutter
APP_DIR="$(cd "$(dirname "$0")/../../Mobile/wbws_flutter_app" && pwd)"

# 1) swap (the tool compile needs ~3 GB; box has ~2 GB)
if ! swapon --show 2>/dev/null | grep -q .; then
  if [ ! -f /swapfile ]; then
    fallocate -l 5G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null
  fi
  swapon /swapfile || echo "NOTE: could not enable swap (may OOM)" >&2
fi

# 2) Flutter SDK (stable, shallow)
if [ ! -x "$FLUTTER_DIR/bin/flutter" ]; then
  git clone --depth 1 -b stable https://github.com/flutter/flutter "$FLUTTER_DIR"
fi
export PATH="$FLUTTER_DIR/bin:$PATH"

# 3) bootstrap (downloads the pinned Dart SDK; ~1 min)
flutter --version >/dev/null

# 4) analyze — errors/warnings are the gate; info lints are pre-existing
cd "$APP_DIR"
flutter pub get >/dev/null
echo "flutter analyze $(cd "$APP_DIR" && git rev-parse --show-toplevel >/dev/null 2>&1 && echo "(app @ $(git log --oneline -1 -- . | cut -c1-7))" || echo)"
flutter analyze
