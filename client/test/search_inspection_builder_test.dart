import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/inspection/builder_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Inspection › Builder: the list of inspections (opened from the name in
/// the bar) is searchable however few inspections there are.
void main() {
  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1400, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    const json = {'content-type': 'application/json'};
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/inspections/catalogue')) {
          return http.Response(
            '{"data":{"field_types":[],"scopes":[],"codes":[],"bindable":[]}}',
            200,
            headers: json,
          );
        }
        if (path.endsWith('/inspections')) {
          return http.Response(_list, 200, headers: json);
        }
        return http.Response('{"message":"not stubbed"}', 500, headers: json);
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: BuilderTab(api: api, session: Session(api)),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('inspection-picker')));
    await tester.pumpAndSettle();
  }

  Finder inDialog(Finder f) =>
      find.descendant(of: find.byType(AlertDialog), matching: f);

  testWidgets('two inspections already offer the search, and typing narrows '
      'them', (tester) async {
    await pump(tester);
    expect(inDialog(find.text('Chimney level 1')), findsOneWidget);
    expect(inDialog(find.text('Dryer vent')), findsOneWidget);

    await tester.enterText(inDialog(find.byType(TextField)), 'dryer');
    await tester.pumpAndSettle();

    expect(inDialog(find.text('Dryer vent')), findsOneWidget);
    expect(inDialog(find.text('Chimney level 1')), findsNothing);
  });

  testWidgets('a term matching nothing says so in one sentence', (
    tester,
  ) async {
    await pump(tester);

    await tester.enterText(inDialog(find.byType(TextField)), 'zebra');
    await tester.pumpAndSettle();

    expect(inDialog(find.text('Nothing matches that.')), findsOneWidget);
    expect(inDialog(find.text('Chimney level 1')), findsNothing);
    expect(inDialog(find.text('Dryer vent')), findsNothing);
  });
}

const _list = '''
{
  "data": [
    {
      "id": "insp-1",
      "name": "Chimney level 1",
      "layout_preset": "step_strip",
      "published": true,
      "current_version": 1,
      "screens": [{"name": "Crown", "fields": []}]
    },
    {
      "id": "insp-2",
      "name": "Dryer vent",
      "layout_preset": "step_strip",
      "published": false,
      "current_version": null,
      "screens": [{"name": "Vent", "fields": []}]
    }
  ]
}
''';
