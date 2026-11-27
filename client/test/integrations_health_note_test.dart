import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/integrations_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Option A of docs/previews/integrations-cards.html (Jose, 2026-11-12):
/// the old cards plus a note with one line per CAUSE, never one per account.
void main() {
  testWidgets('nine branches with one cause are one plain line at the bottom, '
      'the URLs only behind Show steps', (tester) async {
    await _pump(tester, overview: _overview(workiz: 9));

    expect(
      find.text('9 Workiz branches need a webhook rule in Workiz (one-time).'),
      findsOneWidget,
    );
    expect(find.textContaining('https://'), findsNothing);
    expect(find.text('Try again'), findsNothing);
    // The last thing on the page: below every category section.
    final attention = tester.getTopLeft(
      find.byKey(const ValueKey('integrations-section:attention')),
    );
    for (final e
        in find
            .byWidgetPredicate(
              (w) =>
                  w.key is ValueKey &&
                  '${(w.key as ValueKey).value}'.startsWith('provider:'),
            )
            .evaluate()) {
      expect(
        tester.getTopLeft(find.byWidget(e.widget)).dy,
        lessThan(attention.dy),
      );
    }

    await tester.tap(
      find.byKey(const ValueKey('integrations-details:workiz:workiz_webhook')),
    );
    await tester.pumpAndSettle();
    expect(find.text('Show steps'), findsOneWidget);
    expect(find.textContaining('webhook/uuid-3'), findsOneWidget);
  });

  testWidgets('fourteen A2P failures are one blue waiting line, no button', (
    tester,
  ) async {
    await _pump(tester, overview: _overview(phoneLines: 14));

    final causes = find.byKey(
      const ValueKey('integrations-cause:phone:texting'),
    );
    expect(causes, findsOneWidget);
    expect(
      find.text("14 phone lines can't text yet: waiting on Twilio."),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('integrations-fix:phone:texting')),
      findsNothing,
    );
    expect(find.textContaining('in 0 min'), findsNothing);
  });

  testWidgets('a broken account is a red line with its fix', (tester) async {
    await _pump(tester, overview: _overview(bluesky: true));

    expect(
      find.text(
        'ezbizi.bsky.social: Bluesky no longer accepts this connection.',
      ),
      findsOneWidget,
    );
    final fix = find.byKey(const ValueKey('integrations-fix:bluesky:sign_in'));
    expect(fix, findsOneWidget);
    expect(
      find.descendant(of: fix, matching: find.text('Reconnect')),
      findsOneWidget,
    );
  });

  testWidgets('missing branches are one quiet line that opens a panel', (
    tester,
  ) async {
    String? patched;
    await _pump(
      tester,
      overview: _overview(branchless: 3),
      onPatch: (path, body) => patched = '$path ${jsonEncode(body)}',
    );

    expect(
      find.text('3 accounts have no branch for their leads.'),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const ValueKey('integrations-choose-later')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('branch:ads-0:b-1')));
    await tester.pumpAndSettle();
    expect(
      patched,
      '/api/v1/social/connections/ads-0/leads-franchise '
      '{"leads_franchise_id":"b-1"}',
    );
  });

  testWidgets('no note when all is well', (tester) async {
    await _pump(tester, overview: _overview());
    expect(find.byKey(const ValueKey('integrations-note')), findsNothing);
  });

  testWidgets('a failed overview leaves the plain grid', (tester) async {
    await _pump(tester, overview: null);

    expect(find.byKey(const ValueKey('integrations-note')), findsNothing);
    expect(find.byKey(const ValueKey('provider:pinterest')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('last-checked:pinterest')),
      findsOneWidget,
    );
    expect(find.text('Try again'), findsNothing);
  });
}

Map<String, dynamic> _check(
  String key,
  String state, {
  String? reason,
  Map<String, dynamic>? fix,
  String? waitingOn,
  String? summary,
}) => {
  'key': key,
  'label': key,
  'state': state,
  'reason': reason,
  'summary': summary,
  'fix': fix,
  'checked_at': null,
  'next_retry_at': DateTime.now().toUtc().toIso8601String(),
  'waiting_on': ?waitingOn,
};

Map<String, dynamic> _conn(
  String id,
  String provider,
  String label,
  String status,
  List<Map<String, dynamic>> checks,
) => {
  'id': id,
  'provider': provider,
  'label': label,
  'branch_id': null,
  'branch_label': null,
  'choices': const [],
  'used_by': 0,
  'status': status,
  'last_checked_at': null,
  'checks': checks,
};

