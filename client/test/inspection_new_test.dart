import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/inspection/new_tab.dart';
import 'package:ezbizi_client/widgets/calendar_day_field.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('a job-shaped miss says Job not found', (tester) async {
    await _pump(tester, searchBody: '{"data":[],"message":"Job not found"}');

    await tester.enterText(find.byType(TextField), 'J-1042');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();

    expect(find.text('Job not found'), findsOneWidget);
    expect(find.text('Client not found'), findsNothing);
  });

  testWidgets('Next names Crown when it is required and empty', (tester) async {
    await _pump(tester, startBody: _run);

    await tester.tap(find.text('14 Maple'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Level 1'));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'Submit'));
    await tester.pump();

    expect(
      find.text('Crown needs an answer before you can continue.'),
      findsOneWidget,
    );
  });

  /// A photo answer is a LIST, and an empty list is the missing case.
  ///
  /// The check this pins used to ask `value.toString().trim().isEmpty`, which
  /// is `'[]'` for an empty list — never empty, so a required photograph waved
  /// every technician straight through to Submit with no picture taken.
  testWidgets('a required photo with nothing taken blocks Next', (tester) async {
    await _pump(tester, startBody: _photoRun);

    await tester.tap(find.text('14 Maple'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Level 1'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('field-damage')), findsOneWidget,
        reason: 'a photo field is a camera control, not a text box');
    expect(find.byKey(const Key('add-photo-Damage')), findsOneWidget);

    await tester.tap(find.widgetWithText(FilledButton, 'Submit'));
    await tester.pump();

    expect(
      find.text('Damage needs an answer before you can continue.'),
      findsOneWidget,
    );
  });

  /// The type on the field decides the control.
  ///
  /// Every field used to be a TextField, which meant a one-of-a-list was typed
  /// from memory and a date was typed by hand on a phone — the two ways a form
  /// produces answers nobody can report on afterwards.
  testWidgets('a choice is a dropdown and a date is a picker', (tester) async {
    await _pump(tester, startBody: _typedRun);

    await tester.tap(find.text('14 Maple'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Level 1'));
    await tester.pumpAndSettle();

    expect(find.byType(SearchPickerField<String>), findsOneWidget,
        reason: 'a one-of-a-list must offer the list');
    expect(find.byKey(const Key('field-flue')), findsOneWidget);

    // The date field is a calendar control. Counting TextFields would be wrong:
    // CalendarDayField is three of them inside (day, month, year), so the count
    // proves nothing about which field is which. The keys do.
    expect(find.byKey(const Key('field-seen')), findsOneWidget);
    expect(find.byType(CalendarDayField), findsOneWidget);

    // And only the genuinely free-text field is a plain box.
    expect(
      find.descendant(
        of: find.byKey(const Key('field-notes')),
        matching: find.byType(EditableText),
      ),
      findsOneWidget,
    );
  });

  /// A signature is a pad, and an unsigned one blocks Next.
  ///
  /// This field used to be a TextField. The failure that made it worth building
  /// was not cosmetic: a typed name printed in the position a signature goes
  /// reads as somebody having signed, and nobody had.
  testWidgets('a signature is drawn, and an unsigned one blocks Next', (tester) async {
    await _pump(tester, startBody: _signatureRun);

    await tester.tap(find.text('14 Maple'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Level 1'));
    await tester.pumpAndSettle();

    // A pad, not a box. No EditableText anywhere on this screen.
    expect(find.byKey(const Key('signature-pad-Customer signature')), findsOneWidget);
    expect(find.byType(EditableText), findsNothing,
        reason: 'a signature must never be typed');

    // Nothing drawn yet, so there is nothing to save.
    final save = tester.widget<FilledButton>(
      find.byKey(const Key('signature-save-Customer signature')),
    );
    expect(save.onPressed, isNull);

    await tester.tap(find.widgetWithText(FilledButton, 'Submit'));
    await tester.pump();

    expect(
      find.text('Customer signature needs an answer before you can continue.'),
      findsOneWidget,
    );

    // Draw, and the save becomes live.
    await tester.drag(
      find.byKey(const Key('signature-pad-Customer signature')),
      const Offset(60, 20),
    );
    await tester.pump();

    final armed = tester.widget<FilledButton>(
      find.byKey(const Key('signature-save-Customer signature')),
    );
    expect(armed.onPressed, isNotNull, reason: 'a drawn mark can be saved');
  });

  /// A branch the technician is not on is not on screen.
  ///
  /// The A1 inspection branches five ways on system type and roughly a hundred
  /// of its hundred and twenty fields sit behind one, so this is the difference
  /// between a form somebody can fill in and one that asks a masonry technician
  /// about wood stoves.
  testWidgets('a field whose condition is false is not asked', (tester) async {
    await _pump(tester, startBody: _branchingRun);

    await tester.tap(find.text('14 Maple'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Level 1'));
    await tester.pumpAndSettle();

    // Nothing chosen yet, so the wood branch is not shown.
    expect(find.byKey(const Key('field-wood_stove_type')), findsNothing);
    expect(find.byKey(const Key('field-system_type')), findsOneWidget);

    // And Submit is not blocked by the hidden required field — the server
    // agrees, in RequiredFields, which is the half that actually refuses.
    await tester.tap(find.widgetWithText(FilledButton, 'Submit'));
    await tester.pump();
    expect(
      find.text('Wood stove type needs an answer before you can continue.'),
      findsNothing,
      reason: 'a question that was never asked cannot be the thing blocking Submit',
    );
  });

  testWidgets('choosing the branch reveals its fields', (tester) async {
    await _pump(tester, startBody: _branchingRun);

    await tester.tap(find.text('14 Maple'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Level 1'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('field-system_type')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('wood').last);
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('field-wood_stove_type')), findsOneWidget,
        reason: 'answering the branch has to bring its questions with it');
  });

  /// Failing a finding demands its evidence, on screen and at Submit.
  ///
  /// This is the pattern the whole A1 port rests on: their Fail branch carries
  /// a required photograph, an explanation and priced work, so a technician
  /// cannot record a failure and move on.
  testWidgets('choosing the failing option reveals what it demands', (tester) async {
    await _pump(tester, startBody: _findingRun);

    await tester.tap(find.text('14 Maple'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Level 1'));
    await tester.pumpAndSettle();

    // Nothing chosen: no evidence asked for.
    expect(find.byKey(const Key('field-crown_note')), findsNothing);

    // Passing asks for nothing either.
    await tester.tap(find.byKey(const Key('field-crown')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Meets standards').last);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('field-crown_note')), findsNothing);

    // Failing does.
    await tester.tap(find.byKey(const Key('field-crown')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Does not meet standards').last);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('field-crown_note')), findsOneWidget);

    // And it blocks Submit by name until it is answered.
    await tester.tap(find.widgetWithText(FilledButton, 'Submit'));
    await tester.pump();
    expect(
      find.text('Explanation needs an answer before you can continue.'),
      findsOneWidget,
    );
  });

  /// Each screen the inspection asks is a step; a screen its condition hides
  /// is not one. Next moves the highlight on and ticks the step behind it,
  /// and tapping an earlier step goes back (Jose, 2026-10-26).
  testWidgets('the steps follow the screens asked and go back', (tester) async {
    await _pump(tester, startBody: _steppedRun);
    await tester.tap(find.text('14 Maple'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Level 1'));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('inspection-step-0')), findsOneWidget);
    expect(find.byKey(const ValueKey('inspection-step-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('inspection-step-2')), findsNothing,
        reason: 'the hidden Wood stove screen is not a step');
    expect(find.text('Wood stove'), findsNothing);

    await tester.tap(find.widgetWithText(FilledButton, 'Next'));
    await tester.pumpAndSettle();
    expect(find.byIcon(Icons.check), findsOneWidget, reason: 'Client is done');
    expect(find.widgetWithText(FilledButton, 'Submit'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('inspection-step-0')));
    await tester.pumpAndSettle();
    expect(find.widgetWithText(FilledButton, 'Next'), findsOneWidget,
        reason: 'back on the first screen');
    expect(find.byIcon(Icons.check), findsNothing);
  });

  testWidgets('resume vs start again is a panel, not a modal', (tester) async {
    await _pump(tester, startStatus: 409, startBody: _resume);

    await tester.tap(find.text('14 Maple'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Level 1'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('inspection-resume-panel')), findsOneWidget);
    expect(find.text('Resume'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  String? searchBody,
  String startBody = _run,
  int startStatus = 201,
}) async {
  tester.view.physicalSize = const Size(1400, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/inspections/search')) {
        return http.Response(
          searchBody ?? _hits,
          200,
          headers: _json,
        );
      }
      if (path.endsWith('/inspections/startable')) {
        return http.Response(_picker, 200, headers: _json);
      }
      if (path.endsWith('/inspections/runs') && request.method == 'POST') {
        return http.Response(startBody, startStatus, headers: _json);
      }
      return http.Response('{"message":"not stubbed"}', 500, headers: _json);
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: NewInspectionTab(api: api, session: Session(api)),
      ),
    ),
  );
  await tester.pumpAndSettle();

  if (searchBody == null) {
    await tester.enterText(find.byType(TextField), 'Maple');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
  }
}

const _json = {'content-type': 'application/json'};

const _hits = '''
{"data":[{"type":"customer","id":"c-1","label":"14 Maple","customer_id":"c-1","job_id":null,"customer_name":"14 Maple"}]}
''';

const _picker = '''
{"data":[{"id":"def-1","name":"Level 1","draft_id":null}]}
''';

const _run = '''
{
  "data": {
    "id": "run-1",
    "definition_name": "Level 1",
    "customer_name": "14 Maple",
    "layout_preset": "step_strip",
    "answers": {},
    "screens": [
      {
        "name": "Roof",
        "fields": [
          {"key": "crown", "label": "Crown", "type": "text", "required": true}
        ]
      }
    ]
  }
}
''';

const _typedRun = '''
{
  "data": {
    "id": "run-1",
    "definition_name": "Level 1",
    "customer_name": "14 Maple",
    "layout_preset": "step_strip",
    "answers": {},
    "screens": [
      {
        "name": "Roof",
        "fields": [
          {"key": "flue", "label": "Flue", "type": "choice",
           "options": ["Clay", "Metal"]},
          {"key": "seen", "label": "Seen on", "type": "date"},
          {"key": "notes", "label": "Notes", "type": "text"}
        ]
      }
    ]
  }
}
''';

const _signatureRun = '''
{
  "data": {
    "id": "run-1",
    "definition_name": "Level 1",
    "customer_name": "14 Maple",
    "layout_preset": "step_strip",
    "answers": {},
    "screens": [
      {
        "name": "Sign off",
        "fields": [
          {"key": "signed_by", "label": "Customer signature",
           "type": "signature", "required": true}
        ]
      }
    ]
  }
}
''';

const _branchingRun = '''
{
  "data": {
    "id": "run-1",
    "definition_name": "Level 1",
    "customer_name": "14 Maple",
    "layout_preset": "step_strip",
    "answers": {},
    "screens": [
      {
        "name": "System",
        "fields": [
          {"key": "system_type", "label": "System type", "type": "choice",
           "options": ["masonry", "wood"], "required": true},
          {"key": "wood_stove_type", "label": "Wood stove type", "type": "text",
           "required": true,
           "visible_if": {"field": "system_type", "op": "eq", "value": "wood"}}
        ]
      }
    ]
  }
}
''';

const _findingRun = '''
{
  "data": {
    "id": "run-1",
    "definition_name": "Level 1",
    "customer_name": "14 Maple",
    "layout_preset": "step_strip",
    "answers": {},
    "screens": [
      {
        "name": "Crown",
        "fields": [
          {"key": "crown", "label": "Crown condition", "type": "choice",
           "required": true,
           "options": [
             {"value": "ok", "label": "Meets standards", "variant": "pass"},
             {"value": "not-ok", "label": "Does not meet standards",
              "variant": "fail",
              "fields": [
                {"key": "crown_note", "label": "Explanation", "type": "text",
                 "required": true}
              ]}
           ]}
        ]
      }
    ]
  }
}
''';

const _photoRun = '''
{
  "data": {
    "id": "run-1",
    "definition_name": "Level 1",
    "customer_name": "14 Maple",
    "layout_preset": "step_strip",
    "answers": {},
    "screens": [
      {
        "name": "Roof",
        "fields": [
          {"key": "damage", "label": "Damage", "type": "photo",
           "max_count": 4, "required": true}
        ]
      }
    ]
  }
}
''';

const _resume = '''
{
  "data": {
    "choice": "resume",
    "run": {
      "id": "run-1",
      "definition_name": "Level 1",
      "answers": {},
      "screens": []
    }
  }
}
''';

const _steppedRun = '''
{
  "data": {
    "id": "run-1",
    "definition_name": "Level 1",
    "customer_name": "14 Maple",
    "layout_preset": "step_strip",
    "answers": {},
    "screens": [
      {"name": "Client", "fields": [{"key": "note", "label": "Note", "type": "text"}]},
      {"name": "Wood stove", "visible_if": {"field": "note", "op": "eq", "value": "wood"},
       "fields": [{"key": "stove", "label": "Stove", "type": "text"}]},
      {"name": "Sign off", "fields": [{"key": "end", "label": "End", "type": "text"}]}
    ]
  }
}
''';
