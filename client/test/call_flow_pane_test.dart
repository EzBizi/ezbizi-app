import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/call_flow_chart_pane.dart';
import 'package:ezbizi_client/screens/phone/call_flow_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Call flow, with General folded in (UI queue 51, Jose 2026-10-05: 1 and 4).
///
/// The company's answer is drawn once and changed where it is drawn; the
/// lines are rows; a row opens its chart in place. What a line DOES is still
/// the server's trace, read again after every change.
///
/// Jose, 2026-09-23, still holds: *"i'd like to be able to click the 4 items
/// they each have, and that should direct me to the screen where that can be
/// changed"* — a box now changes its own value, and the thing it points at
/// (the menu, the group) is one link away.
void main() {
  testWidgets('the answer is drawn once, however many lines follow it', (
    tester,
  ) async {
    await _pump(tester, lines: [_line('n-1', 'Main'), _line('n-2', 'Sam'), _line('n-3', 'Avery')]);

    expect(find.byKey(const Key('call-flow-box:hours')), findsOneWidget);
    expect(find.byKey(const Key('call-flow-box:answer')), findsOneWidget);
    expect(find.text('3 lines'), findsOneWidget);

    // Control: the three lines ARE on screen, each once, as rows.
    for (final id in ['n-1', 'n-2', 'n-3']) {
      expect(find.byKey(ValueKey('flow:$id')), findsOneWidget);
    }
  });

  testWidgets('the wrong box carries its fix, and a pick saves the company '
      'and reads every line again', (tester) async {
    final server = _Server(lines: [_line('n-1', 'Main', reaches: false)]);
    await _pump(tester, server: server);

    expect(find.text('Nobody'), findsOneWidget);
    expect(server.traces, 1);

    await tester.tap(find.widgetWithText(FilledButton, 'Pick who rings'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Office team'));
    await tester.pumpAndSettle();

    expect(server.patches, hasLength(1));
    expect(server.patches.single.$1, '/api/v1/phone/connections/cx-1');
    expect(server.patches.single.$2['default_ring_group_id'], 'g-1');

    // The rest of the answer travels unchanged, as General's Save sent it.
    expect(server.patches.single.$2['default_flow_id'], 'f-1');
    expect(server.patches.single.$2['default_record'], isTrue);

    // And the lines are the server's again, not the screen's guess.
    expect(server.traces, 2);
  });

  testWidgets('a group that can ring nobody is red too, with its fix and '
      'the way to the group', (tester) async {
    final opened = <String>[];
    await _pump(
      tester,
      server: _Server(lines: [_line('n-1', 'Main')], ringGroup: 'g-1', reaches: false),
      onOpen: opened.add,
    );

    expect(find.text('Nobody in it can be rung, so calls go straight to voicemail.'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Pick who rings'), findsOneWidget);

    await tester.tap(find.text('Edit the group'));
    await tester.pumpAndSettle();
    expect(opened, ['ring-groups']);

    // Control: the same group, ringing somebody, is not a fault.
    await _pump(
      tester,
      server: _Server(lines: [_line('n-1', 'Main')], ringGroup: 'g-1', reaches: true),
    );
    expect(find.widgetWithText(FilledButton, 'Pick who rings'), findsNothing);
    expect(find.text('Whoever is on duty in that group is dialled.'), findsOneWidget);
  });

  testWidgets('dismissing a picker saves nothing', (tester) async {
    final server = _Server(lines: [_line('n-1', 'Main')]);
    await _pump(tester, server: server);

    await tester.tap(find.byKey(const Key('call-flow-box:hours')));
    await tester.pumpAndSettle();
    expect(find.text('Business hours'), findsOneWidget);

    await tester.tapAt(const Offset(4, 4));
    await tester.pumpAndSettle();

    expect(server.patches, isEmpty);
  });

  testWidgets('picking Nobody saves no ring group, not an empty one', (
    tester,
  ) async {
    final server = _Server(lines: [_line('n-1', 'Main')], ringGroup: 'g-1');
    await _pump(tester, server: server);

    await tester.tap(find.byKey(const Key('call-flow-box:answer')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Nobody — every call goes to voicemail'));
    await tester.pumpAndSettle();

    expect(server.patches, hasLength(1));
    expect(server.patches.single.$2.containsKey('default_ring_group_id'), isTrue);
    expect(server.patches.single.$2['default_ring_group_id'], isNull);
  });

  testWidgets('the menu and the group are one link from what edits them', (
    tester,
  ) async {
    final opened = <String>[];
    await _pump(
      tester,
      server: _Server(lines: [_line('n-1', 'Main')], ringGroup: 'g-1'),
      onOpen: opened.add,
    );

    await tester.tap(find.text('Edit the menu'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Edit the group'));
    await tester.pumpAndSettle();

    // The SECOND matters as much as the first: a screen that sent every link
    // to one place would pass on either tap alone.
    expect(opened, ['flows', 'ring-groups']);
  });

  testWidgets('a line opens its chart in place, and back returns to the list', (
    tester,
  ) async {
    await _pump(tester, lines: [_line('n-1', 'Main'), _line('n-2', 'Sam')]);

    final pushes = _navigatorPushes;

    await tester.tap(find.byKey(const ValueKey('flow:n-2')));
    await tester.pumpAndSettle();

    expect(find.byType(CallFlowChartPane), findsOneWidget);
    expect(
      tester.widget<CallFlowChartPane>(find.byType(CallFlowChartPane)).lineId,
      'n-2',
    );
    expect(_navigatorPushes, pushes, reason: 'Containment: a line is not a new screen.');

    await tester.tap(find.byTooltip('Back to Call flow'));
    await tester.pumpAndSettle();

    expect(find.byType(CallFlowChartPane), findsNothing);
    expect(find.byKey(const ValueKey('flow:n-1')), findsOneWidget);
  });

  testWidgets('a line says whose answer it uses only once the trace says so', (
    tester,
  ) async {
    await _pump(
      tester,
      lines: [
        _line('n-1', 'Main', from: 'company', reaches: false),
        _line('n-2', 'Dana', from: 'line', answer: 'Rings Existing Clients'),
        _line('n-3', 'Sam', from: 'line', reaches: false),
      ],
    );

    expect(find.text('Follows A-1 Chimney'), findsOneWidget);
    expect(find.text('Its own: Rings Existing Clients'), findsOneWidget);

    // Two lines reach nobody, and only the one that follows the company is
    // that box's doing: Sam's own group is the other.
    expect(find.text('2 lines reach nobody. Every call to them goes straight to voicemail.'), findsOneWidget);
    expect(find.text('1 line goes straight to voicemail.'), findsOneWidget);

    // Control: the same lines from a server that does not say.
    await _pump(
      tester,
      lines: [_line('n-1', 'Main', reaches: false), _line('n-2', 'Dana')],
    );

    expect(find.textContaining('Follows'), findsNothing);
    expect(find.textContaining('Its own'), findsNothing);
    expect(find.text('Every call goes straight to voicemail.'), findsOneWidget);
  });

  testWidgets("a company's lines are its own once the rows say which", (
    tester,
  ) async {
    await _pump(
      tester,
      server: _Server(
        companies: 2,
        lines: [
          _line('n-1', 'Main', connection: 'cx-1'),
          _line('n-2', 'Ever', connection: 'cx-2'),
        ],
      ),
    );

    expect(find.byKey(const ValueKey('flow:n-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('flow:n-2')), findsNothing);
    expect(find.text('1 line'), findsOneWidget);
  });

  testWidgets('without phone.edit the answer is shown and nothing changes it', (
    tester,
  ) async {
    final server = _Server(lines: [_line('n-1', 'Main')], permissions: ['phone.view']);
    await _pump(tester, server: server);

    expect(find.text('Nobody'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Pick who rings'), findsNothing);
    expect(find.text('Change'), findsNothing);
    expect(
      tester.widget<SwitchListTile>(find.byKey(const Key('call-flow-switch:record'))).onChanged,
      isNull,
    );
    expect(find.text('You can see how calls are answered, but not change it.'), findsOneWidget);

    await tester.tap(find.byKey(const Key('call-flow-box:hours')));
    await tester.pumpAndSettle();
    expect(find.text('Business hours'), findsNothing);
    expect(server.patches, isEmpty);
  });

  testWidgets('a phone gets the boxes top to bottom, the wrong one with its fix', (
    tester,
  ) async {
    await _pump(tester, lines: [_line('n-1', 'Main', reaches: false)], width: 390);

    final hours = tester.getRect(find.byKey(const Key('call-flow-box:hours')));
    final answer = tester.getRect(find.byKey(const Key('call-flow-box:answer')));

    expect(answer.top, greaterThan(hours.bottom - 1), reason: 'Still a row of boxes.');
    expect(find.widgetWithText(FilledButton, 'Pick'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}

// ── the server ─────────────────────────────────────────────────────

int _navigatorPushes = 0;

class _Observer extends NavigatorObserver {
  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) =>
      _navigatorPushes++;
}

class _Server {
  _Server({
    required this.lines,
    this.companies = 1,
    this.ringGroup,
    this.reaches,
    this.permissions = const ['phone.view', 'phone.edit'],
  });

  final List<Map<String, dynamic>> lines;
  final int companies;
  final String? ringGroup;
  final bool? reaches;
  final List<String> permissions;

  final patches = <(String, Map<String, dynamic>)>[];
  int traces = 0;

  Map<String, Object?> _connection(String id, String name) => {
    'id': id,
    'scope_type': 'company',
    'scope_id': 'co-$id',
    'scope_name': name,
    'default_flow_id': 'f-1',
    'default_hours_schedule_id': null,
    'default_ring_group_id': ringGroup,
    'default_reaches': ?reaches,
    'default_voicemail_box_id': 'b-1',
    'default_fallback_number': null,
    'default_recording_notice': null,
    'default_record': true,
    'default_transcribe': false,
    'default_voicemail_transcribe': false,
    'default_after_hours': 'voicemail',
    'default_after_hours_number': null,
  };

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {..._me, 'permissions': permissions},
      });
    }
    if (request.method == 'PATCH') {
      patches.add((path, jsonDecode(request.body) as Map<String, dynamic>));
      return _json({'data': _connection('cx-1', 'A-1 Chimney')});
    }
    if (path.endsWith('/phone/connections')) {
      return _json({
        'data': [
          _connection('cx-1', 'A-1 Chimney'),
          if (companies > 1) _connection('cx-2', 'EverDoors'),
        ],
      });
    }
    if (path.endsWith('/phone/ring-groups')) {
      return _json({
        'data': [
          {'id': 'g-1', 'name': 'Office team'},
        ],
      });
    }
    if (path.endsWith('/phone/flows')) {
      return _json({
        'data': [
          {'id': 'f-1', 'name': 'Main menu'},
        ],
      });
    }
    if (path.endsWith('/phone/voicemail-boxes')) {
      return _json({
        'data': [
          {'id': 'b-1', 'name': 'Harbor Mailbox'},
        ],
      });
    }
    if (path.endsWith('/phone/call-flow/live')) {
      return _json({
        'data': {'on_line': 0, 'queues_known': false},
      });
    }
    if (path.endsWith('/phone/call-flow')) {
      traces++;
      return _json({'data': lines});
    }

    return _json({'data': const []});
  }
}

Map<String, dynamic> _line(
  String id,
  String label, {
  bool reaches = true,
  String? from,
  String? connection,
  String answer = 'Rings Office team',
}) => {
  'id': id,
  'e164': '+1239555010${id.substring(2)}',
  'label': label,
  'connection_id': ?connection,
  'record': true,
  'transcribe': false,
  'steps': [
    {
      'step': 'arrival',
      'title': 'A call arrives',
      'usable': true,
      'from': ?(from == null ? null : 'line'),
    },
    {
      'step': 'answer',
      'title': reaches ? answer : 'Nobody answers this line',
      'usable': reaches,
      'from': ?from,
    },
  ],
  'graph': {
    'nodes': [
      {'id': 'caller', 'kind': 'caller', 'title': 'Caller', 'detail': 'Somebody dials.', 'state': 'ok'},
      {'id': 'team', 'kind': 'ring_group', 'title': 'Office team', 'detail': 'Rings 3 people.', 'state': 'ok'},
    ],
    'edges': [
      {'from': 'caller', 'to': 'team', 'label': ''},
    ],
  },
};

Future<void> _pump(
  WidgetTester tester, {
  List<Map<String, dynamic>>? lines,
  _Server? server,
  void Function(String)? onOpen,
  double width = 1100,
}) async {
  tester.view.physicalSize = Size(width, 1400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final serving = server ?? _Server(lines: lines ?? const []);
  final api = ApiClient(client: MockClient(serving.handle));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      navigatorObservers: [_Observer()],
      // The chart's flow animation repeats; reduced motion is what lets
      // pumpAndSettle return, as in call_flow_chart_pane_test.
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(context).copyWith(disableAnimations: true),
        child: child!,
      ),
      home: Scaffold(
        body: KeyedSubtree(
          key: UniqueKey(),
          child: CallFlowPane(api: api, session: session, onOpen: onOpen),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Dana Reyes',
  'email': 'dana@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['phone.view', 'phone.edit'],
  'modules': <Map<String, Object?>>[],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A1-Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A1-Group',
    'path': ['A1-Group'],
  },
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
