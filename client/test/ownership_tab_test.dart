import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/screens/monitoring/ownership_tab.dart';
import 'package:ezbizi_client/widgets/list_table.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Monitoring's ownership chart.
///
/// The load-bearing test is the one about a WITHHELD column. The endpoint
/// omits `computer_name` and `schedule` for a viewer who may not see them, and
/// the table builds its header from the rows — so a bug that drew the column
/// anyway would show every person with a blank where their hours go, which
/// reads as "nobody has hours set" rather than "you may not see this".
void main() {
  /// A signed-in session holding exactly the given keys.
  Future<Session> sessionWith(ApiClient api, List<String> permissions) async {
    final session = Session(api);
    await session.adopt('tok');
    return session;
  }

  /// [tag] gives each pump a DISTINCT key, and it is load-bearing.
  ///
  /// `pumpWidget` reuses the element when the widget type matches, so a second
  /// pump of another OwnershipTab keeps the first one's State — `initState`
  /// never re-runs, the old rows stay, and a test comparing two permission
  /// fixtures silently reads the first one's tree for both. That is how the
  /// three-case test below passed its first assertion and failed its second
  /// against a table it thought it had replaced.
  Widget wrap(ApiClient api, Session session, {String tag = 'one'}) =>
      MaterialApp(
        home: Scaffold(
          body: OwnershipTab(key: ValueKey(tag), api: api, session: session),
        ),
      );

  /// Renames sent to the server, so a test can assert the ONE call rather
  /// than only what the cell drew afterwards.
  final renames = <String>[];

  /// Seatings sent to the server, and the desks the picker is offered.
  final seatings = <String>[];
  var freeDesks = <Map<String, dynamic>>[];

  ApiClient apiReturning(
    List<Map<String, dynamic>> rows, {
    List<String> permissions = const ['monitoring.view', 'monitoring.general.view'],
  }) =>
      ApiClient(
        client: MockClient((request) async {
          final path = request.url.path;

          if (path.endsWith('/me')) {
            return http.Response(
              jsonEncode({
                'data': {
                  'id': 'me',
                  'name': 'Viewer',
                  'email': 'v@e.test',
                  'permissions': permissions,
                  'modules': <dynamic>[],
                  'preferences': <String, dynamic>{},
                  'organization': {
                    'id': 'org-1',
                    'name': 'Everdoors',
                    'franchise_label': 'Franchise',
                    'is_read_only': false,
                  },
                  'companies': <dynamic>[],
                  'franchises': <dynamic>[],
                },
              }),
              200,
              headers: {'content-type': 'application/json'},
            );
          }

          if (path.contains('/nodes/') && path.endsWith('/label')) {
            renames.add('${request.method} $path ${request.body}');
            return http.Response(
              jsonEncode({'data': {'label': 'Reception'}}),
              200,
              headers: {'content-type': 'application/json'},
            );
          }

          if (path.endsWith('/monitoring/ownership/desks')) {
            return http.Response(
              jsonEncode({'data': freeDesks}),
              200,
              headers: {'content-type': 'application/json'},
            );
          }

          if (path.contains('/nodes/') && path.endsWith('/user')) {
            seatings.add('$path ${request.body}');
            return http.Response(
              jsonEncode({'data': {'user_id': 'u-1'}}),
              200,
              headers: {'content-type': 'application/json'},
            );
          }

          if (path.endsWith('/monitoring/ownership')) {
            return http.Response(
              jsonEncode({'data': rows}),
              200,
              headers: {'content-type': 'application/json'},
            );
          }
          return http.Response('{"message":"unstubbed"}', 500);
        }),
      );

  Map<String, dynamic> person({
    String name = 'Marco Reyes',
    bool online = true,
    String? computerName,
    String? workDays,
    String? shift,
    String? planId,
    String? nodeId,
  }) =>
      {
        'user': {'id': 'u-1', 'name': name},
        'role': 'Dispatcher',
        'desk': 'Front desk',
        'office': 'Main Office',
        'online': online,
        'assignment': 'Boston',
        'computer_name': ?computerName,
        'work_days': ?workDays,
        'shift': ?shift,
        'plan_id': ?planId,
        'node_id': ?nodeId,
      };

  testWidgets('a column the server withheld is not drawn at all',
      (tester) async {
    tester.view.physicalSize = const Size(1600, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    // No `computer_name`, no `schedule` — this viewer holds neither grant.
    final api = apiReturning([person()]);
    await tester.pumpWidget(wrap(api, await sessionWith(api, const [])));
    await tester.pumpAndSettle();

    expect(find.text('PC NAME'), findsNothing);
    expect(find.text('WORK DAYS'), findsNothing);
    expect(find.text('SHIFT'), findsNothing);

    // Control: the columns this viewer MAY see are drawn, so the absences
    // above are the gate rather than a table that rendered nothing.
    expect(find.text('USER'), findsOneWidget);
    expect(find.text('ASSIGNMENT'), findsOneWidget);
    expect(find.text('Marco Reyes'), findsOneWidget);
  });

  testWidgets('the same columns appear when the server does send them',
      (tester) async {
    tester.view.physicalSize = const Size(1600, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    // The positive control for the test above, and the reason it means
    // anything: the table CAN draw these.
    final api = apiReturning([
      person(workDays: 'Mon – Fri', shift: '8:00 AM – 5:00 PM', computerName: 'TOULA'),
    ]);
    await tester.pumpWidget(wrap(api, await sessionWith(api, const [])));
    await tester.pumpAndSettle();

    expect(find.text('PC NAME'), findsOneWidget);
    expect(find.text('WORK DAYS'), findsOneWidget);
    expect(find.text('SHIFT'), findsOneWidget);
    expect(find.text('TOULA'), findsOneWidget);

    // Two short facts rather than the raw week. The chart used to print the
    // whole map per row, in two different shapes depending on the source.
    expect(find.text('Mon – Fri'), findsOneWidget);
    expect(find.text('8:00 AM – 5:00 PM'), findsOneWidget);
  });

  testWidgets('online is a word and a shape, never a colour alone',
      (tester) async {
    tester.view.physicalSize = const Size(1600, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = apiReturning([
      person(name: 'On Person'),
      person(name: 'Off Person', online: false),
    ]);
    await tester.pumpWidget(wrap(api, await sessionWith(api, const [])));
    await tester.pumpAndSettle();

    // Same language as the roster and the map: a bolt, a struck-through bolt,
    // and the word beside it — so a screenshot pasted into a ticket loses
    // nothing, and neither does anybody who cannot tell this green from this
    // grey.
    expect(find.text('Online'), findsOneWidget);
    expect(find.text('Offline'), findsOneWidget);
    expect(find.byIcon(Icons.flash_on), findsOneWidget);
    expect(find.byIcon(Icons.flash_off), findsOneWidget);
  });

  testWidgets('a missing value reads as a dash, not as an empty cell',
      (tester) async {
    tester.view.physicalSize = const Size(1600, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    // Somebody with no desk and no assignment — a real state, and the one the
    // chart exists to make visible. A blank cell reads as a rendering fault.
    final api = apiReturning([
      {
        'user': {'id': 'u-2', 'name': 'Unplaced Person'},
        'role': null,
        'desk': null,
        'office': null,
        'online': false,
        'assignment': null,
      },
    ]);
    await tester.pumpWidget(wrap(api, await sessionWith(api, const [])));
    await tester.pumpAndSettle();

    expect(find.text('Unplaced Person'), findsOneWidget);
    expect(find.text('—'), findsNWidgets(4));
  });

  testWidgets('an empty chart says so rather than showing bare headings',
      (tester) async {
    final api = apiReturning(const []);
    await tester.pumpWidget(wrap(api, await sessionWith(api, const [])));
    await tester.pumpAndSettle();

    expect(find.text(OwnershipTab.emptyNote), findsOneWidget);
  });

  testWidgets('the desk is editable only with the map grant AND a real seat',
      (tester) async {
    tester.view.physicalSize = const Size(1600, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    // Holds the edit grant, and the row names a plan and a node.
    final api = apiReturning(
      [person(planId: 'p-1', nodeId: 'n1')],
      permissions: const [
        'monitoring.view', 'monitoring.general.view', 'monitoring.map.manage',
      ],
    );
    await tester.pumpWidget(wrap(api, await sessionWith(api, const [])));
    await tester.pumpAndSettle();

    expect(find.byIcon(Icons.edit_outlined), findsOneWidget);

    // Same grant, but this person sits on no plan — so there is no desk to
    // rename and no pencil. An editable-looking cell that refuses on save is
    // worse than one that never offered.
    final seatless = apiReturning(
      [person()],
      permissions: const [
        'monitoring.view', 'monitoring.general.view', 'monitoring.map.manage',
      ],
    );
    await tester.pumpWidget(
      wrap(seatless, await sessionWith(seatless, const []), tag: 'seatless'));
    await tester.pumpAndSettle();

    expect(find.byIcon(Icons.edit_outlined), findsNothing);

    // And a full seat WITHOUT the grant: still nothing. The two conditions
    // are tested apart so neither can be carrying the other.
    final noGrant = apiReturning([person(planId: 'p-1', nodeId: 'n1')]);
    await tester.pumpWidget(
      wrap(noGrant, await sessionWith(noGrant, const []), tag: 'no-grant'));
    await tester.pumpAndSettle();

    expect(find.byIcon(Icons.edit_outlined), findsNothing);
  });

  testWidgets('renaming a desk sends the label and shows the new name',
      (tester) async {
    tester.view.physicalSize = const Size(1600, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    renames.clear();

    final api = apiReturning(
      [person(planId: 'p-1', nodeId: 'n1')],
      permissions: const [
        'monitoring.view', 'monitoring.general.view', 'monitoring.map.manage',
      ],
    );
    await tester.pumpWidget(wrap(api, await sessionWith(api, const [])));
    await tester.pumpAndSettle();

    await tester.tap(find.byIcon(Icons.edit_outlined));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField).last, 'Reception');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    // The CALL, not just the cell: a rename that only repainted locally would
    // look identical here and be gone on the next refresh.
    expect(renames, hasLength(1));
    expect(renames.single, contains('/monitoring/floor-plans/p-1/nodes/n1/label'));
    expect(renames.single, contains('Reception'));

    expect(find.text('Reception'), findsOneWidget);
    expect(find.text('Front desk'), findsNothing);
  });

  testWidgets('a person with NO desk can be given one, which was the gap',
      (tester) async {
    // Jose, 2026-09-23: "you forgot that there are empty fields, and thus no
    // edit button for them." Renaming shipped first and reached only people
    // who already had a desk — so the column that exists to show who is
    // unplaced offered nothing on exactly those rows.
    tester.view.physicalSize = const Size(1600, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    seatings.clear();
    freeDesks = [
      {'plan_id': 'p-1', 'node_id': 'n9', 'label': 'Computer 9', 'office': 'Beacon 511'},
    ];

    final api = apiReturning(
      [{
        'user': {'id': 'u-1', 'name': 'Unplaced Person'},
        'role': 'Dispatcher',
        'desk': null,
        'office': null,
        'online': false,
        'assignment': 'A-1 Chimney',
      }],
      permissions: const [
        'monitoring.view', 'monitoring.general.view', 'monitoring.map.manage',
      ],
    );
    await tester.pumpWidget(wrap(api, await sessionWith(api, const [])));
    await tester.pumpAndSettle();

    // The affordance exists on an EMPTY cell — the thing that was missing.
    expect(find.byKey(const Key('desk-seat-picker')), findsOneWidget);

    await tester.tap(find.byKey(const Key('desk-seat-picker')));
    await tester.pumpAndSettle();

    // The office is named beside the desk: two plans may each have a
    // "Computer 1", and a menu of identical names cannot be chosen from.
    expect(find.text('Computer 9 · Beacon 511'), findsOneWidget);

    await tester.tap(find.text('Computer 9 · Beacon 511'));
    await tester.pumpAndSettle();

    // The CALL, not just the menu closing.
    expect(seatings, hasLength(1));
    expect(seatings.single, contains('/monitoring/floor-plans/p-1/nodes/n9/user'));
    expect(seatings.single, contains('u-1'));
  });

  testWidgets('with every desk taken, the empty cell says so rather than '
      'opening an empty menu', (tester) async {
    tester.view.physicalSize = const Size(1600, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    freeDesks = [];

    final api = apiReturning(
      [{
        'user': {'id': 'u-1', 'name': 'Unplaced Person'},
        'role': 'Dispatcher',
        'desk': null,
        'office': null,
        'online': false,
        'assignment': 'A-1 Chimney',
      }],
      permissions: const [
        'monitoring.view', 'monitoring.general.view', 'monitoring.map.manage',
      ],
    );
    await tester.pumpWidget(
      wrap(api, await sessionWith(api, const []), tag: 'no-free'));
    await tester.pumpAndSettle();

    // No picker, because there is nothing to pick. An empty menu is a control
    // that looks like it works and cannot.
    expect(find.byKey(const Key('desk-seat-picker')), findsNothing);
    expect(find.byIcon(Icons.info_outline), findsOneWidget);
  });

  testWidgets(
      'on a phone the chart is a list — a name line and a detail line — and '
      'nothing runs off the right edge', (tester) async {
    // The UI/UX Android audit, 2026-09-28: "General's ownership table is a
    // desktop table on a phone: User / Role / Desk… cut off at the right
    // edge." A DataTable in a sideways scroll LOOKS cut off, because it is.
    tester.view.physicalSize = const Size(375, 812);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    // An overflow paints stripes and passes — it never fails a test on its
    // own — so it is caught here and asserted on.
    final overflows = <String>[];
    final previous = FlutterError.onError;
    FlutterError.onError = (details) {
      final text = details.exceptionAsString();
      if (text.contains('overflowed')) overflows.add(text);
      previous?.call(details);
    };
    addTearDown(() => FlutterError.onError = previous);

    // The widest row the chart can have: every withheld column sent, and a
    // real seat, so the desk is the editable control and not a dash.
    final api = apiReturning(
      [
        person(
          computerName: 'A1DISPATCHER1',
          workDays: 'Mon – Fri',
          shift: '8:00 AM – 5:00 PM',
          planId: 'plan-1',
          nodeId: 'node-1',
        ),
      ],
      permissions: const [
        'monitoring.view',
        'monitoring.general.view',
        'monitoring.map.manage',
      ],
    );
    await tester.pumpWidget(wrap(api, await sessionWith(api, const [])));
    await tester.pumpAndSettle();

    expect(find.byType(ListTable), findsNothing,
        reason: 'Below ${OwnershipTab.narrowBelow.toInt()} the chart is a list.');

    // The name line.
    expect(find.text('Marco Reyes'), findsOneWidget);
    expect(find.text('Online'), findsOneWidget,
        reason: 'Status stays a word beside the name, not only a colour.');

    // The detail line carries what the columns carried.
    final detail = find.text(
      'Dispatcher · A1DISPATCHER1 · Mon – Fri, 8:00 AM – 5:00 PM · Boston · Main Office',
    );
    expect(detail, findsOneWidget);

    // The desk is the SAME control as on the desktop, not a read-only copy —
    // a phone that could see a desk but not rename or fill it would be a
    // quieter version of the gap Jose already reported once.
    expect(find.byKey(const ValueKey('desk-node-1')), findsOneWidget);
    expect(find.text('Front desk'), findsOneWidget);

    for (final text in [find.text('Marco Reyes'), detail, find.text('Online')]) {
      expect(tester.getRect(text).right, lessThanOrEqualTo(375),
          reason: 'Something on the row still runs past the phone edge.');
    }
    expect(overflows, isEmpty, reason: overflows.join(' | '));
  });

  testWidgets(
      'the desktop table scrolls vertically when the rows run past the viewport',
      (tester) async {
    // Jose, 2026-10-01: "you also cant scroll down on the general tab." The
    // table sits in a bounded height and a DataTable is as tall as its rows, so
    // without a vertical scroll the lower rows were clipped and unreachable.
    // A SHORT viewport with many rows is the only way to reproduce it — a tall
    // one has nothing below the fold and would pass either way.
    tester.view.physicalSize = const Size(1600, 320);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final many = [
      for (var i = 0; i < 25; i++)
        person(name: 'Person ${i.toString().padLeft(2, '0')}'),
    ];
    final api = apiReturning(many);
    await tester.pumpWidget(wrap(api, await sessionWith(api, const [])));
    await tester.pumpAndSettle();

    // The vertical scroll view the table now sits in (the inner one is
    // horizontal, for the columns).
    final vertical = find.byWidgetPredicate(
      (w) => w is SingleChildScrollView && w.scrollDirection == Axis.vertical,
    );
    expect(vertical, findsOneWidget,
        reason: 'The table must have a vertical scroll to reach lower rows.');

    final position = tester.state<ScrollableState>(
      find.descendant(of: vertical, matching: find.byType(Scrollable)).first,
    ).position;

    expect(position.maxScrollExtent, greaterThan(0),
        reason: '25 rows in a 320px viewport must leave something to scroll to.');

    // And it actually scrolls — a late row is reachable after dragging down.
    await tester.drag(vertical, const Offset(0, -600));
    await tester.pumpAndSettle();
    expect(position.pixels, greaterThan(0),
        reason: 'The table scrolled, so the rows past the fold are reachable.');
  });

  testWidgets('control: a wide screen still gets the table', (tester) async {
    // Without this, the test above passes just as well for a chart that
    // became a list at EVERY width.
    tester.view.physicalSize = const Size(1600, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = apiReturning([person()]);
    await tester.pumpWidget(wrap(api, await sessionWith(api, const [])));
    await tester.pumpAndSettle();

    expect(find.byType(ListTable), findsOneWidget);
    expect(find.text('ROLE'), findsOneWidget);
  });
}
