import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/money.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../inventory/item_pane.dart';
import 'service_photo.dart';

/// Open a service: fields, square photo, delete.
class ServicePane extends StatefulWidget {
  const ServicePane({
    super.key,
    required this.api,
    required this.session,
    required this.service,
    required this.onBack,
    required this.onChanged,
    required this.onDeleted,
    this.showBack = false,
  });

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> service;
  final VoidCallback onBack;
  final ValueChanged<Map<String, dynamic>> onChanged;
  final VoidCallback onDeleted;
  final bool showBack;

  @override
  State<ServicePane> createState() => _ServicePaneState();
}

class _ServicePaneState extends State<ServicePane> {
  late Map<String, dynamic> _service = widget.service;
  late final _name = TextEditingController(
    text: widget.service['name'] as String? ?? '',
  );
  late final _description = TextEditingController(
    text: widget.service['description'] as String? ?? '',
  );
  late final _price = TextEditingController(
    text: _priceText(widget.service['price_minor']),
  );
  String? _groupId;
  String? _labelId;
  List<Map<String, dynamic>> _groups = [];
  List<Map<String, dynamic>> _labels = [];
  bool _taxonomyReady = false;
  bool _busy = false;
  String? _error;

  bool get _canEdit => widget.session.me!.can('services.edit');
  bool get _canDelete => widget.session.me!.can('services.delete');

  @override
  void initState() {
    super.initState();
    _groupId = inventoryMap(widget.service['group'])?['id'] as String?;
    _labelId = inventoryMap(widget.service['label'])?['id'] as String?;
    _loadTaxonomy();
  }

  @override
  void didUpdateWidget(covariant ServicePane oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.service != widget.service) {
      _service = widget.service;
      _name.text = widget.service['name'] as String? ?? '';
      _description.text = widget.service['description'] as String? ?? '';
      _price.text = _priceText(widget.service['price_minor']);
      _groupId = inventoryMap(widget.service['group'])?['id'] as String?;
      _labelId = inventoryMap(widget.service['label'])?['id'] as String?;
    }
  }

  @override
  void dispose() {
    _name.dispose();
    _description.dispose();
    _price.dispose();
    super.dispose();
  }

  String _priceText(dynamic cents) {
    if (cents is! num) return '';
    return (cents / 100).toStringAsFixed(2);
  }

  Future<void> _loadTaxonomy() async {
    final groups = await widget.api.get('/services/groups');
    final labels = await widget.api.get('/services/labels');
    if (!mounted) return;
    setState(() {
      if (groups.ok) _groups = inventoryRows(groups.data);
      if (labels.ok) _labels = inventoryRows(labels.data);
      _taxonomyReady = true;
    });
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) return;

    int? cents;
    try {
      cents = parseAmountMinor(_price.text);
    } on FormatException catch (e) {
      setState(() => _error = e.message);
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final id = _service['id'];
    final response = await widget.api.patch('/services/$id', {
      'name': _name.text.trim(),
      'description': _description.text.trim().isEmpty
          ? null
          : _description.text.trim(),
      'price_minor': cents,
      'group_id': _groupId,
      'label_id': _labelId,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    setState(() {
      _busy = false;
      _service = Map<String, dynamic>.from(response.data as Map);
    });
    widget.onChanged(_service);
  }

  Future<void> _delete() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final id = _service['id'];
    final response = await widget.api.delete('/services/$id');
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    widget.onDeleted();
  }

  Future<void> _reload() async {
    final id = _service['id'];
    final response = await widget.api.get('/services/$id');
    if (!mounted || !response.ok) return;
    final row = Map<String, dynamic>.from(response.data as Map);
    setState(() => _service = row);
    widget.onChanged(row);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final id = _service['id'] as String?;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        paneHeader(
          context,
          _service['name'] as String? ?? 'Service',
          showBack: widget.showBack,
          onBack: _busy ? null : widget.onBack,
          ellipsis: true,
        ),
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  [
                    _service['number'],
                    inventoryOwnerLabel(
                      _service,
                      widget.session.me!.organization.name,
                    ),
                    inventoryPlaceLabel(_service),
                  ].where((p) => p != null && '$p'.isNotEmpty).join(' · '),
                  style: theme.textTheme.bodyMedium,
                ),
                if (_error != null) ...[
                  const SizedBox(height: 14),
                  ErrorNote(_error!),
                ],
                const SizedBox(height: 16),
                if (id != null)
                  ServicePhoto(
                    api: widget.api,
                    serviceId: id,
                    photo: inventoryMap(_service['photo']),
                    canEdit: _canEdit,
                    onChanged: _reload,
                  ),
                const SizedBox(height: 16),
                TextField(
                  controller: _name,
                  enabled: _canEdit && !_busy,
                  decoration: const InputDecoration(
                    labelText: 'Name of service',
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _description,
                  enabled: _canEdit && !_busy,
                  maxLines: 4,
                  decoration: const InputDecoration(labelText: 'Description'),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _price,
                  enabled: _canEdit && !_busy,
                  keyboardType: const TextInputType.numberWithOptions(
                    decimal: true,
                  ),
                  decoration: const InputDecoration(labelText: 'Price'),
                ),
                const SizedBox(height: 12),
                if (_taxonomyReady)
                  SearchPickerField<String?>(
                    key: ValueKey('group-$_groupId'),
                    value: _groupId,
                    label: 'Group',
                    options: [
                      const SearchOption(value: null, label: 'No group'),
                      for (final row in _groups)
                        SearchOption(
                          value: row['id'] as String?,
                          label: row['name'] as String? ?? '',
                        ),
                    ],
                    onChanged: !_canEdit || _busy
                        ? null
                        : (v) => setState(() => _groupId = v),
                  ),
                const SizedBox(height: 12),
                if (_taxonomyReady)
                  SearchPickerField<String?>(
                    key: ValueKey('label-$_labelId'),
                    value: _labelId,
                    label: 'Label',
                    options: [
                      const SearchOption(value: null, label: 'No label'),
                      for (final row in _labels)
                        SearchOption(
                          value: row['id'] as String?,
                          label: row['name'] as String? ?? '',
                        ),
                    ],
                    onChanged: !_canEdit || _busy
                        ? null
                        : (v) => setState(() => _labelId = v),
                  ),
                if (_canEdit) ...[
                  const SizedBox(height: 16),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: FilledButton(
                      onPressed: _busy ? null : _save,
                      child: const Text('Save'),
                    ),
                  ),
                ],
                // Set apart from Save and in the error colour, so it is not
                // read as the next step (deep dive 2026-11-02 #11).
                if (_canDelete) ...[
                  const SizedBox(height: 32),
                  const Divider(),
                  const SizedBox(height: 8),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton.icon(
                      icon: const Icon(Icons.delete_outline),
                      style: TextButton.styleFrom(
                        foregroundColor: Theme.of(context).colorScheme.error,
                      ),
                      onPressed: _busy ? null : _delete,
                      label: const Text('Delete service'),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ],
    );
  }
}
