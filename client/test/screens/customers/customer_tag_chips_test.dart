import 'package:ezbizi_client/screens/customers/customer_tags.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('three tags in a narrow cell show the first by name, then +2', (
    tester,
  ) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(
          body: SizedBox(
            width: 140,
            child: CustomerTagChips(['Gold', 'Spam', 'Gutter']),
          ),
        ),
      ),
    );
    expect(find.text('Gold'), findsOneWidget);
    expect(find.text('+2'), findsOneWidget);
    expect(find.text('Spam'), findsNothing);
  });
}
