import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import 'inventory_place_fields.dart';
import 'item_pane.dart';

/// Add a vendor in the 75% pane, not a modal.
class AddVendorPane extends StatefulWidget {
  const AddVendorPane({
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
  State<AddVendorPane> createState() => _AddVendorPaneState();
}

class _AddVendorPaneState extends State<AddVendorPane> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  String? _companyId;
  String? _franchiseId;
  bool _busy = false;
  String? _error;

  Me get _me => widget.session.me!;

  bool get _atOrganization => _me.scope.type == 'organization';

  @override
  void initState() {
    super.initState();
    final place = inventoryPlaceFromScope(_me);
    _companyId = place.companyId;
    _franchiseId = place.franchiseId;
  }

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _email.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) return;
    if (_companyId == null && !_atOrganization) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/inventory/vendors', {
      'company_id': ?_companyId,
      'franchise_id': ?_franchiseId,
      'name': _name.text.trim(),
      if (_phone.text.trim().isNotEmpty) 'phone': _phone.text.trim(),
      if (_email.text.trim().isNotEmpty) 'email': _email.text.trim(),
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
        _name.text.trim().isNotEmpty &&
        (_companyId != null || _atOrganization);

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
                  tooltip: 'Back',
                  onPressed: _busy ? null : widget.onCancel,
                ),
              Expanded(
                child: Text('New vendor', style: theme.textTheme.titleMedium),
              ),
            ],
          ),
        ),
        Expanded(
          child: SingleChildScrollView(
            clipBehavior: Clip.none,
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                InventoryPlaceFields(
                  me: _me,
                  companyId: _companyId,
                  franchiseId: _franchiseId,
                  enabled: !_busy,
                  onCompanyChanged: (v) => setState(() {
                    _companyId = v;
                    _franchiseId = null;
                  }),
                  onFranchiseChanged: (v) => setState(() => _franchiseId = v),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _name,
                  enabled: !_busy,
                  decoration: const InputDecoration(labelText: 'Name'),
                  onChanged: (_) => setState(() {}),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _phone,
                  enabled: !_busy,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(labelText: 'Phone'),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _email,
                  enabled: !_busy,
                  keyboardType: TextInputType.emailAddress,
                  decoration: const InputDecoration(labelText: 'Email'),
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
}
