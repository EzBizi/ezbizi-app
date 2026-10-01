import 'package:ezbizi_client/widgets/colour_field.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Jose, 2026-10-01: every colour box gets a picker, and typing still works.
void main() {
  Future<List<String>> pump(WidgetTester tester, {String value = ''}) async {
    final changes = <String>[];
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: SingleChildScrollView(
          child: ColourField(label: 'Header colour', value: value, onChanged: changes.add),
        ),
      ),
    ));
    return changes;
  }

  testWidgets('the swatch opens a picker in place, and a ready colour fills the box', (tester) async {
    final changes = await pump(tester);

    expect(find.byKey(const ValueKey('colour-picker-Header colour')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('colour-swatch-Header colour')));
    await tester.pump();
    expect(find.byKey(const ValueKey('colour-picker-Header colour')), findsOneWidget);
    expect(find.byType(Dialog), findsNothing, reason: 'no pop-ups (§6)');

    await tester.tap(find.byKey(const ValueKey('colour-ready-#0b5cad')));
    await tester.pump();
    expect(changes.last, '#0b5cad');
    expect(find.text('#0b5cad'), findsOneWidget);
  });

  testWidgets('a slider moves the colour, and typing still works', (tester) async {
    final changes = await pump(tester, value: '#ff0000');

    await tester.tap(find.byKey(const ValueKey('colour-swatch-Header colour')));
    await tester.pump();
    await tester.drag(find.byType(Slider).last, const Offset(-200, 0));
    await tester.pump();
    expect(changes.last, isNot('#ff0000'), reason: 'brightness moved');

    await tester.enterText(find.byType(TextField), '#123456');
    expect(changes.last, '#123456');
  });

  test('every form the server keeps reads as a colour', () {
    expect(ColourField.parse('#abc'), const Color(0xFFAABBCC));
    expect(ColourField.parse('#aabbcc'), const Color(0xFFAABBCC));
    expect(ColourField.parse('#aabbcc80'), const Color(0x80AABBCC));
    expect(ColourField.parse('nope'), isNull);
    expect(ColourField.hex(const Color(0xFF0B5CAD)), '#0b5cad');
  });
}
