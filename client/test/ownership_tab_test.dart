import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/screens/monitoring/ownership_tab.dart';
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

    expect(find.text('PC name'), findsNothing);
    expect(find.text('Work days'), findsNothing);
    expect(find.text('Shift'), findsNothing);

    // Control: the columns this viewer MAY see are drawn, so the absences
    // above are the gate rather than a table that rendered nothing.
    expect(find.text('User'), findsOneWidget);
    expect(find.text('Assignment'), findsOneWidget);
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

    expect(find.text('PC name'), findsOneWidget);
    expect(find.text('Work days'), findsOneWidget);
    expect(find.text('Shift'), findsOneWidget);
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

    await tester.enterText(find.byType(TextField), 'Reception');
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
}
