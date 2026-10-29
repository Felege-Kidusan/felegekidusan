import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/screens/auth/login_screen.dart';
import 'package:fkss_app/utils/config.dart';
import 'package:fkss_app/utils/theme.dart';

void main() {
  test('owner-provided short/full spellings are exact and distinct', () {
    expect(AppConfig.appName, 'ውሉደ ብርሃን');
    expect(AppConfig.appNameAmharic, 'ውሉደ ብርሃን ሰንበት ት/ቤት');
    expect(AppConfig.appFullNameAmharic,
        'የገላን ጉራ ቅዱስ ገብርኤል ቤተክርስቲያን ውሉደብርሃን ሰንበት ት/ቤት');
    expect(AppConfig.apiBaseUrl, 'https://felegekidusan.com/api/v1');
    expect(AppConfig.tokenKey, 'fkss_token');
  });

  for (final width in <double>[320, 600]) {
    for (final scale in <double>[1, 1.5]) {
      testWidgets('login identity wraps at width $width and text scale $scale',
          (tester) async {
        final semantics = tester.ensureSemantics();
        addTearDown(semantics.dispose);
        tester.view.devicePixelRatio = 1;
        tester.view.physicalSize = Size(width, 800);
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        await tester.pumpWidget(MaterialApp(
          theme: AppTheme.lightTheme,
          builder: (context, child) => MediaQuery(
            data: MediaQuery.of(context)
                .copyWith(textScaler: TextScaler.linear(scale)),
            child: child!,
          ),
          home: const LoginScreen(),
        ));
        await tester.pumpAndSettle();
        expect(find.text(AppConfig.appNameAmharic), findsOneWidget);
        expect(find.text(AppConfig.appFullNameAmharic), findsOneWidget);
        expect(tester.widget<Image>(find.byType(Image)).semanticLabel,
            AppConfig.appNameAmharic);
        // Logo and heading both correctly expose the school's name.
        expect(find.bySemanticsLabel(AppConfig.appNameAmharic), findsWidgets);
        expect(find.textContaining('Spring of Saints'), findsNothing);
        expect(tester.takeException(), isNull);
      });
    }
  }
}
