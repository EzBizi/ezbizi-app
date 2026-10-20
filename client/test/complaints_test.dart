import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/phone_numbers.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customer_experience/complaints_tab.dart';
import 'package:ezbizi_client/screens/customer_experience_screen.dart';
import 'package:ezbizi_client/widgets/open_destination.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Customer Experience › Complaints (Jose, 2026-10-20; UI queue option 1 and
/// phone 4). Every name below is invented.
void main() {
  setUp(() {
    calls.clear();
    DeepLinks.pending.value = null;
  });

  testWidgets('the segment counts each status off meta.counts', (tester) async {
    await _pump(tester);

    for (final label in [
      'Open · 5',
      'New · 3',
      'In progress · 2',
      'Solved · 7',
      "Can't be solved · 1",
      'All',
    ]) {
      expect(find.widgetWithText(ChoiceChip, label), findsOneWidget);
    }
    expect(_lists.first.queryParameters['status'], 'open');

    await tester.tap(find.widgetWithText(ChoiceChip, 'Solved · 7'));
    await tester.pumpAndSettle();
    expect(_lists.last.queryParameters['status'], 'solved');
  });

  testWidgets('the table draws a row per complaint, with its columns', (
    tester,
  ) async {
    await _pump(tester);

    for (final head in [
      'CAME IN',
      'KIND',
      'FROM',
      'WHAT THEY SAID',
      'BRANCH',
      'OWNER',
      'STATUS',
      'NOTES',
    ]) {
      expect(find.text(head), findsOneWidget);
    }
    expect(find.text('Quinn Harlow'), findsOneWidget);
    expect(find.text('★2 Review'), findsOneWidget);
    expect(find.text('Call'), findsOneWidget);
    expect(find.text('Elm Ridge'), findsOneWidget);
    expect(find.text('Nobody'), findsWidgets);
  });

  testWidgets('filters send kind, owner, branch, dates and a debounced q', (
    tester,
  ) async {
    await _pump(tester);

    tester
        .widget<SearchPickerField<String?>>(
          find.byKey(const ValueKey('complaints-kind')),
        )
        .onChanged!('call_complaint');
    await tester.pumpAndSettle();
    expect(_lists.last.queryParameters['kind'], 'call_complaint');

    tester
        .widget<SearchPickerField<String?>>(
          find.byKey(const ValueKey('complaints-owner')),
        )
        .onChanged!('none');
    await tester.pumpAndSettle();
    expect(_lists.last.queryParameters['owner'], 'none');

    tester
        .widget<SearchPickerField<String?>>(
          find.byKey(const ValueKey('complaints-branch')),
        )
        .onChanged!('fr-1');
    await tester.pumpAndSettle();
    expect(_lists.last.queryParameters['branch'], 'fr-1');

    tester
        .widget<SearchPickerField<int?>>(
          find.byKey(const ValueKey('complaints-dates')),
        )
        .onChanged!(30);
    await tester.pumpAndSettle();
    expect(
      _lists.last.queryParameters['from'],
      matches(RegExp(r'^\d{4}-\d{2}-\d{2}$')),
    );

    final before = _lists.length;
    await tester.enterText(
      find.byKey(const ValueKey('complaints-search')),
      'soot',
    );
    await tester.pump(const Duration(milliseconds: 100));
    expect(_lists.length, before, reason: 'debounced, not on each key');
    await tester.pump(const Duration(milliseconds: 500));
    await tester.pumpAndSettle();
    expect(_lists.last.queryParameters['q'], 'soot');
    expect(_lists.last.queryParameters['kind'], 'call_complaint');
  });

  testWidgets('the pane PATCHes status and owner and POSTs a note', (
    tester,
  ) async {
    await _pump(tester);
    await tester.tap(find.byKey(const ValueKey('complaint-row-c-1')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('complaint-pane')), findsOneWidget);
    expect(
      calls.any((c) => c.method == 'GET' && _path(c) == '/complaints/c-1'),
      isTrue,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('complaint-pane')),
        matching: find.text('Called twice already.'),
      ),
      findsOneWidget,
    );

    tester
        .widget<SearchPickerField<String>>(
          find.byKey(const ValueKey('complaint-status')),
        )
        .onChanged!('in_progress');
    await tester.pumpAndSettle();
    var patch = calls.lastWhere((c) => c.method == 'PATCH');
    expect(_path(patch), '/complaints/c-1');
    expect(jsonDecode(patch.body), {'status': 'in_progress'});

    tester
        .widget<SearchPickerField<String?>>(
          find.byKey(const ValueKey('complaint-owner')),
        )
        .onChanged!('u-7');
    await tester.pumpAndSettle();
    patch = calls.lastWhere((c) => c.method == 'PATCH');
    expect(jsonDecode(patch.body), {'owner_user_id': 'u-7'});

    tester
        .widget<SearchPickerField<String?>>(
          find.byKey(const ValueKey('complaint-owner')),
        )
        .onChanged!(null);
    await tester.pumpAndSettle();
    patch = calls.lastWhere((c) => c.method == 'PATCH');
    expect(jsonDecode(patch.body), {'owner_user_id': null});

    await tester.enterText(
      find.byKey(const ValueKey('complaint-note')),
      'Offered a free sweep.',
    );
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('complaint-note-add')));
    await tester.pumpAndSettle();
    final post = calls.lastWhere((c) => c.method == 'POST');
    expect(_path(post), '/complaints/c-1/notes');
    expect(jsonDecode(post.body), {'text': 'Offered a free sweep.'});
  });

  testWidgets('without manage the pane is read-only and the board does not '
      'drag', (tester) async {
    await _pump(tester, manage: false);
    await tester.tap(find.byKey(const ValueKey('complaint-row-c-1')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('complaint-status')), findsNothing);
    expect(find.byKey(const ValueKey('complaint-owner')), findsNothing);
    expect(find.byKey(const ValueKey('complaint-note')), findsNothing);
    expect(find.byKey(const ValueKey('complaint-note-add')), findsNothing);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('complaint-pane')),
        matching: find.text('Called twice already.'),
      ),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('complaint-pane-close')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Board'));
    await tester.pumpAndSettle();
    expect(find.byType(Draggable<Map<String, dynamic>>), findsNothing);
    expect(calls.where((c) => c.method != 'GET'), isEmpty);
  });

  testWidgets('dragging a board card to another column PATCHes its status', (
    tester,
  ) async {
    await _pump(tester);
    await tester.tap(find.text('Board'));
    await tester.pumpAndSettle();
    expect(_lists.last.queryParameters['status'], 'all');
    expect(find.text('New · 3'), findsOneWidget);

    final card = find.byKey(const ValueKey('complaint-card-c-1'));
    final target = find.byKey(const ValueKey('complaints-column-solved'));
    final gesture = await tester.startGesture(tester.getCenter(card));
    await tester.pump(const Duration(milliseconds: 50));
    await gesture.moveBy(const Offset(20, 0));
    await tester.pump();
    await gesture.moveTo(tester.getCenter(target));
    await tester.pump();
    await gesture.up();
    await tester.pumpAndSettle();

    final patch = calls.lastWhere((c) => c.method == 'PATCH');
    expect(_path(patch), '/complaints/c-1');
    expect(jsonDecode(patch.body), {'status': 'solved'});
  });

  testWidgets('a complaint whose source was erased says so', (tester) async {
    await _pump(tester);

    expect(find.text('Removed at the source'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('complaint-row-c-3')));
    await tester.pumpAndSettle();
    expect(find.text('Removed at the source'), findsWidgets);
    expect(find.textContaining('was erased'), findsOneWidget);
    expect(find.byKey(const ValueKey('complaint-open-source')), findsNothing);
  });

  testWidgets('a nameless caller shows the number, not "Removed"', (
    tester,
  ) async {
    await _pump(tester);

    expect(find.text(formatPhoneNumber('+14145340603')), findsWidgets);
    expect(
      find.text('Removed at the source'),
      findsOneWidget,
      reason: 'only the erased one',
    );
  });

  testWidgets('Open the lead goes to Leads with that lead', (tester) async {
    final opened = <String>[];
    await _pump(tester, openLead: opened.add);
    await tester.tap(find.byKey(const ValueKey('complaint-row-c-2')));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Open the lead'));
    await tester.pumpAndSettle();
    expect(opened, ['ld-5']);
  });

  testWidgets('Open the review brings Reviews forward with that review', (
    tester,
  ) async {
    await _pump(tester, container: true);
    await tester.tap(find.widgetWithText(ChoiceChip, 'Complaints'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('complaint-row-c-1')));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Open the review'));
    await tester.pumpAndSettle();

    expect(
      tester
          .widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Reviews'))
          .selected,
      isTrue,
    );
    expect(calls.map(_path), contains('/reviews/rv-9'));
  });

  testWidgets('the Complaints chip rides on its own grant', (tester) async {
    await _pump(tester, container: true, view: false);
    expect(find.widgetWithText(ChoiceChip, 'Complaints'), findsNothing);
    expect(find.widgetWithText(ChoiceChip, 'Reviews'), findsOneWidget);

    await _pump(tester, container: true);
    expect(find.widgetWithText(ChoiceChip, 'Complaints'), findsOneWidget);
  });

  testWidgets('each status has its own empty state', (tester) async {
    await _pump(tester, empty: true);
    expect(find.text('No open complaints'), findsOneWidget);

    await tester.tap(find.widgetWithText(ChoiceChip, 'Solved · 0'));
    await tester.pumpAndSettle();
    expect(find.text('Nothing solved yet'), findsOneWidget);
  });

  testWidgets('Load more asks for the next page and keeps the rows', (
    tester,
  ) async {
    await _pump(tester, total: 80);
    await tester.tap(find.text('Load more (77 left)'));
    await tester.pumpAndSettle();
    expect(_lists.last.queryParameters['page'], '2');
    expect(find.text('Quinn Harlow'), findsWidgets);
  });

  testWidgets('on a phone: cards, details in place with back, one board '
      'column at a time, nothing overflows', (tester) async {
    final errors = <FlutterErrorDetails>[];
    final previous = FlutterError.onError;
    FlutterError.onError = errors.add;
    addTearDown(() => FlutterError.onError = previous);

    await _pump(tester, size: const Size(390, 844));

    expect(find.text('CAME IN'), findsNothing, reason: 'cards, not a table');
    expect(find.text('Quinn Harlow'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('complaint-row-c-1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('complaint-pane')), findsOneWidget);
    expect(find.byIcon(Icons.arrow_back), findsOneWidget);
    expect(find.byKey(const ValueKey('tour-complaints-list')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('complaint-pane-close')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('tour-complaints-list')), findsOneWidget);

    await tester.tap(find.byIcon(Icons.view_column_outlined));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('complaints-board-status')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('complaints-column-new')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('complaints-column-solved')),
      findsNothing,
    );

    tester
        .widget<SearchPickerField<String>>(
          find.byKey(const ValueKey('complaints-board-status')),
        )
        .onChanged!('solved');
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('complaints-column-solved')),
      findsOneWidget,
    );

    expect(
      errors,
      isEmpty,
      reason: errors.map((e) => e.exceptionAsString()).join(' | '),
    );
  });
}

