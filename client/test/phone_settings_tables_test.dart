import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/numbers_pane.dart';
import 'package:ezbizi_client/screens/phone/porting_pane.dart';
import 'package:ezbizi_client/screens/phone/ring_groups_pane.dart';
import 'package:ezbizi_client/screens/phone/settings_tab.dart';
import 'package:ezbizi_client/screens/phone/voicemail_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Communication › Settings as tiles over a table (Mia, 2026-10-02): each
/// list's tiles count the whole list from the server's own fields, and a tile
/// filters the table to what it counted.
void main() {
  testWidgets('Connection counts accounts by status and filters to them', (
    tester,
  ) async {
    await _pump(
      tester,
      (api, session) => PhoneSettingsTab(api: api, session: session),
      companies: const [
        {'id': 'co-1', 'name': 'A-1 Chimney', 'parent_company_id': null},
        {'id': 'co-2', 'name': 'Everdoors Co', 'parent_company_id': null},
        {'id': 'co-3', 'name': 'Garage Doors', 'parent_company_id': null},
      ],
      routes: {
        '/phone/connections': [
          _conn('pc-1', 'co-1', 'connected'),
          _conn('pc-2', 'co-2', 'failed'),
        ],
        '/phone/numbers': [
          {'id': 'n-1', 'connection_id': 'pc-1', 'e164': '+12035550100'},
          {'id': 'n-2', 'connection_id': 'pc-1', 'e164': '+12035550101'},
          {'id': 'n-3', 'connection_id': 'pc-2', 'e164': '+18605550150'},
        ],
      },
    );

    expect(_count(tester, 'connection', 'all'), '3');
    expect(_count(tester, 'connection', 'ok'), '1');
    expect(_count(tester, 'connection', 'bad'), '1');
    expect(_count(tester, 'connection', 'none'), '1');

    // Lines on each account, counted from /phone/numbers by connection_id.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('connection-company-co-1')),
        matching: find.text('2'),
      ),
      findsOneWidget,
    );

    await _pick(tester, 'connection', 'bad');
    expect(find.text('Everdoors Co'), findsOneWidget);
    expect(find.text('A-1 Chimney'), findsNothing);
    expect(find.text('Garage Doors'), findsNothing);
  });

  testWidgets('Numbers counts silent and recorded lines and filters to them', (
    tester,
  ) async {
    await _pump(
      tester,
      (api, session) => NumbersPane(api: api, session: session),
      routes: {
        '/phone/connections': [_conn('pc-1', 'co-1', 'connected')],
        '/phone/numbers': [
          _line('n-1', 'Main', answers: true, record: true),
          _line('n-2', 'Hamden', answers: true, record: false),
          _line('n-3', 'Old line', answers: false, record: false),
        ],
      },
    );

    expect(_count(tester, 'lines', 'all'), '3');
    expect(_count(tester, 'lines', 'answered'), '2');
    expect(_count(tester, 'lines', 'silent'), '1');
    expect(_count(tester, 'lines', 'recorded'), '1');

    // The server's own words for what answers it.
    expect(find.text('Rings Dispatch'), findsNWidgets(2));
    // No bare Yes / No for recording and writing down: stacked, they read as
    // "Yes Yes" with nothing saying what (Jose, 2026-10-05: "get rid of it").
    expect(find.text('Yes'), findsNothing);
    expect(find.text('No'), findsNothing);

    await _pick(tester, 'lines', 'silent');
    expect(find.text('Old line'), findsOneWidget);
    expect(find.text('Main'), findsNothing);
    expect(find.text('Hamden'), findsNothing);

    await _pick(tester, 'lines', 'recorded');
    expect(find.text('Main'), findsOneWidget);
    expect(find.text('Old line'), findsNothing);
  });

  testWidgets('a line with a health check still draws its row', (
    tester,
  ) async {
    // Desktop review 2026-11-13, Top 3: the health pill and each problem were
    // cells with no column, the table threw, and the list was a grey block.
    await _pump(
      tester,
      (api, session) => NumbersPane(api: api, session: session),
      routes: {
        '/phone/connections': [_conn('pc-1', 'co-1', 'connected')],
        '/phone/numbers': [
          {
            ..._line('n-1', 'Main', answers: true, record: true),
            'health': {
              'problems': ['Nobody is signed in to take calls.'],
            },
          },
          _line('n-2', 'Hamden', answers: true, record: false),
        ],
      },
    );

    expect(tester.takeException(), isNull);
    expect(find.text('Main'), findsOneWidget);
    expect(find.text('Hamden'), findsOneWidget);
    expect(find.byKey(const Key('number-health-n-1')), findsOneWidget);
  });

  testWidgets('Porting counts by the carrier status and filters to it', (
    tester,
  ) async {
    await _pump(
      tester,
      (api, session) => PortingPane(api: api, session: session),
      routes: {
        '/phone/connections': [_conn('pc-1', 'co-1', 'connected')],
        '/phone/ports': [
          _port('p-1', '+12035550199', 'in_review', settled: false),
          _port('p-2', '+12035550198', 'action_required', settled: false),
          _port('p-3', '+12035550197', 'waiting_for_signature', settled: false),
          _port('p-4', '+18605550140', 'completed', settled: true),
          _port('p-5', '+18605550141', 'canceled', settled: true),
        ],
      },
    );

    expect(_count(tester, 'ports', 'all'), '5');
    expect(_count(tester, 'ports', 'progress'), '1');
    // A letter of authorization waiting to be signed is something to do here.
    expect(_count(tester, 'ports', 'action'), '2');
    expect(_count(tester, 'ports', 'done'), '1');

    await _pick(tester, 'ports', 'action');
    expect(find.text('+12035550198'), findsOneWidget);
    expect(find.text('+12035550197'), findsOneWidget);
    expect(find.text('+12035550199'), findsNothing);
    expect(find.text('+18605550141'), findsNothing);
    expect(find.text('Action needed'), findsOneWidget);
  });

  // Jose, 2026-10-05: port in "showing pin codes and verification". A port
  // started here says the PIN and account it was given, and who was sent
  // the letter of authorization while it waits for their signature.
  testWidgets('a port says its PIN, account and who must sign', (tester) async {
    await _pump(
      tester,
      (api, session) => PortingPane(api: api, session: session),
      routes: {
        '/phone/connections': [_conn('pc-1', 'co-1', 'connected')],
        '/phone/ports': [
          {
            ..._port('p-1', '+12035550199', 'waiting_for_signature', settled: false),
            'carrier': {
              'pin': '4321',
              'losing_carrier': {
                'account_number': '55501234',
                'authorized_representative_email': 'jose@example.com',
              },
            },
          },
        ],
      },
    );

    expect(find.textContaining('PIN 4321'), findsOneWidget);
    expect(find.textContaining('Account 55501234'), findsOneWidget);
    expect(
      find.textContaining('Letter of authorization sent to jose@example.com'),
      findsOneWidget,
    );
  });

  testWidgets('Ring groups counts who can answer now and filters to it', (
    tester,
  ) async {
    await _pump(
      tester,
      (api, session) => RingGroupsPane(api: api, session: session),
      routes: {
        '/phone/connections': [_conn('pc-1', 'co-1', 'connected')],
        '/phone/ring-groups': [
          _group('rg-1', 'Dispatch', [
            {'user_id': 'u-1', 'name': 'Ana', 'reachable': true},
            {'user_id': 'u-2', 'name': 'Bo', 'reachable': false},
          ]),
          _group('rg-2', 'Garage team', [
            {'user_id': 'u-3', 'name': 'Cy', 'reachable': false},
          ]),
          _group('rg-3', 'Empty', const []),
        ],
      },
    );

    expect(_count(tester, 'ring-groups', 'all'), '3');
    expect(_count(tester, 'ring-groups', 'free'), '1');
    expect(_count(tester, 'ring-groups', 'none'), '2');
    expect(find.text('1 of 2'), findsOneWidget);

    await _pick(tester, 'ring-groups', 'none');
    expect(find.text('Garage team'), findsOneWidget);
    expect(find.text('Empty'), findsOneWidget);
    expect(find.text('Dispatch'), findsNothing);
  });

  testWidgets('Voicemail boxes counts unused and transcribed and filters', (
    tester,
  ) async {
    await _pump(
      tester,
      (api, session) => VoicemailPane(api: api, session: session),
      routes: {
        '/phone/connections': [
          {
            ..._conn('pc-1', 'co-1', 'connected'),
            'default_voicemail_box_id': 'vm-3',
          },
        ],
        '/phone/voicemail-boxes': [
          _box('vm-1', 'Main office', lines: 2, transcribe: true),
          _box('vm-2', 'Old line', lines: 0, transcribe: false),
          // No line of its own, but the account default: not unused.
          _box('vm-3', 'After hours', lines: 0, transcribe: true),
        ],
      },
    );

    expect(_count(tester, 'voicemail-boxes', 'all'), '3');
    expect(_count(tester, 'voicemail-boxes', 'unused'), '1');
    expect(_count(tester, 'voicemail-boxes', 'transcribed'), '2');
    expect(find.text('Yes'), findsNothing);
    expect(find.text('No'), findsNothing);

    await _pick(tester, 'voicemail-boxes', 'unused');
    expect(find.text('Old line'), findsOneWidget);
    expect(find.text('Main office'), findsNothing);
    expect(find.text('After hours'), findsNothing);
  });
}

