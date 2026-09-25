/// A job's amount as a LIST shows it — or null, when there is nothing to show.
///
/// Null for zero as well as for absent. Two-thirds of the jobs on the box have
/// no lines (2,679 of 8,177 had an amount, 2026-09-24), and "USD 0.00" down a
/// column claims a price nobody set; a dash says nobody priced it. A job whose
/// price really is nothing loses little by the same dash.
///
/// `display` is already grouped by the server (`amountForDisplay`), so only the
/// currency is dressed here: a symbol for dollars, the code for anything else,
/// because a bare symbol would say "$" for a price in another currency.
String? listAmount(Object? amount) {
  if (amount is! Map) return null;

  final display = amount['display'];
  if (display is! String || display.isEmpty || amount['minor'] == 0) {
    return null;
  }

  final currency = amount['currency'];

  return currency == 'USD' ? '\$$display' : '$currency $display';
}

/// A count as a chip shows it: `8,152`, not `8152`.
String formatCount(int n) {
  final digits = n.abs().toString();
  final grouped = digits.replaceAllMapped(
    RegExp(r'\B(?=(\d{3})+(?!\d))'),
    (_) => ',',
  );

  return n < 0 ? '-$grouped' : grouped;
}
