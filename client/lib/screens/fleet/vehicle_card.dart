import 'package:flutter/material.dart';

import '../../core/money.dart';
import '../../core/timestamps.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/not_filled_in.dart';

/// Shared chrome and labels for the vehicle cards (spec §11).
class FleetSectionCard extends StatelessWidget {
  const FleetSectionCard({
    super.key,
    required this.title,
    required this.child,
    this.tags = const [],
  });

  final String title;
  final Widget child;

  /// State of the group, read before its fields — how much is filled, or what
  /// is missing. Wraps under the title rather than pushing it off a 375 screen.
  final List<Widget> tags;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Wrap(
              crossAxisAlignment: WrapCrossAlignment.center,
              runSpacing: 4,
              children: [
                Text(title, style: Theme.of(context).textTheme.titleMedium),
                ...tags,
              ],
            ),
            const SizedBox(height: 12),
            child,
          ],
        ),
      ),
    );
  }
}

/// Short fields side by side when there is room, stacked when there is not.
///
/// The 375px floor is the whole point of grouping the identity card: three
/// fields in a row is a desktop luxury, and below roughly 170px each a field
/// cannot show its own label, so it stacks instead of shrinking.
Widget fleetFieldRow(List<Widget> fields) {
  return LayoutBuilder(
    builder: (context, constraints) {
      if (constraints.maxWidth < fields.length * 170) {
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: fields,
        );
      }

      return Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (var i = 0; i < fields.length; i++) ...[
            if (i > 0) const SizedBox(width: 12),
            Expanded(child: fields[i]),
          ],
        ],
      );
    },
  );
}

Map<String, dynamic>? fleetMap(dynamic value) =>
    value is Map ? Map<String, dynamic>.from(value) : null;

List<Map<String, dynamic>> fleetMaps(dynamic value) {
  if (value is! List) return const [];
  return [
    for (final row in value)
      if (row is Map) Map<String, dynamic>.from(row),
  ];
}

String fleetToday() => formatCalendarDay(DateTime.now());

/// Calendar day, or the date part of an instant. Never parsed as UTC midnight.
String fleetDay(String? stamp) {
  if (stamp == null || stamp.isEmpty) return '';
  return stamp.split('T').first;
}

/// The device's calendar day of an instant (`assigned_at`, `released_at`):
/// 9 PM in New York is still that day, not tomorrow in UTC (audit 2026-11-27).
String fleetInstantDay(String? stamp) {
  final at = stamp == null ? null : DateTime.tryParse(stamp);
  return at == null ? fleetDay(stamp) : formatCalendarDay(at.toLocal());
}

String fleetStatusLabel(String status) => switch (status) {
  'in_shop' => 'In shop',
  'out_of_service' => 'Out of service',
  'sold' => 'Sold',
  'returned' => 'Returned',
  'active' => 'Active',
  _ => status,
};

String fleetKindLabel(String? kind) => switch (kind) {
  'rented' => 'Rented',
  'owned' => 'Owned',
  _ => '',
};

/// Empty franchise is General, never a blank place (spec §4).
const fleetGeneralLabel = 'General';
const fleetOrgValue = '__org__';
const fleetGeneralValue = '__pool__';

String fleetPlaceLabel(Map<String, dynamic> vehicle) {
  final franchise = fleetMap(vehicle['franchise']);
  if (franchise != null) {
    return franchise['name'] as String? ?? fleetGeneralLabel;
  }
  return fleetGeneralLabel;
}

String fleetOwnerLabel(Map<String, dynamic> vehicle, String organizationName) {
  final company = fleetMap(vehicle['company']);
  return company?['name'] as String? ?? organizationName;
}

String? fleetCents(dynamic cents) {
  if (cents is! int) return null;
  final dollars = (cents / 100).toStringAsFixed(2);
  return '\$$dollars';
}

int? fleetParseInt(String raw, String sentence) {
  final text = raw.trim();
  if (text.isEmpty) return null;
  final value = int.tryParse(text);
  if (value == null) throw FormatException(sentence);
  return value;
}

int? fleetParseCents(String raw) => parseAmountMinor(raw);

/// [flagEmpty]: amber and "Not filled in" while there is no day — the look
/// HR's record and Web Management use, from `notFilledIn`.
Widget fleetCalendarDay(
  TextEditingController controller,
  String label, {
  required DisplayFormats formats,
  bool enabled = true,
  bool flagEmpty = false,
}) {
  return Padding(
    padding: const EdgeInsets.only(top: 12),
    child: CalendarDayField(
      controller: controller,
      label: label,
      formats: formats,
      enabled: enabled,
      flagEmpty: flagEmpty,
    ),
  );
}

