import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/money.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';

/// Add is the same 75% pane as a vehicle, not a modal.
///
/// One save writes the vehicle and its first ownership period. Cancel clears
/// the selection and leaves nothing. Display name is a preview of what the
/// server will derive — it is not a field, because two names for one van is
/// how they drift.
class AddVehiclePane extends StatefulWidget {
  const AddVehiclePane({
    super.key,
    required this.api,
    required this.session,
    required this.onCancel,
    required this.onSaved,
    this.showBack = false,
  });

  final ApiClient api;
  final Session session;
  final VoidCallback onCancel;
  final ValueChanged<Map<String, dynamic>> onSaved;
  final bool showBack;

  @override
  State<AddVehiclePane> createState() => _AddVehiclePaneState();
}

class _AddVehiclePaneState extends State<AddVehiclePane> {
  final _year = TextEditingController();
  final _make = TextEditingController();
  final _model = TextEditingController();
  final _plate = TextEditingController();
  final _vin = TextEditingController();
  final _colour = TextEditingController();
  final _odometer = TextEditingController();
  final _nextServiceOn = TextEditingController();
  final _nextServiceMiles = TextEditingController();
  final _counterparty = TextEditingController();
  final _startedOn = TextEditingController();
  final _purchasePrice = TextEditingController();
  final _lienholder = TextEditingController();
  final _loanAccount = TextEditingController();
  final _loanPayment = TextEditingController();
  final _loanPayoff = TextEditingController();
  final _transport = TextEditingController();
  final _rentalRate = TextEditingController();
  final _rentalDeposit = TextEditingController();
  final _rentalAccount = TextEditingController();

  String? _companyId;
  String? _franchiseId;
  String _bodyType = 'van';
  String _fuelType = 'gas';
  String? _kind;
  String? _rentalPeriod;
  String? _rentalTerms;
  String? _rentalMethod;

  bool _busy = false;
  String? _error;

  Me get _me => widget.session.me!;
  bool get _financials => _me.can('fleet_management.financials');

  static const _bodyTypes = <(String, String)>[
    ('van', 'Van'),
    ('truck', 'Truck'),
    ('car', 'Car'),
    ('suv', 'SUV'),
    ('trailer', 'Trailer'),
    ('other', 'Other'),
  ];

  static const _fuelTypes = <(String, String)>[
    ('gas', 'Gas'),
    ('diesel', 'Diesel'),
    ('hybrid', 'Hybrid'),
    ('electric', 'Electric'),
    ('other', 'Other'),
  ];

  static const _periods = <(String, String)>[
    ('day', 'Day'),
    ('week', 'Week'),
    ('month', 'Month'),
  ];

  static const _terms = <(String, String)>[
    ('due_on_receipt', 'Due on receipt'),
    ('net_15', 'Net 15'),
    ('net_30', 'Net 30'),
    ('net_45', 'Net 45'),
    ('net_60', 'Net 60'),
  ];

  static const _methods = <(String, String)>[
    ('cash', 'Cash'),
    ('check', 'Check'),
    ('credit_card', 'Credit card'),
    ('debit_card', 'Debit card'),
    ('ach', 'ACH'),
    ('net_30', 'Net 30'),
    ('other', 'Other'),
  ];

  @override
  void initState() {
    super.initState();
    final companies = _me.companies;
    if (companies.length == 1) _companyId = companies.first.id;
    final places = _places;
    if (places.length == 1) _franchiseId = places.first.id;
    _startedOn.text = _today();
    for (final c in [_year, _make, _model, _plate, _vin]) {
      c.addListener(() => setState(() {}));
    }
  }

