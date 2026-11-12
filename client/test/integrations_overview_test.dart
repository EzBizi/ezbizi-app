import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/integrations_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose 2026-11-12: Integrations as options A and C of
/// docs/previews/integrations-patterns.html, from GET integrations/overview.
void main() {
  testWidgets('attention block, rollup expand, account checks and Verify all', (
    tester,
  ) async {
    final posted = <String>[];
    await _pump(tester, posted);

    // A: the broken WhatsApp number heads the screen with its failing check.
    final block = find.byKey(const ValueKey('integrations-attention'));
    expect(block, findsOneWidget);
    expect(
      find.descendant(
        of: block,
        matching: find.textContaining('Sending: Template rejected'),
      ),
      findsOneWidget,
    );
    // The setup one (no branch) is listed too.
    expect(
      find.byKey(const ValueKey('integrations-attention:ga-2')),
      findsOneWidget,
    );

    // Google Ads has a problem, so it starts expanded; 12 accounts show the
    // problems first, then "+N more".
    expect(
      find.byKey(const ValueKey('integrations-account:ga-2')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('integrations-account:ga-5')), findsNothing);
    await tester.tap(
      find.byKey(const ValueKey('integrations-app-more:google_ads')),
    );
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('integrations-account:ga-5')),
      findsOneWidget,
    );

    // Mailchimp is healthy: collapsed until opened.
    expect(find.byKey(const ValueKey('integrations-account:mc-1')), findsNothing);
    await tester.tap(
      find.byKey(const ValueKey('integrations-app-toggle:mailchimp')),
    );
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('integrations-account:mc-1')),
      findsOneWidget,
    );

    // C: the account panel, one row per check with its reason.
    await tester.ensureVisible(
      find.byKey(const ValueKey('integrations-account:wa-1')),
    );
    await tester.tap(find.byKey(const ValueKey('integrations-account:wa-1')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('integration-check:sending')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('integration-check:webhook')),
      findsOneWidget,
    );
    expect(find.text('Template rejected'), findsOneWidget);
    expect(find.byKey(const ValueKey('integration-fix:sending')), findsOneWidget);
    expect(find.byKey(const ValueKey('integration-fix:webhook')), findsNothing);

    // Verify all swaps the answered connection in.
    await tester.tap(find.byKey(const ValueKey('integration-verify-all')));
    await tester.pumpAndSettle();
    expect(posted, contains('/api/v1/integrations/wa-1/recheck'));
    expect(find.text('Template rejected'), findsNothing);
    expect(find.text('Every check passes'), findsOneWidget);
  });
}

Map<String, dynamic> _check(String key, String state, [String? reason]) => {
  'key': key,
  'label': key == 'sending' ? 'Sending' : 'Webhook',
  'state': state,
  'reason': reason,
  'fix': state == 'fail' ? {'action': 'retry', 'label': 'Try again'} : null,
  'checked_at': DateTime.now().toUtc().toIso8601String(),
  'next_retry_at': null,
};

Map<String, dynamic> _wa(bool ok) => {
  'id': 'wa-1',
  'provider': 'whatsapp',
  'label': '+1 786 555 0100',
  'branch_id': 'f-1',
  'branch_label': 'Miami',
  'choices': [
    {'label': 'Purpose', 'value': 'Company'},
  ],
  'used_by': 0,
  'status': ok ? 'ok' : 'broken',
  'last_checked_at': null,
  'checks': [
    _check('sending', ok ? 'pass' : 'fail', ok ? null : 'Template rejected'),
    _check('webhook', 'pass'),
  ],
};

Map<String, dynamic> _ga(int i) => {
  'id': 'ga-$i',
  'provider': 'google_ads',
  'label': 'Account $i',
  'branch_id': i == 2 ? null : 'f-1',
  'branch_label': i == 2 ? null : 'Miami',
  'choices': const [],
  'used_by': 0,
  'status': i == 2 ? 'setup' : 'ok',
  'last_checked_at': null,
  'checks': [
    {
      'key': 'branch',
      'label': 'Branch',
      'state': i == 2 ? 'unknown' : 'pass',
      'reason': i == 2 ? 'Choose which branch its leads go to.' : null,
      'fix': i == 2
          ? {'action': 'choose_branch', 'label': 'Choose branch'}
          : null,
      'checked_at': null,
      'next_retry_at': null,
    },
  ],
};

Map<String, dynamic> get _overview => {
  'summary': {'broken': 1, 'setup': 1},
  'branches': [
    {'id': 'f-1', 'label': 'Miami'},
  ],
  'apps': [
    {
      'provider': 'google_ads',
      'label': 'Google Ads',
      'counts': {'ok': 11, 'setup': 1, 'broken': 0},
      'last_checked_at': null,
      'connections': [for (var i = 1; i <= 12; i++) _ga(i)],
    },
    {
      'provider': 'mailchimp',
      'label': 'Mailchimp',
      'counts': {'ok': 1, 'setup': 0, 'broken': 0},
      'last_checked_at': null,
      'connections': [
        {
          'id': 'mc-1',
          'provider': 'mailchimp',
          'label': 'Newsletter',
          'branch_id': null,
          'branch_label': null,
          'choices': const [],
          'used_by': 0,
          'status': 'ok',
          'last_checked_at': null,
          'checks': [_check('webhook', 'pass')],
        },
      ],
    },
    {
      'provider': 'whatsapp',
      'label': 'WhatsApp',
      'counts': {'ok': 0, 'setup': 0, 'broken': 1},
      'last_checked_at': null,
      'connections': [_wa(false)],
    },
  ],
};

Future<void> _pump(WidgetTester tester, List<String> posted) async {
  tester.view.physicalSize = const Size(1280, 3000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (request.method == 'POST') posted.add(path);
      if (path.endsWith('/me')) return _json({'data': _me});
      if (path.endsWith('/integrations/overview')) {
        return _json({'data': _overview});
      }
      if (path.endsWith('/integrations/wa-1/recheck')) {
        return _json({'data': _wa(true)});
      }
      if (path.endsWith('/social/connections')) return _json({'data': const []});
      if (path.endsWith('/social/providers')) return _json({'data': const []});
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: IntegrationsScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'permissions': ['marketing.view', 'marketing.edit'],
  'modules': <dynamic>[],
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
