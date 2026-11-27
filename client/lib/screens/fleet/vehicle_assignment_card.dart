import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import 'vehicle_card.dart';

/// Current person plus history. Assign / Unassign only with `assign` (spec §9).
class VehicleAssignmentCard extends StatefulWidget {
  const VehicleAssignmentCard({
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
  State<VehicleAssignmentCard> createState() => _VehicleAssignmentCardState();
}

class _VehicleAssignmentCardState extends State<VehicleAssignmentCard> {
  List<Map<String, dynamic>> _drivers = const [];
  String? _pick;
  final _note = TextEditingController();
  bool _busy = false;
  String? _error;

  bool get _canAssign => widget.session.me!.can('fleet_management.assign');

  Map<String, dynamic>? get _open => fleetMap(widget.vehicle['assignment']);

  List<Map<String, dynamic>> get _history {
    final rows = fleetMaps(widget.vehicle['assignments']);
    if (rows.isNotEmpty) return rows;
    final open = _open;
    return open == null ? const [] : [open];
  }

  @override
  void initState() {
    super.initState();
    if (_canAssign) _loadDrivers();
  }

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _loadDrivers() async {
    final company = fleetMap(widget.vehicle['company']);
    final companyId = company?['id'] as String?;

    final response = await widget.api.get('/vehicles/drivers', {
      'company_id': ?companyId,
    });

    if (!mounted || !response.ok) return;

    setState(() {
      _drivers = [
        for (final row in (response.data as List? ?? const []))
          if (row is Map) Map<String, dynamic>.from(row),
      ];
    });
  }

  Future<void> _assign() async {
    final userId = _pick;
    if (userId == null) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post(
      '/vehicles/${widget.vehicle['id']}/assignments',
      {
        'user_id': userId,
        if (_note.text.trim().isNotEmpty) 'note': _note.text.trim(),
      },
    );

    if (!mounted) return;

    setState(() => _busy = false);

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    _note.clear();
    await widget.onReload();
  }

  Future<void> _unassign() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post(
      '/vehicles/${widget.vehicle['id']}/unassign',
      const {},
    );

    if (!mounted) return;

    setState(() => _busy = false);

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    await widget.onReload();
  }

  @override
  Widget build(BuildContext context) {
    final open = _open;
    final user = fleetMap(open?['user']);
    final name = user?['name'] as String?;
    final history = [..._history]
      ..sort((a, b) => fleetInstantDay(b['assigned_at'] as String?)
          .compareTo(fleetInstantDay(a['assigned_at'] as String?)));

    return FleetSectionCard(
      title: 'Assignment',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            name ?? 'Nobody has the keys',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          if (open != null) ...[
            const SizedBox(height: 4),
            Text(
              [
                if (fleetInstantDay(open['assigned_at'] as String?).isNotEmpty)
                  'Since ${fleetInstantDay(open['assigned_at'] as String?)}',
                if ((open['note'] as String?)?.isNotEmpty == true) open['note'],
              ].join(' · '),
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ],
          if (_canAssign) ...[
            const SizedBox(height: 12),
            SearchPickerField<String>(
              value: _pick,
              label: 'Person',
              hint: 'Choose somebody',
              options: [
                for (final d in _drivers)
                  SearchOption(
                    value: d['id'] as String,
                    label: d['name'] as String? ?? d['id'] as String,
                  ),
              ],
              onChanged: _busy ? null : (v) => setState(() => _pick = v),
            ),
            fleetField(_note, 'Note', enabled: !_busy),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                FilledButton(
                  onPressed: _busy || _pick == null ? null : _assign,
                  child: const Text('Assign'),
                ),
                if (open != null)
                  OutlinedButton(
                    onPressed: _busy ? null : _unassign,
                    child: const Text('Unassign'),
                  ),
              ],
            ),
          ],
          if (history.isNotEmpty) ...[
            const SizedBox(height: 16),
            Text('History', style: Theme.of(context).textTheme.titleMedium),
            for (final row in history)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  [
                    fleetMap(row['user'])?['name'] ?? 'Someone',
                    fleetInstantDay(row['assigned_at'] as String?),
                    if (row['released_at'] != null)
                      'to ${fleetInstantDay(row['released_at'] as String?)}'
                    else
                      'current',
                  ].where((s) => '$s'.isNotEmpty).join(' · '),
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              ),
          ],
          if (_error != null) ...[
            const SizedBox(height: 14),
            ErrorNote(_error!),
          ],
        ],
      ),
    );
  }
}
