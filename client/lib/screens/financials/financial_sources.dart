import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';

/// Financials → Settings: which of the organization's Airtable tables is which
/// part of its books, and which column means what. Reports read this map.
class FinancialSourcesSettings extends StatefulWidget {
  const FinancialSourcesSettings({
    super.key,
    required this.api,
    required this.me,
  });

  final ApiClient api;
  final Me me;

  @override
  State<FinancialSourcesSettings> createState() =>
      _FinancialSourcesPanelState();
}

class _FinancialSourcesPanelState extends State<FinancialSourcesSettings> {
  Map<String, dynamic>? _data;
  String? _error;

  bool get _canEdit =>
      widget.me.can('financials.edit') &&
      widget.me.scope.type == 'organization';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/financials/sources');
    if (!mounted) return;
    setState(() {
      _data = r.ok ? r.body : null;
      _error = r.ok ? null : r.message;
    });
  }

  String _tableName(Map s) {
    for (final t in (_data?['airtable_tables'] as List? ?? const [])) {
      if (t['base_id'] == s['base_id'] && t['table_id'] == s['table_id']) {
        return '${t['table_name']}';
      }
    }
    return '${s['base_id']} / ${s['table_id']}';
  }

  Future<void> _edit(Map? source) async {
    final saved = await showSidePanel<bool>(
      context,
      builder: (_) => _SourcePanel(
        api: widget.api,
        me: widget.me,
        data: _data!,
        source: source,
      ),
    );
    if (saved == true) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final roles = (_data?['roles'] as Map?) ?? const {};
    final sources = ((_data?['data'] as List?) ?? const []).cast<Map>();
    return ListView(
      key: const ValueKey('tour-financials-sources'),
      padding: const EdgeInsets.all(16),
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                'Airtable sources',
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            if (_canEdit && _data != null)
              FilledButton.icon(
                icon: const Icon(Icons.add),
                label: const Text('Add source'),
                onPressed: () => _edit(null),
              ),
          ],
        ),
        const SizedBox(height: 8),
        const Text(
          'Say which Airtable table holds which part of your books. The hourly '
          'Airtable copy and the weekly and monthly reports read this list.',
        ),
        if (!_canEdit)
          const Padding(
            padding: EdgeInsets.only(top: 8),
            child: Text(
              'Changing these needs Financials edit, from the organization view.',
            ),
          ),
        if (_error != null) Text(_error!),
        if (_data == null && _error == null)
          const Padding(
            padding: EdgeInsets.all(24),
            child: Center(child: CircularProgressIndicator()),
          ),
        if (_data != null && sources.isEmpty)
          const Padding(
            padding: EdgeInsets.only(top: 16),
            child: Text('No sources yet.'),
          ),
        for (final s in sources)
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: Text('${roles[s['role']] ?? s['role']} · ${_tableName(s)}'),
            subtitle: Text(
              [
                s['place'] ?? 'Whole organization',
                if ((s['field_map'] as Map).isNotEmpty)
                  '${(s['field_map'] as Map).length} column${(s['field_map'] as Map).length == 1 ? '' : 's'} mapped',
              ].join(' · '),
            ),
            trailing: _canEdit ? const Icon(Icons.chevron_right) : null,
            onTap: _canEdit ? () => _edit(s) : null,
          ),
      ],
    );
  }
}

class _SourcePanel extends StatefulWidget {
  const _SourcePanel({
    required this.api,
    required this.me,
    required this.data,
    this.source,
  });

  final ApiClient api;
  final Me me;
  final Map<String, dynamic> data;
  final Map? source;

  @override
  State<_SourcePanel> createState() => _SourcePanelState();
}

class _SourcePanelState extends State<_SourcePanel> {
  late String? _role = widget.source?['role'] as String?;
  late String? _table = widget.source == null
      ? null
      : '${widget.source!['base_id']}/${widget.source!['table_id']}';
  late String? _place =
      (widget.source?['franchise_id'] ?? widget.source?['company_id'])
          as String?;
  late final Map<String, TextEditingController> _map = {
    for (final k in (widget.data['field_keys'] as List).cast<String>())
      k: TextEditingController(
        text: (widget.source?['field_map'] as Map?)?[k] as String?,
      ),
  };
  late final _validFrom = TextEditingController(
    text: widget.source?['valid_from'] as String?,
  );
  late final _validUntil = TextEditingController(
    text: widget.source?['valid_until'] as String?,
  );
  late final _filterField = TextEditingController(
    text: widget.source?['filter_field'] as String?,
  );
  late final _filterValue = TextEditingController(
    text: widget.source?['filter_value'] as String?,
  );
  late final Map<String, TextEditingController> _markers = {
    for (final k
        in ((widget.data['marker_keys'] as List?) ?? const []).cast<String>())
      k: TextEditingController(
        text: (widget.source?['markers'] as Map?)?[k] as String?,
      ),
  };
  String? _error;
  bool _busy = false;

  static const _markerLabels = {
    'summary': 'Value marking a summary row (e.g. Weekly Summary)',
    'job': 'Value marking a job row (e.g. Job)',
    'skip': 'Value marking a row to leave out (e.g. Total)',
  };

  static const _keyLabels = {
    'amount': 'Amount column',
    'date': 'Date column',
    'company': 'Company column',
    'technician': 'Technician column',
    'team': 'Team column',
    'job_number': 'Job number column',
    'category': 'Category column',
    'kind': 'Row kind column',
    'status': 'Status column',
    'reason': 'Reason column',
    'total': 'Total column',
    'parts': 'Parts cost column',
    'tech_share': 'Technician share column',
    'external_share': 'External company share column',
    'contracting': 'Contracting profit column',
    'employee': 'Employee cost column',
    'helper': 'Helper cost column',
    'gas': 'Gas cost column',
    'operation': 'Operating cost column',
    'hours': 'Hours column',
  };

