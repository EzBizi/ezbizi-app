import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/connected_account.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Naming the connected account, so a wrong one is visible.
///
/// Jose connected EzBizi's own manager account to A-1 Chimney by mistake, and
/// the tab only said "No enquiries yet". The name is the fix.
///
/// Jose, 2026-10-16: no Disconnect on the tab itself. "any actions like that
/// should be on a settings sub tab, not available to anyone who visits the
/// tab". It is in Settings › Integrations.
void main() {
  testWidgets('the account is named, and the tab offers no Disconnect', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: const Scaffold(
          body: ConnectedAccount(
            connection: {
              'id': 'conn-1',
              'provider': 'google_ads',
              'display_name': '706-109-4046',
            },
          ),
        ),
      ),
    );

    expect(find.textContaining('706-109-4046'), findsOneWidget);
    expect(find.textContaining('Google Ads'), findsOneWidget);
    expect(find.text('Disconnect'), findsNothing);
    expect(find.text('Change it in Settings › Integrations'), findsOneWidget);
  });
}
