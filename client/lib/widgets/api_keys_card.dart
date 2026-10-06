import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import 'date_range_picker.dart';
import 'error_note.dart';
import 'status_pill.dart';

/// Your account › API keys (UI queue 62).
///
/// A key lets an AI, or a script, work in EzBizi as the person who made it:
/// their permissions, their reach, nothing more. Made, copied and revoked in
/// this card. The key itself is shown ONCE — only its hash is stored — so it
/// stays on the page until its owner says it is copied, never in a layer a
/// stray click could close.
class ApiKeysCard extends StatefulWidget {
  const ApiKeysCard({
    super.key,
    required this.api,
    this.formats = DisplayFormats.fallback,
    this.now = DateTime.now,
  });

  final ApiClient api;

  /// Which way round a date is written: "Oct 2" or "2 Oct".
  final DisplayFormats formats;

  /// The clock, so a test can say what today is.
  final DateTime Function() now;

  @override
  State<ApiKeysCard> createState() => _ApiKeysCardState();
}

class _ApiKeysCardState extends State<ApiKeysCard> {
  static const _path = '/me/api-keys';

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _keys = const [];

  /// The form is open.
  bool _making = false;
  bool _saving = false;
  String? _saveError;
  final _name = TextEditingController();

  /// Read only until somebody chooses otherwise: the safer of the two.
  String _access = 'read';

  /// The key just made, until its owner says it is copied.
  Map<String, dynamic>? _made;

