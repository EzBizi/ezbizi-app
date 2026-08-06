import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/main.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('an unauthenticated app shows the sign-in screen', (tester) async {
    await tester.pumpWidget(const EzBiziApp());
    await tester.pump();

    // The gate that matters: no session, no application shell.
    expect(find.text('Sign in'), findsOneWidget);
    expect(find.text('Sign in to your account'), findsOneWidget);
  });

  test('the API base URL is a build flag, never a hardcoded host', () {
    // A1 Tools compiled its host into the binary, which is why moving that app
    // to a new domain is a migration rather than a config change. The default
    // here is same-origin, so a web build cannot drift from where it was served.
    expect(ApiClient.baseUrl, isEmpty,
        reason: 'Default must be same-origin; set API_BASE at build time to override.');
  });
}
