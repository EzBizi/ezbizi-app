import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/task_board_columns.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Task Board column types (Jose, 2026-11-09): tags added from the cell, a
/// type change that says what it would clear, and the contact types.
void main() {
  BoardColumn column(String type, [List<ColumnOption> options = const []]) => (
    id: 'c1',
    name: 'Description',
    type: type,
    unit: null,
    max: null,
    options: options,
    format: 'plain',
    footer: 'none',
    formula: null,
  );

  Future<void> pump(WidgetTester tester, Widget child) async {
    tester.view.physicalSize = const Size(1400, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: Center(child: child)),
      ),
    );
  }

  testWidgets('a tags cell picks several and adds a new tag inline', (
    tester,
  ) async {
    final changes = <Object?>[];
    final added = <String>[];
    await pump(
      tester,
      SizedBox(
        width: 240,
        child: ColumnCell(
          column: column('tags', const [
            (id: 'a', label: 'Complaint', colour: null, retired: false),
            (id: 'b', label: 'Bad Review', colour: null, retired: false),
            (id: 'r', label: 'Old', colour: null, retired: true),
          ]),
          value: const ['a'],
          people: const {},
          formats: DisplayFormats.fallback,
          onChanged: changes.add,
          onAddOption: (label) async {
            added.add(label);
            return 'new';
          },
          onEditOptions: () {},
        ),
      ),
    );
    expect(find.text('Complaint'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('column-cell-c1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('column-option-c1-b')).first);
    await tester.pumpAndSettle();
    expect(changes.last, ['a', 'b']);
    // A retired tag is not offered.
    expect(find.byKey(const ValueKey('column-option-c1-r')), findsNothing);

    await tester.enterText(
      find.byKey(const ValueKey('column-option-new-c1')),
      'Refund',
    );
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    expect(added, ['Refund']);
    expect(changes.last, ['a', 'new']);
    expect(find.text('Edit tags…'), findsOneWidget);
  });

  testWidgets('a type change that would clear values asks first', (
    tester,
  ) async {
    final bodies = <Map>[];
    final api = ApiClient(
      client: MockClient((request) async {
        final body = jsonDecode(request.body) as Map;
        bodies.add(body);
        if (body['accept_loss'] != true) {
          return http.Response(
            jsonEncode({
              'message': '2 values cannot become number and would be cleared.',
              'errors': {
                'type': ['2 values cannot become number and would be cleared.'],
              },
            }),
            422,
            headers: const {'content-type': 'application/json'},
          );
        }
        return http.Response(
          jsonEncode({'data': {}}),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );
    await pump(
      tester,
      Builder(
        builder: (context) => FilledButton(
          onPressed: () => showSidePanel<bool>(
            context,
            builder: (_) => ColumnPanel(
              api: api,
              boardId: 'b1',
              type: 'text',
              newType: 'number',
              column: column('text'),
            ),
          ),
          child: const Text('open'),
        ),
      ),
    );
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('column-save')));
    await tester.pumpAndSettle();
    expect(bodies.single['type'], 'number');
    expect(
      find.text('2 values cannot become number and would be cleared.'),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const Key('column-type-anyway')));
    await tester.pumpAndSettle();
    expect(bodies.last['accept_loss'], true);
    expect(find.byType(ColumnPanel), findsNothing);
  });

  test('phone, email and url are checked before they are sent', () {
    expect(validColumnText('phone', '(305) 724-2199'), isTrue);
    expect(validColumnText('phone', 'call me'), isFalse);
    expect(validColumnText('email', 'ann@example.com'), isTrue);
    expect(validColumnText('email', 'ann'), isFalse);
    expect(validColumnText('url', 'https://example.com'), isTrue);
    expect(validColumnText('url', 'example'), isFalse);
    expect(contactUri('phone', '(305) 724-2199').toString(), 'tel:3057242199');
  });

  testWidgets('a text cell edits in place: Enter saves, Esc cancels', (
    tester,
  ) async {
    final changes = <Object?>[];
    await pump(
      tester,
      SizedBox(
        width: 200,
        height: 38,
        child: ColumnCell(
          column: column('text'),
          value: 'old',
          people: const {},
          formats: DisplayFormats.fallback,
          onChanged: changes.add,
        ),
      ),
    );
    await tester.tap(find.byKey(const ValueKey('column-cell-edit-c1')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'new');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    expect(changes, ['new']);
    expect(find.byType(TextField), findsNothing);

    await tester.tap(find.byKey(const ValueKey('column-cell-edit-c1')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'typo');
    await tester.sendKeyEvent(LogicalKeyboardKey.escape);
    await tester.pumpAndSettle();
    expect(changes, ['new']);
    expect(find.byType(TextField), findsNothing);
  });

  testWidgets("hovering a People cell's face opens that person's card", (
    tester,
  ) async {
    final asked = <String>[];
    final api = ApiClient(
      client: MockClient((request) async {
        asked.add(request.url.path);
        return http.Response(
          jsonEncode({
            'data': {'id': 'u-2', 'name': 'Tobin Marsh', 'title': 'Tech'},
          }),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );
    await pump(
      tester,
      SizedBox(
        width: 240,
        child: ColumnCell(
          column: column('people'),
          value: const ['u-2'],
          people: const {'u-2': 'Tobin Marsh'},
          formats: DisplayFormats.fallback,
          api: api,
          boardId: 'b-1',
        ),
      ),
    );
    final mouse = await tester.createGesture(kind: PointerDeviceKind.mouse);
    await mouse.addPointer(location: Offset.zero);
    addTearDown(mouse.removePointer);
    await mouse.moveTo(
      tester.getCenter(find.byKey(const ValueKey('people-face-u-2'))),
    );
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(asked.single, endsWith('/task-boards/b-1/people/u-2'));
    expect(find.byKey(const ValueKey('person-card-u-2')), findsOneWidget);
  });

  testWidgets('a Dependency cell ticks tasks in a card under it, searched '
      'by title', (tester) async {
    final changes = <Object?>[];
    await pump(
      tester,
      SizedBox(
        width: 240,
        child: ColumnCell(
          column: column('dependency'),
          value: const ['t2'],
          people: const {},
          formats: DisplayFormats.fallback,
          tasks: const [('t2', 'Measure the flue'), ('t3', 'Place the order')],
          onChanged: changes.add,
        ),
      ),
    );
    expect(find.text('1 task'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('column-cell-c1')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('task-pick-search-c1')),
      'order',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('task-pick-c1-t2')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('task-pick-c1-t3')).first);
    await tester.pumpAndSettle();
    expect(changes.single, ['t2', 't3']);
  });

  testWidgets('a Mirror column picks the connect column it follows and the '
      'column it shows', (tester) async {
    final bodies = <Map>[];
    final api = ApiClient(
      client: MockClient((request) async {
        bodies.add(jsonDecode(request.body) as Map);
        return http.Response(
          jsonEncode({'data': {}}),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );
    BoardColumn other(String id, String name, String type) => (
      id: id,
      name: name,
      type: type,
      unit: null,
      max: null,
      options: const <ColumnOption>[],
      format: 'plain',
      footer: 'none',
      formula: null,
    );
    await pump(
      tester,
      Builder(
        builder: (context) => FilledButton(
          onPressed: () => showSidePanel<bool>(
            context,
            builder: (_) => ColumnPanel(
              api: api,
              boardId: 'b1',
              type: 'mirror',
              column: column('mirror'),
              raw: const {'id': 'c1', 'settings': null},
              others: [
                other('c-link', 'Linked jobs', 'connect'),
                other('c-hours', 'Est. hours', 'number'),
              ],
            ),
          ),
          child: const Text('open'),
        ),
      ),
    );
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('column-mirror-connect')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Linked jobs').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('column-mirror-column')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Est. hours').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('column-save')));
    await tester.pumpAndSettle();
    expect(bodies.single['settings'], {
      'connect_column_id': 'c-link',
      'column_id': 'c-hours',
    });
  });
}
