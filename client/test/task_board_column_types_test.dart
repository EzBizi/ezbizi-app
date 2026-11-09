import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/task_board_columns.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:flutter/material.dart';
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
            (id: 'a', label: 'Complaint', colour: null),
            (id: 'b', label: 'Bad Review', colour: null),
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
}
