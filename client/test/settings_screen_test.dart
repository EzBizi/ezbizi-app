import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The settings screen after a change (plan §6).
///
/// The owner's complaint in their own words: "whenever you change a value, for
/// example i change time format from 12 to 24, the whole page reloads". It did
/// — every write ended in `_load()`, which raised `_loading`, which replaced
/// the screen with a spinner and refetched every setting to learn something the
/// write had already answered.
///
/// So the assertion running through all of these is the count of GET /settings.
/// It must stay at one. "No spinner" is the symptom; "no second request" is the
/// cause, and only the second one can be checked without racing an animation.
void main() {
  testWidgets('changing a setting moves its row and nothing else', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    var reads = 0;

    // Held open so the screen can be inspected WHILE the write is in flight —
    // that is the moment the spinner used to appear.
    final write = Completer<http.Response>();

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      if (request.method == 'GET') {
        reads++;
        return _json({
          'data': [
            _row(
              'time_format',
              label: 'Time format',
              type: 'enum',
              value: '12h',
              options: const ['12h', '24h'],
              source: _source('organization', 'org-1', 'Everdoors', true),
            ),
          ],
        });
      }

      return write.future;
    });

    expect(reads, 1);
    expect(find.text('Inherited from Everdoors'), findsOneWidget);

    await tester.tap(find.byType(DropdownButton<String>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('24h').last);
    await tester.pump();

    // Mid-flight. The old screen is still the honest one: the server has not
    // answered, so nothing is known to have changed yet — and there is nothing
    // to hide behind a spinner.
    expect(find.byType(CircularProgressIndicator), findsNothing);
    expect(find.text('Inherited from Everdoors'), findsOneWidget);

    write.complete(
      _json({
        'data': _row(
          'time_format',
          label: 'Time format',
          type: 'enum',
          value: '24h',
          options: const ['12h', '24h'],
          source: _source('franchise', 'fr-1', 'Connecticut', false),
        ),
      }),
    );
    await tester.pumpAndSettle();

    expect(
      reads,
      1,
      reason:
          'The write already said what the setting now is. Asking again is the '
          'round trip that blanked the screen.',
    );
    expect(find.byType(CircularProgressIndicator), findsNothing);
    expect(find.text('Saved'), findsOneWidget);

    // The row picked up BOTH halves of the answer — the value and where it now
    // comes from. Source is the half the client could not have worked out.
    expect(
      tester.widget<DropdownButton<String>>(find.byType(DropdownButton<String>)).value,
      '24h',
    );
    expect(find.text('Set here'), findsOneWidget);
    expect(find.text('Inherited from Everdoors'), findsNothing);
  });

  testWidgets('a refused change leaves the control on the value the server has', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    var reads = 0;

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      if (request.method == 'GET') {
        reads++;
        return _json({
          'data': [
            _row(
              'notifications.email_enabled',
              label: 'Send email notifications',
              type: 'bool',
              value: false,
              source: null,
            ),
          ],
        });
      }

      return _json({
        'message': 'That franchise is outside your assignments.',
      }, 403);
    });

    await tester.tap(find.byType(Switch));
    await tester.pumpAndSettle();

    // A switch left sitting in the position the server refused reads as saved,
    // which is worse than one that never moved.
    expect(tester.widget<Switch>(find.byType(Switch)).value, isFalse);
    expect(find.text('That franchise is outside your assignments.'), findsOneWidget);
    expect(reads, 1, reason: 'A refusal changes nothing, so there is nothing to refetch.');
  });

  testWidgets('resetting a setting splices in the row it now inherits', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    var reads = 0;

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      if (request.method == 'GET') {
        reads++;
        return _json({
          'data': [
            _row(
              'money.tax_rate',
              label: 'Default tax rate (%)',
              type: 'decimal',
              value: 6.625,
              source: _source('franchise', 'fr-1', 'Connecticut', false),
            ),
          ],
        });
      }

      return _json({
        'data': _row(
          'money.tax_rate',
          label: 'Default tax rate (%)',
          type: 'decimal',
          value: 8.5,
          source: _source('company', 'co-1', 'Northeast', true),
        ),
      });
    });

    expect(find.text('Set here'), findsOneWidget);

    await tester.tap(find.byIcon(Icons.settings_backup_restore));
    await tester.pumpAndSettle();

    expect(reads, 1);

    // Which ancestor caught it is the server's answer, not a guess from the
    // scope on screen — dropping a franchise override can land on the company,
    // the organization, or nothing at all.
    expect(find.text('Inherited from Northeast'), findsOneWidget);
    expect(find.text('Now inherited from Northeast'), findsOneWidget);

    expect(
      tester.widget<TextField>(find.byType(TextField)).controller?.text,
      '8.5',
    );
  });

  testWidgets('a write answering with an unexpected body falls back to a reload', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    var reads = 0;

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      if (request.method == 'GET') {
        reads++;
        return _json({
          'data': [
            _row(
              'notifications.email_enabled',
              label: 'Send email notifications',
              type: 'bool',
              // The second read is the truth the fallback goes and gets.
              value: reads > 1,
              source: null,
            ),
          ],
        });
      }

      // A row for a different setting — the shape is plausible, the content is
      // not about the thing that was written.
      return _json({
        'data': {'key': 'money.tax_rate', 'value': true},
      });
    });

    await tester.tap(find.byType(Switch));
    await tester.pumpAndSettle();

    expect(
      reads,
      2,
      reason:
          'Nothing spliceable came back, so the only honest option is to go and '
          'ask — a stale control that looks saved is the failure worth a spinner.',
    );
    expect(tester.widget<Switch>(find.byType(Switch)).value, isTrue);
  });
}

// ── harness ────────────────────────────────────────────────────────

/// Put the settings screen on screen with a canned server behind it.
///
/// The session is adopted through /me exactly as the real app does, because the
/// screen reads its scope off `session.me` and a hand-built one would let the
/// test disagree with the client's own parsing.
Future<void> _pumpSettings(WidgetTester tester, MockClientHandler handler) async {
  final api = ApiClient(client: MockClient(handler));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(session.signedIn, isTrue, reason: 'The /me stub did not produce a session.');

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: SettingsScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

/// One row in the shape SettingsController builds for every endpoint.
Map<String, dynamic> _row(
  String key, {
  required String label,
  required String type,
  required dynamic value,
  required Map<String, dynamic>? source,
  List<String>? options,
}) => {
      'key': key,
      'label': label,
      'type': type,
      'value': value,
      'is_sensitive': false,
      'options': options,
      'help': null,
      'editable_here': true,
      'may_edit': true,
      'permission': 'organizations.edit',
      'allowed_scopes': const ['organization', 'company', 'franchise'],
      'source': source,
    };

Map<String, dynamic> _source(String type, String id, String name, bool inherited) => {
      'type': type,
      'id': id,
      'name': name,
      'inherited': inherited,
    };

/// Signed in, looking at a franchise — the scope where inheritance is visible
/// and therefore where a wrong `source` would show.
const Map<String, dynamic> _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'franchise',
    'id': 'fr-1',
    'label': 'Connecticut',
    'path': ['Everdoors', 'Connecticut'],
  },
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
};
