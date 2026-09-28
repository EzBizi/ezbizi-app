import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/numbered_step.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  // Beside the heading on a phone, the step's action left its sentence a
  // third of the width: five words a line down the automation builder.
  testWidgets('on a phone the action goes under the sentence', (tester) async {
    await _pump(tester, width: 375);
    expect(
      tester.getTopLeft(find.text('Add a step')).dy,
      greaterThan(tester.getBottomLeft(find.text(_note)).dy),
    );
  });

  testWidgets('with room the action stays beside the heading', (tester) async {
    await _pump(tester, width: 1000);
    expect(
      tester.getTopLeft(find.text('Add a step')).dy,
      lessThan(tester.getBottomLeft(find.text(_note)).dy),
    );
  });

  // Side by side on a phone, the second label read "What it is for (opti…".
  testWidgets('a field pair stacks when there is no room', (tester) async {
    tester.view.physicalSize = const Size(375, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(
          body: FieldPair(
            first: TextField(decoration: InputDecoration(labelText: 'Name')),
            second: TextField(
              decoration: InputDecoration(labelText: 'What it is for'),
            ),
          ),
        ),
      ),
    );

    final boxes = find.byType(TextField);
    expect(
      tester.getTopLeft(boxes.at(1)).dy,
      greaterThan(tester.getBottomLeft(boxes.at(0)).dy),
    );
  });
}

const _note = 'One step at a time, in order.';

Future<void> _pump(WidgetTester tester, {required double width}) async {
  tester.view.physicalSize = Size(width, 800);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: NumberedStep(
          number: 4,
          title: 'Then do this',
          note: _note,
          action: TextButton(onPressed: () {}, child: const Text('Add a step')),
          child: const SizedBox(height: 40),
        ),
      ),
    ),
  );
}
