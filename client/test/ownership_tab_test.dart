import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
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
  Widget wrap(ApiClient api) => MaterialApp(
        home: Scaffold(body: OwnershipTab(api: api)),
      );

  ApiClient apiReturning(List<Map<String, dynamic>> rows) => ApiClient(
        client: MockClient((request) async {
          if (request.url.path.endsWith('/monitoring/ownership')) {
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
    Object? schedule,
  }) =>
      {
        'user': {'id': 'u-1', 'name': name},
        'role': 'Dispatcher',
        'desk': 'Front desk',
        'office': 'Main Office',
        'online': online,
        'assignment': 'Boston',
        'computer_name': ?computerName,
        'schedule': ?schedule,
      };

  testWidgets('a column the server withheld is not drawn at all',
      (tester) async {
    tester.view.physicalSize = const Size(1600, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    // No `computer_name`, no `schedule` — this viewer holds neither grant.
    await tester.pumpWidget(wrap(apiReturning([person()])));
    await tester.pumpAndSettle();

    expect(find.text('PC name'), findsNothing);
    expect(find.text('Schedule'), findsNothing);

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
    await tester.pumpWidget(wrap(apiReturning([
      person(computerName: 'TOULA', schedule: 'Mon-Fri 9-5'),
    ])));
    await tester.pumpAndSettle();

    expect(find.text('PC name'), findsOneWidget);
    expect(find.text('Schedule'), findsOneWidget);
    expect(find.text('TOULA'), findsOneWidget);
    expect(find.text('Mon-Fri 9-5'), findsOneWidget);
  });

  testWidgets('online is a word and a shape, never a colour alone',
      (tester) async {
    tester.view.physicalSize = const Size(1600, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(wrap(apiReturning([
      person(name: 'On Person'),
      person(name: 'Off Person', online: false),
    ])));
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
    await tester.pumpWidget(wrap(apiReturning([
      {
        'user': {'id': 'u-2', 'name': 'Unplaced Person'},
        'role': null,
        'desk': null,
        'office': null,
        'online': false,
        'assignment': null,
      },
    ])));
    await tester.pumpAndSettle();

    expect(find.text('Unplaced Person'), findsOneWidget);
    expect(find.text('—'), findsNWidgets(4));
  });

  testWidgets('an empty chart says so rather than showing bare headings',
      (tester) async {
    await tester.pumpWidget(wrap(apiReturning(const [])));
    await tester.pumpAndSettle();

    expect(find.text(OwnershipTab.emptyNote), findsOneWidget);
  });
}
