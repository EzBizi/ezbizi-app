import 'package:flutter/material.dart';

import '../core/api_client.dart';

/// Integrations › Airtable: one token, then tick the bases it reaches (Jose,
/// 2026-10-20: "shouldn't i just need to provide the access token?").
/// "Replace token" swaps a new token into every connected base at once, and
/// only if it reaches all of them.
class AirtableTokenConnect extends StatefulWidget {
  const AirtableTokenConnect({
    super.key,
    required this.api,
    required this.onChanged,
    this.hasConnections = false,
  });

  final ApiClient api;
  final VoidCallback onChanged;
  final bool hasConnections;

  @override
  State<AirtableTokenConnect> createState() => _AirtableTokenConnectState();
}

class _AirtableTokenConnectState extends State<AirtableTokenConnect> {
  final _token = TextEditingController();
  List<Map>? _bases;
  final Set<String> _ticked = {};
  String? _error;
  String? _note;
  bool _busy = false;

  Future<void> _find() async {
    setState(() {
      _busy = true;
      _error = null;
      _note = null;
    });
    final r = await widget.api.post('/social/airtable/bases', {
      'api_token': _token.text.trim(),
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (r.ok) {
        _bases = (r.body['data'] as List).cast<Map>();
        _ticked
          ..clear()
          ..addAll([
            // Nothing connected yet: every base starts ticked (Jose, 2026-10-20).
            for (final b in _bases!)
              if (b['connected'] == true ||
                  !_bases!.any((x) => x['connected'] == true))
                '${b['id']}',
          ]);
      } else {
        _error = r.message;
      }
    });
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    final r = await widget.api.post('/social/airtable/connect', {
      'api_token': _token.text.trim(),
      'base_ids': _ticked.toList(),
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      _error = r.ok ? null : r.message;
      _note = r.ok ? '${(r.body['data'] as Map)['message']}' : null;
    });
    if (r.ok) widget.onChanged();
  }

  Future<void> _replace() async {
    setState(() {
      _busy = true;
      _error = null;
      _note = null;
    });
    final r = await widget.api.post('/social/airtable/replace-token', {
      'api_token': _token.text.trim(),
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      _error = r.ok ? null : r.message;
      _note = r.ok ? '${(r.body['data'] as Map)['message']}' : null;
    });
    if (r.ok) widget.onChanged();
  }

  /// Airtable's permission level, in plain words.
  static String _permission(Object? level) => switch ('$level') {
    'owner' || 'create' => 'Can edit',
    'edit' => 'Can edit records',
    'comment' => 'Can comment',
    'read' => 'Read only',
    final other => other,
  };

  Widget _step(ThemeData theme, int n, String title, String? help) => Padding(
    padding: const EdgeInsets.only(bottom: 6),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        CircleAvatar(
          radius: 11,
          backgroundColor: theme.colorScheme.primary,
          child: Text(
            '$n',
            style: theme.textTheme.labelSmall?.copyWith(
              color: theme.colorScheme.onPrimary,
              fontWeight: FontWeight.w800,
            ),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: theme.textTheme.titleSmall),
              if (help != null) Text(help, style: theme.textTheme.bodySmall),
            ],
          ),
        ),
      ],
    ),
  );

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final bases = _bases;
    final hasToken = _token.text.trim().isNotEmpty;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // UI queue 121 follow-up (Jose, 2026-10-20): one token, then tick the
        // bases it reaches. No base IDs anywhere.
        _step(
          theme,
          1,
          'Paste your Airtable access token',
          'Airtable › Builder hub › Personal access tokens. It needs '
              'data.records:read, data.records:write and schema.bases:read.',
        ),
        TextField(
          key: const ValueKey('airtable-token'),
          controller: _token,
          obscureText: true,
          decoration: const InputDecoration(
            labelText: 'Access token',
            isDense: true,
          ),
          onChanged: (_) => setState(() => _bases = null),
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            FilledButton(
              key: const ValueKey('airtable-find'),
              onPressed: _busy || !hasToken ? null : _find,
              child: Text(bases == null ? 'Find bases' : 'Look again'),
            ),
            if (widget.hasConnections)
              OutlinedButton(
                key: const ValueKey('airtable-replace'),
                onPressed: _busy || !hasToken ? null : _replace,
                child: const Text('Replace the token on every base'),
              ),
          ],
        ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              _error!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
        if (_note != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(_note!, style: theme.textTheme.bodySmall),
          ),
        if (bases != null) ...[
          const SizedBox(height: 16),
          _step(
            theme,
            2,
            'Tick the bases EzBizi should copy',
            'Unticked bases are disconnected when you save.',
          ),
          if (bases.isEmpty)
            Text(
              'This token reaches no bases. In Airtable, add bases to the '
              "token's access, then Look again.",
              style: theme.textTheme.bodySmall,
            )
          else ...[
            Row(
              children: [
                TextButton(
                  onPressed: () => setState(
                    () => _ticked.addAll([for (final b in bases) '${b['id']}']),
                  ),
                  child: const Text('Tick all'),
                ),
                TextButton(
                  onPressed: () => setState(_ticked.clear),
                  child: const Text('Untick all'),
                ),
              ],
            ),
            Card(
              margin: EdgeInsets.zero,
              child: Column(
                children: [
                  for (final (i, b) in bases.indexed) ...[
                    if (i > 0) const Divider(height: 1),
                    CheckboxListTile(
                      key: ValueKey('airtable-base-${b['id']}'),
                      dense: true,
                      value: _ticked.contains('${b['id']}'),
                      title: Text('${b['name']}'),
                      subtitle: Text(
                        [
                          if (b['tables'] is num)
                            b['tables'] == 1
                                ? '1 table'
                                : '${b['tables']} tables',
                          _permission(b['permission']),
                          if (b['connected'] == true) 'Connected now',
                        ].join(' · '),
                      ),
                      onChanged: (on) => setState(
                        () => on == true
                            ? _ticked.add('${b['id']}')
                            : _ticked.remove('${b['id']}'),
                      ),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: 12),
            Align(
              alignment: Alignment.centerLeft,
              child: FilledButton(
                key: const ValueKey('airtable-save'),
                onPressed: _busy ? null : _save,
                child: Text(
                  'Use ${_ticked.length} '
                  '${_ticked.length == 1 ? 'base' : 'bases'}',
                ),
              ),
            ),
          ],
        ],
      ],
    );
  }
}