  @override
  void dispose() {
    for (final c in [
      _year,
      _make,
      _model,
      _plate,
      _vin,
      _colour,
      _odometer,
      _nextServiceOn,
      _nextServiceMiles,
      _counterparty,
      _startedOn,
      _purchasePrice,
      _lienholder,
      _loanAccount,
      _loanPayment,
      _loanPayoff,
      _transport,
      _rentalRate,
      _rentalDeposit,
      _rentalAccount,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  List<FranchiseSummary> get _places {
    final companyId = _companyId;
    if (companyId == null) return const [];
    return _me.franchises.where((f) => f.companyId == companyId).toList();
  }

  String _today() {
    final n = DateTime.now();
    final m = n.month.toString().padLeft(2, '0');
    final d = n.day.toString().padLeft(2, '0');
    return '${n.year}-$m-$d';
  }

  /// Same degrade as the server: year/make/model · plate → description →
  /// plate → VIN → Unidentified vehicle. Kept here so the preview cannot
  /// invent a fourth name.
  String get _preview {
    final year = _year.text.trim();
    final make = _make.text.trim();
    final model = _model.text.trim();
    final plate = _plate.text.trim();
    final vin = _vin.text.trim();
    final parts = [
      if (year.isNotEmpty) year,
      if (make.isNotEmpty) make,
      if (model.isNotEmpty) model,
    ];
    final description = parts.isEmpty ? null : parts.join(' ');
    if (description != null && plate.isNotEmpty) return '$description · $plate';
    if (description != null) return description;
    if (plate.isNotEmpty) return plate;
    if (vin.isNotEmpty) return vin;
    return 'Unidentified vehicle';
  }

  Future<void> _save() async {
    final companyId = _companyId;
    final kind = _kind;
    if (companyId == null || kind == null) return;

    final yearText = _year.text.trim();
    final year = yearText.isEmpty ? null : int.tryParse(yearText);
    if (yearText.isNotEmpty && year == null) {
      setState(() => _error = 'Year has to be a number.');
      return;
    }

    final odometerText = _odometer.text.trim();
    final odometer = odometerText.isEmpty ? null : int.tryParse(odometerText);
    if (odometerText.isNotEmpty && odometer == null) {
      setState(() => _error = 'Odometer has to be a whole number of miles.');
      return;
    }

    final nextMilesText = _nextServiceMiles.text.trim();
    final nextMiles = nextMilesText.isEmpty
        ? null
        : int.tryParse(nextMilesText);
    if (nextMilesText.isNotEmpty && nextMiles == null) {
      setState(() => _error = 'Next service miles has to be a whole number.');
      return;
    }

    int? cents(String raw) {
      try {
        return parseAmountMinor(raw);
      } on FormatException catch (e) {
        throw FormatException(e.message);
      }
    }

    final Map<String, dynamic> ownership = {
      'kind': kind,
      if (_counterparty.text.trim().isNotEmpty)
        'counterparty': _counterparty.text.trim(),
      if (_startedOn.text.trim().isNotEmpty)
        'started_on': _startedOn.text.trim(),
    };

    if (_financials) {
      try {
        if (kind == 'owned') {
          final purchase = cents(_purchasePrice.text);
          final payment = cents(_loanPayment.text);
          final transport = cents(_transport.text);
          if (purchase != null) ownership['purchase_price_cents'] = purchase;
          if (_lienholder.text.trim().isNotEmpty) {
            ownership['lienholder'] = _lienholder.text.trim();
          }
          if (_loanAccount.text.trim().isNotEmpty) {
            ownership['loan_account'] = _loanAccount.text.trim();
          }
          if (payment != null) {
            ownership['loan_monthly_payment_cents'] = payment;
          }
          if (_loanPayoff.text.trim().isNotEmpty) {
            ownership['loan_payoff_date'] = _loanPayoff.text.trim();
          }
          if (transport != null) ownership['transport_cost_cents'] = transport;
        } else {
          final rate = cents(_rentalRate.text);
          final deposit = cents(_rentalDeposit.text);
          if (rate != null) ownership['rental_rate_cents'] = rate;
          if (_rentalPeriod != null) {
            ownership['rental_rate_period'] = _rentalPeriod;
          }
          if (deposit != null) ownership['rental_deposit_cents'] = deposit;
          if (_rentalAccount.text.trim().isNotEmpty) {
            ownership['rental_account_number'] = _rentalAccount.text.trim();
          }
          if (_rentalTerms != null) {
            ownership['rental_payment_terms'] = _rentalTerms;
          }
          if (_rentalMethod != null) {
            ownership['rental_payment_method'] = _rentalMethod;
          }
        }
      } on FormatException catch (e) {
        setState(() => _error = e.message);
        return;
      }
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/vehicles', {
      'company_id': companyId,
      'franchise_id': ?_franchiseId,
      'year': ?year,
      if (_make.text.trim().isNotEmpty) 'make': _make.text.trim(),
      if (_model.text.trim().isNotEmpty) 'model': _model.text.trim(),
      if (_plate.text.trim().isNotEmpty) 'plate': _plate.text.trim(),
      if (_vin.text.trim().isNotEmpty) 'vin': _vin.text.trim(),
      if (_colour.text.trim().isNotEmpty) 'colour': _colour.text.trim(),
      'body_type': _bodyType,
      'fuel_type': _fuelType,
      'odometer_miles': ?odometer,
      if (_nextServiceOn.text.trim().isNotEmpty)
        'next_service_on': _nextServiceOn.text.trim(),
      'next_service_miles': ?nextMiles,
      'ownership': ownership,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    widget.onSaved(Map<String, dynamic>.from(response.data as Map));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final canSave = !_busy && _companyId != null && _kind != null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(widget.showBack ? 8 : 20, 12, 20, 8),
          child: Row(
            children: [
              if (widget.showBack)
                IconButton(
                  icon: const Icon(Icons.arrow_back),
                  tooltip: 'Back to the list',
                  onPressed: _busy ? null : widget.onCancel,
                ),
              Expanded(
                child: Text('New vehicle', style: theme.textTheme.titleMedium),
              ),
            ],
          ),
        ),
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (_me.companies.length > 1) ...[
                  DropdownButtonFormField<String>(
                    initialValue: _companyId,
                    isExpanded: true,
                    decoration: const InputDecoration(labelText: 'Company'),
                    items: [
                      for (final c in _me.companies)
                        DropdownMenuItem(value: c.id, child: Text(c.name)),
                    ],
                    onChanged: _busy
                        ? null
                        : (v) => setState(() {
                            _companyId = v;
                            if (_places.every((p) => p.id != _franchiseId)) {
                              _franchiseId = null;
                            }
                          }),
                  ),
                  const SizedBox(height: 12),
                ],
                DropdownButtonFormField<String>(
                  key: ValueKey(_companyId),
                  initialValue: _franchiseId ?? '__pool__',
                  isExpanded: true,
                  decoration: InputDecoration(
                    labelText: _me.organization.franchiseLabel,
                    helperText:
                        'Company pool is visible to everyone who can see this company.',
                    helperMaxLines: 3,
                  ),
                  items: [
                    const DropdownMenuItem(
                      value: '__pool__',
                      child: Text('Company pool'),
                    ),
                    for (final f in _places)
                      DropdownMenuItem(value: f.id, child: Text(f.name)),
                  ],
                  onChanged: _busy
                      ? null
                      : (v) => setState(
                          () => _franchiseId = v == '__pool__' ? null : v,
                        ),
                ),
                const SizedBox(height: 16),
                _card(
                  context,
                  title: 'Identity',
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text(
                        'Will be listed as $_preview',
                        style: theme.textTheme.bodyMedium,
                      ),
                      const SizedBox(height: 12),
                      _field(_year, 'Year', keyboard: TextInputType.number),
                      _field(_make, 'Make'),
                      _field(_model, 'Model'),
                      _field(_plate, 'Plate'),
                      _field(_vin, 'VIN'),
                      _field(_colour, 'Colour'),
                      const SizedBox(height: 12),
                      DropdownButtonFormField<String>(
                        initialValue: _bodyType,
                        isExpanded: true,
                        decoration: const InputDecoration(
                          labelText: 'Body type',
                        ),
                        items: [
                          for (final t in _bodyTypes)
                            DropdownMenuItem(value: t.$1, child: Text(t.$2)),
                        ],
                        onChanged: _busy
                            ? null
                            : (v) => setState(() => _bodyType = v ?? _bodyType),
                      ),
                      const SizedBox(height: 12),
                      DropdownButtonFormField<String>(
                        initialValue: _fuelType,
                        isExpanded: true,
                        decoration: const InputDecoration(
                          labelText: 'Fuel type',
                        ),
                        items: [
                          for (final t in _fuelTypes)
                            DropdownMenuItem(value: t.$1, child: Text(t.$2)),
                        ],
                        onChanged: _busy
                            ? null
                            : (v) => setState(() => _fuelType = v ?? _fuelType),
                      ),
                      const SizedBox(height: 12),
                      _field(
                        _odometer,
                        'Odometer (miles)',
                        keyboard: TextInputType.number,
                      ),
                      _field(
                        _nextServiceOn,
                        'Next service on',
                        helper: 'A calendar day — 2026-09-01.',
                      ),
                      _field(
                        _nextServiceMiles,
                        'Next service miles',
                        keyboard: TextInputType.number,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                _card(
                  context,
                  title: 'How do we hold it?',
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          ChoiceChip(
                            label: const Text('Owned'),
                            selected: _kind == 'owned',
                            onSelected: _busy
                                ? null
                                : (_) => setState(() => _kind = 'owned'),
                          ),
                          ChoiceChip(
                            label: const Text('Rented'),
                            selected: _kind == 'rented',
                            onSelected: _busy
                                ? null
                                : (_) => setState(() => _kind = 'rented'),
                          ),
                        ],
                      ),
                      const SizedBox(height: 12),
                      _field(
                        _counterparty,
                        _kind == 'rented' ? 'Rented from' : 'Bought from',
                      ),
                      _field(
                        _startedOn,
                        'Started on',
                        helper: 'A calendar day — 2026-03-08.',
                      ),
                      if (_financials && _kind == 'owned') ...[
                        _field(
                          _purchasePrice,
                          'Purchase price',
                          helper:
                              'In dollars — 25000.00. Stored as whole cents.',
                          keyboard: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                        ),
                        _field(_lienholder, 'Lienholder'),
                        _field(_loanAccount, 'Loan account'),
                        _field(
                          _loanPayment,
                          'Monthly payment',
                          keyboard: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                        ),
                        _field(_loanPayoff, 'Payoff date'),
                        _field(
                          _transport,
                          'Transport cost',
                          keyboard: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                        ),
                      ],
                      if (_financials && _kind == 'rented') ...[
                        _field(
                          _rentalRate,
                          'Rental rate',
                          helper: 'In dollars. Stored as whole cents.',
                          keyboard: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                        ),
                        const SizedBox(height: 12),
                        DropdownButtonFormField<String>(
                          initialValue: _rentalPeriod,
                          isExpanded: true,
                          decoration: const InputDecoration(
                            labelText: 'Rate period',
                          ),
                          items: [
                            for (final t in _periods)
                              DropdownMenuItem(value: t.$1, child: Text(t.$2)),
                          ],
                          onChanged: _busy
                              ? null
                              : (v) => setState(() => _rentalPeriod = v),
                        ),
                        const SizedBox(height: 12),
                        _field(
                          _rentalDeposit,
                          'Deposit',
                          keyboard: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                        ),
                        _field(_rentalAccount, 'Account number'),
                        const SizedBox(height: 12),
                        DropdownButtonFormField<String>(
                          initialValue: _rentalTerms,
                          isExpanded: true,
                          decoration: const InputDecoration(
                            labelText: 'Payment terms',
                          ),
                          items: [
                            for (final t in _terms)
                              DropdownMenuItem(value: t.$1, child: Text(t.$2)),
                          ],
                          onChanged: _busy
                              ? null
                              : (v) => setState(() => _rentalTerms = v),
                        ),
                        const SizedBox(height: 12),
                        DropdownButtonFormField<String>(
                          initialValue: _rentalMethod,
                          isExpanded: true,
                          decoration: const InputDecoration(
                            labelText: 'Payment method',
                          ),
                          items: [
                            for (final t in _methods)
                              DropdownMenuItem(value: t.$1, child: Text(t.$2)),
                          ],
                          onChanged: _busy
                              ? null
                              : (v) => setState(() => _rentalMethod = v),
                        ),
                      ],
                    ],
                  ),
                ),
                if (_error != null) ...[
                  const SizedBox(height: 14),
                  ErrorNote(_error!),
                ],
              ],
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            alignment: WrapAlignment.end,
            children: [
              TextButton(
                onPressed: _busy ? null : widget.onCancel,
                child: const Text('Cancel'),
              ),
              FilledButton(
                onPressed: canSave ? _save : null,
                child: const Text('Save'),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _card(
    BuildContext context, {
    required String title,
    required Widget child,
  }) {
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

  Widget _field(
    TextEditingController controller,
    String label, {
    String? helper,
    TextInputType? keyboard,
  }) {
    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: TextField(
        controller: controller,
        enabled: !_busy,
        keyboardType: keyboard,
        decoration: InputDecoration(
          labelText: label,
          helperText: helper,
          helperMaxLines: 2,
        ),
      ),
    );
  }
}
