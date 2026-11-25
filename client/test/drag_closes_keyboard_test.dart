import 'dart:io';

import 'package:ezbizi_client/core/soft_keyboard.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Mobile review 11-24: on a phone a tap outside a text box never closes the
/// keyboard, and the iPhone number pad has no Done key. Dragging the page does.
void main() {
  Future<bool> focusedAfterDrag(
    WidgetTester tester,
    ScrollBehavior? behavior,
  ) async {
    final node = FocusNode();
    addTearDown(node.dispose);
    await tester.pumpWidget(
      MaterialApp(
        scrollBehavior: behavior,
        home: Scaffold(
          body: ListView(
            children: [
              TextField(focusNode: node, keyboardType: TextInputType.number),
              for (var i = 0; i < 30; i++)
                SizedBox(height: 60, child: Text('row $i')),
            ],
          ),
        ),
      ),
    );
    await tester.tap(find.byType(TextField));
    await tester.pump();
    expect(node.hasFocus, isTrue);
    await tester.drag(find.text('row 5'), const Offset(0, -200));
    await tester.pumpAndSettle();
    return node.hasFocus;
  }

  testWidgets('dragging the page puts the keyboard away', (tester) async {
    expect(await focusedAfterDrag(tester, dragClosesKeyboard), isFalse);
  });

  testWidgets('without it the keyboard stays up (the control)', (tester) async {
    expect(await focusedAfterDrag(tester, null), isTrue);
  });

  test('the app uses it', () {
    expect(
      File('lib/main.dart').readAsStringSync(),
      contains('scrollBehavior: dragClosesKeyboard,'),
    );
  });
}
