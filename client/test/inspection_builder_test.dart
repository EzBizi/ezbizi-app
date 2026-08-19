import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/inspection/builder_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Builder: list | field work | live phone. Stacked at 375, side by side wide.
void main() {
  testWidgets('three panes sit side by side on a wide window', (tester) async {
    await _pump(tester, size: const Size(1400, 900));

    final list = tester.getRect(find.byKey(const Key('inspection-builder-list')));
    final fields = tester.getRect(find.byKey(const Key('inspection-builder-fields')));
    final phone = tester.getRect(find.byKey(const Key('inspection-builder-phone')));

    expect(list.left, lessThan(fields.left));
    expect(fields.left, lessThan(phone.left));
    expect((list.top - fields.top).abs(), lessThan(8));
    expect((fields.top - phone.top).abs(), lessThan(8));
  });

  testWidgets('three panes stack on 375', (tester) async {
    await _pump(tester, size: const Size(375, 2400));

    final list = tester.getRect(find.byKey(const Key('inspection-builder-list')));
    final fields = tester.getRect(find.byKey(const Key('inspection-builder-fields')));
    final phone = tester.getRect(find.byKey(const Key('inspection-builder-phone')));

    expect(list.top, lessThan(fields.top));
    expect(fields.top, lessThan(phone.top));
    expect((list.left - fields.left).abs(), lessThan(8));
    expect(tester.takeException(), isNull);
  });

  testWidgets('the phone uses sample answers, not a live client', (tester) async {
    await _pump(tester, size: const Size(1400, 900));

    expect(find.text('Sample client'), findsWidgets);
    expect(find.text('Jose Silva'), findsNothing);
    expect(find.text('14 Maple'), findsNothing);
    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
  });
}

Future<void> _pump(WidgetTester tester, {required Size size}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/inspections/catalogue')) {
        return http.Response(_catalogue, 200, headers: _json);
      }
      if (path.endsWith('/inspections')) {
        return http.Response(_list, 200, headers: _json);
      }
      return http.Response('{"message":"not stubbed"}', 500, headers: _json);
    }),
  );
  final session = Session(api);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: BuilderTab(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

const _json = {'content-type': 'application/json'};

const _list = '''
{
  "data": [
    {
      "id": "insp-1",
      "name": "Level 1",
      "intro": "Check the crown.",
      "layout_preset": "step_strip",
      "published": true,
      "screens": [
        {
          "name": "Crown",
          "fields": [
            {
              "key": "client",
              "label": "Client",
              "type": "text",
              "bind": "customer.name"
            }
          ]
        }
      ]
    }
  ]
}
''';

const _catalogue = '''
{
  "data": {
    "field_types": [
      {"key": "text", "label": "Text", "takes_options": false, "allows_multiple": false}
    ],
    "layout_presets": [
      {"key": "step_strip", "label": "One screen at a time"},
      {"key": "document", "label": "Screens as headings"},
      {"key": "quiet_stack", "label": "One scrolling column"}
    ],
    "bindable": [
      {"key": "customer.name", "label": "Client name"}
    ]
  }
}
''';
