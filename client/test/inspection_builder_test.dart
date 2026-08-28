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

  testWidgets('a published inspection is unpublished, not deleted', (tester) async {
    await _pump(tester, size: const Size(1400, 900));

    await tester.drag(
      find.byKey(const Key('inspection-builder-fields')),
      const Offset(0, -600),
    );
    await tester.pumpAndSettle();

    expect(find.text('Unpublish'), findsOneWidget);
    expect(find.text('Delete draft'), findsNothing);
  });

  /// The list a `choice` offers has to be enterable somewhere.
  ///
  /// It was not. The type was pickable and its options were not, so a company
  /// could ask "one of a list" and never say which list — which stayed invisible
  /// only because the technician's screen drew every field as a text box.
  testWidgets('a choice field can be given its choices', (tester) async {
    await _pump(tester, size: const Size(1400, 900), list: _choiceList);

    expect(find.byKey(const Key('field-options')), findsOneWidget);

    final box = tester.widget<TextField>(find.byKey(const Key('field-options')));
    expect(box.controller?.text, 'Clay\nMetal',
        reason: 'the options already on the field should be in the box');

    // Read back through the phone preview, which samples `options.first`.
    // That is the only place the parsed list is observable from here, and it
    // proves the two things worth proving: the box writes to the field, and a
    // pasted list arrives trimmed rather than with its whitespace intact.
    await tester.enterText(
      find.byKey(const Key('field-options')),
      '  Brick  \n\nClay\n',
    );
    await tester.pumpAndSettle();

    expect(find.text('Brick'), findsWidgets,
        reason: 'the first choice, trimmed, should be the sample answer');
    expect(find.text('  Brick  '), findsNothing);
  });

  testWidgets('a never-published draft can be deleted', (tester) async {
    final methods = <String>[];

    await _pump(
      tester,
      size: const Size(1400, 900),
      list: _draftList,
      onRequest: (request) {
        methods.add('${request.method} ${request.url.path}');
        if (request.method == 'DELETE' &&
            request.url.path.endsWith('/inspections/insp-draft')) {
          return http.Response('{"message":"Draft deleted."}', 200, headers: _json);
        }
        return null;
      },
    );

    expect(find.text('Delete draft'), findsOneWidget);
    expect(find.text('Unpublish'), findsNothing);

    await tester.ensureVisible(find.text('Delete draft'));
    await tester.tap(find.text('Delete draft'));
    await tester.pumpAndSettle();

    expect(
      methods.any((m) => m == 'DELETE /api/v1/inspections/insp-draft'),
      isTrue,
    );
    expect(find.text('Untitled inspection'), findsNothing);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required Size size,
  String list = _list,
  http.Response? Function(http.Request request)? onRequest,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final override = onRequest?.call(request);
      if (override != null) return override;

      final path = request.url.path;
      if (path.endsWith('/inspections/catalogue')) {
        return http.Response(_catalogue, 200, headers: _json);
      }
      if (path.endsWith('/inspections')) {
        return http.Response(list, 200, headers: _json);
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

const _choiceList = '''
{
  "data": [
    {
      "id": "insp-1",
      "name": "Level 1",
      "intro": null,
      "layout_preset": "step_strip",
      "published": false,
      "current_version": null,
      "screens": [
        {
          "name": "Crown",
          "fields": [
            {
              "key": "flue",
              "label": "Flue",
              "type": "choice",
              "options": ["Clay", "Metal"]
            }
          ]
        }
      ]
    }
  ]
}
''';

const _draftList = '''
{
  "data": [
    {
      "id": "insp-draft",
      "name": "Untitled inspection",
      "intro": null,
      "layout_preset": "step_strip",
      "published": false,
      "current_version": null,
      "screens": []
    }
  ]
}
''';

const _catalogue = '''
{
  "data": {
    "field_types": [
      {"key": "text", "label": "Text", "takes_options": false, "allows_multiple": false},
      {"key": "choice", "label": "One of a list", "takes_options": true, "allows_multiple": true},
      {"key": "photo", "label": "A photograph", "takes_options": false, "allows_multiple": false}
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
