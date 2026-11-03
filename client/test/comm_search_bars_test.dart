import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/field/recordings_tab.dart';
import 'package:ezbizi_client/screens/phone/blocked_numbers_pane.dart';
import 'package:ezbizi_client/screens/phone/call_history_pane.dart';
import 'package:ezbizi_client/screens/phone/extensions_pane.dart';
import 'package:ezbizi_client/screens/phone/flows_pane.dart';
import 'package:ezbizi_client/screens/phone/hours_pane.dart';
import 'package:ezbizi_client/screens/phone/numbers_pane.dart';
import 'package:ezbizi_client/screens/phone/porting_pane.dart';
import 'package:ezbizi_client/screens/phone/records_tab.dart';
import 'package:ezbizi_client/screens/phone/ring_groups_pane.dart';
import 'package:ezbizi_client/screens/phone/settings_tab.dart';
import 'package:ezbizi_client/screens/phone/tags_pane.dart';
import 'package:ezbizi_client/screens/phone/team_tab.dart';
import 'package:ezbizi_client/screens/phone/voicemail_pane.dart';
import 'package:ezbizi_client/screens/phone/voicemails_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'support/fake_recording_player.dart';

/// Jose, 2026-10-22: "i want search bars". Every Communication list, and
/// Field › Recordings, has a search box.
///
/// Each test seeds two rows that differ on the term, so a box that filters
/// nothing fails: the row that does not match must go, and the one that does
/// must stay. Lists the server returns whole are filtered on the client; the
/// capped /phone/calls and /field/recordings lists also send `q`, and those
/// tests assert the request that carried it.
void main() {
  group('client-side filter', () {
    testWidgets('Records › Calls', (tester) async {
      await _pump(
        tester,
        (api, session) => RecordsTab(
          api: api,
          session: session,
          player: FakeRecordingPlayer(),
        ),
        routes: {
          '/phone/calls': {
            'data': [
              _call('ca-1', '+12035550199', customer: 'Dana Whitlock'),
              _call('ca-2', '+18605550142', customer: 'Omar Pike'),
            ],
          },
          '/phone/messages': {'data': <Object>[]},
          '/phone/numbers': {'data': <Object>[]},
        },
      );

      await _search(tester, 'comm-records-calls-search', 'whitlock');
      _shows('phone-record-ca-1', hides: 'phone-record-ca-2');

      // A number typed with its punctuation still finds the row.
      await _search(tester, 'comm-records-calls-search', '(860) 555');
      _shows('phone-record-ca-2', hides: 'phone-record-ca-1');

      await _search(tester, 'comm-records-calls-search', 'zzz');
      expect(find.text('Nothing matches "zzz".'), findsOneWidget);
    });

    testWidgets('Records › Messages', (tester) async {
      await _pump(
        tester,
        (api, session) => RecordsTab(
          api: api,
          session: session,
          player: FakeRecordingPlayer(),
          initialFilter: RecordsFilter.sms,
          showFilters: false,
        ),
        routes: {
          '/phone/calls': {'data': <Object>[]},
          '/phone/messages': {
            'data': [
              _sms('m-1', '+12035550199', 'Is Thursday still good?'),
              _sms('m-2', '+18605550142', 'Please send the invoice'),
            ],
          },
          '/phone/numbers': {'data': <Object>[]},
        },
      );

      await _search(tester, 'comm-records-messages-search', 'INVOICE');
      _shows('phone-thread-+18605550142', hides: 'phone-thread-+12035550199');
    });

    testWidgets('Users', (tester) async {
      await _pump(
        tester,
        (api, session) => TeamTab(api: api, session: session),
        routes: {
          '/phone/extensions': {
            'connection_id': 'pc-1',
            'data': [
              {
                'user_id': 'u-1',
                'name': 'Andy Ocampo',
                'extension': '0101',
                'number_id': null,
              },
              {
                'user_id': 'u-2',
                'name': 'Sasha Solano',
                'extension': '0102',
                'number_id': null,
              },
            ],
            'numbers': <Object>[],
          },
        },
      );

      await _search(tester, 'comm-users-search', 'sasha');
      _shows('user-u-2', hides: 'user-u-1');
    });

    testWidgets('Settings › Numbers', (tester) async {
      await _pump(
        tester,
        (api, session) => NumbersPane(api: api, session: session),
        routes: {
          '/phone/connections': {
            'data': [_conn('pc-1', 'co-1')],
          },
          '/phone/numbers': {
            'data': [
              _line('n-1', 'Main', '+12035550101'),
              _line('n-2', 'Hamden', '+18605550102'),
            ],
          },
        },
      );

      await _search(tester, 'comm-numbers-search', 'hamden');
      _shows('line:n-2', hides: 'line:n-1');
    });

    testWidgets('Settings › Porting', (tester) async {
      await _pump(
        tester,
        (api, session) => PortingPane(api: api, session: session),
        routes: {
          '/phone/connections': {
            'data': [_conn('pc-1', 'co-1')],
          },
          '/phone/ports': {
            'data': [
              _port('p-1', '+12035550199'),
              _port('p-2', '+18605550140'),
            ],
          },
        },
      );

      await _search(tester, 'comm-porting-search', '860555');
      _shows('port:p-2', hides: 'port:p-1');
    });

    testWidgets('Settings › Ring groups', (tester) async {
      await _pump(
        tester,
        (api, session) => RingGroupsPane(api: api, session: session),
        routes: {
          '/phone/connections': {
            'data': [_conn('pc-1', 'co-1')],
          },
          '/phone/ring-groups': {
            'data': [
              _group('rg-1', 'Dispatch', 'Ana Ruiz'),
              _group('rg-2', 'Garage team', 'Cy Brandt'),
            ],
          },
        },
      );

      // A member's name finds the group they are in.
      await _search(tester, 'comm-ring-groups-search', 'brandt');
      _shows('ring-group-rg-2', hides: 'ring-group-rg-1');
    });

    testWidgets('Settings › Voicemail boxes', (tester) async {
      await _pump(
        tester,
        (api, session) => VoicemailPane(api: api, session: session),
        routes: {
          '/phone/connections': {
            'data': [_conn('pc-1', 'co-1')],
          },
          '/phone/voicemail-boxes': {
            'data': [_box('vm-1', 'Main office'), _box('vm-2', 'After hours')],
          },
        },
      );

      await _search(tester, 'comm-voicemail-boxes-search', 'after');
      _shows('vm:vm-2', hides: 'vm:vm-1');
    });

    testWidgets('Settings › Extensions', (tester) async {
      await _pump(
        tester,
        (api, session) => ExtensionsPane(api: api, session: session),
        routes: {
          '/phone/extensions/directory': {
            'connection_id': 'pc-1',
            'data': [
              {
                'id': 'e-1',
                'extension': '0102',
                'target_kind': 'user',
                'target_id': 'u-1',
                'target_name': 'Alice Nolan',
              },
              {
                'id': 'e-2',
                'extension': '0300',
                'target_kind': 'ring_group',
                'target_id': 'g-1',
                'target_name': 'Appointments',
              },
            ],
          },
        },
      );

      await _search(tester, 'comm-extensions-search', '0300');
      _shows('extension:e-2', hides: 'extension:e-1');
    });

    testWidgets('Settings › Blocked callers', (tester) async {
      await _pump(
        tester,
        (api, session) => BlockedNumbersPane(api: api, session: session),
        routes: {
          '/phone/connections': {
            'data': [_conn('cx-1', 'co-1')],
          },
          '/phone/blocked-numbers': {
            'data': [
              _blocked('bn-1', '+13055550911', 'Robocall'),
              _blocked('bn-2', '+13055550912', 'Abusive caller'),
            ],
          },
        },
      );

      await _search(tester, 'comm-blocked-search', 'robo');
      _shows('blocked-bn-1', hides: 'blocked-bn-2');
    });

    testWidgets('Settings › Tags', (tester) async {
      await _pump(
        tester,
        (api, session) => TagsPane(api: api, session: session),
        routes: {
          '/phone/connections': {
            'data': [_conn('cx-1', 'co-1')],
          },
          '/phone/tags': {
            'data': [
              {'id': 'tg-1', 'name': 'Booked', 'is_active': true},
              {'id': 'tg-2', 'name': 'Warranty', 'is_active': true},
            ],
          },
        },
      );

      await _search(tester, 'comm-tags-search', 'warr');
      _shows('tag:tg-2', hides: 'tag:tg-1');
    });

    testWidgets('Settings › Connection', (tester) async {
      await _pump(
        tester,
        (api, session) => PhoneSettingsTab(api: api, session: session),
        companies: const [
          {'id': 'co-1', 'name': 'A-1 Chimney', 'parent_company_id': null},
          {'id': 'co-2', 'name': 'Garage Doors', 'parent_company_id': null},
        ],
        routes: {
          '/phone/connections': {
            'data': [_conn('pc-1', 'co-1'), _conn('pc-2', 'co-2')],
          },
          '/phone/numbers': {'data': <Object>[]},
        },
      );

      await _search(tester, 'comm-connections-search', 'garage');
      _shows('connection-company-co-2', hides: 'connection-company-co-1');
    });

    testWidgets('Settings › IVR menus', (tester) async {
      await _pump(
        tester,
        (api, session) => FlowsPane(api: api, session: session),
        routes: {
          '/phone/connections': {
            'data': [
              {'id': 'pc-1', 'label': 'Main account'},
            ],
          },
          '/phone/flows': {
            'data': [_flow('f-1', 'Main menu'), _flow('f-2', 'Garage menu')],
          },
        },
      );

      await _search(tester, 'comm-ivr-menus-search', 'garage');
      _shows('flow:f-2', hides: 'flow:f-1');
    });

    testWidgets('Settings › Hours', (tester) async {
      await _pump(
        tester,
        (api, session) => HoursPane(api: api, session: session),
        routes: {
          '/phone/connections': {
            'data': [_conn('cx-1', 'co-1')],
          },
          '/phone/hours': {
            'data': [
              _hours('hs-1', 'Office hours', 'America/New_York'),
              _hours('hs-2', 'Weekend desk', 'America/Chicago'),
            ],
          },
        },
      );

      await _search(tester, 'comm-hours-search', 'weekend');
      _shows('hours:hs-2', hides: 'hours:hs-1');
    });
  });

  group('server search, with the client filter until it is honoured', () {
    testWidgets('Voicemail › messages sends q', (tester) async {
      final seen = <http.Request>[];
      await _pump(
        tester,
        (api, session) => VoicemailsPane(
          api: api,
          session: session,
          player: FakeRecordingPlayer(),
        ),
        seen: seen,
        routes: {
          '/phone/voicemail-boxes': {'data': <Object>[]},
          '/phone/calls': {
            'data': [
              _voicemail('c-1', '+13055550100', 'Please ring me back.'),
              _voicemail('c-2', '+13055550101', 'The chimney is leaking.'),
            ],
          },
        },
      );

      await _search(tester, 'comm-voicemail-messages-search', 'chimney');

      final searched = seen.where(
        (r) =>
            r.url.path.endsWith('/phone/calls') &&
            r.url.queryParameters['q'] == 'chimney',
      );
      expect(searched, hasLength(1));
      expect(searched.single.url.queryParameters['voicemail_only'], '1');
      expect(find.text('The chimney is leaking.'), findsOneWidget);
      expect(find.text('Please ring me back.'), findsNothing);
    });

    testWidgets('Phone › call history sends q', (tester) async {
      final seen = <http.Request>[];
      await _pump(
        tester,
        (api, session) => CallHistoryPane(
          api: api,
          formats: DisplayFormats.fallback,
          onPick: (_) {},
        ),
        seen: seen,
        routes: {
          '/phone/calls': {
            'data': [
              _call('c-1', '+12035550199', customer: 'Dana Whitlock'),
              _call('c-2', '+18605550142', customer: 'Omar Pike'),
            ],
          },
        },
      );

      await _search(tester, 'comm-call-history-search', 'pike');

      final searched = seen.where(
        (r) =>
            r.url.path.endsWith('/phone/calls') &&
            r.url.queryParameters['q'] == 'pike',
      );
      expect(searched, hasLength(1));
      expect(searched.single.url.queryParameters['per_page'], '40');
      _shows('call-c-2', hides: 'call-c-1');
    });

    testWidgets('Field › Recordings sends q', (tester) async {
      final seen = <http.Request>[];
      tester.view.physicalSize = const Size(1400, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);

      final api = ApiClient(
        client: MockClient((request) async {
          seen.add(request);
          return _json({
            'data': [
              _recording('r1', 'Sam Ortiz', 1042),
              _recording('r2', 'Lena Park', 1077),
            ],
          });
        }),
      );

      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            body: FieldRecordingsTab(
              api: api,
              canListen: true,
              canKeep: false,
              player: FakeRecordingPlayer(),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      await _search(tester, 'field-recordings-search', 'JOB-1077');

      final searched = seen.where(
        (r) =>
            r.url.path.endsWith('/field/recordings') &&
            r.url.queryParameters['q'] == 'JOB-1077',
      );
      expect(searched, hasLength(1));
      _shows('field-recording-r2', hides: 'field-recording-r1');
    });
  });
}

/// Types [term] into the box, waits out the 400 ms debounce, and settles.
Future<void> _search(WidgetTester tester, String key, String term) async {
  final box = find.byKey(ValueKey(key));
  expect(box, findsOneWidget);
  expect(
    tester.getSize(box).height,
    44,
    // Leads went to 44 on 2026-11-02 (6af32366a) and ListSearch with it.
    reason: 'the standard field height, as Leads (Jose, 2026-10-27)',
  );

  await tester.enterText(box, term);
  await tester.pump(const Duration(milliseconds: 450));
  await tester.pumpAndSettle();
}

void _shows(String kept, {required String hides}) {
  expect(find.byKey(ValueKey(kept)), findsOneWidget);
  expect(find.byKey(ValueKey(hides)), findsNothing);
}

Future<void> _pump(
  WidgetTester tester,
  Widget Function(ApiClient api, Session session) build, {
  required Map<String, Object> routes,
  List<Map<String, Object?>>? companies,
  List<http.Request>? seen,
}) async {
  tester.view.physicalSize = const Size(1400, 1200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      seen?.add(request);
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'companies': ?companies},
        });
      }

      for (final entry in routes.entries) {
        if (path.endsWith(entry.key)) return _json(entry.value);
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

Map<String, Object?> _call(String id, String from, {String? customer}) => {
  'id': id,
  'number_id': 'n-1',
  'connection_id': 'pc-1',
  'direction': 'inbound',
  'from_e164': from,
  'to_e164': '+12035550100',
  'status': 'completed',
  'duration_seconds': 60,
  'voicemail': false,
  'customer_name': customer,
  'created_at': '2026-10-20T09:14:00+00:00',
};

Map<String, Object?> _sms(String id, String from, String body) => {
  'id': id,
  'number_id': 'n-1',
  'connection_id': 'pc-1',
  'direction': 'inbound',
  'from_e164': from,
  'to_e164': '+12035550100',
  'body': body,
  'created_at': '2026-10-20T16:02:00+00:00',
};

Map<String, Object?> _voicemail(String id, String from, String transcript) => {
  'id': id,
  'from_e164': from,
  'voicemail': true,
  'dealt_at': null,
  'voicemail_transcript': transcript,
  'created_at': '2026-10-20T10:00:00+00:00',
};

Map<String, Object?> _conn(String id, String companyId) => {
  'id': id,
  'scope_type': 'company',
  'scope_id': companyId,
  'scope_name': companyId,
  'kind': 'subaccount',
  'status': 'connected',
};

Map<String, Object?> _line(String id, String label, String e164) => {
  'id': id,
  'connection_id': 'pc-1',
  'scope_type': 'company',
  'scope_id': 'co-1',
  'e164': e164,
  'label': label,
  'record': false,
  'transcribe': false,
  'after_hours': 'voicemail',
  'ring_group_id': 'rg-1',
  'answers': true,
  'steps': [
    {'step': 'answer', 'title': 'Rings Dispatch', 'usable': true},
  ],
};

Map<String, Object?> _port(String id, String e164) => {
  'id': id,
  'connection_id': 'pc-1',
  'e164s': [e164],
  'status': 'in_review',
  'status_reason': null,
  'created_at': '2026-09-25T10:00:00Z',
  'settled': false,
};

Map<String, Object?> _group(String id, String name, String member) => {
  'id': id,
  'connection_id': 'pc-1',
  'name': name,
  'mode': 'simultaneous',
  'timeout_seconds': 20,
  'overflow': 'voicemail',
  'member_user_ids': ['u-$id'],
  'members': [
    {'user_id': 'u-$id', 'name': member, 'reachable': true},
  ],
  'members_measured_at': DateTime.now().toUtc().toIso8601String(),
};

Map<String, Object?> _box(String id, String name) => {
  'id': id,
  'connection_id': 'pc-1',
  'name': name,
  'owner_type': 'company',
  'owner_name': 'A-1 Chimney',
  'line_count': 1,
  'max_seconds': 120,
  'transcribe': false,
  'notify_emails': <String>[],
  'spoken_greeting': 'Please leave a message after the tone.',
};

Map<String, Object?> _blocked(String id, String e164, String reason) => {
  'id': id,
  'connection_id': 'cx-1',
  'e164': e164,
  'reason': reason,
  'blocked_by_name': 'Jose Silva',
  'created_at': '2026-09-09T12:00:00+00:00',
};

Map<String, Object?> _flow(String id, String name) => {
  'id': id,
  'connection_id': 'pc-1',
  'name': name,
  'prompt': 'Press 1 for the office.',
  'wait_seconds': 7,
  'replays': 1,
  'no_input': 'ring',
  'options': [
    {'digit': '1', 'action': 'voicemail'},
  ],
  'number_ids': ['n-1'],
  'plays': true,
};

Map<String, Object?> _hours(String id, String name, String zone) => {
  'id': id,
  'connection_id': 'cx-1',
  'name': name,
  'timezone': zone,
  'weeks': {
    'mon': [
      ['09:00', '17:00'],
    ],
  },
};

Map<String, Object?> _recording(String id, String person, int job) => {
  'id': id,
  'user': {'id': 'u-$id', 'name': person},
  'job': {'id': 'j-$id', 'number': job, 'title': 'Sweep'},
  'started_at': '2026-10-16T13:12:00Z',
  'ended_at': '2026-10-16T14:00:00Z',
  'stop_reason': 'done',
  'duration_s': 2880,
  'chunks': 48,
  'events': <Object>[],
  'flagged': false,
  'flag_reason': null,
  'delete_on': '2027-01-14',
};

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
