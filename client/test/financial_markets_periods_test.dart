import 'package:ezbizi_client/screens/financials/financial_markets.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('Last 3 months clamps the day to the month it lands in', () {
    expect(
      marketPeriods(DateTime(2026, 5, 31))['Last 3 months']!.$1,
      DateTime(2026, 2, 28),
    );
    expect(
      marketPeriods(DateTime(2028, 5, 31))['Last 3 months']!.$1,
      DateTime(2028, 2, 29),
    );
    expect(
      marketPeriods(DateTime(2026, 12, 31))['Last 3 months']!.$1,
      DateTime(2026, 9, 30),
    );
    expect(
      marketPeriods(DateTime(2026, 2, 15))['Last 3 months']!.$1,
      DateTime(2025, 11, 15),
    );
  });
}
