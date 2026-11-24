import 'package:ezbizi_client/widgets/people_search_list.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('Jose 11-24: the person picker is a search, focused, filtering '
      'as you type', (tester) async {
    String? picked = 'unset';
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: PeopleSearchList(
            people: const {'u1': 'Lena Okafor', 'u2': 'Omar Feld'},
            nobody: true,
            onPick: (id) => picked = id,
          ),
        ),
      ),
    );
    final box = find.byKey(const ValueKey('person-search'));
    expect(tester.widget<TextField>(box).autofocus, isTrue);
    expect(find.text('Nobody'), findsOneWidget);

    await tester.enterText(box, 'oma');
    await tester.pump();
    expect(find.text('Omar Feld'), findsOneWidget);
    expect(find.text('Lena Okafor'), findsNothing);
    expect(find.text('Nobody'), findsNothing);
    await tester.tap(find.text('Omar Feld'));
    await tester.pump();
    expect(picked, 'u2');

    await tester.enterText(box, 'zz');
    await tester.pump();
    expect(find.text('Nobody by that name'), findsOneWidget);
  });
}
