import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import 'item_pane.dart';

/// Issue stock in the 75% pane, posted on save.
class AddIssuePane extends StatefulWidget {
  const AddIssuePane({
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
  State<AddIssuePane> createState() => _AddIssuePaneState();
}

class _AddIssuePaneState extends State<AddIssuePane> {
  String? _locationId;
  String? _jobId;
  String _jobLabel = '';
  final _note = TextEditingController();
  List<Map<String, dynamic>> _locations = [];
  List<Map<String, dynamic>> _items = [];
  final List<_IssueLine> _lines = [_IssueLine()];
  bool _loading = true;
  bool _busy = false;
  String? _error;
  String? _jobSearchError;

  Me get _me => widget.session.me!;

  @override
  void initState() {
    super.initState();
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
      _locationId ??= locRows.length == 1
          ? locRows.first['id'] as String?
          : null;
      if (itemRows.length == 1) {
        _lines.first.itemId ??= itemRows.first['id'] as String?;
      }
    });
  }

  List<_IssueLine> get _readyLines => [
    for (final line in _lines)
      if (line.itemId != null && line.qty.text.trim().isNotEmpty) line,
  ];

  Future<void> _save() async {
    if (_locationId == null) return;
    final ready = _readyLines;
    if (ready.isEmpty) return;
    final note = _note.text.trim();
    if (_jobId == null && note.isEmpty) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/inventory/issues', {
      'location_id': _locationId,
      'job_id': ?_jobId,
      if (note.isNotEmpty) 'note': note,
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

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final canSave =
        !_busy &&
        !_loading &&
        _locationId != null &&
        _readyLines.isNotEmpty &&
        (_jobId != null || _note.text.trim().isNotEmpty);

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
                child: Text('Issue stock', style: theme.textTheme.titleMedium),
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
                      DropdownButtonFormField<String>(
                        initialValue: _locationId,
                        isExpanded: true,
                        decoration: const InputDecoration(
                          labelText: 'From location',
                        ),
                        items: [
                          for (final loc in _locations)
                            DropdownMenuItem(
                              value: loc['id'] as String?,
                              child: Text(
                                inventoryLocationChoiceLabel(
                                  loc,
                                  _me.organization.name,
                                ),
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                        ],
                        onChanged: _busy
                            ? null
                            : (v) => setState(() => _locationId = v),
                      ),
                      const SizedBox(height: 12),
                      Autocomplete<Map<String, dynamic>>(
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
                                _jobSearchError =
                                    'Could not search jobs: ${response.message}';
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
                          _jobLabel =
                              '${job['number'] ?? ''} · ${job['title'] ?? ''}';
                        }),
                        fieldViewBuilder:
                            (context, controller, focus, onSubmit) {
                              if (_jobLabel.isNotEmpty &&
                                  controller.text.isEmpty) {
                                controller.text = _jobLabel;
                              }
                              return TextField(
                                controller: controller,
                                focusNode: focus,
                                enabled: !_busy,
                                decoration: const InputDecoration(
                                  labelText: 'Job',
                                  helperText:
                                      'Name a job, or say why it left below.',
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
                      ),
                      if (_jobSearchError != null) ...[
                        const SizedBox(height: 8),
                        ErrorNote(_jobSearchError!),
                      ],
                      const SizedBox(height: 12),
                      TextField(
                        controller: _note,
                        enabled: !_busy,
                        decoration: const InputDecoration(
                          labelText: 'Why it left',
                        ),
                        onChanged: (_) => setState(() {}),
                      ),
                      for (var i = 0; i < _lines.length; i++) ...[
                        const SizedBox(height: 12),
                        DropdownButtonFormField<String>(
                          key: ValueKey('issue-item-$i-${_lines[i].itemId}'),
                          initialValue: _lines[i].itemId,
                          isExpanded: true,
                          decoration: const InputDecoration(labelText: 'Item'),
                          items: [
                            for (final item in _items)
                              DropdownMenuItem(
                                value: item['id'] as String?,
                                child: Text(
                                  inventoryItemChoiceLabel(item),
                                  overflow: TextOverflow.ellipsis,
                                ),
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
                              : () => setState(() => _lines.add(_IssueLine())),
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
                child: const Text('Issue'),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _IssueLine {
  String? itemId;
  final qty = TextEditingController();

  void dispose() => qty.dispose();
}
