import '../../core/branch_options.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/money.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import 'vehicle_card.dart';

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

  bool get _atOrganization => widget.session.me!.scope.type == 'organization';

  bool get _showCompanyField => _me.offersCompanyChoice;

  bool get _showFranchiseField =>
      _me.offersFranchiseChoice && _companyId != null;

  @override
  void initState() {
    super.initState();
    _companyId = _me.filingCompanyId;
    _franchiseId = _me.filingFranchiseId;
    _startedOn.text = fleetToday();
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

  List<FranchiseSummary> get _places => _me.franchisesOf(_companyId);

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
    if (kind == null) return;
    if (companyId == null && !_atOrganization) return;

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
      'company_id': ?companyId,
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
    final canSave =
        !_busy &&
        _kind != null &&
        (_companyId != null || _atOrganization);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        paneHeader(
          context,
          'New vehicle',
          showBack: widget.showBack,
          onBack: _busy ? null : widget.onCancel,
        ),
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (_showCompanyField) ...[
                  SearchPickerField<String>(
                    value: _companyId ?? fleetOrgValue,
                    label: 'Company',
                    helperText: _companyId == null && _atOrganization
                          ? '$fleetGeneralLabel — the whole group can see it.'
                          : null,
                    helperMaxLines: 3,
                    options: [
                      if (_atOrganization)
                        SearchOption(value: fleetOrgValue, label: _me.organization.name),
                      for (final c in _me.companiesInView)
                        SearchOption(value: c.id, label: c.name),
                    ],
                    onChanged: _busy
                        ? null
                        : (v) => setState(() {
                            _companyId = v == fleetOrgValue ? null : v;
                            _franchiseId = null;
                          }),
                  ),
                  const SizedBox(height: 12),
                ],
                if (_showFranchiseField)
                  SearchPickerField<String>(
                    key: ValueKey(_companyId),
                    value: _franchiseId ?? fleetGeneralValue,
                    label: _me.organization.franchiseLabel,
                    helperText: '$fleetGeneralLabel is visible to everyone who can see this company.',
                    helperMaxLines: 3,
                    options: [
                      const SearchOption(value: fleetGeneralValue, label: fleetGeneralLabel),
                      for (final f in branchOptions(_places, _me.companies, activeOnly: false))
                        SearchOption(value: f.id, label: f.label),
                    ],
                    onChanged: _busy
                        ? null
                        : (v) => setState(
                            () => _franchiseId =
                                v == fleetGeneralValue ? null : v,
                          ),
                  ),
                const SizedBox(height: 16),
                FleetSectionCard(
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
                      SearchPickerField<String>(
                        value: _bodyType,
                        label: 'Body type',
                        options: [
                          for (final t in fleetBodyTypes)
                            SearchOption(value: t.$1, label: t.$2),
                        ],
                        onChanged: _busy
                            ? null
                            : (v) => setState(() => _bodyType = v ?? _bodyType),
                      ),
                      const SizedBox(height: 12),
                      SearchPickerField<String>(
                        value: _fuelType,
                        label: 'Fuel type',
                        options: [
                          for (final t in fleetFuelTypes)
                            SearchOption(value: t.$1, label: t.$2),
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
                      _day(_nextServiceOn, 'Next service on'),
                      _field(
                        _nextServiceMiles,
                        'Next service miles',
                        keyboard: TextInputType.number,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                FleetSectionCard(
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
                      if (_kind != null) ...[
                        const SizedBox(height: 12),
                        _field(
                          _counterparty,
                          _kind == 'rented' ? 'Rented from' : 'Bought from',
                        ),
                        _day(_startedOn, 'Started on'),
                      ],
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
                        _day(_loanPayoff, 'Payoff date'),
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
                        SearchPickerField<String>(
                          value: _rentalPeriod,
                          label: 'Rate period',
                          options: [
                            for (final t in fleetRatePeriods)
                              SearchOption(value: t.$1, label: t.$2),
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
                        SearchPickerField<String>(
                          value: _rentalTerms,
                          label: 'Payment terms',
                          options: [
                            for (final t in fleetRentalTerms)
                              SearchOption(value: t.$1, label: t.$2),
                          ],
                          onChanged: _busy
                              ? null
                              : (v) => setState(() => _rentalTerms = v),
                        ),
                        const SizedBox(height: 12),
                        SearchPickerField<String>(
                          value: _rentalMethod,
                          label: 'Payment method',
                          options: [
                            for (final t in fleetPaymentMethods)
                              SearchOption(value: t.$1, label: t.$2),
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
        paneFooter(
          onCancel: _busy ? null : widget.onCancel,
          onSave: canSave ? _save : null,
        ),
      ],
    );
  }

  Widget _day(TextEditingController controller, String label) =>
      fleetCalendarDay(
        controller,
        label,
        formats: _me.display,
        enabled: !_busy,
      );

  Widget _field(
    TextEditingController controller,
    String label, {
    String? helper,
    TextInputType? keyboard,
  }) => fleetField(
    controller,
    label,
    enabled: !_busy,
    helper: helper,
    keyboard: keyboard,
  );
}
