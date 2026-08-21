import 'package:flutter/material.dart';

import '../../core/money.dart';

/// Shared chrome and labels for the vehicle cards (spec §11).
class FleetSectionCard extends StatelessWidget {
  const FleetSectionCard({super.key, required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(title, style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 12),
            child,
          ],
        ),
      ),
    );
  }
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

String fleetToday() {
  final n = DateTime.now();
  final m = n.month.toString().padLeft(2, '0');
  final d = n.day.toString().padLeft(2, '0');
  return '${n.year}-$m-$d';
}

/// Calendar day, or the date part of an instant. Never parsed as UTC midnight.
String fleetDay(String? stamp) {
  if (stamp == null || stamp.isEmpty) return '';
  return stamp.split('T').first;
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

Widget fleetField(
  TextEditingController controller,
  String label, {
  bool enabled = true,
  String? helper,
  TextInputType? keyboard,
  int minLines = 1,
  int maxLines = 1,
}) {
  return Padding(
    padding: const EdgeInsets.only(top: 12),
    child: TextField(
      controller: controller,
      enabled: enabled,
      keyboardType: keyboard,
      minLines: minLines,
      maxLines: maxLines,
      decoration: InputDecoration(
        labelText: label,
        helperText: helper,
        helperMaxLines: 2,
      ),
    ),
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
