import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import 'item_pane.dart';

/// Issue stock, or move it from one location to another, in the 75% pane,
/// posted on save.
class AddStockMovePane extends StatefulWidget {
  const AddStockMovePane.issue({
    super.key,
    required this.api,
    required this.session,
    required this.onCancel,
    required this.onSaved,
    this.showBack = false,
  }) : transfer = false,
       fromLocationId = null,
       itemId = null;

  const AddStockMovePane.transfer({
    super.key,
    required this.api,
    required this.session,
    required this.onCancel,
    required this.onSaved,
    this.showBack = false,
    this.fromLocationId,
    this.itemId,
  }) : transfer = true;

  final ApiClient api;
  final Session session;
  final VoidCallback onCancel;
  final ValueChanged<Map<String, dynamic>> onSaved;
  final bool showBack;
  final bool transfer;
  final String? fromLocationId;
  final String? itemId;

  @override
  State<AddStockMovePane> createState() => _AddStockMovePaneState();
}

class _AddStockMovePaneState extends State<AddStockMovePane> {
  String? _fromId;
  String? _toId;
  String? _jobId;
  String _jobLabel = '';
  final _note = TextEditingController();
  List<Map<String, dynamic>> _locations = [];
  List<Map<String, dynamic>> _items = [];
  final List<_MoveLine> _lines = [_MoveLine()];
  bool _loading = true;
  bool _busy = false;
  String? _error;
  String? _jobSearchError;

  Me get _me => widget.session.me!;

  @override
  void initState() {
    super.initState();
    _fromId = widget.fromLocationId;
    _lines.first.itemId = widget.itemId;
    _load();
  }

