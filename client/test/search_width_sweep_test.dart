import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Mobile review 2026-11-13 #10 / desktop D3: search bars drew at four
/// widths. These lists use the one shared ListSearch at its own width.
void main() {
  const files = [
    'lib/screens/financials/financial_adjustments.dart',
    'lib/screens/financials/financial_bills.dart',
    'lib/screens/inventory/order_list.dart',
    'lib/screens/marketing/newsletter_tab.dart',
    'lib/screens/marketing/social_media_tab.dart',
    'lib/screens/settings_screen.dart',
    'lib/screens/ad_campaigns_shell.dart',
  ];

  for (final path in files) {
    test('$path searches through ListSearch at the shared width', () {
      final source = File(path).readAsStringSync();
      expect(source, contains('ListSearch('));
      expect(source, isNot(contains('Icon(Icons.search')));
      final call = RegExp(r'ListSearch\(([^;]*?)\)', dotAll: true);
      for (final m in call.allMatches(source)) {
        expect(m.group(1), isNot(contains('width:')), reason: path);
      }
    });
  }
}