/// [flagEmpty]: amber and "Not filled in" while the box is empty, through
/// `notFilledIn` — the one look HR's record and Web Management share. The box
/// listens to its own controller so the amber clears as the first letter is
/// typed, without the card having to rebuild.
Widget fleetField(
  TextEditingController controller,
  String label, {
  bool enabled = true,
  String? helper,
  TextInputType? keyboard,
  int minLines = 1,
  int maxLines = 1,
  bool flagEmpty = false,
}) {
  final decoration = InputDecoration(
    labelText: label,
    helperText: helper,
    helperMaxLines: 2,
  );

  Widget box(InputDecoration decoration) => TextField(
    controller: controller,
    enabled: enabled,
    keyboardType: keyboard,
    minLines: minLines,
    maxLines: maxLines,
    decoration: decoration,
  );

  return Padding(
    padding: const EdgeInsets.only(top: 12),
    child: flagEmpty
        ? ValueListenableBuilder<TextEditingValue>(
            valueListenable: controller,
            builder: (context, value, _) => box(
              notFilledIn(
                context,
                decoration,
                empty: value.text.trim().isEmpty,
              ),
            ),
          )
        : box(decoration),
  );
}

const fleetBodyTypes = <(String, String)>[
  ('van', 'Van'),
  ('truck', 'Truck'),
  ('car', 'Car'),
  ('suv', 'SUV'),
  ('trailer', 'Trailer'),
  ('other', 'Other'),
];

const fleetFuelTypes = <(String, String)>[
  ('gas', 'Gas'),
  ('diesel', 'Diesel'),
  ('hybrid', 'Hybrid'),
  ('electric', 'Electric'),
  ('other', 'Other'),
];

const fleetStatuses = <(String, String)>[
  ('active', 'Active'),
  ('in_shop', 'In shop'),
  ('out_of_service', 'Out of service'),
  ('sold', 'Sold'),
  ('returned', 'Returned'),
];

const fleetRatePeriods = <(String, String)>[
  ('day', 'Day'),
  ('week', 'Week'),
  ('month', 'Month'),
];

const fleetRentalTerms = <(String, String)>[
  ('due_on_receipt', 'Due on receipt'),
  ('net_15', 'Net 15'),
  ('net_30', 'Net 30'),
  ('net_45', 'Net 45'),
  ('net_60', 'Net 60'),
];

const fleetPaymentMethods = <(String, String)>[
  ('cash', 'Cash'),
  ('check', 'Check'),
  ('credit_card', 'Credit card'),
  ('debit_card', 'Debit card'),
  ('ach', 'ACH'),
  ('net_30', 'Net 30'),
  ('other', 'Other'),
];

const fleetPremiumPeriods = <(String, String)>[
  ('month', 'Month'),
  ('six_months', 'Six months'),
  ('year', 'Year'),
];

const fleetServiceKinds = <(String, String)>[
  ('oil', 'Oil'),
  ('tires', 'Tires'),
  ('brakes', 'Brakes'),
  ('repair', 'Repair'),
  ('inspection', 'Inspection'),
  ('registration', 'Registration'),
  ('other', 'Other'),
];

const fleetPaymentCategories = <(String, String)>[
  ('purchase', 'Purchase'),
  ('loan', 'Loan'),
  ('registration', 'Registration'),
  ('insurance', 'Insurance'),
  ('maintenance', 'Maintenance'),
  ('rental', 'Rental'),
  ('toll', 'Toll'),
  ('deposit', 'Deposit'),
  ('other', 'Other'),
];

String fleetPairLabel(List<(String, String)> pairs, String? value) {
  for (final pair in pairs) {
    if (pair.$1 == value) return pair.$2;
  }
  return value ?? '';
}

/// Same degrade as the server, so the preview cannot invent a fourth name.
String fleetPreviewName({
  required String year,
  required String make,
  required String model,
  required String plate,
  required String vin,
}) {
  final parts = [
    if (year.trim().isNotEmpty) year.trim(),
    if (make.trim().isNotEmpty) make.trim(),
    if (model.trim().isNotEmpty) model.trim(),
  ];
  final description = parts.isEmpty ? null : parts.join(' ');
  final p = plate.trim();
  final v = vin.trim();
  if (description != null && p.isNotEmpty) return '$description · $p';
  if (description != null) return description;
  if (p.isNotEmpty) return p;
  if (v.isNotEmpty) return v;
  return 'Unidentified vehicle';
}
