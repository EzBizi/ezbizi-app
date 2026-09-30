import 'package:flutter/material.dart';

import '../core/api_client.dart';

/// Leads > Manage > Templates: the canned replies beside Send, one list per
/// company, edited where they are used (Jose, 2026-09-30: "add a template
/// manager so they can be changed from within the app"). Each brand signs its
/// own name, which is why the list is per company - a garage-door customer
/// was sent the chimney prices the same day.
class ReplyTemplatesManager extends StatelessWidget {
  const ReplyTemplatesManager({super.key, required this.api});

  final ApiClient api;

  @override
  Widget build(BuildContext context) => _Manager(api: api);
}

class _Manager extends StatefulWidget {
  const _Manager({required this.api});

  final ApiClient api;

  @override
  State<_Manager> createState() => _ManagerState();
}

class _Row {
  _Row(String name, String text)
    : name = TextEditingController(text: name),
      text = TextEditingController(text: text);

  final TextEditingController name;
  final TextEditingController text;

  void dispose() {
    name.dispose();
    text.dispose();
  }
}

class _ManagerState extends State<_Manager> {
  bool _loading = true;
  bool _saving = false;
  bool _mayEdit = false;
  String? _error;
  String? _saved;
  List<Map<String, dynamic>> _companies = const [];
  String? _companyId;
  List<_Row> _rows = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final r in _rows) {
      r.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/leads/reply-templates');
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.reason;
      });
      return;
    }
    final body = response.body as Map<String, dynamic>? ?? const {};
    _companies = (body['data'] as List? ?? const [])
        .cast<Map<String, dynamic>>();
    _mayEdit = (body['meta'] as Map?)?['may_edit'] == true;
    setState(() {
      _loading = false;
      _pick(_companyId ?? _companyIdAt(0));
    });
  }

  String? _companyIdAt(int i) => _companies.length > i
      ? (_companies[i]['company'] as Map)['id'] as String
      : null;

  void _pick(String? id) {
    for (final r in _rows) {
      r.dispose();
    }
    _companyId = id;
    _saved = null;
    final entry = _companies.firstWhere(
      (c) => (c['company'] as Map)['id'] == id,
      orElse: () => const {},
    );
    _rows = [
      for (final t in (entry['templates'] as List? ?? const []).cast<Map>())
        _Row('${t['name']}', '${t['text']}'),
    ];
  }

  Future<void> _save() async {
    final id = _companyId;
    if (id == null) return;
    setState(() {
      _saving = true;
      _error = null;
      _saved = null;
    });
    final response = await widget.api.put('/leads/reply-templates/$id', {
      'templates': [
        for (final r in _rows)
          if (r.name.text.trim().isNotEmpty && r.text.text.trim().isNotEmpty)
            {'name': r.name.text.trim(), 'text': r.text.text.trim()},
      ],
    });
    if (!mounted) return;
    setState(() {
      _saving = false;
      if (response.ok) {
        final data = (response.body as Map)['data'] as Map<String, dynamic>;
        final i = _companies.indexWhere(
          (c) => (c['company'] as Map)['id'] == id,
        );
        if (i >= 0) _companies[i] = data;
        _saved = 'Saved.';
      } else {
        _error = response.reason;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_companies.isEmpty) {
      return Center(child: Text(_error ?? 'No companies to show.'));
    }

    return ListView(
      key: const ValueKey('reply-templates-manager'),
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          'Shown beside Send on a lead. A tap puts the text in the reply box '
          'to read first; nothing is sent by itself. {{greeting}} becomes '
          '"Hi Mark" (or "Hi there"), {{company}} the company name, '
          '{{missing}} asks only for what the customer has not given yet, and '
          '{{availability}} names the first open day for their ZIP.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final c in _companies)
              ChoiceChip(
                key: ValueKey('templates-company-${(c['company'] as Map)['id']}'),
                label: Text('${(c['company'] as Map)['name']}'),
                selected: (c['company'] as Map)['id'] == _companyId,
                onSelected: (_) => setState(
                  () => _pick((c['company'] as Map)['id'] as String),
                ),
              ),
          ],
        ),
        const SizedBox(height: 16),
        for (var i = 0; i < _rows.length; i++)
          Card(
            key: ValueKey('template-row-$i'),
            margin: const EdgeInsets.only(bottom: 10),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: _rows[i].name,
                          enabled: _mayEdit,
                          decoration: const InputDecoration(
                            labelText: 'Button name',
                            isDense: true,
                          ),
                        ),
                      ),
                      if (_mayEdit)
                        IconButton(
                          tooltip: 'Remove',
                          icon: const Icon(Icons.delete_outline),
                          onPressed: () => setState(() {
                            _rows.removeAt(i).dispose();
                          }),
                        ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: _rows[i].text,
                    enabled: _mayEdit,
                    minLines: 2,
                    maxLines: 8,
                    decoration: const InputDecoration(
                      labelText: 'Text',
                      border: OutlineInputBorder(),
                    ),
                  ),
                ],
              ),
            ),
          ),
        if (_mayEdit)
          Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              OutlinedButton.icon(
                key: const ValueKey('template-add'),
                onPressed: () => setState(() => _rows.add(_Row('', ''))),
                icon: const Icon(Icons.add),
                label: const Text('Add template'),
              ),
              FilledButton(
                key: const ValueKey('templates-save'),
                onPressed: _saving ? null : _save,
                child: Text(_saving ? 'Saving…' : 'Save'),
              ),
              if (_saved != null) Text(_saved!),
            ],
          )
        else
          Text(
            'Changing these needs permission to edit Leads.',
            style: theme.textTheme.bodySmall,
          ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              _error!,
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ),
      ],
    );
  }
}
