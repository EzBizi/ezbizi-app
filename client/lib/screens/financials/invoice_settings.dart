import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/search_picker.dart';

/// Financials › Invoices › Settings, written as sentences: the charge lines
/// (in order, on or off, where each comes from), how invoices are emailed,
/// when they are due and how their numbers start.
class InvoiceSettingsTab extends StatefulWidget {
  const InvoiceSettingsTab({super.key, required this.api, required this.me});

  final ApiClient api;
  final Me me;

  @override
  State<InvoiceSettingsTab> createState() => _InvoiceSettingsTabState();
}

class _ChargeLine {
  _ChargeLine({
    this.id,
    String name = '',
    String? source,
    this.active = true,
    this.label,
  }) : name = TextEditingController(text: name),
       source = TextEditingController(text: source ?? '');

  final String? id;
  final TextEditingController name;
  final TextEditingController source;
  bool active;

  /// Where the server says it comes from, in words.
  final String? label;

  /// A stable key while it is dragged, before the server has given it an id.
  late final Key key = ValueKey(id ?? Object());

  void dispose() {
    name.dispose();
    source.dispose();
  }
}

class _InvoiceSettingsTabState extends State<InvoiceSettingsTab> {
  List<_ChargeLine>? _lines;
  Map<String, Map> _settings = {};
  String? _nextNumber;
  String? _error;
  String? _linesNote;
  bool _linesDirty = false;
  bool _busy = false;

  final _summaryTo = TextEditingController();
  final _prefix = TextEditingController();

  static const _keys = [
    'invoicing.email_mode',
    'invoicing.summary_to',
    'money.payment_terms_days',
    'numbering.invoice_prefix',
  ];

  bool get _canEdit => widget.me.can('invoices.edit');

