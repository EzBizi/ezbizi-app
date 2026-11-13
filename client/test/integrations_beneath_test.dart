import 'package:ezbizi_client/screens/settings/integration_provider_screen.dart';
import 'package:ezbizi_client/screens/settings/integrations_screen.dart';
import 'package:flutter_test/flutter_test.dart';

/// UI/UX audit 2026-11-13: Google Ads said 89 here and 46 on the Dashboard,
/// because this line counted revoked, paused and broken accounts as connected.
void main() {
  IntegrationConnection row(String id, bool connected) => IntegrationConnection(
    id: id,
    provider: 'google_ads',
    providerLabel: 'Google Ads',
    displayName: 'Acct $id',
    scopeType: 'organization',
    scopeId: 'org-1',
    connected: connected,
  );

  test('every account connected: "N connected"', () {
    expect(
      integrationsBeneath([row('a', true), row('b', true)]),
      '2 connected at the organization',
    );
  });

  test('some not connected: "N accounts (X connected)"', () {
    expect(
      integrationsBeneath([
        row('a', true),
        row('b', false),
        row('c', false),
        row('d', true),
      ]),
      '4 accounts (2 connected) at the organization',
    );
  });
}
