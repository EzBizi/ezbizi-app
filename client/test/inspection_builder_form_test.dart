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

/// The Builder as the form it builds (UI queue 56; Jose, 2026-10-06, on the
/// one it replaced: "extremely hard to understand and use").
///
/// What these hold to: a question opens where it is, everything is said in
/// words rather than keys, an answer shows and edits what it asks next, a
/// screen can wait for an earlier answer, and work saves itself without ever
/// sending the Builder's own bookkeeping to the server.
void main() {
  testWidgets('a question opens where it stands, not under the list', (
    tester,
  ) async {
    await _pump(tester);
    await _openScreen(tester, 'The system');

    final below = tester.getTopLeft(_card('Smoke chamber')).dy;
    final at = tester.getTopLeft(_card('Firebox')).dy;
    await _open(tester, 'Firebox');

    final editor = find.byKey(const Key('question-label'));
    expect(editor, findsOneWidget);
    // Opened in its own place: above the question that followed it, which is
    // still on screen below it.
    expect(tester.getTopLeft(editor).dy, closeTo(at, 40));
    expect(tester.getTopLeft(_card('Smoke chamber')).dy, greaterThan(below));
    expect(_card('Smoke chamber'), findsOneWidget);
  });

  testWidgets('a type and a condition are said in words, never as keys', (
    tester,
  ) async {
    await _pump(tester);
    await _openScreen(tester, 'The system');

    expect(find.text('One of a list · 3 answers'), findsWidgets);
    expect(
      find.text('Only when System type is Masonry fireplace'),
      findsWidgets,
    );
    expect(
      find.text('Only when System type is Masonry fireplace or Wood stove'),
      findsOneWidget,
    );
    expect(find.textContaining('system_type'), findsNothing);
    expect(find.textContaining('masonry'), findsNothing);
    expect(find.text('Fail asks 3 more'), findsWidgets);
    expect(find.text('Finding'), findsWidgets);
    // A heading is drawn as a heading, not as a question with no answer.
    expect(
      find.textContaining('MASONRY FIREPLACE', findRichText: true),
      findsOneWidget,
    );
  });

  testWidgets(
    'edits save themselves, and nothing of the Builder\'s own is sent',
    (tester) async {
      final server = _Server();
      await _pump(tester, server: server);
      await _open(tester, 'Level');

      await tester.enterText(
        find.byKey(const Key('question-label')),
        'Inspection level',
      );
      await tester.pump();
      expect(find.text('Saving…'), findsOneWidget);
      expect(
        server.patches,
        isEmpty,
        reason: 'it waits for the typing to stop',
      );

      await tester.pump(const Duration(seconds: 1));
      await tester.pumpAndSettle();

      expect(server.patches, hasLength(1));
      final sent = jsonEncode(server.patches.single);
      expect(sent, contains('Inspection level'));
      expect(sent, isNot(contains('_uid')));
      expect(
        find.text('Saved. Technicians keep version 3 until you publish.'),
        findsOneWidget,
      );
    },
  );

  testWidgets('a refused save says why and keeps the work', (tester) async {
    final server = _Server()
      ..refuse = 'Give it at least one choice, or make it a text field.';
    await _pump(tester, server: server);
    await _open(tester, 'Level');

    await tester.enterText(
      find.byKey(const Key('question-label')),
      'Inspection level',
    );
    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();

    expect(
      find.text(
        'Not saved: Give it at least one choice, or make it a text field.',
      ),
      findsOneWidget,
    );
    expect(find.text('Try again'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Inspection level'), findsOneWidget);
  });

  testWidgets(
    'renaming a plain answer renames every condition waiting for it',
    (tester) async {
      final server = _Server();
      await _pump(tester, server: server);
      await _open(tester, 'Flue');

      await tester.enterText(
        find.widgetWithText(TextField, 'Clay'),
        'Clay tile',
      );
      await tester.pump(const Duration(seconds: 1));
      await tester.pumpAndSettle();

      final screens = server.patches.last['screens'] as List;
      final fields = (screens.first as Map)['fields'] as List;
      final flue = fields.firstWhere((f) => f['key'] == 'flue') as Map;
      final liner = fields.firstWhere((f) => f['key'] == 'liner_age') as Map;

      expect(
        (flue['options'] as List).first,
        containsPair('value', 'Clay tile'),
      );
      expect(liner['visible_if'], {
        'field': 'flue',
        'op': 'eq',
        'value': 'Clay tile',
      });
    },
  );

  testWidgets(
    'an answer with a value of its own keeps it when its words change',
    (tester) async {
      final server = _Server();
      await _pump(tester, server: server);
      await _openScreen(tester, 'The system');
      await _open(tester, 'Firebox');

      await tester.enterText(
        find.widgetWithText(TextField, 'Meets industry standards'),
        'Meets the standard',
      );
      await tester.pump(const Duration(seconds: 1));
      await tester.pumpAndSettle();

      final screens = server.patches.last['screens'] as List;
      final firebox =
          ((screens[1] as Map)['fields'] as List).firstWhere(
                (f) => f['key'] == 'firebox',
              )
              as Map;
      final ok = (firebox['options'] as List).first as Map;
      expect(ok['value'], 'ok', reason: 'stored answers and conditions use it');
      expect(ok['label'], 'Meets the standard');
    },
  );

  testWidgets('an answer can be given a follow-up question', (tester) async {
    final server = _Server();
    await _pump(tester, server: server);
    await _open(tester, 'Flue');

    await tester.tap(find.byTooltip('Ask something after “Metal”'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.widgetWithText(TextField, 'What to ask'),
      'Gauge',
    );
    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();

    final screens = server.patches.last['screens'] as List;
    final flue =
        ((screens.first as Map)['fields'] as List).firstWhere(
              (f) => f['key'] == 'flue',
            )
            as Map;
    final metal = (flue['options'] as List)[1] as Map;
    final asked = (metal['fields'] as List).single as Map;
    expect(asked['label'], 'Gauge');
    expect(asked['type'], 'text');
    // A fresh key nobody else holds, never one derived from the words.
    expect(asked['key'], 'question_1');
  });

  testWidgets('the phone reveals what an answer asks next', (tester) async {
    await _pump(tester);
    await _openScreen(tester, 'The system');

    final phone = find.byKey(const Key('live-phone'));
    expect(
      find.descendant(of: phone, matching: find.text('Firebox *')),
      findsNothing,
      reason: 'it waits for the system type',
    );

    await tester.tap(
      find.descendant(of: phone, matching: find.text('Masonry fireplace')),
    );
    await tester.pumpAndSettle();
    expect(
      find.descendant(of: phone, matching: find.text('Firebox *')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: phone, matching: find.text('Photograph *')),
      findsNothing,
    );

    await tester.tap(
      find
          .descendant(
            of: phone,
            matching: find.text('Does not meet industry standards'),
          )
          .first,
    );
    await tester.pumpAndSettle();
    expect(
      find.descendant(of: phone, matching: find.text('Photograph *')),
      findsOneWidget,
    );
  });

  testWidgets('a screen can wait for an answer from a screen before it', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server: server);
    await _openScreen(tester, 'The system');

    await tester.tap(find.byKey(const Key('screen-when-change')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('cond-start')));
    await tester.pumpAndSettle();

    // Only questions asked BEFORE this screen: its own are not answered yet
    // when it is decided whether to show it.
    final picker = tester.widget<SearchPickerField<String>>(
      find.byKey(const Key('cond-field')),
    );
    final offered = [for (final o in picker.options) o.value];
    expect(offered, containsAll(['inspection_level', 'flue']));
    expect(offered, isNot(contains('system_type')));

    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();
    final screens = server.patches.last['screens'] as List;
    expect((screens[1] as Map)['visible_if'], isA<Map>());
    expect(find.textContaining('Only when Level is Level 1'), findsWidgets);
  });

  testWidgets(
    'a question something waits for is not deleted out from under it',
    (tester) async {
      await _pump(tester);

      await tester.tap(find.byTooltip('More for “Flue”'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Delete').last);
      await tester.pumpAndSettle();

      expect(find.textContaining('waits for “Flue”'), findsOneWidget);
      expect(_card('Flue'), findsOneWidget);
    },
  );

  testWidgets(
    'a question nothing waits for is deleted, and Undo brings it back',
    (tester) async {
      await _pump(tester);

      await tester.tap(find.byTooltip('More for “Liner age”'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Delete').last);
      await tester.pumpAndSettle();
      expect(_card('Liner age'), findsNothing);

      await tester.tap(find.text('Undo'));
      await tester.pumpAndSettle();
      expect(_card('Liner age'), findsOneWidget);
    },
  );

  testWidgets(
    'a question waiting for a later screen is flagged as never asked',
    (tester) async {
      await _pump(tester, definition: _laterDefinition);

      expect(
        find.text('Never asked: it waits for a later answer'),
        findsOneWidget,
      );
    },
  );

  testWidgets('Publish saves what was typed first, then publishes', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server: server);
    await _open(tester, 'Level');

    await tester.enterText(
      find.byKey(const Key('question-label')),
      'Inspection level',
    );
    await tester.tap(find.byKey(const Key('inspection-publish')));
    await tester.pumpAndSettle();

    expect(server.calls.where((c) => c != 'GET'), ['PATCH', 'POST publish']);
    expect(find.text('Published · version 4'), findsOneWidget);
    expect(
      find.text('Published. Technicians now get version 4.'),
      findsOneWidget,
    );
  });

  testWidgets('leaving an inspection saves it before the next one opens', (
    tester,
  ) async {
    final server = _Server()..second = true;
    await _pump(tester, server: server);
    await _open(tester, 'Level');

    // Switched well inside the save delay, so only saving ON LEAVING can have
    // sent the edit: a save that waited for its timer would send whichever
    // inspection is open by then.
    await tester.enterText(
      find.byKey(const Key('question-label')),
      'Inspection level',
    );
    await tester.tap(find.byKey(const Key('inspection-picker')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));
    await tester.tap(find.text('Dryer vent').last);
    await tester.pump(const Duration(milliseconds: 200));
    await tester.pumpAndSettle();

    expect(server.patches, hasLength(1));
    expect(jsonEncode(server.patches.single), contains('Inspection level'));
    expect(find.text('Lint trap'), findsWidgets);
  });

  testWidgets(
    'Settings holds the name, who it belongs to and the intro, not the Look',
    (tester) async {
      await _pump(tester);

      await tester.tap(find.byKey(const Key('inspection-settings')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('inspection-name')), findsOneWidget);
      expect(find.byKey(const Key('inspection-intro')), findsOneWidget);
      // Nothing reads the Look: a choice that changed nothing is not offered.
      expect(find.text('One screen at a time'), findsNothing);
    },
  );

  testWidgets(
    'on a phone a question opens in place, its when and report one line each',
    (tester) async {
      await _pump(tester, size: const Size(390, 844));

      await tester.tap(find.text('Client and site'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Liner age'));
      await tester.pumpAndSettle();

      expect(find.text('Only when Flue is Clay'), findsOneWidget);
      expect(find.byKey(const Key('cond-field')), findsNothing);

      await tester.tap(find.text('Change').first);
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('cond-field')), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );
}

Finder _card(String label) => find.descendant(
  of: find.byKey(const Key('inspection-builder-fields')),
  matching: find.text(label),
);

Future<void> _open(WidgetTester tester, String label) async {
  await tester.tap(_card(label));
  await tester.pumpAndSettle();
}

Future<void> _openScreen(WidgetTester tester, String name) async {
  await tester.tap(
    find.descendant(
      of: find.byKey(const Key('inspection-builder-list')),
      matching: find.text(name),
    ),
  );
  await tester.pumpAndSettle();
}

class _Server {
  final List<Map<String, dynamic>> patches = [];
  final List<String> calls = [];
  String? refuse;
  bool second = false;

  http.Response handle(http.Request request, String definition) {
    final path = request.url.path;
    if (path.endsWith('/inspections/catalogue')) return _ok(_catalogue);
    if (request.method == 'GET' && path.endsWith('/inspections')) {
      return _ok({
        'data': [jsonDecode(definition), if (second) jsonDecode(_dryerVent)],
      });
    }
    if (request.method == 'PATCH') {
      calls.add('PATCH');
      if (refuse != null) {
        return http.Response(
          jsonEncode({
            'message': 'The given data was invalid.',
            'errors': {
              'screens.0.fields.0.options': [refuse],
            },
          }),
          422,
          headers: _json,
        );
      }
      patches.add(jsonDecode(request.body) as Map<String, dynamic>);
      return _ok({
        'data': {'published': true, 'current_version': 3},
      });
    }
    if (request.method == 'POST' && path.endsWith('/publish')) {
      calls.add('POST publish');
      return _ok({
        'data': {'published': true, 'current_version': 4},
      });
    }
    return http.Response('{"message":"not stubbed"}', 500, headers: _json);
  }
}

Future<void> _pump(
  WidgetTester tester, {
  Size size = const Size(1600, 1400),
  _Server? server,
  String definition = _definition,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final serving = server ?? _Server();
  final api = ApiClient(
    client: MockClient((request) async => serving.handle(request, definition)),
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
}

http.Response _ok(Object body) =>
    http.Response(jsonEncode(body), 200, headers: _json);

const _json = {'content-type': 'application/json'};

const _definition = '''
{
  "id": "insp-1",
  "name": "Chimney Inspection",
  "intro": null,
  "layout_preset": "step_strip",
  "published": true,
  "current_version": 3,
  "scope": {"type": null, "id": null},
  "screens": [
    {"name": "Client and site", "fields": [
      {"key": "inspection_level", "label": "Level", "type": "choice", "required": true,
       "options": [
         {"value": "1", "label": "Level 1", "variant": "neutral", "fields": []},
         {"value": "2", "label": "Level 2", "variant": "neutral", "fields": []}
       ]},
      {"key": "flue", "label": "Flue", "type": "choice", "options": ["Clay", "Metal"]},
      {"key": "liner_age", "label": "Liner age", "type": "number",
       "visible_if": {"field": "flue", "op": "eq", "value": "Clay"}}
    ]},
    {"name": "The system", "fields": [
      {"key": "system_type", "label": "System type", "type": "choice", "required": true,
       "print": "ignore",
       "options": [
         {"value": "masonry", "label": "Masonry fireplace", "variant": "neutral", "fields": []},
         {"value": "wood", "label": "Wood stove", "variant": "neutral", "fields": []}
       ]},
      {"type": "divider", "label": "Masonry fireplace",
       "visible_if": {"field": "system_type", "op": "eq", "value": "masonry"}},
      {"key": "firebox", "label": "Firebox", "type": "choice", "required": true, "print": "root",
       "visible_if": {"field": "system_type", "op": "eq", "value": "masonry"},
       "options": [
         {"value": "ok", "label": "Meets industry standards", "variant": "pass", "fields": []},
         {"value": "not-ok", "label": "Does not meet industry standards", "variant": "fail",
          "fields": [
            {"key": "firebox_photo", "label": "Photograph", "type": "photo", "max_count": 4, "required": true},
            {"key": "firebox_explanation", "label": "What is wrong", "type": "textarea"},
            {"key": "firebox_work", "label": "Recommended work", "type": "services"}
          ]},
         {"value": "not-applicable", "label": "Not applicable", "variant": "not_applicable", "fields": []}
       ]},
      {"key": "smoke_chamber", "label": "Smoke chamber", "type": "text",
       "visible_if": {"field": "system_type", "op": "in", "values": ["masonry", "wood"]}}
    ]}
  ]
}
''';

const _laterDefinition = '''
{
  "id": "insp-1",
  "name": "Backwards",
  "published": false,
  "current_version": null,
  "scope": {"type": null, "id": null},
  "screens": [
    {"name": "First", "fields": [
      {"key": "early", "label": "Early", "type": "text",
       "visible_if": {"field": "late", "op": "eq", "value": "yes"}}
    ]},
    {"name": "Second", "fields": [
      {"key": "late", "label": "Late", "type": "text"}
    ]}
  ]
}
''';

const _dryerVent = '''
{
  "id": "insp-2",
  "name": "Dryer vent",
  "published": false,
  "current_version": null,
  "scope": {"type": null, "id": null},
  "screens": [
    {"name": "Vent", "fields": [
      {"key": "lint", "label": "Lint trap", "type": "text"}
    ]}
  ]
}
''';

const _catalogue = {
  'data': {
    'field_types': [
      {'key': 'text', 'label': 'Text'},
      {'key': 'textarea', 'label': 'Longer text'},
      {'key': 'number', 'label': 'Number'},
      {'key': 'choice', 'label': 'One of a list'},
      {'key': 'photo', 'label': 'A photograph'},
      {'key': 'services', 'label': 'Recommended work'},
      {'key': 'divider', 'label': 'A heading between fields'},
      {'key': 'alert', 'label': 'A note to the technician'},
    ],
    'layout_presets': [
      {'key': 'step_strip', 'label': 'One screen at a time'},
    ],
    'bindable': [
      {'key': 'customer.name', 'label': 'Client name'},
    ],
    'scopes': [
      {'type': null, 'id': null, 'label': 'Whole organization'},
    ],
  },
};
