import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/hr/work_hours_reports_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Work Hours › Reports' editor picks people with the house search picker
/// (Jose, 2026-09-17: a list of people is a search picker), not a bottom
/// sheet: searchable once the list is long, and what is chosen is saved.
void main() {
  testWidgets('a person is added from the search picker and saved', (
    tester,
  ) async {
    Map<String, dynamic>? posted;
    final api = ApiClient(
      client: MockClient((request) async {
        if (request.method == 'POST') {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': posted});
        }
        return _json({
          'data': <dynamic>[],
          'roles': [
            {'id': 'r-1', 'name': 'Dispatcher'},
          ],
          'people': [
            for (var i = 1; i <= 9; i++)
              {'id': 'u-$i', 'name': 'Person $i', 'email': 'p$i@example.com'},
          ],
        });
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: WorkHoursReportsTab(api: api, canManage: true)),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('New report'));
    await tester.pumpAndSettle();

    // "Also include" is the first of the two person pickers.
    await tester.tap(find.text('Add a person').first);
    await tester.pumpAndSettle();

    expect(find.byType(BottomSheet), findsNothing);
    // Nine people: long enough for the picker to offer its search.
    expect(find.byType(TextField), findsWidgets);
    await tester.tap(find.text('Person 7'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(InputChip, 'Person 7'), findsOneWidget);

    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(posted?['include_user_ids'], ['u-7']);
  });
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: const {'content-type': 'application/json'},
);
