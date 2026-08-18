import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/screens/auth/login_screen.dart';
import 'package:flutter/material.dart';
import 'package:fkss_app/utils/theme.dart';

void main() {
  testWidgets('Login screen shows sign-in controls', (WidgetTester tester) async {
    await tester.pumpWidget(MaterialApp(
      theme: AppTheme.lightTheme,
      home: const LoginScreen(),
    ));
    expect(find.text('Login'), findsOneWidget);
    expect(find.text('Username'), findsOneWidget);
  });
}
