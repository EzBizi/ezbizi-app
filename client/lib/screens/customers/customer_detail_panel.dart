import '../../core/phone_numbers.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/franchise_field.dart';
import '../../widgets/refused.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';

/// One customer, opened from the list.
///
/// The list is not a dead end. Detail, edit, deactivate, delete and moving the
/// owner all live here — the API already did them; this is the missing face.
/// Reassignment is a separate act (§5.2), not a field on the ordinary save.
class CustomerDetailPanel extends StatefulWidget {
  const CustomerDetailPanel({
    super.key,
    required this.api,
    required this.session,
    required this.id,
  });

  final ApiClient api;
  final Session session;
  final String id;

  @override
  State<CustomerDetailPanel> createState() => _CustomerDetailPanelState();
}

class _CustomerDetailPanelState extends State<CustomerDetailPanel> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _line1 = TextEditingController();
  final _line2 = TextEditingController();
  final _city = TextEditingController();
  final _state = TextEditingController();
  final _postal = TextEditingController();
  final _notes = TextEditingController();
  final _reason = TextEditingController();

  Map<String, dynamic>? _row;
  bool _loading = true;
  bool _busy = false;
  bool _reassigning = false;
  String? _error;
  String? _newOwnerId;

  Me get _me => widget.session.me!;

  String? get _editRefusal => _me.refusal('customers.edit', 'change a customer');
  String? get _deleteRefusal =>
      _me.refusal('customers.delete', 'delete a customer');

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _email.dispose();
    _line1.dispose();
    _line2.dispose();
    _city.dispose();
    _state.dispose();
    _postal.dispose();
    _notes.dispose();
    _reason.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/customers/${widget.id}');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.message;
      });
      return;
    }

    final row = Map<String, dynamic>.from(response.data as Map);
    _fill(row);

    setState(() {
      _row = row;
      _loading = false;
    });
  }

  void _fill(Map<String, dynamic> row) {
    _name.text = row['name'] as String? ?? '';
    // Loaded formatted, saved back through toE164 — the field never shows
    // the stored form and never sends the pretty one.
    _phone.text = formatPhoneNumber(row['phone'] as String?);
    _email.text = row['email'] as String? ?? '';
    _notes.text = row['notes'] as String? ?? '';

    final address = row['address'] as Map<String, dynamic>? ?? const {};
    _line1.text = address['line1'] as String? ?? '';
    _line2.text = address['line2'] as String? ?? '';
    _city.text = address['city'] as String? ?? '';
    _state.text = address['state'] as String? ?? '';
    _postal.text = address['postal_code'] as String? ?? '';
  }

  Future<void> _save({bool? active}) async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.patch('/customers/${widget.id}', {
      'name': _name.text.trim(),
      // Stored as E.164, never as the pretty form the field is showing.
      'phone': toE164(_phone.text),
      'email': _email.text.trim().isEmpty ? null : _email.text.trim(),
      'address_line1': _line1.text.trim().isEmpty ? null : _line1.text.trim(),
      'address_line2': _line2.text.trim().isEmpty ? null : _line2.text.trim(),
      'city': _city.text.trim().isEmpty ? null : _city.text.trim(),
      'state': _state.text.trim().isEmpty ? null : _state.text.trim().toUpperCase(),
      'postal_code': _postal.text.trim().isEmpty ? null : _postal.text.trim(),
      'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
      'is_active': ?active,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = _firstError(response);
      });
      return;
    }

    final row = Map<String, dynamic>.from(response.data as Map);
    _fill(row);

    setState(() {
      _row = row;
      _busy = false;
    });

    Navigator.of(context).pop(true);
  }

  Future<void> _reassign() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/customers/${widget.id}/owner', {
      'owning_franchise_id': _newOwnerId,
      if (_reason.text.trim().isNotEmpty) 'reason': _reason.text.trim(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = _firstError(response);
      });
      return;
    }

    Navigator.of(context).pop(true);
  }

  Future<void> _delete() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.delete('/customers/${widget.id}');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = _firstError(response);
      });
      return;
    }

    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final row = _row;
    final owner = row?['owning_franchise'] as Map<String, dynamic>?;
    final active = row?['is_active'] as bool? ?? true;
    final jobs = row?['jobs_count'] as int? ?? 0;
    final editable = _editRefusal == null && !_busy && !_loading;

    return SidePanel(
      title: row == null ? 'Customer' : row['name'] as String,
      subtitle: row == null
          ? null
          : [
              row['number'],
              owner?['name'],
            ].where((s) => s != null && '$s'.isNotEmpty).join(' · '),
      width: 520,
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        Refused(
          reason: _editRefusal,
          child: FilledButton(
            onPressed: editable ? () => _save() : null,
            child: const Text('Save'),
          ),
        ),
      ],
      child: _loading
          ? const Padding(
              padding: EdgeInsets.all(32),
              child: Center(child: CircularProgressIndicator()),
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (row != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 14),
                    child: Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        Tag(owner?['name'] as String? ?? 'Unassigned'),
                        if (!active) const Tag('Inactive', warn: true),
                      ],
                    ),
                  ),
                TextField(
                  controller: _name,
                  decoration: const InputDecoration(labelText: 'Name'),
                  enabled: editable,
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _phone,
                  decoration: const InputDecoration(labelText: 'Phone'),
                  keyboardType: TextInputType.phone,
                  inputFormatters: const [PhoneNumberInputFormatter()],
                  enabled: editable,
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _email,
                  decoration: const InputDecoration(labelText: 'Email'),
                  keyboardType: TextInputType.emailAddress,
                  enabled: editable,
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _line1,
                  decoration: const InputDecoration(labelText: 'Street address'),
                  enabled: editable,
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _line2,
                  decoration: const InputDecoration(labelText: 'Apartment, suite, etc.'),
                  enabled: editable,
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _city,
                  decoration: const InputDecoration(labelText: 'City'),
                  enabled: editable,
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: _state,
                        decoration: const InputDecoration(
                          labelText: 'State',
                          helperText: 'Two letters.',
                        ),
                        textCapitalization: TextCapitalization.characters,
                        enabled: editable,
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: TextField(
                        controller: _postal,
                        decoration: const InputDecoration(labelText: 'ZIP'),
                        keyboardType: TextInputType.streetAddress,
                        enabled: editable,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _notes,
                  decoration: const InputDecoration(labelText: 'Notes'),
                  minLines: 2,
                  maxLines: 5,
                  enabled: editable,
                ),
                const SizedBox(height: 20),
                Text(
                  'Accountability',
                  style: Theme.of(context).textTheme.titleSmall,
                ),
                const SizedBox(height: 8),
                Text(
                  'Moving who is accountable is a separate, audited step — not '
                  'something an ordinary save can do by accident.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                const SizedBox(height: 12),
                if (!_reassigning)
                  Align(
                    alignment: Alignment.centerLeft,
                    child: Refused(
                      reason: _editRefusal,
                      child: OutlinedButton(
                        onPressed: editable
                            ? () => setState(() => _reassigning = true)
                            : null,
                        child: const Text('Reassign owner'),
                      ),
                    ),
                  )
                else ...[
                  FranchiseField(
                    me: _me,
                    value: _newOwnerId,
                    label: 'New owning ${_me.organization.franchiseLabel}',
                    enabled: !_busy,
                    onChanged: (v) => setState(() => _newOwnerId = v),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _reason,
                    decoration: const InputDecoration(
                      labelText: 'Reason',
                      helperText: 'Optional. Kept on the audit record.',
                    ),
                    enabled: !_busy,
                  ),
                  const SizedBox(height: 12),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      OutlinedButton(
                        onPressed: _busy
                            ? null
                            : () => setState(() {
                                _reassigning = false;
                                _newOwnerId = null;
                              }),
                        child: const Text('Cancel'),
                      ),
                      FilledButton(
                        onPressed: (_busy || _newOwnerId == null)
                            ? null
                            : _reassign,
                        child: const Text('Hand over'),
                      ),
                    ],
                  ),
                ],
                const SizedBox(height: 20),
                Text(
                  'This record',
                  style: Theme.of(context).textTheme.titleSmall,
                ),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    Refused(
                      reason: _editRefusal,
                      child: OutlinedButton(
                        onPressed: editable
                            ? () => _save(active: !active)
                            : null,
                        child: Text(active ? 'Deactivate' : 'Reactivate'),
                      ),
                    ),
                    Refused(
                      reason: _deleteRefusal,
                      child: OutlinedButton(
                        onPressed:
                            (_deleteRefusal == null && !_busy && !_loading)
                            ? _delete
                            : null,
                        child: const Text('Delete'),
                      ),
                    ),
                  ],
                ),
                if (jobs > 0) ...[
                  const SizedBox(height: 8),
                  Text(
                    'This customer has $jobs ${jobs == 1 ? 'job' : 'jobs'}. '
                    'Deleting them would leave that work with no one attached '
                    'to it — deactivate instead.',
                    style: Theme.of(context).textTheme.bodySmall,
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

String _firstError(ApiResponse response) {
  final errors = response.body['errors'];

  if (errors is Map && errors.isNotEmpty) {
    final first = errors.values.first;
    if (first is List && first.isNotEmpty) return first.first.toString();
  }

  return response.message;
}
