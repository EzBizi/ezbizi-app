import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/technician_links.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Settings › Schedule (Jose, 2026-09-25): Workiz technicians linked to their
/// EzBizi user.
void main() {
  testWidgets('lists technicians, their link and the email guess', (
    tester,
  ) async {
    await _pump(tester);

    expect(find.text('Ivan Sharkov'), findsOneWidget);
    expect(find.text('Harry Jenkins'), findsOneWidget);
    expect(find.text('1 of 2 linked to an EzBizi user.'), findsOneWidget);
    expect(find.text('Matched by email'), findsOneWidget);
  });

  testWidgets('choosing a user saves the link', (tester) async {
    final puts = <String, Object?>{};
    await _pump(tester, puts: puts);

    // Harry is second; his picker is the second field.
    await tester.tap(find.byType(SearchPickerField<String>).at(1));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Dana Diaz').last);
    await tester.pumpAndSettle();

    expect(puts, {'/api/v1/scheduling/technicians/m-2': 'u-2'});
    expect(find.text('2 of 2 linked to an EzBizi user.'), findsOneWidget);
  });

  testWidgets('"Unlinked only" hides the linked', (tester) async {
    await _pump(tester);

    await tester.tap(find.text('Unlinked only'));
    await tester.pumpAndSettle();

    expect(find.text('Harry Jenkins'), findsOneWidget);
    expect(find.text('Ivan Sharkov'), findsNothing);
  });

  testWidgets('a phone-width screen draws without overflowing', (
    tester,
  ) async {
    final errors = <FlutterErrorDetails>[];
    final previous = FlutterError.onError;
    FlutterError.onError = errors.add;
    addTearDown(() => FlutterError.onError = previous);

    await _pump(tester, width: 375);

    expect(find.text('Ivan Sharkov'), findsOneWidget);
    expect(
      errors,
      isEmpty,
      reason: errors.map((e) => e.exceptionAsString()).join(' | '),
    );
  });
}

Future<void> _pump(
  WidgetTester tester, {
  Map<String, Object?>? puts,
  double width = 1200,
}) async {
  tester.view.physicalSize = Size(width, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.method == 'PUT') {
        puts?[request.url.path] = jsonDecode(request.body)['user_id'];
        return _json({'data': {}});
      }
      if (request.url.path.endsWith('/scheduling/technicians')) {
        return _json({'data': _members(), 'users': _users});
      }
      return _json({'data': const []});
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: TechnicianLinks(api: api, visible: true)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

// A fresh copy per call: the screen updates rows in place.
List<Map<String, dynamic>> _members() => [
  {
    'id': 'm-1',
    'name': 'Ivan Sharkov',
    'email': 'ivan@example.test',
    'role': 'Technician',
    'is_field_tech': true,
    'is_active': true,
    'franchise_name': 'Connecticut',
    'user': {'id': 'u-1', 'name': 'Ivan S', 'email': 'ivan@example.test'},
    'auto_matched': true,
  },
  {
    'id': 'm-2',
    'name': 'Harry Jenkins',
    'email': 'harry@example.test',
    'role': 'Technician',
    'is_field_tech': true,
    'is_active': true,
    'franchise_name': 'Connecticut',
    'user': null,
    'auto_matched': false,
  },
];

const _users = [
  {'id': 'u-1', 'name': 'Ivan S', 'email': 'ivan@example.test'},
  {'id': 'u-2', 'name': 'Dana Diaz', 'email': 'dana@example.test'},
];
