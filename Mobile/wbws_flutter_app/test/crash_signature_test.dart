import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/crash_log_service.dart';

/// Crash signatures — the bounded, privacy-safe location identity sent
/// alongside a crash key.
///
/// The contract, mirrored by api/v1/routes/telemetry.php:
///   * class + frames only — never messages, values, or PII;
///   * allow-listed charset [A-Za-z0-9 .:_/<>()$#-];
///   * each part ≤ 120 chars, at most 3 frames;
///   * total JSON payload ≤ 512 bytes;
///   * deterministic for the same entry.
void main() {
  CrashLogEntry entryWith(String body) => CrashLogEntry(
        at: DateTime.fromMillisecondsSinceEpoch(1757160000000),
        nativeCrash: true,
        header: 'CRASH 1757160000000',
        body: body,
      );

  group('buildCrashSignature', () {
    test('native crash: class + first-party native frame only', () {
      final sig = buildCrashSignature(entryWith(
        'java.lang.UnsatisfiedLinkError: libflutter.so\n'
        '\tat com.arkeonethiopia.fkss.FkssApplication.onCreate(FkssApplication.kt:31)\n'
        '\tat android.app.Instrumentation.callApplicationOnCreate(Instrumentation.java:1192)\n'
        '\tat com.android.internal.os.ZygoteInit.main(ZygoteInit.java:930)\n',
      ));
      expect(sig, isNotNull);
      expect(sig!.signatureClass, 'java.lang.UnsatisfiedLinkError');
      // Framework/OS frames are deliberately excluded.
      expect(sig.frames, hasLength(1));
      expect(sig.frames.first, 'FkssApplication.kt:31 onCreate');
    });

    test('dart bootstrap: class + first-party package frames', () {
      final sig = buildCrashSignature(entryWith(
        'SqfliteException: database corrupted\n'
        '#0      LocalDb.open (package:fkss_app/services/local_db.dart:214:9)\n'
        '#1      main.runBootstrap (package:fkss_app/main.dart:88:5)\n'
        '#2      _DefaultBinaryMessengerBinding... (package:flutter/src/binding.dart:1:1)\n'
        '#3      sqflite internals (package:sqflite/src/database.dart:40:3)\n',
      ));
      expect(sig, isNotNull);
      expect(sig!.signatureClass, 'SqfliteException');
      expect(sig.frames, hasLength(2));
      expect(sig.frames[0], 'local_db.dart:214 LocalDb.open');
      expect(sig.frames[1], 'main.dart:88 main.runBootstrap');
    });

    test('caps frames at three', () {
      final buffer = StringBuffer('SomeError: x\n');
      for (var i = 0; i < 6; i++) {
        buffer.writeln(
          '#$i  Foo$i.bar (package:fkss_app/services/file$i.dart:1$i:2)',
        );
      }
      final sig = buildCrashSignature(entryWith(buffer.toString()));
      expect(sig!.frames, hasLength(3));
    });

    test('strips disallowed characters from every part', () {
      final sig = buildCrashSignature(entryWith(
        'Weird@Class|Name: message with ; evil\n'
        '#0  Sym\"bol (package:fkss_app/services/x.dart:5:1)\n',
      ));
      expect(sig, isNotNull);
      expect(sig!.signatureClass, 'WeirdClassName');
      // The frame keeps only allow-listed characters.
      expect(sig.frames.first.contains('"'), isFalse);
      expect(sig.frames.first.contains(';'), isFalse);
    });

    test('total JSON payload stays within 512 bytes', () {
      // Worst case (class 120 chars + 3 frames of 120) encodes to ~595
      // bytes; frames must be dropped from the end until the budget holds.
      final longClass = 'Very${'Long' * 23}ExceptionClassName';
      final longSymbol = 'A' * 300;
      final buffer = StringBuffer('$longClass: x\n');
      for (var i = 0; i < 4; i++) {
        buffer.writeln(
          '#$i  $longSymbol (package:fkss_app/services/file$i.dart:99:9)',
        );
      }
      final sig = buildCrashSignature(entryWith(buffer.toString()));
      final encoded = jsonEncode(sig!.toTelemetryData('0' * 64));
      expect(utf8.encode(encoded).length, lessThanOrEqualTo(512));
      expect(sig.frames.length, lessThan(3));
    });

    test('deterministic: same entry, same signature', () {
      final body =
          'java.lang.RuntimeException: boom\n\tat com.arkeonethiopia.fkss.A.b(A.kt:1)\n';
      final a = buildCrashSignature(entryWith(body));
      final b = buildCrashSignature(entryWith(body));
      expect(a!.signatureClass, b!.signatureClass);
      expect(a.frames, b.frames);
    });

    test('empty body yields null; no first-party frames yields class only', () {
      expect(buildCrashSignature(entryWith('')), isNull);
      final sig = buildCrashSignature(entryWith(
        'java.lang.OutOfMemoryError: no frames for you\n'
        '\tat android.os.Handler.dispatchMessage(Handler.java:106)\n',
      ));
      expect(sig!.signatureClass, 'java.lang.OutOfMemoryError');
      expect(sig.frames, isEmpty);
    });
  });
}
