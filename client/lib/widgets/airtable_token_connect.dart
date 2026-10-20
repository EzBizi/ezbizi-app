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

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const ValueKey('airtable-token'),
          controller: _token,
          obscureText: true,
          decoration: const InputDecoration(
            labelText: 'Access token',
            helperText: 'Airtable › Builder hub › Personal access tokens',
          ),
          onChanged: (_) => setState(() => _bases = null),
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          children: [
            FilledButton(
              onPressed: _busy || _token.text.trim().isEmpty ? null : _find,
              child: const Text('Find bases'),
            ),
            if (widget.hasConnections)
              OutlinedButton(
                onPressed: _busy || _token.text.trim().isEmpty
                    ? null
                    : _replace,
                child: const Text('Replace token for all bases'),
              ),
          ],
        ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              _error!,
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ),
        if (_note != null)
          Padding(padding: const EdgeInsets.only(top: 8), child: Text(_note!)),
        if (_bases != null) ...[
          const SizedBox(height: 12),
          Text('Bases this token reaches', style: theme.textTheme.titleSmall),
          for (final b in _bases!)
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              value: _ticked.contains(b['id']),
              title: Text('${b['name']}'),
              subtitle: Text('${b['permission']}'),
              onChanged: (on) => setState(
                () => on == true
                    ? _ticked.add('${b['id']}')
                    : _ticked.remove(b['id']),
              ),
            ),
          Align(
            alignment: Alignment.centerLeft,
            child: FilledButton(
              onPressed: _busy ? null : _save,
              child: Text(
                'Use ${_ticked.length} ${_ticked.length == 1 ? 'base' : 'bases'}',
              ),
            ),
          ),
        ],
      ],
    );
  }
}