Map<String, dynamic> _overview({
  int phoneLines = 0,
  bool bluesky = false,
  int branchless = 0,
  int workiz = 0,
}) {
  final apps = <Map<String, dynamic>>[
    {
      'provider': 'pinterest',
      'label': 'Pinterest',
      'counts': {'ok': 1, 'setup': 0, 'broken': 0},
      'connections': [
        _conn('pin-1', 'pinterest', 'A-1 Chimney', 'ok', [
          _check('sign_in', 'pass'),
        ]),
      ],
    },
  ];
  if (phoneLines > 0) {
    apps.add({
      'provider': 'phone',
      'label': 'Phone',
      'connections': [
        for (var i = 0; i < phoneLines; i++)
          _conn('ph-$i', 'phone', 'Line $i', 'broken', [
            _check(
              'texting',
              'fail',
              reason: 'Texting: not registered (A2P), so texts cannot be sent.',
              waitingOn: 'Twilio',
            ),
          ]),
      ],
    });
  }
  if (bluesky) {
    apps.add({
      'provider': 'bluesky',
      'label': 'Bluesky',
      'connections': [
        _conn('bs-1', 'bluesky', 'ezbizi.bsky.social', 'broken', [
          _check(
            'sign_in',
            'fail',
            reason: 'Bluesky no longer accepts this connection.',
            fix: {'action': 'reconnect', 'label': 'Reconnect'},
          ),
        ]),
      ],
    });
  }
  if (workiz > 0) {
    apps.add({
      'provider': 'workiz',
      'label': 'Workiz',
      'connections': [
        for (var i = 0; i < workiz; i++)
          _conn('wz-$i', 'workiz', 'Branch $i', 'broken', [
            _check(
              'workiz_webhook',
              'fail',
              reason:
                  'Workiz has no API for this: add a rule "post webhook" to '
                  'https://app.ezbizi.com/api/v1/social/workiz/webhook/uuid-$i.',
              summary: 'needs a webhook rule in Workiz (one-time)',
              fix: {'action': 'retry', 'label': 'Try again'},
            ),
          ]),
      ],
    });
  }
  if (branchless > 0) {
    apps.add({
      'provider': 'google_ads',
      'label': 'Google Ads',
      'connections': [
        for (var i = 0; i < branchless; i++)
          _conn('ads-$i', 'google_ads', 'Ads $i', 'setup', [
            _check('sign_in', 'pass'),
            _check(
              'branch',
              'unknown',
              reason: 'Choose which branch its leads go to.',
              fix: {'action': 'choose_branch', 'label': 'Choose branch'},
            ),
          ]),
      ],
    });
  }
  return {
    'summary': {'broken': 0, 'setup': 0},
    'apps': apps,
    'branches': [
      {'id': 'b-1', 'label': 'Miami'},
      {'id': 'b-2', 'label': 'Tampa'},
    ],
  };
}

Future<void> _pump(
  WidgetTester tester, {
  required Map<String, dynamic>? overview,
  void Function(String path, Object? body)? onPatch,
}) async {
  tester.view.physicalSize = const Size(1280, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (request.method == 'PATCH') {
        onPatch?.call(path, jsonDecode(request.body));
        return _json({'data': <String, dynamic>{}});
      }
      if (path.endsWith('/me')) return _json({'data': _me});
      if (path.endsWith('/integrations/overview')) {
        return overview == null
            ? _json({'message': 'Server Error'}, 500)
            : _json({'data': overview});
      }
      if (path.endsWith('/social/connections')) {
        return _json({
          'data': [
            {
              'id': 'pin-1',
              'provider': 'pinterest',
              'provider_label': 'Pinterest',
              'display_name': 'A-1 Chimney',
              'scope_type': 'organization',
              'scope_id': 'org-1',
              'status': 'connected',
              'last_checked_at': null,
            },
          ],
        });
      }
      if (path.endsWith('/social/providers')) {
        return _json({
          'data': const [
            {
              'value': 'pinterest',
              'label': 'Pinterest',
              'connects_by': 'sign_in',
              'start_path': '/social/pinterest/start',
              'permission': 'marketing.edit',
            },
          ],
        });
      }
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
