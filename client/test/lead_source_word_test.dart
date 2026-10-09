import 'package:ezbizi_client/screens/leads_screen.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('a rating from the review link is named, not "Form" (2026-10-09)', () {
    expect(
      leadSourceWord({'source': 'website_form', 'lead_type': 'BAD_RATING'}),
      'Bad Rating',
    );
    expect(
      leadSourceWord({'source': 'website_form', 'lead_type': 'GOOD_RATING'}),
      'Good Rating',
    );
    expect(leadSourceWord({'source': 'website_form'}), 'Form');
  });
}