  String? _confirming;
  String? _revoking;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final response = await widget.api.get(_path);
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _keys = [
        for (final row in (response.body['api_keys'] as List? ?? const []))
          if (row is Map) Map<String, dynamic>.from(row),
      ];
    });
  }

  Future<void> _make() async {
    setState(() {
      _saving = true;
      _saveError = null;
    });
    final response = await widget.api.post(_path, {
      'name': _name.text.trim(),
      'access': _access,
    });
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _saving = false;
        _saveError = response.reason;
      });
      return;
    }
    final made = Map<String, dynamic>.from(response.body);
    setState(() {
      _saving = false;
      _making = false;
      _made = made;
      _name.clear();
      _access = 'read';
      _keys = [
        {
          'id': made['id'],
          'name': made['name'],
          'access': made['access'],
          'last_used_at': null,
          'created_at': DateTime.now().toUtc().toIso8601String(),
        },
        ..._keys,
      ];
    });
  }

  Future<void> _revoke(String id) async {
    setState(() {
      _revoking = id;
      _confirming = null;
    });
    final response = await widget.api.delete('$_path/$id');
    if (!mounted) return;
    setState(() {
      _revoking = null;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _keys = [
        for (final key in _keys)
          if ('${key['id']}' != id) key,
      ];
      if ('${_made?['id']}' == id) _made = null;
    });
  }

  Future<void> _copy(String text, String said) async {
    await Clipboard.setData(ClipboardData(text: text));
    if (!mounted) return;
    ScaffoldMessenger.maybeOf(
      context,
    )?.showSnackBar(SnackBar(content: Text(said)));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text('API keys', style: theme.textTheme.titleMedium),
                ),
                if (!_making && !_loading && _error == null)
                  FilledButton.icon(
                    key: const Key('api-key-new'),
                    onPressed: () => setState(() => _making = true),
                    icon: const Icon(Icons.add, size: 18),
                    label: const Text('New API key'),
                  ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              'Let an AI like Claude, or a script, work in EzBizi as you, with '
              'your permissions and nothing more.',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 12),
            if (_loading)
              const Center(child: CircularProgressIndicator())
            else if (_error != null) ...[
              ErrorNote(_error!),
              const SizedBox(height: 8),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton(
                  onPressed: _load,
                  child: const Text('Try again'),
                ),
              ),
            ] else ...[
              if (_made case final made?) ...[
                _shownOnce(context, made),
                const SizedBox(height: 12),
              ],
              if (_making) ...[_form(context), const SizedBox(height: 12)],
              if (_keys.isEmpty)
                Text('No API keys yet.', style: theme.textTheme.bodyMedium)
              else
                for (final key in _keys) _key(context, key),
            ],
          ],
        ),
      ),
    );
  }

  Widget _form(BuildContext context) {
    final named = _name.text.trim().isNotEmpty;

    return Column(
      key: const Key('api-key-form'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const Key('api-key-name'),
          controller: _name,
          autofocus: true,
          maxLength: 60,
          decoration: const InputDecoration(
            labelText: 'What is it for?',
            hintText: 'Claude on my laptop',
            border: OutlineInputBorder(),
          ),
          onChanged: (_) => setState(() {}),
        ),
        const SizedBox(height: 8),
        LayoutBuilder(
          builder: (context, box) {
            final choices = [
              _choice(
                context,
                'read',
                'Read only',
                'Looks things up and never changes anything',
              ),
              _choice(
                context,
                'write',
                'Can make changes',
                'Creates, edits and deletes, as you can',
              ),
            ];
            // Side by side where they fit, the house rule for a short
            // choice; one over the other on a phone.
            if (box.maxWidth < 420) {
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [choices[0], const SizedBox(height: 8), choices[1]],
              );
            }
            return IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Expanded(child: choices[0]),
                  const SizedBox(width: 8),
                  Expanded(child: choices[1]),
                ],
              ),
            );
          },
        ),
        if (_saveError != null) ...[
          const SizedBox(height: 8),
          ErrorNote(_saveError!),
        ],
        const SizedBox(height: 12),
        Row(
          children: [
            FilledButton(
              key: const Key('api-key-make'),
              onPressed: named && !_saving ? _make : null,
              child: Text(_saving ? 'Making it…' : 'Make the key'),
            ),
            const SizedBox(width: 8),
            TextButton(
              onPressed: _saving
                  ? null
                  : () => setState(() {
                      _making = false;
                      _saveError = null;
                      _name.clear();
                      _access = 'read';
                    }),
              child: const Text('Cancel'),
            ),
          ],
        ),
      ],
    );
  }

  Widget _choice(
    BuildContext context,
    String value,
    String title,
    String detail,
  ) {
    final theme = Theme.of(context);
    final on = _access == value;
    final radius = BorderRadius.circular(EzBiziTheme.controlRadius);

    return Semantics(
      selected: on,
      button: true,
      child: Material(
        color: on ? theme.colorScheme.primaryContainer : Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: radius,
          side: BorderSide(
            color: on ? theme.colorScheme.primary : EzBiziTheme.edge(context),
          ),
        ),
        child: InkWell(
          key: ValueKey('api-key-access-$value'),
          borderRadius: radius,
          onTap: () => setState(() => _access = value),
          child: Padding(
            padding: const EdgeInsets.all(10),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(
                  on
                      ? Icons.radio_button_checked
                      : Icons.radio_button_unchecked,
                  size: 18,
                  color: on ? theme.colorScheme.primary : null,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        title,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      Text(detail, style: theme.textTheme.bodySmall),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// The key, once, with the line that connects Claude Code to it.
  Widget _shownOnce(BuildContext context, Map<String, dynamic> made) {
    final theme = Theme.of(context);
    final caution = ToneColours.of(context, Tone.caution);
    final key = '${made['key'] ?? ''}';
    final setup =
        'claude mcp add --transport http ezbizi ${mcpUrl()} '
        '--header "Authorization: Bearer $key"';
    final mono = theme.textTheme.bodySmall?.copyWith(
      fontFamily: 'monospace',
      color: theme.colorScheme.onSurface,
    );

    Widget line(String text, Key copyKey, String copied, String label) => Row(
      children: [
        Expanded(
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: theme.colorScheme.surface,
              borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            ),
            child: SelectableText(text, style: mono),
          ),
        ),
        const SizedBox(width: 8),
        OutlinedButton(
          key: copyKey,
          onPressed: () => _copy(text, copied),
          child: Text(label),
        ),
      ],
    );

    return Container(
      key: const Key('api-key-shown'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: caution.fill,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Copy “${made['name']}” now. It will not be shown again.',
            style: theme.textTheme.bodyMedium?.copyWith(
              color: caution.ink,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 8),
          line(key, const Key('api-key-copy'), 'Key copied.', 'Copy key'),
          const SizedBox(height: 10),
          Text(
            'To connect Claude Code, paste this into a terminal:',
            style: theme.textTheme.bodySmall?.copyWith(
              color: caution.ink,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 6),
          line(
            setup,
            const Key('api-key-copy-setup'),
            'Setup line copied.',
            'Copy',
          ),
          const SizedBox(height: 10),
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton(
              key: const Key('api-key-done'),
              onPressed: () => setState(() => _made = null),
              child: const Text("Done, I've copied it"),
            ),
          ),
        ],
      ),
    );
  }

  Widget _key(BuildContext context, Map<String, dynamic> key) {
    final theme = Theme.of(context);
    final id = '${key['id']}';
    final write = key['access'] == 'write';
    final bad = ToneColours.of(context, Tone.bad);

    if (_confirming == id) {
      return Container(
        key: ValueKey('api-key-confirm-$id'),
        margin: const EdgeInsets.symmetric(vertical: 4),
        padding: const EdgeInsets.fromLTRB(12, 4, 4, 4),
        decoration: BoxDecoration(
          color: bad.fill,
          borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        ),
        child: Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          spacing: 8,
          children: [
            Text(
              'Revoke “${key['name']}”? Anything using it stops at once.',
              style: theme.textTheme.bodySmall?.copyWith(color: bad.ink),
            ),
            FilledButton(
              key: ValueKey('api-key-revoke-yes-$id'),
              style: FilledButton.styleFrom(
                backgroundColor: theme.colorScheme.error,
                foregroundColor: theme.colorScheme.onError,
              ),
              onPressed: _revoking == id ? null : () => _revoke(id),
              child: const Text('Revoke'),
            ),
            TextButton(
              onPressed: () => setState(() => _confirming = null),
              child: const Text('Keep'),
            ),
          ],
        ),
      );
    }

    return Padding(
      key: ValueKey('api-key-$id'),
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          const Icon(Icons.key_outlined, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Wrap(
                  spacing: 8,
                  runSpacing: 2,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Text('${key['name'] ?? ''}'),
                    StatusPill(
                      write ? 'Can make changes' : 'Read only',
                      tone: write ? Tone.caution : Tone.neutral,
                    ),
                  ],
                ),
                Text(
                  [
                    _used(key['last_used_at']),
                    _madeOn(key['created_at']),
                  ].where((part) => part.isNotEmpty).join(' · '),
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
          ),
          TextButton(
            key: ValueKey('api-key-revoke-$id'),
            onPressed: () => setState(() => _confirming = id),
            child: const Text('Revoke'),
          ),
        ],
      ),
    );
  }

  /// "Never used" is a fact worth reading: a key nobody has used yet may be
  /// one somebody made and never set up.
  String _used(Object? iso) {
    final at = DateTime.tryParse('${iso ?? ''}')?.toLocal();
    if (at == null) return 'Never used';

    final ago = widget.now().difference(at);
    if (ago.inMinutes < 1) return 'Used just now';
    if (ago.inHours < 1) return 'Last used ${ago.inMinutes} min ago';
    if (ago.inDays < 1) return 'Last used ${ago.inHours} h ago';
    return 'Last used ${ago.inDays} d ago';
  }

  String _madeOn(Object? iso) {
    final at = DateTime.tryParse('${iso ?? ''}')?.toLocal();
    if (at == null) return '';

    final today = widget.now();
    if (DateUtils.isSameDay(at, today)) return 'made today';
    return 'made ${dayWords(at, today, widget.formats, weekday: false)}';
  }
}

/// Where an AI reaches EzBizi's MCP server: the API this app itself talks to.
String mcpUrl() {
  final base = Uri.base;
  final root = ApiClient.baseUrl.isNotEmpty
      ? ApiClient.baseUrl
      : (base.isScheme('http') || base.isScheme('https') ? base.origin : '');
  return '$root/api/v1/mcp';
}