  Future<void> _save() async {
    if (_role == null || _table == null) {
      return setState(() => _error = 'Choose the part and the Airtable table.');
    }
    final parts = _table!.split('/');
    final franchise = widget.me.franchisesInView
        .where((f) => f.id == _place)
        .firstOrNull;
    final body = {
      'base_id': parts[0],
      'table_id': parts[1],
      'role': _role,
      'franchise_id': franchise?.id,
      'company_id': franchise?.companyId ?? (franchise == null ? _place : null),
      'valid_from': _validFrom.text.trim().isEmpty
          ? null
          : _validFrom.text.trim(),
      'valid_until': _validUntil.text.trim().isEmpty
          ? null
          : _validUntil.text.trim(),
      'filter_field': _filterField.text.trim().isEmpty
          ? null
          : _filterField.text.trim(),
      'filter_value': _filterValue.text.trim().isEmpty
          ? null
          : _filterValue.text.trim(),
      'field_map': {for (final e in _map.entries) e.key: e.value.text.trim()},
      'markers': {for (final e in _markers.entries) e.key: e.value.text.trim()},
    };
    setState(() => _busy = true);
    final r = widget.source == null
        ? await widget.api.post('/financials/sources', body)
        : await widget.api.patch(
            '/financials/sources/${widget.source!['id']}',
            body,
          );
    if (!mounted) return;
    setState(() => _busy = false);
    r.ok ? Navigator.of(context).pop(true) : setState(() => _error = r.message);
  }

  Future<void> _delete() async {
    final r = await widget.api.delete(
      '/financials/sources/${widget.source!['id']}',
    );
    if (!mounted) return;
    r.ok ? Navigator.of(context).pop(true) : setState(() => _error = r.message);
  }

  @override
  Widget build(BuildContext context) {
    final roles = (widget.data['roles'] as Map).cast<String, dynamic>();
    final tables = (widget.data['airtable_tables'] as List).cast<Map>();
    return SidePanel(
      title: widget.source == null ? 'Add source' : 'Edit source',
      actions: [
        if (widget.source != null)
          TextButton(
            onPressed: _busy ? null : _delete,
            child: const Text('Remove'),
          ),
        FilledButton(
          onPressed: _busy ? null : _save,
          child: const Text('Save'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null)
            Text(
              _error!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          SearchPickerField<String>(
            label: 'Part of the books',
            value: _role,
            options: [
              for (final e in roles.entries)
                SearchOption(value: e.key, label: '${e.value}'),
            ],
            onChanged: (v) => setState(() => _role = v),
          ),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            label: 'Airtable table',
            value: _table,
            helperText: tables.isEmpty
                ? 'Nothing copied yet. Connect Airtable in Integrations.'
                : null,
            options: [
              for (final t in tables)
                SearchOption(
                  value: '${t['base_id']}/${t['table_id']}',
                  label: '${t['table_name']} (${t['rows']} rows)',
                ),
            ],
            onChanged: (v) => setState(() => _table = v),
          ),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            label: 'Which company or place it covers',
            hint: 'Whole organization',
            allowClear: true,
            value: _place,
            options: [
              for (final c in widget.me.companiesInView)
                SearchOption(value: c.id, label: c.name),
              for (final f in widget.me.franchisesInView)
                SearchOption(value: f.id, label: f.name),
            ],
            onChanged: (v) => setState(() => _place = v),
          ),
          const SizedBox(height: 16),
          Text(
            'When it applies',
            style: Theme.of(context).textTheme.titleSmall,
          ),
          const Text(
            'Leave blank unless this table holds the books only for a stretch of time.',
          ),
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: TextField(
              controller: _validFrom,
              decoration: const InputDecoration(
                labelText: 'From (YYYY-MM-DD)',
                isDense: true,
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: TextField(
              controller: _validUntil,
              decoration: const InputDecoration(
                labelText: 'Until (YYYY-MM-DD)',
                isDense: true,
              ),
            ),
          ),
          const SizedBox(height: 16),
          Text('Shared table', style: Theme.of(context).textTheme.titleSmall),
          const Text(
            'If this table holds rows for several places, say which rows are this '
            "place's: the column and the value it has on them.",
          ),
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: TextField(
              controller: _filterField,
              decoration: const InputDecoration(
                labelText: 'Column (e.g. Company Name)',
                isDense: true,
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: TextField(
              controller: _filterValue,
              decoration: const InputDecoration(
                labelText: 'Value (e.g. A1 CHIMNEY NJ)',
                isDense: true,
              ),
            ),
          ),
          const SizedBox(height: 16),
          Text('Row markers', style: Theme.of(context).textTheme.titleSmall),
          for (final e in _markers.entries)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: TextField(
                controller: e.value,
                decoration: InputDecoration(
                  labelText: _markerLabels[e.key] ?? e.key,
                  isDense: true,
                ),
              ),
            ),
          const SizedBox(height: 16),
          Text('Columns', style: Theme.of(context).textTheme.titleSmall),
          const Text(
            'Type the Airtable column name for each meaning this table has. Leave the rest blank.',
          ),
          for (final e in _map.entries)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: TextField(
                controller: e.value,
                decoration: InputDecoration(
                  labelText: _keyLabels[e.key] ?? e.key,
                  isDense: true,
                ),
              ),
            ),
        ],
      ),
    );
  }
}
