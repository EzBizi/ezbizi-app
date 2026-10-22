import 'package:ezbizi_client/screens/hr/onboarding_form.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Several commission rates by weekly sales (the accountant via Jose,
/// 2026-10-22: "15% when above X, 30% when above X").
void main() {
  test('tiers read back from the sentence the contract prints', () {
    expect(CommissionTiers.parse('15% above \$5,000; 30% above \$10,000'), [
      ('15', '5,000'),
      ('30', '10,000'),
    ]);
    expect(CommissionTiers.parse('10% weekly'), isNull);
    expect(
      CommissionTiers.format([('15', '5,000'), ('30', '10,000'), ('', '')]),
      '15% above \$5,000; 30% above \$10,000',
    );
  });

  testWidgets('adding a second rate saves both as one sentence', (
    tester,
  ) async {
    String? saved;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: CommissionTiers(
            value: '15% above \$5,000',
            enabled: true,
            onChanged: (v) => saved = v,
          ),
        ),
      ),
    );

    await tester.tap(find.byKey(const Key('tier-add')));
    await tester.pump();
    await tester.enterText(find.byKey(const Key('tier-pct-1')), '30');
    await tester.enterText(find.byKey(const Key('tier-above-1')), '10,000');

    expect(saved, '15% above \$5,000; 30% above \$10,000');
  });

  testWidgets('text from before tiers is shown, not lost', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: CommissionTiers(
            value: '10% weekly',
            enabled: true,
            onChanged: (_) {},
          ),
        ),
      ),
    );

    expect(find.textContaining('"10% weekly"'), findsOneWidget);
  });
}