// ------------------------------------------------------------------ harness

final calls = <http.Request>[];

String _path(http.Request r) => r.url.path.replaceFirst('/api/v1', '');

List<Uri> get _lists => [
  for (final c in calls)
    if (c.method == 'GET' && _path(c) == '/complaints') c.url,
];

Future<void> _pump(
  WidgetTester tester, {
  bool manage = true,
  bool view = true,
  bool container = false,
  bool empty = false,
  int? total,
  Size size = const Size(1400, 1000),
  void Function(String)? openLead,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(
      _handler(manage: manage, view: view, empty: empty, total: total),
    ),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  final Widget body = container
      ? CustomerExperienceScreen(api: api, session: session)
      : ComplaintsTab(api: api, session: session);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: OpenDestination(open: (_) {}, openLead: openLead, child: body),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

final _rows = <Map<String, dynamic>>[
  {
    'id': 'c-1',
    'kind': 'bad_review',
    'status': 'new',
    'title': 'Quinn Harlow',
    'summary': 'Called twice already.',
    'rating': 2,
    'branch': {'id': 'fr-1', 'name': 'Elm Ridge'},
    'owner': null,
    'raised_at': '2026-10-19T14:40:00Z',
    'updated_at': '2026-10-19T14:40:00Z',
    'solved_at': null,
    'note_count': 0,
    'source': {'type': 'review', 'id': 'rv-9'},
  },
  {
    'id': 'c-2',
    'kind': 'call_complaint',
    'status': 'in_progress',
    // A caller who gave no name and left no summary: still there (desk, 2026-10-20).
    'title': null,
    'summary': null,
    'phone': '+14145340603',
    'source_missing': false,
    'rating': null,
    'branch': null,
    'owner': {'id': 'u-7', 'name': 'Wren Ashby'},
    'raised_at': '2026-10-18T09:12:00Z',
    'updated_at': '2026-10-18T09:12:00Z',
    'solved_at': null,
    'note_count': 2,
    'source': {'type': 'lead', 'id': 'ld-5'},
  },
  {
    'id': 'c-3',
    'kind': 'bad_rating',
    'status': 'new',
    'title': null,
    'summary': null,
    'rating': 1,
    'branch': null,
    'owner': null,
    'raised_at': '2026-10-17T10:00:00Z',
    'updated_at': '2026-10-17T10:00:00Z',
    'solved_at': null,
    'note_count': 0,
    'source_missing': true,
    'source': {'type': 'review', 'id': 'rv-gone'},
  },
];

MockClientHandler _handler({
  required bool manage,
  required bool view,
  required bool empty,
  int? total,
}) {
  // The server's state: a write shows in the next list, as it does there.
  final store = [
    for (final r in _rows) {...r},
  ];

  return (request) async {
    calls.add(request);
    final path = _path(request);

    if (path == '/me') {
      return _json({
        'data': {
          'id': 'u-1',
          'name': 'Ines Varga',
          'email': 'ines@example.com',
          'has_avatar': false,
          'role': {'id': 'role-1', 'name': 'Office'},
          'permissions': [
            'customer_experience.view',
            'reviews.view',
            if (view) 'customer_experience.complaints.view',
            if (manage) 'customer_experience.complaints.manage',
          ],
          'modules': [
            {
              'key': 'customer_experience',
              'label': 'Customer Experience',
              'available': true,
              'reachable': true,
            },
            {
              'key': 'reviews',
              'label': 'Reviews',
              'parent': 'customer_experience',
              'available': true,
              'reachable': true,
            },
          ],
          'preferences': <String, dynamic>{},
          'organization': {
            'id': 'org-1',
            'name': 'Lanternfield',
            'franchise_label': 'Franchise',
            'is_read_only': false,
          },
          'scope': {
            'type': 'organization',
            'id': 'org-1',
            'label': 'Lanternfield',
            'path': ['Lanternfield'],
          },
          'companies': <Object>[],
          'franchises': [
            {'id': 'fr-1', 'name': 'Elm Ridge'},
            {'id': 'fr-2', 'name': 'Copper Hollow'},
          ],
        },
      });
    }

    if (path == '/complaints') {
      final rows = empty ? <Map<String, dynamic>>[] : store;
      return _json({
        'data': rows,
        'meta': {
          'total': total ?? rows.length,
          'per_page': 50,
          'current_page':
              int.tryParse(request.url.queryParameters['page'] ?? '') ?? 1,
          'last_page': 1,
          'counts': empty
              ? {
                  'new': 0,
                  'in_progress': 0,
                  'solved': 0,
                  'not_solvable': 0,
                  'open': 0,
                  'all': 0,
                }
              : {
                  'new': 3,
                  'in_progress': 2,
                  'solved': 7,
                  'not_solvable': 1,
                  'open': 5,
                  'all': 13,
                },
          'people': manage
              ? [
                  {'id': 'u-7', 'name': 'Wren Ashby'},
                  {'id': 'u-8', 'name': 'Odile Brand'},
                ]
              : <Object>[],
        },
      });
    }

    final one = RegExp(r'^/complaints/([^/]+)(/notes)?$').firstMatch(path);
    if (one != null) {
      final row = store.firstWhere((r) => r['id'] == one.group(1));
      if (request.method == 'PATCH') {
        final change = jsonDecode(request.body) as Map<String, dynamic>;
        if (change['status'] != null) row['status'] = change['status'];
        if (change.containsKey('owner_user_id')) {
          row['owner'] = change['owner_user_id'] == null
              ? null
              : {'id': change['owner_user_id'], 'name': 'Wren Ashby'};
        }
      }
      return _json({
        'data': {
          ...row,
          'notes': [
            if (request.method == 'POST')
              {
                'id': 'n-1',
                'at': '2026-10-20T12:00:00Z',
                'by': 'Ines Varga',
                'text': (jsonDecode(request.body) as Map)['text'],
              },
          ],
        },
      });
    }

    // Reviews, opened by the container: an empty page renders.
    return _json({
      'data': <dynamic>[],
      'meta': {'total': 0, 'connections': <dynamic>[]},
    });
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