Map<String, Object?> _conn(String id, String companyId, String status) => {
  'id': id,
  'scope_type': 'company',
  'scope_id': companyId,
  'scope_name': companyId,
  'kind': 'subaccount',
  'status': status,
};

Map<String, Object?> _line(
  String id,
  String label, {
  required bool answers,
  required bool record,
}) => {
  'id': id,
  'connection_id': 'pc-1',
  'scope_type': 'company',
  'scope_id': 'co-1',
  'e164': '+1203555010${id.substring(2)}',
  'label': label,
  'record': record,
  'transcribe': false,
  'after_hours': 'voicemail',
  'ring_group_id': answers ? 'rg-1' : null,
  'answers': answers,
  'steps': [
    {
      'step': 'answer',
      'title': answers ? 'Rings Dispatch' : 'Nobody answers this line',
      'usable': answers,
    },
  ],
};

Map<String, Object?> _port(
  String id,
  String e164,
  String status, {
  required bool settled,
}) => {
  'id': id,
  'connection_id': 'pc-1',
  'e164s': [e164],
  'status': status,
  'status_reason': null,
  'created_at': '2026-09-25T10:00:00Z',
  'settled': settled,
};

Map<String, Object?> _group(
  String id,
  String name,
  List<Map<String, Object?>> members,
) => {
  'id': id,
  'connection_id': 'pc-1',
  'name': name,
  'mode': 'simultaneous',
  'timeout_seconds': 20,
  'overflow': 'voicemail',
  'member_user_ids': [for (final m in members) m['user_id']],
  'members': members,
  'members_measured_at': DateTime.now().toUtc().toIso8601String(),
};

