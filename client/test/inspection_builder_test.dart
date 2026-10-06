import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/inspection/builder_tab.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Builder: screens | the chosen screen's questions | live phone, side by side
/// wide (UI queue 56); on a phone the screens are a list and a screen its page.
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

  // Was "three panes stack on 375". Phone option 4 replaced the stack: the
  // screens are a list, and a screen opens as its own page.
  testWidgets('on 375 the screens are a list and a screen opens as a page', (
    tester,
  ) async {
    await _pump(tester, size: const Size(375, 900));

    expect(find.byKey(const Key('inspection-builder-list')), findsNothing);
    expect(find.text('Crown'), findsOneWidget);
    expect(find.text('Client'), findsNothing);

    await tester.tap(find.text('Crown'));
    await tester.pumpAndSettle();

    expect(find.text('Client'), findsOneWidget);
    expect(find.byTooltip('Back to the screens'), findsOneWidget);
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

    // In Settings now, out of the way of the questions.
    await tester.tap(find.byKey(const Key('inspection-settings')));
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
    final patches = <Map<String, dynamic>>[];
    await _pump(
      tester,
      size: const Size(1400, 900),
      list: _choiceList,
      onRequest: (request) {
        if (request.method != 'PATCH') return null;
        patches.add(jsonDecode(request.body) as Map<String, dynamic>);
        return http.Response('{"data": {}}', 200, headers: _json);
      },
    );

    // Opened where it stands, its answers one row each (UI queue 56).
    await _open(tester, 'Flue');

    final clay = find.widgetWithText(TextField, 'Clay');
    expect(clay, findsOneWidget,
        reason: 'the options already on the field should be in their rows');
    expect(find.widgetWithText(TextField, 'Metal'), findsOneWidget);

    // Typed with its whitespace; stored without it. Read back from what was
    // SENT, the only place the stored list is observable from here.
    await tester.enterText(clay, '  Brick  ');
    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();

    final options = ((patches.last['screens'] as List).first['fields'] as List)
        .first['options'] as List;
    expect([for (final o in options) (o as Map)['value']], ['Brick', 'Metal']);
    expect([for (final o in options) (o as Map)['label']], ['Brick', 'Metal']);
    expect(find.text('Brick'), findsWidgets,
        reason: 'the phone offers the new answer');
  });

  /// Every part of a ported inspection has to be editable here.
  ///
  /// The A1 form is 2,818 lines of TypeScript precisely because nobody could
  /// edit it. If the Builder cannot express a condition, a print mode or an
  /// alert, the port is a second hardcoded form in a different language.
  /// Every part of a ported inspection has to be editable here.
  ///
  /// The A1 form is 2,818 lines of TypeScript precisely because nobody could
  /// edit it. If the Builder cannot express a condition or a print mode, the
  /// port is a second hardcoded form in a different language.
  testWidgets('a field can be given a condition and a print mode', (tester) async {
    await _pump(tester, size: const Size(1400, 2400), list: _twoFieldList);
    await _open(tester, 'Flue');

    // No condition yet, said in words, with the way to add one.
    expect(find.byKey(const Key('cond-field')), findsNothing);
    expect(find.text('Always asked'), findsOneWidget);

    await tester.tap(find.byKey(const Key('cond-start')));
    await tester.pumpAndSettle();

    // The condition points at the OTHER field. Reaching across is the whole
    // point — answering on screen one is what removes questions from screen
    // four — so the picker is not limited to this question.
    final picker = tester.widget<SearchPickerField<String>>(
      find.byKey(const Key('cond-field')),
    );
    expect(picker.value, 'notes');

    // And how it prints, which defaults to a plain line so no existing report
    // is restyled.
    expect(
      tester.widget<ChoiceChip>(find.byKey(const ValueKey('print-text'))).selected,
      isTrue,
    );
  });

  /// With nothing else to point at, the editor says so rather than offering an
  /// empty dropdown that writes a condition naming no field.
  testWidgets('a lone field is told there is nothing to condition on', (tester) async {
    await _pump(tester, size: const Size(1400, 2400), list: _choiceList);
    await _open(tester, 'Flue');

    expect(find.text('Always asked. Add another question first.'), findsOneWidget);
    expect(find.byKey(const Key('cond-field')), findsNothing);
    expect(find.byKey(const Key('cond-start')), findsNothing);
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

    await tester.tap(find.byKey(const Key('inspection-settings')));
    await tester.pumpAndSettle();

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

  /// Where an inspection belongs is chosen here, not in a config file.
  ///
  /// The organization is a real option rather than the absence of one:
  /// "belongs to nobody" and "belongs to everybody" read identically on screen
  /// otherwise, and the second is what a null scope actually means.
  testWidgets('the scope picker offers the organization and the places you reach',
      (tester) async {
    await _pump(tester, size: const Size(1400, 900));

    await tester.tap(find.byKey(const Key('inspection-settings')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('inspection-scope-picker')), findsOneWidget);

    // Opened, because the options are what matter and the closed control shows
    // only the selection.
    await tester.tap(find.byKey(const Key('inspection-scope-picker')));
    await tester.pumpAndSettle();

    expect(find.text('Whole organization'), findsWidgets);

    // The company and its franchise are BOTH called "A1Chimney" — the ordinary
    // case, not a contrived one — which is why the dropdown keys on type:id and
    // never on the label. Two entries, one name.
    expect(find.text('A1Chimney'), findsNWidgets(2));
  });

}

/// Open a question where it stands, by tapping its card — not its twin on the
/// phone beside it, which shows the same words.
Future<void> _open(WidgetTester tester, String label) async {
  await tester.tap(
    find.descendant(
      of: find.byKey(const Key('inspection-builder-fields')),
      matching: find.text(label),
    ),
  );
  await tester.pumpAndSettle();
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

const _twoFieldList = '''
{
  "data": [
    {
      "id": "insp-1",
      "name": "Level 1",
      "scope": {"type": null, "id": null},
      "layout_preset": "step_strip",
      "published": false,
      "screens": [
        {"name": "System", "fields": [
          {"key": "flue", "label": "Flue", "type": "choice", "options": ["Clay", "Metal"]},
          {"key": "notes", "label": "Notes", "type": "text"}
        ]}
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
    ],
    "scopes": [
      {"type": null, "id": null, "label": "Whole organization"},
      {"type": "company", "id": "c-1", "label": "A1Chimney"},
      {"type": "franchise", "id": "f-1", "label": "A1Chimney"}
    ]
  }
}
''';
