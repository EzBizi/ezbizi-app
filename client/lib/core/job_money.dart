import 'numbers.dart';
import 'theme.dart';

/// Workiz's money on a job, as the jobs list and the job panel say it
/// (queue 31, Jose's 1 and 4, 2026-09-29).
///
/// It arrives only for a role with the Financials toggle, in minor units, and
/// read only: Workiz stays the truth (Jose's ruling the same day). Overdue is
/// the reader's to decide, as JobsController decides it: money due, and a due
/// date before their own today.
class JobMoney {
  JobMoney._(this._map);

  final Map<String, dynamic> _map;

  /// The job's `money`, or null when there is none: a job Workiz does not
  /// have, or a reader the server told nothing.
  static JobMoney? of(Object? money) =>
      money is Map ? JobMoney._(Map<String, dynamic>.from(money)) : null;

  int _minor(String key) => (_map[key] as num?)?.toInt() ?? 0;

  int get total => _minor('total');
  int get subtotal => _minor('subtotal');
  int get due => _minor('due');

  /// What was paid, never below nothing. The list's rows carry no `paid`, so
  /// it is worked out the way the server works it.
  int get paid => _map.containsKey('paid')
      ? _minor('paid')
      : (total - (due > 0 ? due : 0)).clamp(0, total);

  /// A negative amount due is money owed back to the customer.
  int get credit => due < 0 ? -due : 0;

  String get state => '${_map['state'] ?? 'none'}';
  String get currency => '${_map['currency'] ?? 'USD'}';

  /// A wall date, `Y-m-d`: no zone, and never shifted by one.
  DateTime? get dueOn => DateTime.tryParse('${_map['due_on'] ?? ''}');

  List<Map<String, dynamic>> get lines => [
    for (final line in (_map['lines'] as List? ?? const []))
      if (line is Map) Map<String, dynamic>.from(line),
  ];

  bool overdue(DateTime today) {
    final on = dueOn;
    return due > 0 &&
        on != null &&
        on.isBefore(DateTime(today.year, today.month, today.day));
  }

  /// The word and the tone a pill says, or null for a job with nothing
  /// billed. Overdue comes first: it is a date on top of a state.
  (String, Tone)? pill(DateTime today) {
    if (overdue(today)) return ('Overdue', Tone.bad);

    return switch (state) {
      'paid' => ('Paid', Tone.ok),
      'partial' => ('Partly paid', Tone.caution),
      'unpaid' => ('Unpaid', Tone.neutral),
      'credit' => ('Credit', Tone.info),
      _ => null,
    };
  }
}

/// The money filter's choices, in the order the chips draw them.
/// Mirrors App\Support\ProjectBoard\WorkOrderPdf::PAYMENT_RULE (Jose 11-17).
const paymentRule =
    "Don't leave the job site without confirmation of collected payment. "
    'Reach out to the project manager so we can collect payment from the client.';

const moneyStates = <(String, String, Tone?)>[
  ('unpaid', 'Unpaid', null),
  ('partial', 'Partly paid', null),
  ('overdue', 'Overdue', Tone.bad),
  ('paid', 'Paid', Tone.ok),
  ('credit', 'Credit', Tone.info),
];

/// Minor units as money: `$1,925.00`, `−$45.00`, or `EUR 12.00` off dollars.
/// Without [cents], whole units: a total of five million needs no pennies.
String formatMoney(int minor, {String currency = 'USD', bool cents = true}) {
  final negative = minor < 0;
  final abs = minor.abs();
  final units = formatCount(cents ? abs ~/ 100 : (abs / 100).round());
  final tail = cents ? '.${(abs % 100).toString().padLeft(2, '0')}' : '';
  final symbol = currency == 'USD' ? r'$' : '$currency ';

  return '${negative ? '−' : ''}$symbol$units$tail';
}

/// Short enough for a phone's strip: `$5.09M`, `$718.5K`, `$950`.
String shortMinor(int minor, {String currency = 'USD'}) {
  final units = minor / 100;
  final symbol = currency == 'USD' ? r'$' : '$currency ';

  if (units.abs() >= 1000000) {
    return '$symbol${(units / 1000000).toStringAsFixed(2)}M';
  }
  if (units.abs() >= 10000) {
    return '$symbol${(units / 1000).toStringAsFixed(1)}K';
  }
  return formatMoney(minor, currency: currency, cents: false);
}
