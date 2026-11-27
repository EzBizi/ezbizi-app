import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import 'vehicle_card.dart';
import 'vehicle_papers.dart';

/// Open period prominent, older periods below. Kind change starts a new row.
class VehicleOwnershipCard extends StatefulWidget {
  const VehicleOwnershipCard({
    super.key,
    required this.api,
    required this.session,
    required this.vehicle,
    required this.onReload,
  });

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> vehicle;
  final Future<void> Function() onReload;

  @override
  State<VehicleOwnershipCard> createState() => _VehicleOwnershipCardState();
}

class _VehicleOwnershipCardState extends State<VehicleOwnershipCard> {
  bool _starting = false;
  String? _kind;
  final _counterparty = TextEditingController();
  final _registeredOwner = TextEditingController();
  final _rentalAgreementNumber = TextEditingController();
  final _mileageAllowance = TextEditingController();
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
  String? _rentalPeriod;
  String? _rentalTerms;
  String? _rentalMethod;
  bool _busy = false;
  String? _error;

  bool get _canEdit => widget.session.me!.can('fleet_management.edit');
  bool get _financials => widget.session.me!.can('fleet_management.financials');

  String get _status => widget.vehicle['status'] as String? ?? '';

  List<Map<String, dynamic>> get _periods {
    final rows = fleetMaps(widget.vehicle['ownership_periods']);
    if (rows.isNotEmpty) return rows;
    final open = fleetMap(widget.vehicle['ownership']);
    return open == null ? const [] : [open];
  }

  @override
  void initState() {
    super.initState();
    _startedOn.text = fleetToday();
  }

