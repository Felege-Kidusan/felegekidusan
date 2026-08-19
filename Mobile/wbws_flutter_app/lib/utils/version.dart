/// Pure version helpers — no Flutter, easy to test.
class AppVersion {
  final int major;
  final int minor;
  final int patch;
  final int build;

  const AppVersion(this.major, this.minor, this.patch, [this.build = 0]);

  factory AppVersion.parse(String version, [int build = 0]) {
    final cleaned = version.replaceAll(RegExp(r'[^0-9.]'), '');
    final parts = cleaned.split('.');
    int n(int i) => i < parts.length ? int.tryParse(parts[i]) ?? 0 : 0;
    return AppVersion(n(0), n(1), n(2), build);
  }

  /// -1 if this < other, 0 if equal, 1 if this > other.
  int compareTo(AppVersion other) {
    if (major != other.major) return major < other.major ? -1 : 1;
    if (minor != other.minor) return minor < other.minor ? -1 : 1;
    if (patch != other.patch) return patch < other.patch ? -1 : 1;
    if (build != other.build) return build < other.build ? -1 : 1;
    return 0;
  }

  bool get isZero => major == 0 && minor == 0 && patch == 0;

  @override
  String toString() => '$major.$minor.$patch+$build';
}

class UpdateDecision {
  final bool force;
  final bool optional;
  bool get any => force || optional;

  const UpdateDecision({required this.force, required this.optional});
}

UpdateDecision decideUpdate({
  required String currentVersion,
  required int currentBuild,
  required String latestVersion,
  required int latestBuild,
  required String minVersion,
  required int minBuild,
  bool forceFlag = false,
}) {
  final cur = AppVersion.parse(currentVersion, currentBuild);
  final latest = AppVersion.parse(latestVersion, latestBuild);
  final min = AppVersion.parse(minVersion, minBuild);
  final belowMin = cur.compareTo(min) < 0;
  final belowLatest = cur.compareTo(latest) < 0;
  return UpdateDecision(
    force: forceFlag || belowMin,
    optional: !belowMin && belowLatest,
  );
}
