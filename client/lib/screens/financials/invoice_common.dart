import '../../core/theme.dart';
import '../../core/timestamps.dart';
import 'financial_cells.dart';

/// `$3,880.00` for 388000 cents, the way Financials writes money.
String invoiceMoney(int cents) {
  final shown = formatCell(const {
    'type': 'currency',
    'options': {},
  }, cents / 100);
  return shown.isEmpty ? r'$0.00' : shown;
}

/// Cents from what somebody typed in a money cell: `2,400`, `$2400.5`, `-30`.
/// Empty is zero; anything else that is not a number is null.
int? parseMoneyCents(String typed) {
  final clean = typed.replaceAll(RegExp(r'[\s$,]'), '');
  if (clean.isEmpty) return 0;
  final value = double.tryParse(clean);
  if (value == null || !value.isFinite) return null;
  return (value * 100).round();
}

/// What the status chip says, and its colour. A sent invoice past its due
/// date reads Overdue, as the server's `overdue` says.
(String, Tone) invoiceStatus(Map invoice) {
  if (invoice['overdue'] == true) return ('Overdue', Tone.bad);
  return switch (invoice['status']) {
    'draft' => ('Draft', Tone.neutral),
    'approved' => ('Approved', Tone.info),
    'sent' => ('Sent', Tone.caution),
    'paid' => ('Paid', Tone.ok),
    'void' => ('Void', Tone.neutral),
    final other => ('$other', Tone.neutral),
  };
}

/// A `YYYY-MM-DD` day in the organization's date format, or [fallback].
String invoiceDay(
  Object? iso,
  DisplayFormats formats, {
  String fallback = '—',
}) {
  final at = iso is String ? DateTime.tryParse(iso) : null;
  return at == null ? fallback : formats.formatDate(at);
}

/// `2026-10-01` for a day, the spelling the API takes.
String isoDay(DateTime d) =>
    '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

/// The width under which Invoices draws its phone layout (option 4).
const invoicesPhoneWidth = 600.0;
