import 'package:ezbizi_client/widgets/list_table.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Phone audit 2026-11-04, Section 4 #3: stacked on a phone, a row's values
/// lost their headings and read as "11/4/2026 11/4/2026 $0.00 $1,249.00".
void main() {
  testWidgets('a stacked row names each value by its column', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(
          body: ListTable(
            columns: [
              ListColumn('Customer'),
              ListColumn('Created'),
              ListColumn('Total'),
              ListColumn(''),
            ],
            rows: [
              [
                Text('Marta Ruiz'),
                Text('11/4/2026'),
                Text(r'$1,249.00'),
                Text('Go'),
              ],
            ],
          ),
        ),
      ),
    );

    expect(find.text('Created '), findsOneWidget);
    expect(find.text('Total '), findsOneWidget);
    // The first cell heads the row, and an unlabelled column stays bare.
    expect(find.text('Customer '), findsNothing);
    expect(find.text(' '), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
