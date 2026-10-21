import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/financials/financial_grid.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

final _fields = <Map>[
  {'id': 'n', 'name': 'Name', 'type': 'text', 'is_primary': true},
  {'id': 'a', 'name': 'Amount', 'type': 'currency', 'options': {}},
  for (var i = 0; i < 12; i++)
    {'id': 'x$i', 'name': 'Extra $i', 'type': 'text'},
];

List<Map> _records() => [
  {
    'id': 'r1',
    'values': {'n': 'Diesel', 'a': 10},
  },
  {
    'id': 'r2',
    'values': {'n': 'Rent', 'a': 20},
  },
  {'id': 'r3', 'values': <String, dynamic>{}},
];

Future<List<List<CellChange>>> _pump(
  WidgetTester tester, {
  List<Map>? records,
  void Function(Map<String, int>)? onWidths,
}) async {
  tester.view.physicalSize = const Size(900, 600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final rows = records ?? _records();
  final batches = <List<CellChange>>[];
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: FinancialGrid(
          tableId: 't1',
          fields: _fields,
          allFields: _fields,
          records: rows,
          config: const {},
          onOpen: (_, {initial = const {}}) {},
          onField: (_) {},
          canEdit: true,
          onWidths: onWidths,
          onChange: (changes) async {
            batches.add(changes);
            for (final c in changes) {
              final values = c.record['values'] as Map;
              c.value == null
                  ? values.remove(c.fieldId)
                  : values[c.fieldId] = c.value;
            }
            return null;
          },
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return batches;
}

Future<void> _key(
  WidgetTester tester,
  LogicalKeyboardKey key, {
  bool ctrl = false,
  bool shift = false,
}) async {
  if (ctrl) await tester.sendKeyDownEvent(LogicalKeyboardKey.controlLeft);
  if (shift) await tester.sendKeyDownEvent(LogicalKeyboardKey.shiftLeft);
  await tester.sendKeyEvent(key);
  if (shift) await tester.sendKeyUpEvent(LogicalKeyboardKey.shiftLeft);
  if (ctrl) await tester.sendKeyUpEvent(LogicalKeyboardKey.controlLeft);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('typing over a cell and Enter saves it and moves down', (
    tester,
  ) async {
    final batches = await _pump(tester);
    await tester.tap(find.text('Diesel'));
    await tester.pumpAndSettle();
    await tester.sendKeyEvent(LogicalKeyboardKey.keyF, character: 'F');
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'Fuel');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    expect(batches.single.single.value, 'Fuel');
    expect(find.text('Fuel'), findsOneWidget);
  });

  testWidgets(
    'a block pasted from Excel lands from the selected cell; undo puts it back',
    (tester) async {
      final batches = await _pump(tester);
      String? clipboard = 'A\t1\nB\t2';
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        (call) async {
          if (call.method == 'Clipboard.getData') return {'text': clipboard};
          if (call.method == 'Clipboard.setData')
            clipboard = (call.arguments as Map)['text'] as String?;
          return null;
        },
      );

      await tester.tap(find.text('Diesel'));
      await tester.pumpAndSettle();
      await _key(tester, LogicalKeyboardKey.keyV, ctrl: true);

      final pasted = {
        for (final c in batches.last) '${c.record['id']}:${c.fieldId}': c.value,
      };
      expect(pasted, {'r1:n': 'A', 'r1:a': '1', 'r2:n': 'B', 'r2:a': '2'});

      await _key(tester, LogicalKeyboardKey.keyZ, ctrl: true);
      final undone = {
        for (final c in batches.last) '${c.record['id']}:${c.fieldId}': c.value,
      };
      expect(undone, {
        'r1:n': 'Diesel',
        'r1:a': 10,
        'r2:n': 'Rent',
        'r2:a': 20,
      });

      // Copy a range out as tab-separated text.
      await _key(tester, LogicalKeyboardKey.arrowRight, shift: true);
      await _key(tester, LogicalKeyboardKey.keyC, ctrl: true);
      expect(clipboard, 'Diesel\t10');
    },
  );

  testWidgets(
    'fill down copies the top row of the selection; delete clears it',
    (tester) async {
      final batches = await _pump(tester);
      await tester.tap(find.text('Diesel'));
      await tester.pumpAndSettle();
      await _key(tester, LogicalKeyboardKey.arrowDown, shift: true);
      await _key(tester, LogicalKeyboardKey.arrowDown, shift: true);
      await _key(tester, LogicalKeyboardKey.keyD, ctrl: true);
      expect(batches.last.map((c) => c.value).toList(), [
        'Diesel',
        'Diesel',
        'Diesel',
      ]);

      await _key(tester, LogicalKeyboardKey.delete);
      expect(batches.last.every((c) => c.value == null), isTrue);
    },
  );

  testWidgets('a dragged column edge resizes it and is remembered', (
    tester,
  ) async {
    Map<String, int>? saved;
    await _pump(tester, onWidths: (w) => saved = w);
    await tester.drag(
      find.byKey(const ValueKey('resize:a')),
      const Offset(120, 0),
    );
    await tester.pumpAndSettle();
    expect(saved?['t1:a'], greaterThan(200));
  });

  testWidgets('the sideways scrollbar can be dragged', (tester) async {
    await _pump(tester);
    final scroll = tester.widget<SingleChildScrollView>(
      find.byKey(const ValueKey('financials-grid-horizontal')),
    );
    expect(scroll.controller!.offset, 0);
    // Drag the thumb near the bottom-left edge of the grid to the right.
    final bottomLeft = tester.getBottomLeft(
      find.byKey(const ValueKey('financials-grid-horizontal')),
    );
    await tester.dragFrom(
      bottomLeft + const Offset(40, -4),
      const Offset(300, 0),
    );
    await tester.pumpAndSettle();
    expect(scroll.controller!.offset, greaterThan(0));
  });
}
