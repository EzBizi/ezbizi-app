import 'package:flutter/material.dart';

import '../../widgets/search_picker.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import 'item_pane.dart';

/// Move stock from one location to another, posted on save.
class AddTransferPane extends StatefulWidget {
  const AddTransferPane({
    super.key,
    required this.api,
    required this.session,
    required this.onCancel,
    required this.onSaved,
    this.showBack = false,
    this.fromLocationId,
    this.itemId,
  });

  final ApiClient api;
  final Session session;
  final VoidCallback onCancel;
  final ValueChanged<Map<String, dynamic>> onSaved;
  final bool showBack;
  final String? fromLocationId;
  final String? itemId;

  @override
  State<AddTransferPane> createState() => _AddTransferPaneState();
}

class _AddTransferPaneState extends State<AddTransferPane> {
  String? _fromId;
  String? _toId;
  List<Map<String, dynamic>> _locations = [];
  List<Map<String, dynamic>> _items = [];
  final List<_TransferLine> _lines = [_TransferLine()];
  bool _loading = true;
  bool _busy = false;
  String? _error;

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

    final locRows = inventoryRows(locations.data)
        .where((row) => row['status'] != 'closed')
        .toList();
    final itemRows = inventoryRows(items.data)
        .where((row) => row['status'] != 'discontinued')
        .toList();

    setState(() {
      _loading = false;
      _locations = locRows;
      _items = itemRows;
      _fromId ??= locRows.isNotEmpty ? locRows.first['id'] as String? : null;
      if (itemRows.length == 1) {
        _lines.first.itemId ??= itemRows.first['id'] as String?;
      }
    });
  }

  List<_TransferLine> get _readyLines => [
    for (final line in _lines)
      if (line.itemId != null && line.qty.text.trim().isNotEmpty) line,
  ];

  Future<void> _save() async {
    if (_fromId == null || _toId == null || _fromId == _toId) return;
    final ready = _readyLines;
    if (ready.isEmpty) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/inventory/transfers', {
      'from_location_id': _fromId,
      'to_location_id': _toId,
      'lines': [
        for (final line in ready)
          {'item_id': line.itemId, 'qty': line.qty.text.trim()},
      ],
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
    final theme = Theme.of(context);
    final canSave =
        !_busy &&
        !_loading &&
        _fromId != null &&
        _toId != null &&
        _fromId != _toId &&
        _readyLines.isNotEmpty;

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
                child: Text('Transfer', style: theme.textTheme.titleMedium),
              ),
            ],
          ),
        ),
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : SingleChildScrollView(
                  clipBehavior: Clip.none,
                  padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      SearchPickerField<String>(
                        value: _fromId,
                        label: 'From',
                        options: _locationItems(),
                        onChanged: _busy
                            ? null
                            : (v) => setState(() {
                                _fromId = v;
                                if (_toId == v) _toId = null;
                              }),
                      ),
                      const SizedBox(height: 12),
                      SearchPickerField<String>(
                        key: ValueKey('to-$_fromId-$_toId'),
                        value: _toId,
                        label: 'To',
                        options: _locationItems(exclude: _fromId),
                        onChanged: _busy
                            ? null
                            : (v) => setState(() => _toId = v),
                      ),
                      for (var i = 0; i < _lines.length; i++) ...[
                        const SizedBox(height: 12),
                        SearchPickerField<String>(
                          key: ValueKey(
                            'transfer-item-$i-${_lines[i].itemId}',
                          ),
                          value: _lines[i].itemId,
                          label: 'Item',
                          options: [
                            for (final item in _items)
                              SearchOption(value: item['id'] as String?, label: inventoryItemChoiceLabel(item)),
                          ],
                          onChanged: _busy
                              ? null
                              : (v) => setState(() => _lines[i].itemId = v),
                        ),
                        const SizedBox(height: 12),
                        TextField(
                          controller: _lines[i].qty,
                          enabled: !_busy,
                          keyboardType: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                          decoration: const InputDecoration(labelText: 'Qty'),
                          onChanged: (_) => setState(() {}),
                        ),
                      ],
                      Align(
                        alignment: Alignment.centerLeft,
                        child: TextButton(
                          onPressed: _busy
                              ? null
                              : () =>
                                    setState(() => _lines.add(_TransferLine())),
                          child: const Text('Add line'),
                        ),
                      ),
                      if (_error != null) ...[
                        const SizedBox(height: 8),
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
                child: const Text('Transfer'),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _TransferLine {
  String? itemId;
  final qty = TextEditingController();

  void dispose() => qty.dispose();
}