  @override
  void dispose() {
    _note.dispose();
    for (final line in _lines) {
      line.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    final locations = await widget.api.get('/inventory/locations');
    final items = await widget.api.get('/inventory/items', {'per_page': '200'});
    if (!mounted) return;

    if (!locations.ok) {
      setState(() {
        _loading = false;
        _error = locations.message;
      });
      return;
    }
    if (!items.ok) {
      setState(() {
        _loading = false;
        _error = items.message;
      });
      return;
    }

    final locRows = inventoryRows(
      locations.data,
    ).where((row) => row['status'] != 'closed').toList();
    final itemRows = inventoryRows(
      items.data,
    ).where((row) => row['status'] != 'discontinued').toList();

    setState(() {
      _loading = false;
      _locations = locRows;
      _items = itemRows;
      // A transfer starts from the first location; an issue only when there
      // is no other to choose.
      _fromId ??= (widget.transfer ? locRows.isNotEmpty : locRows.length == 1)
          ? locRows.first['id'] as String?
          : null;
      if (itemRows.length == 1) {
        _lines.first.itemId ??= itemRows.first['id'] as String?;
      }
    });
  }

  List<_MoveLine> get _readyLines => [
    for (final line in _lines)
      if (line.itemId != null && line.qty.text.trim().isNotEmpty) line,
  ];

  Future<void> _save() async {
    if (_fromId == null) return;
    final ready = _readyLines;
    if (ready.isEmpty) return;
    final note = _note.text.trim();
    if (widget.transfer) {
      if (_toId == null || _fromId == _toId) return;
    } else if (_jobId == null && note.isEmpty) {
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final lines = [
      for (final line in ready)
        {'item_id': line.itemId, 'qty': line.qty.text.trim()},
    ];
    final response = widget.transfer
        ? await widget.api.post('/inventory/transfers', {
            'from_location_id': _fromId,
            'to_location_id': _toId,
            'lines': lines,
          })
        : await widget.api.post('/inventory/issues', {
            'location_id': _fromId,
            'job_id': ?_jobId,
            if (note.isNotEmpty) 'note': note,
            'lines': lines,
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

  List<SearchOption<String>> _locationItems({String? exclude}) {
    return [
      for (final loc in _locations)
        if (loc['id'] != exclude)
          SearchOption(
            value: loc['id'] as String?,
            label: inventoryLocationChoiceLabel(loc, _me.organization.name),
          ),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final transfer = widget.transfer;
    final canSave =
        !_busy &&
        !_loading &&
        _fromId != null &&
        _readyLines.isNotEmpty &&
        (transfer
            ? _toId != null && _fromId != _toId
            : _jobId != null || _note.text.trim().isNotEmpty);

    return formPane(
      context,
      transfer ? 'Transfer' : 'Issue stock',
      showBack: widget.showBack,
      busy: _busy,
      onCancel: widget.onCancel,
      onSave: canSave ? _save : null,
      saveLabel: transfer ? 'Transfer' : 'Issue',
      loading: _loading,
      children: [
        SearchPickerField<String>(
          value: _fromId,
          label: transfer ? 'From' : 'From location',
          options: _locationItems(),
          onChanged: _busy
              ? null
              : (v) => setState(() {
                  _fromId = v;
                  if (_toId == v) _toId = null;
                }),
        ),
        const SizedBox(height: 12),
        if (transfer)
          SearchPickerField<String>(
            key: ValueKey('to-$_fromId-$_toId'),
            value: _toId,
            label: 'To',
            options: _locationItems(exclude: _fromId),
            onChanged: _busy ? null : (v) => setState(() => _toId = v),
          )
        else ...[
          _jobField(),
          if (_jobSearchError != null) ...[
            const SizedBox(height: 8),
            ErrorNote(_jobSearchError!),
          ],
          const SizedBox(height: 12),
          TextField(
            controller: _note,
            enabled: !_busy,
            decoration: const InputDecoration(labelText: 'Why it left'),
            onChanged: (_) => setState(() {}),
          ),
        ],
        for (var i = 0; i < _lines.length; i++) ...[
          const SizedBox(height: 12),
          SearchPickerField<String>(
            key: ValueKey(
              '${transfer ? 'transfer' : 'issue'}-item-$i-'
              '${_lines[i].itemId}',
            ),
            value: _lines[i].itemId,
            label: 'Item',
            options: [
              for (final item in _items)
                SearchOption(
                  value: item['id'] as String?,
                  label: inventoryItemChoiceLabel(item),
                ),
            ],
            onChanged: _busy
                ? null
                : (v) => setState(() => _lines[i].itemId = v),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _lines[i].qty,
            enabled: !_busy,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(labelText: 'Qty'),
            onChanged: (_) => setState(() {}),
          ),
        ],
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton(
            onPressed: _busy
                ? null
                : () => setState(() => _lines.add(_MoveLine())),
            child: const Text('Add line'),
          ),
        ),
        if (_error != null) ...[const SizedBox(height: 8), ErrorNote(_error!)],
      ],
    );
  }

  Widget _jobField() {
    return Autocomplete<Map<String, dynamic>>(
      displayStringForOption: (job) =>
          '${job['number'] ?? ''} · ${job['title'] ?? ''}',
      optionsBuilder: (value) async {
        if (value.text.trim().isEmpty) {
          if (_jobSearchError != null && mounted) {
            setState(() => _jobSearchError = null);
          }
          return const <Map<String, dynamic>>[];
        }

        final response = await widget.api.get('/jobs', {
          'search': value.text.trim(),
          'per_page': '50',
        });

        if (!response.ok) {
          if (mounted) {
            setState(() {
              _jobSearchError = 'Could not search jobs: ${response.message}';
            });
          }
          return const <Map<String, dynamic>>[];
        }

        if (_jobSearchError != null && mounted) {
          setState(() => _jobSearchError = null);
        }

        return inventoryRows(response.data);
      },
      onSelected: (job) => setState(() {
        _jobId = job['id'] as String?;
        _jobLabel = '${job['number'] ?? ''} · ${job['title'] ?? ''}';
      }),
      fieldViewBuilder: (context, controller, focus, onSubmit) {
        if (_jobLabel.isNotEmpty && controller.text.isEmpty) {
          controller.text = _jobLabel;
        }
        return TextField(
          controller: controller,
          focusNode: focus,
          enabled: !_busy,
          decoration: const InputDecoration(
            labelText: 'Job',
            helperText: 'Name a job, or say why it left below.',
            helperMaxLines: 2,
          ),
          onChanged: (text) {
            if (_jobId != null) {
              setState(() {
                _jobId = null;
                _jobLabel = '';
              });
            }
          },
        );
      },
    );
  }
}

class _MoveLine {
  String? itemId;
  final qty = TextEditingController();

  void dispose() => qty.dispose();
}
