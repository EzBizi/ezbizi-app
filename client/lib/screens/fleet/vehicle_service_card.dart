import '../../widgets/formatted_notes.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import 'vehicle_card.dart';
import 'vehicle_papers.dart';

/// Visits newest first; logging one can roll odometer and next-due (spec §6.4).
class VehicleServiceCard extends StatefulWidget {
  const VehicleServiceCard({
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
  State<VehicleServiceCard> createState() => _VehicleServiceCardState();
}

class _VehicleServiceCardState extends State<VehicleServiceCard> {
  bool _logging = false;
  String _kind = 'oil';
  final _occurredOn = TextEditingController();
  final _odometer = TextEditingController();
  final _performedBy = TextEditingController();
  final _notes = FormattedNotesController();
  final _nextOn = TextEditingController();
  final _nextMiles = TextEditingController();
  bool _busy = false;
  String? _error;

  bool get _canEdit => widget.session.me!.can('fleet_management.edit');

  List<Map<String, dynamic>> get _visits {
    final rows = [...fleetMaps(widget.vehicle['service_visits'])];
    rows.sort(
      (a, b) => fleetDay(
        b['occurred_on'] as String?,
      ).compareTo(fleetDay(a['occurred_on'] as String?)),
    );
    return rows;
  }

  @override
  void initState() {
    super.initState();
    _occurredOn.text = fleetToday();
  }

  @override
  void dispose() {
    for (final c in [
      _occurredOn,
      _odometer,
      _performedBy,
      _notes,
      _nextOn,
      _nextMiles,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    int? odometer;
    int? nextMiles;
    try {
      odometer = fleetParseInt(
        _odometer.text,
        'Odometer has to be a whole number of miles.',
      );
      nextMiles = fleetParseInt(
        _nextMiles.text,
        'Next service miles has to be a whole number.',
      );
    } on FormatException catch (e) {
      setState(() => _error = e.message);
      return;
    }

    if (_occurredOn.text.trim().isEmpty) {
      setState(() => _error = 'When did this visit happen?');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api
        .post('/vehicles/${widget.vehicle['id']}/service', {
          'kind': _kind,
          'occurred_on': _occurredOn.text.trim(),
          'odometer_miles': ?odometer,
          if (_performedBy.text.trim().isNotEmpty)
            'performed_by': _performedBy.text.trim(),
          if (_notes.text.trim().isNotEmpty) 'notes': _notes.markup.trim(),
          if (_nextOn.text.trim().isNotEmpty)
            'next_service_on': _nextOn.text.trim(),
          'next_service_miles': ?nextMiles,
        });

    if (!mounted) return;

    setState(() => _busy = false);

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    setState(() => _logging = false);
    // Emptied once saved, so the next entry does not start as a copy of
    // this one and get saved twice (audit 2026-11-27).
    for (final c in [_odometer, _performedBy, _notes, _nextOn, _nextMiles]) {
      c.clear();
    }
    await widget.onReload();
  }

  @override
  Widget build(BuildContext context) {
    final visits = _visits;

    return FleetSectionCard(
      title: 'Service',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (visits.isEmpty)
            Text(
              'No visits logged.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          for (final visit in visits) _visit(context, visit),
          if (_canEdit && !_logging) ...[
            const SizedBox(height: 12),
            Align(
              alignment: Alignment.centerLeft,
              child: FilledButton.tonal(
                onPressed: () => setState(() => _logging = true),
                child: const Text('Log a visit'),
              ),
            ),
          ],
          if (_logging) _form(),
          if (_error != null) ...[
            const SizedBox(height: 14),
            ErrorNote(_error!),
          ],
        ],
      ),
    );
  }

  Widget _visit(BuildContext context, Map<String, dynamic> visit) {
    final lines = <String>[
      [
        fleetPairLabel(fleetServiceKinds, visit['kind'] as String?),
        fleetDay(visit['occurred_on'] as String?),
        if (visit['odometer_miles'] != null) '${visit['odometer_miles']} mi',
      ].where((s) => s.isNotEmpty).join(' · '),
      if ((visit['performed_by'] as String?)?.isNotEmpty == true)
        'By ${visit['performed_by']}',
    ];
    final notes = visit['notes'] as String?;

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final line in lines)
            if (line.isNotEmpty)
              Text(line, style: Theme.of(context).textTheme.bodyMedium),
          if (notes != null && notes.isNotEmpty)
            FormattedNotesText(
              notes,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          if (visit['id'] != null) ...[
            const SizedBox(height: 8),
            VehiclePapers(
              key: ValueKey('papers-${visit['id']}'),
              api: widget.api,
              attachableType: 'vehicle_service_visits',
              attachableId: visit['id'] as String,
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
        SearchPickerField<String>(
          value: _kind,
          label: 'Kind',
          options: [
            for (final t in fleetServiceKinds)
              SearchOption(value: t.$1, label: t.$2),
          ],
          onChanged: _busy ? null : (v) => setState(() => _kind = v ?? _kind),
        ),
        fleetCalendarDay(
          _occurredOn,
          'Occurred on',
          formats: widget.session.me!.display,
          enabled: !_busy,
        ),
        fleetField(
          _odometer,
          'Odometer (miles)',
          enabled: !_busy,
          keyboard: TextInputType.number,
        ),
        fleetField(_performedBy, 'Who did it', enabled: !_busy),
        FormattedNotesField(controller: _notes, minLines: 2),
        fleetCalendarDay(
          _nextOn,
          'Next service on',
          formats: widget.session.me!.display,
          enabled: !_busy,
        ),
        fleetField(
          _nextMiles,
          'Next service miles',
          enabled: !_busy,
          keyboard: TextInputType.number,
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          alignment: WrapAlignment.end,
          children: [
            TextButton(
              onPressed: _busy ? null : () => setState(() => _logging = false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: _busy ? null : _save,
              child: const Text('Log visit'),
            ),
          ],
        ),
      ],
    );
  }
}