Map<String, Object?> _box(
  String id,
  String name, {
  required int lines,
  required bool transcribe,
}) => {
  'id': id,
  'connection_id': 'pc-1',
  'name': name,
  'owner_type': 'company',
  'owner_name': 'A-1 Chimney',
  'line_count': lines,
  'max_seconds': 120,
  'transcribe': transcribe,
  'notify_emails': <String>[],
  'spoken_greeting': 'Please leave a message after the tone.',
};

/// The number a tile shows.
String _count(WidgetTester tester, String prefix, String key) {
  final texts = tester
      .widgetList<Text>(
        find.descendant(
          of: find.byKey(Key('$prefix-tile-$key')),
          matching: find.byType(Text),
        ),
      )
      .map((t) => t.data ?? '')
      .toList();

  // Label, count, note - in that order.
  return texts[1];
}

Future<void> _pick(WidgetTester tester, String prefix, String key) async {
  await tester.tap(find.byKey(Key('$prefix-tile-$key')));
  await tester.pumpAndSettle();
}

Future<void> _pump(
  WidgetTester tester,
  Widget Function(ApiClient api, Session session) build, {
  required Map<String, List<Map<String, Object?>>> routes,
  List<Map<String, Object?>>? companies,
}) async {
  tester.view.physicalSize = const Size(1400, 1200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'companies': ?companies},
        });
      }

      for (final entry in routes.entries) {
        if (path.endsWith(entry.key)) return _json({'data': entry.value});
      }

      return _json({'data': <Object>[]});
    }),
  );
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: build(api, session)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['phone.view', 'phone.edit'],
  'modules': [
    {'key': 'phone', 'label': 'Phone', 'available': true, 'reachable': true},
  ],
  'preferences': <String, dynamic>{},
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
  'companies': [
    {'id': 'co-1', 'name': 'A-1 Chimney', 'parent_company_id': null},
  ],
  'franchises': <Map<String, Object?>>[],
};