  Map<String, String> get _scope => {
    'scope_type': 'organization',
    'scope_id': widget.me.organization.id,
  };

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final l in _lines ?? const <_ChargeLine>[]) {
      l.dispose();
    }
    _summaryTo.dispose();
    _prefix.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final results = await Future.wait([
      widget.api.get('/invoices/charge-columns'),
      widget.api.get('/settings', _scope),
      widget.api.get('/invoices/settings'),
    ]);
    if (!mounted) return;
    final [columns, settings, extras] = results;
    setState(() {
      _error = columns.ok ? null : columns.reason;
      _adoptLines(
        columns.ok ? (columns.body['data'] as List).cast<Map>() : const [],
      );
      if (settings.ok && settings.data is List) {
        _settings = {
          for (final s in (settings.data as List).cast<Map>())
            if (_keys.contains(s['key'])) '${s['key']}': s,
        };
        _summaryTo.text =
            '${_settings['invoicing.summary_to']?['value'] ?? ''}';
        _prefix.text =
            '${_settings['numbering.invoice_prefix']?['value'] ?? ''}';
      }
      _nextNumber = extras.ok
          ? ((extras.body['data'] as Map?)?['next_number'] as String?)
          : null;
    });
  }

  void _adoptLines(List<Map> columns) {
    for (final l in _lines ?? const <_ChargeLine>[]) {
      l.dispose();
    }
    _lines = [
      for (final c in columns)
        _ChargeLine(
          id: '${c['id']}',
          name: '${c['name']}',
          source: c['source_column'] as String?,
          active: c['is_active'] != false,
          label: c['source_label'] as String?,
        ),
    ];
    _linesDirty = false;
  }

  Future<void> _saveLines() async {
    setState(() {
      _busy = true;
      _linesNote = null;
    });
    final r = await widget.api.put('/invoices/charge-columns', {
      'columns': [
        for (final l in _lines!)
          {
            'id': ?l.id,
            'name': l.name.text.trim(),
            'source_column': l.source.text.trim().isEmpty
                ? null
                : l.source.text.trim(),
            'is_active': l.active,
          },
      ],
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (r.ok) {
        _adoptLines((r.body['data'] as List).cast<Map>());
        _linesNote = 'Saved.';
      } else {
        _linesNote = r.reason;
      }
    });
  }

  Future<void> _set(String key, Object? value) async {
    final r = await widget.api.patch('/settings/$key', {
      'value': value,
    }, _scope);
    if (!mounted) return;
    if (!r.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(r.reason)));
      return;
    }
    setState(() => _settings[key] = {...?_settings[key], 'value': value});
    if (key == 'numbering.invoice_prefix') {
      final extras = await widget.api.get('/invoices/settings');
      if (mounted && extras.ok) {
        setState(
          () => _nextNumber =
              (extras.body['data'] as Map?)?['next_number'] as String?,
        );
      }
    }
  }

  bool _mayEdit(String key) => _settings[key]?['may_edit'] == true;

  @override
  Widget build(BuildContext context) {
    final lines = _lines;
    if (lines == null) return const Center(child: CircularProgressIndicator());
    final theme = Theme.of(context);

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text('Charge lines', style: theme.textTheme.titleMedium),
        Text(
          'The columns of every new charge sheet, in this order. Drag to reorder.',
          style: theme.textTheme.bodySmall,
        ),
        if (_error != null) Text(_error!),
        ReorderableListView(
          key: const ValueKey('tour-invoices-columns'),
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          buildDefaultDragHandles: false,
          onReorder: (from, to) => setState(() {
            if (to > from) to--;
            lines.insert(to, lines.removeAt(from));
            _linesDirty = true;
          }),
          children: [
            for (final (i, l) in lines.indexed) _lineRow(context, l, i),
          ],
        ),
        Wrap(
          spacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            if (_canEdit)
              TextButton.icon(
                key: const ValueKey('invoice-column-add'),
                onPressed: () => setState(() {
                  lines.add(_ChargeLine());
                  _linesDirty = true;
                }),
                icon: const Icon(Icons.add, size: 18),
                label: const Text('Add a line'),
              ),
            if (_canEdit)
              FilledButton(
                key: const ValueKey('invoice-columns-save'),
                onPressed: _busy || !_linesDirty ? null : _saveLines,
                child: const Text('Save'),
              ),
            if (_linesNote != null)
              Text(_linesNote!, key: const ValueKey('invoice-columns-note')),
          ],
        ),
        const SizedBox(height: 24),
        Column(
          key: const ValueKey('tour-invoices-sentences'),
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Invoicing', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            if (_settings.containsKey('invoicing.email_mode'))
              _emailSentence(context),
            if (_settings.containsKey('money.payment_terms_days')) ...[
              const SizedBox(height: 12),
              _dueSentence(context),
            ],
            if (_settings.containsKey('numbering.invoice_prefix')) ...[
              const SizedBox(height: 12),
              _prefixSentence(context),
            ],
          ],
        ),
      ],
    );
  }

  Widget _lineRow(BuildContext context, _ChargeLine l, int i) {
    final theme = Theme.of(context);
    return Padding(
      key: l.key,
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ReorderableDragStartListener(
            index: i,
            enabled: _canEdit,
            child: const Padding(
              padding: EdgeInsets.only(top: 12, right: 4),
              child: Icon(Icons.drag_indicator),
            ),
          ),
          Checkbox(
            key: ValueKey('invoice-column-$i-active'),
            value: l.active,
            onChanged: _canEdit
                ? (v) => setState(() {
                    l.active = v ?? false;
                    _linesDirty = true;
                  })
                : null,
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Wrap(
                  spacing: 8,
                  runSpacing: 4,
                  children: [
                    SizedBox(
                      width: 220,
                      child: TextField(
                        key: ValueKey('invoice-column-$i-name'),
                        controller: l.name,
                        enabled: _canEdit,
                        decoration: const InputDecoration(
                          isDense: true,
                          labelText: 'Name',
                        ),
                        onChanged: (_) => setState(() => _linesDirty = true),
                      ),
                    ),
                    SizedBox(
                      width: 240,
                      child: TextField(
                        key: ValueKey('invoice-column-$i-source'),
                        controller: l.source,
                        enabled: _canEdit,
                        decoration: const InputDecoration(
                          isDense: true,
                          labelText: 'Source column',
                          hintText: 'Empty: typed in on the sheet',
                        ),
                        onChanged: (_) => setState(() => _linesDirty = true),
                      ),
                    ),
                  ],
                ),
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Text(
                    l.active ? (l.label ?? 'Not saved yet') : 'Off',
                    style: theme.textTheme.bodySmall,
                  ),
                ),
              ],
            ),
          ),
          if (_canEdit)
            IconButton(
              tooltip: 'Remove line',
              icon: const Icon(Icons.close, size: 18),
              onPressed: () => setState(() {
                _lines!.removeAt(i).dispose();
                _linesDirty = true;
              }),
            ),
        ],
      ),
    );
  }

  Widget _inline(Widget child, double width) =>
      SizedBox(width: width, child: child);

  Widget _emailSentence(BuildContext context) {
    final mode =
        '${_settings['invoicing.email_mode']?['value'] ?? 'per_branch'}';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 6,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            const Text('Send'),
            _inline(
              SearchPickerField<String>(
                key: const ValueKey('invoice-setting-email-mode'),
                isDense: true,
                value: mode,
                options: const [
                  SearchOption(
                    value: 'per_branch',
                    label: 'One email per branch',
                  ),
                  SearchOption(
                    value: 'summary',
                    label: 'One summary email to accounting',
                  ),
                ],
                onChanged: _mayEdit('invoicing.email_mode')
                    ? (v) {
                        if (v != null) _set('invoicing.email_mode', v);
                      }
                    : null,
              ),
              300,
            ),
          ],
        ),
        if (mode == 'summary' && _settings.containsKey('invoicing.summary_to'))
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Wrap(
              spacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                const Text('to'),
                _inline(
                  TextField(
                    key: const ValueKey('invoice-setting-summary-to'),
                    controller: _summaryTo,
                    enabled: _mayEdit('invoicing.summary_to'),
                    decoration: const InputDecoration(
                      isDense: true,
                      hintText: 'accounting@…, separated by commas',
                    ),
                    onSubmitted: (v) => _set('invoicing.summary_to', v.trim()),
                  ),
                  300,
                ),
                if (_mayEdit('invoicing.summary_to'))
                  TextButton(
                    key: const ValueKey('invoice-setting-summary-to-save'),
                    onPressed: () =>
                        _set('invoicing.summary_to', _summaryTo.text.trim()),
                    child: const Text('Save'),
                  ),
              ],
            ),
          ),
      ],
    );
  }

  Widget _dueSentence(BuildContext context) {
    final row = _settings['money.payment_terms_days']!;
    final value = (row['value'] as num?)?.toInt();
    final options = [
      for (final o in (row['options'] as List? ?? const []))
        (o is Map ? (o['value'] as num?)?.toInt() : (o as num?)?.toInt()),
    ].whereType<int>().toList();
    return Wrap(
      spacing: 8,
      runSpacing: 6,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        const Text('Invoices are due'),
        _inline(
          SearchPickerField<int>(
            key: const ValueKey('invoice-setting-due-days'),
            isDense: true,
            value: value,
            options: [
              for (final o in options) SearchOption(value: o, label: '$o'),
              if (value != null && !options.contains(value))
                SearchOption(value: value, label: '$value'),
            ],
            onChanged: _mayEdit('money.payment_terms_days')
                ? (v) {
                    if (v != null) _set('money.payment_terms_days', v);
                  }
                : null,
          ),
          100,
        ),
        const Text("days after they're dated."),
      ],
    );
  }

  Widget _prefixSentence(BuildContext context) => Wrap(
    spacing: 8,
    runSpacing: 6,
    crossAxisAlignment: WrapCrossAlignment.center,
    children: [
      const Text('Numbers start with'),
      _inline(
        TextField(
          key: const ValueKey('invoice-setting-prefix'),
          controller: _prefix,
          enabled: _mayEdit('numbering.invoice_prefix'),
          decoration: const InputDecoration(isDense: true),
          onSubmitted: (v) => _set('numbering.invoice_prefix', v.trim()),
        ),
        120,
      ),
      if (_mayEdit('numbering.invoice_prefix'))
        TextButton(
          key: const ValueKey('invoice-setting-prefix-save'),
          onPressed: () =>
              _set('numbering.invoice_prefix', _prefix.text.trim()),
          child: const Text('Save'),
        ),
      if (_nextNumber != null)
        Text(
          'next: $_nextNumber',
          key: const ValueKey('invoice-next-number'),
          style: Theme.of(context).textTheme.bodySmall,
        ),
    ],
  );
}
