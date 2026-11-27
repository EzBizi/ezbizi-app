import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import 'vehicle_card.dart';
import 'vehicle_papers.dart';

/// Open policy first, history below, papers on the period (spec §6.2).
class VehicleInsuranceCard extends StatefulWidget {
  const VehicleInsuranceCard({
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
  State<VehicleInsuranceCard> createState() => _VehicleInsuranceCardState();
}

class _VehicleInsuranceCardState extends State<VehicleInsuranceCard> {
  bool _starting = false;
  final _carrier = TextEditingController();
  final _policyNumber = TextEditingController();
  final _coverage = TextEditingController();
  final _startedOn = TextEditingController();
  final _expiresOn = TextEditingController();
  final _premium = TextEditingController();
  final _agent = TextEditingController();
  final _agentPhone = TextEditingController();
  final _listedDrivers = TextEditingController();
  String? _premiumPeriod;
  bool _busy = false;
  String? _error;

  bool get _canEdit => widget.session.me!.can('fleet_management.edit');
  bool get _financials => widget.session.me!.can('fleet_management.financials');

  List<Map<String, dynamic>> get _policies {
    final rows = fleetMaps(widget.vehicle['insurance_policies']);
    if (rows.isNotEmpty) return rows;
    final open = fleetMap(widget.vehicle['insurance']);
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
      _carrier,
      _policyNumber,
      _coverage,
      _startedOn,
      _expiresOn,
      _premium,
      _agent,
      _agentPhone,
      _listedDrivers,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (_carrier.text.trim().isEmpty) {
      setState(() => _error = 'A carrier is required.');
      return;
    }

    final body = <String, dynamic>{
      'carrier': _carrier.text.trim(),
      if (_policyNumber.text.trim().isNotEmpty)
        'policy_number': _policyNumber.text.trim(),
      if (_coverage.text.trim().isNotEmpty) 'coverage': _coverage.text.trim(),
      if (_startedOn.text.trim().isNotEmpty)
        'started_on': _startedOn.text.trim(),
      if (_expiresOn.text.trim().isNotEmpty)
        'expires_on': _expiresOn.text.trim(),
      if (_agent.text.trim().isNotEmpty) 'agent': _agent.text.trim(),
      if (_agentPhone.text.trim().isNotEmpty)
        'agent_phone': _agentPhone.text.trim(),
      if (_listedDrivers.text.trim().isNotEmpty)
        'listed_drivers': _listedDrivers.text.trim(),
    };

    if (_financials) {
      try {
        final premium = fleetParseCents(_premium.text);
        if (premium != null) body['premium_cents'] = premium;
        if (_premiumPeriod != null) body['premium_period'] = _premiumPeriod;
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
      '/vehicles/${widget.vehicle['id']}/insurance',
      body,
    );

    if (!mounted) return;

    setState(() => _busy = false);

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    setState(() => _starting = false);
    // Emptied once saved, so the next entry does not start as a copy of
    // this one and get saved twice (audit 2026-11-27).
    for (final c in [
      _carrier,
      _policyNumber,
      _coverage,
      _startedOn,
      _expiresOn,
      _premium,
      _agent,
      _agentPhone,
      _listedDrivers,
    ]) {
      c.clear();
    }
    await widget.onReload();
  }

  @override
  Widget build(BuildContext context) {
    final policies = [..._policies]
      ..sort((a, b) {
        final aOpen = a['ended_on'] == null ? 0 : 1;
        final bOpen = b['ended_on'] == null ? 0 : 1;
        if (aOpen != bOpen) return aOpen.compareTo(bOpen);
        return fleetDay(
          b['started_on'] as String?,
        ).compareTo(fleetDay(a['started_on'] as String?));
      });

    return FleetSectionCard(
      title: 'Insurance',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (policies.isEmpty)
            Text(
              'No policies yet.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          for (final policy in policies) _policy(context, policy),
          if (_canEdit && !_starting) ...[
            const SizedBox(height: 12),
            Align(
              alignment: Alignment.centerLeft,
              child: FilledButton.tonal(
                onPressed: () => setState(() => _starting = true),
                child: const Text('Start a new policy'),
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

  Widget _policy(BuildContext context, Map<String, dynamic> policy) {
    final open = policy['ended_on'] == null;
    final lines = <String>[
      [
        policy['carrier'],
        if ((policy['policy_number'] as String?)?.isNotEmpty == true)
          policy['policy_number'],
      ].where((s) => s != null && '$s'.isNotEmpty).join(' · '),
      if ((policy['coverage'] as String?)?.isNotEmpty == true)
        policy['coverage'] as String,
      [
        fleetDay(policy['started_on'] as String?),
        if ((policy['expires_on'] as String?) != null)
          'expires ${fleetDay(policy['expires_on'] as String?)}',
        if (!open) 'ended ${fleetDay(policy['ended_on'] as String?)}',
      ].where((s) => s.isNotEmpty).join(' · '),
      if (_financials && fleetCents(policy['premium_cents']) != null)
        'Premium ${fleetCents(policy['premium_cents'])} / ${fleetPairLabel(fleetPremiumPeriods, policy['premium_period'] as String?)}',
      if ((policy['agent'] as String?)?.isNotEmpty == true)
        [
          'Agent ${policy['agent']}',
          if ((policy['agent_phone'] as String?)?.isNotEmpty == true)
            policy['agent_phone'],
        ].join(' · '),
      if ((policy['listed_drivers'] as String?)?.isNotEmpty == true)
        'Drivers ${policy['listed_drivers']}',
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
          if (policy['id'] != null) ...[
            const SizedBox(height: 8),
            VehiclePapers(
              api: widget.api,
              attachableType: 'vehicle_insurance_policies',
              attachableId: policy['id'] as String,
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
        fleetField(_carrier, 'Carrier', enabled: !_busy),
        fleetField(_policyNumber, 'Policy number', enabled: !_busy),
        fleetField(
          _coverage,
          'Coverage',
          enabled: !_busy,
          minLines: 2,
          maxLines: 4,
        ),
        fleetCalendarDay(
          _startedOn,
          'Started on',
          formats: widget.session.me!.display,
          enabled: !_busy,
        ),
        fleetCalendarDay(
          _expiresOn,
          'Expires on',
          formats: widget.session.me!.display,
          enabled: !_busy,
        ),
        fleetField(_agent, 'Agent', enabled: !_busy),
        fleetField(
          _agentPhone,
          'Agent phone',
          enabled: !_busy,
          keyboard: TextInputType.phone,
        ),
        fleetField(
          _listedDrivers,
          'Listed drivers',
          enabled: !_busy,
          minLines: 2,
          maxLines: 4,
        ),
        if (_financials) ...[
          fleetField(
            _premium,
            'Premium',
            enabled: !_busy,
            keyboard: const TextInputType.numberWithOptions(decimal: true),
          ),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            value: _premiumPeriod,
            label: 'Premium period',
            options: [
              for (final t in fleetPremiumPeriods)
                SearchOption(value: t.$1, label: t.$2),
            ],
            onChanged: _busy ? null : (v) => setState(() => _premiumPeriod = v),
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
              onPressed: _busy ? null : _save,
              child: const Text('Start policy'),
            ),
          ],
        ),
      ],
    );
  }
}