  @override
  void dispose() {
    for (final c in [
      _counterparty,
      _registeredOwner,
      _rentalAgreementNumber,
      _mileageAllowance,
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

  Future<void> _save() async {
    final kind = _kind;
    if (kind == null) return;

    // Parsed before the map, so a bad number shows an error instead of
    // throwing out of Start period (audit 2026-11-27).
    int? allowance;
    try {
      if (_kind == 'rented' && _mileageAllowance.text.trim().isNotEmpty) {
        allowance = fleetParseInt(
          _mileageAllowance.text,
          'Mileage allowance has to be a whole number of miles.',
        );
      }
    } on FormatException catch (e) {
      setState(() => _error = e.message);
      return;
    }

    final ownership = <String, dynamic>{
      'kind': kind,
      if (_counterparty.text.trim().isNotEmpty)
        'counterparty': _counterparty.text.trim(),
      if (_registeredOwner.text.trim().isNotEmpty)
        'registered_owner': _registeredOwner.text.trim(),
      if (_kind == 'rented' && _rentalAgreementNumber.text.trim().isNotEmpty)
        'rental_agreement_number': _rentalAgreementNumber.text.trim(),
      'mileage_allowance': ?allowance,
      if (_startedOn.text.trim().isNotEmpty)
        'started_on': _startedOn.text.trim(),
    };

    if (_financials) {
      try {
        if (kind == 'owned') {
          final purchase = fleetParseCents(_purchasePrice.text);
          final payment = fleetParseCents(_loanPayment.text);
          final transport = fleetParseCents(_transport.text);
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
          final rate = fleetParseCents(_rentalRate.text);
          final deposit = fleetParseCents(_rentalDeposit.text);
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

    final response = await widget.api.post(
      '/vehicles/${widget.vehicle['id']}/ownership',
      ownership,
    );

    if (!mounted) return;

    setState(() => _busy = false);

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    setState(() => _starting = false);
    await widget.onReload();
  }

  @override
  Widget build(BuildContext context) {
    final periods = [..._periods]
      ..sort((a, b) {
        final aOpen = a['ended_on'] == null ? 0 : 1;
        final bOpen = b['ended_on'] == null ? 0 : 1;
        if (aOpen != bOpen) return aOpen.compareTo(bOpen);
        return fleetDay(
          b['started_on'] as String?,
        ).compareTo(fleetDay(a['started_on'] as String?));
      });
    final ended = _status == 'sold' || _status == 'returned';

    return FleetSectionCard(
      title: 'Ownership',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (periods.isEmpty)
            Text(
              'No ownership periods.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          for (final period in periods) _period(context, period),
          if (_canEdit && !ended && !_starting) ...[
            const SizedBox(height: 12),
            Align(
              alignment: Alignment.centerLeft,
              child: FilledButton.tonal(
                onPressed: () => setState(() => _starting = true),
                child: const Text('Start a new period'),
              ),
            ),
          ],
          if (_starting) _form(),
          if (_error != null) ...[
            const SizedBox(height: 14),
            ErrorNote(_error!),
          ],
        ],
      ),
    );
  }

  Widget _period(BuildContext context, Map<String, dynamic> period) {
    final open = period['ended_on'] == null;
    final kind = fleetKindLabel(period['kind'] as String?);
    final lines = <String>[
      [
        kind,
        if ((period['counterparty'] as String?)?.isNotEmpty == true)
          period['counterparty'],
      ].where((s) => '$s'.isNotEmpty).join(' · '),
      [
        fleetDay(period['started_on'] as String?),
        if (!open) 'to ${fleetDay(period['ended_on'] as String?)}',
        if (period['ended_reason'] != null) period['ended_reason'],
      ].where((s) => '$s'.isNotEmpty).join(' · '),
      if (_financials && period['kind'] == 'owned') ...[
        if (fleetCents(period['purchase_price_cents']) != null)
          'Purchase ${fleetCents(period['purchase_price_cents'])}',
        if ((period['lienholder'] as String?)?.isNotEmpty == true)
          'Lienholder ${period['lienholder']}',
      ],
      if ((period['registered_owner'] as String?)?.isNotEmpty == true)
        'Registered to ${period['registered_owner']}',
      if (_financials && period['kind'] == 'rented') ...[
        if (fleetCents(period['rental_rate_cents']) != null)
          'Rate ${fleetCents(period['rental_rate_cents'])} / ${period['rental_rate_period'] ?? ''}',
      ],
    ];

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            open ? 'Current' : 'Earlier',
            style: Theme.of(context).textTheme.labelSmall,
          ),
          const SizedBox(height: 4),
          for (final line in lines)
            if (line.isNotEmpty)
              Text(line, style: Theme.of(context).textTheme.bodyMedium),
          if (period['id'] != null) ...[
            const SizedBox(height: 8),
            VehiclePapers(
              api: widget.api,
              attachableType: 'vehicle_ownership_periods',
              attachableId: period['id'] as String,
              canEdit: _canEdit,
            ),
          ],
        ],
      ),
    );
  }

  Widget _form() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            ChoiceChip(
              label: const Text('Owned'),
              selected: _kind == 'owned',
              onSelected: _busy ? null : (_) => setState(() => _kind = 'owned'),
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
        fleetField(
          _counterparty,
          _kind == 'rented' ? 'Rented from' : 'Bought from',
          enabled: !_busy,
        ),
        // Who the title names, which is not always who we bought it from.
        fleetField(_registeredOwner, 'Registered owner', enabled: !_busy),
        if (_kind == 'rented') ...[
          fleetField(
            _rentalAgreementNumber,
            'Agreement number',
            enabled: !_busy,
          ),
          fleetField(
            _mileageAllowance,
            'Mileage allowance',
            enabled: !_busy,
            keyboard: TextInputType.number,
          ),
        ],
        fleetCalendarDay(
          _startedOn,
          'Started on',
          formats: widget.session.me!.display,
          enabled: !_busy,
        ),
        if (_financials && _kind == 'owned') ...[
          fleetField(
            _purchasePrice,
            'Purchase price',
            enabled: !_busy,
            keyboard: const TextInputType.numberWithOptions(decimal: true),
          ),
          fleetField(_lienholder, 'Lienholder', enabled: !_busy),
          fleetField(_loanAccount, 'Loan account', enabled: !_busy),
          fleetField(
            _loanPayment,
            'Monthly payment',
            enabled: !_busy,
            keyboard: const TextInputType.numberWithOptions(decimal: true),
          ),
          fleetCalendarDay(
            _loanPayoff,
            'Payoff date',
            formats: widget.session.me!.display,
            enabled: !_busy,
          ),
          fleetField(
            _transport,
            'Transport cost',
            enabled: !_busy,
            keyboard: const TextInputType.numberWithOptions(decimal: true),
          ),
        ],
        if (_financials && _kind == 'rented') ...[
          fleetField(
            _rentalRate,
            'Rental rate',
            enabled: !_busy,
            keyboard: const TextInputType.numberWithOptions(decimal: true),
          ),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            value: _rentalPeriod,
            label: 'Rate period',
            options: [
              for (final t in fleetRatePeriods)
                SearchOption(value: t.$1, label: t.$2),
            ],
            onChanged: _busy ? null : (v) => setState(() => _rentalPeriod = v),
          ),
          fleetField(
            _rentalDeposit,
            'Deposit',
            enabled: !_busy,
            keyboard: const TextInputType.numberWithOptions(decimal: true),
          ),
          fleetField(_rentalAccount, 'Account number', enabled: !_busy),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            value: _rentalTerms,
            label: 'Payment terms',
            options: [
              for (final t in fleetRentalTerms)
                SearchOption(value: t.$1, label: t.$2),
            ],
            onChanged: _busy ? null : (v) => setState(() => _rentalTerms = v),
          ),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            value: _rentalMethod,
            label: 'Payment method',
            options: [
              for (final t in fleetPaymentMethods)
                SearchOption(value: t.$1, label: t.$2),
            ],
            onChanged: _busy ? null : (v) => setState(() => _rentalMethod = v),
          ),
        ],
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          alignment: WrapAlignment.end,
          children: [
            TextButton(
              onPressed: _busy ? null : () => setState(() => _starting = false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: _busy || _kind == null ? null : _save,
              child: const Text('Start period'),
            ),
          ],
        ),
      ],
    );
  }
}
