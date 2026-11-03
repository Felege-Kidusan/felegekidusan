import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/main.dart';

void main() {
  testWidgets(
      'migration hold explains preservation without destructive actions',
      (tester) async {
    await tester.pumpWidget(const OfflineDataProtectionFailureApp(
      detail: 'Migration files were preserved.',
      migrationRecoveryRequired: true,
    ));

    expect(find.text('Offline storage could not be opened'), findsOneWidget);
    expect(
        find.textContaining('Historical database migration files need review'),
        findsOneWidget);
    expect(find.textContaining('Do not clear app data or reinstall'),
        findsOneWidget);
    expect(find.text('Retry'), findsOneWidget);
    expect(find.textContaining('Free up some phone storage'), findsNothing);
    expect(find.text('Discard permanently'), findsNothing);
    expect(find.text('Erase local data & reset'), findsNothing);
  });

  testWidgets('ordinary bootstrap failure retains existing retry guidance',
      (tester) async {
    await tester.pumpWidget(const OfflineDataProtectionFailureApp(
      detail: 'Synthetic storage error',
    ));

    expect(find.textContaining('Free up some phone storage'), findsOneWidget);
    expect(find.text('Retry'), findsOneWidget);
    expect(find.text('Synthetic storage error'), findsOneWidget);
    expect(
        find.textContaining('Historical database migration files need review'),
        findsNothing);
  });
}
