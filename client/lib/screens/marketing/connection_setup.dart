import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/search_picker.dart';

/// What Connect did for one account, on its card (Jose, 2026-11-12: "doing a
/// connection should take care of the whole process"): the pass/fail check
/// it ran right after connecting — can it post, how long the token lasts —
/// and each choice it made, offered inline to change.
///
/// The server decides all of it (`connect_check` and `choices` on
/// GET /social/connections, from ConnectSetup); this only draws it.
class ConnectionSetup extends StatefulWidget {
  const ConnectionSetup({
    super.key,
    required this.api,
    required this.connectionId,
    required this.check,
    required this.choices,
    this.canChange = true,
  });

  final ApiClient api;
  final String connectionId;

  /// `connect_check`: passed, summary.
  final Map<String, dynamic>? check;

  /// `choices`: key, label, selected, options [{id, name}].
  final List<dynamic> choices;
  final bool canChange;

  /// The card's line: "Passed: can post · token renews automatically".
  static String? summaryOf(Map<String, dynamic>? check) {
    final s = check?['summary'];
    return s is String && s.isNotEmpty ? s : null;
  }

  @override
  State<ConnectionSetup> createState() => _ConnectionSetupState();
}

class _ConnectionSetupState extends State<ConnectionSetup> {
  late List<Map<String, dynamic>> _choices = _read(widget.choices);
  String? _error;
  bool _busy = false;

  static List<Map<String, dynamic>> _read(List<dynamic> raw) =>
      raw.whereType<Map<String, dynamic>>().toList();

  @override
  void didUpdateWidget(ConnectionSetup old) {
    super.didUpdateWidget(old);
    if (old.choices != widget.choices) _choices = _read(widget.choices);
  }

  Future<void> _choose(String key, String value) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final r = await widget.api.patch(
      '/social/connections/${widget.connectionId}/choice',
      {'key': key, 'value': value},
    );
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (!r.ok) {
        _error = r.reason;
        return;
      }
      final data = r.body['data'] as Map<String, dynamic>? ?? const {};
      _choices = _read(data['choices'] as List<dynamic>? ?? const []);
    });
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final summary = ConnectionSetup.summaryOf(widget.check);
    final passed = widget.check?['passed'] == true;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (summary != null)
          Text(
            summary,
            key: ValueKey('connect-check-${widget.connectionId}'),
            style: TextStyle(
              color: passed ? scheme.onSurfaceVariant : scheme.error,
            ),
          ),
        for (final c in _choices)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 360),
              child: _choice(c),
            ),
          ),
        if (_error != null)
          Text(_error!, style: TextStyle(color: scheme.error)),
      ],
    );
  }

  Widget _choice(Map<String, dynamic> c) {
    final key = c['key'] as String? ?? '';
    final options = (c['options'] as List<dynamic>? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    final label = c['label'] as String? ?? key;

    if (options.isEmpty) {
      return Text(
        '$label: none found. Create one at the provider, then reconnect.',
        key: ValueKey('connect-choice-$key-${widget.connectionId}'),
        style: TextStyle(color: Theme.of(context).colorScheme.error),
      );
    }

    return SearchPickerField<String>(
      key: ValueKey('connect-choice-$key-${widget.connectionId}'),
      label: label,
      isDense: true,
      value: c['selected'] as String?,
      options: [
        for (final o in options)
          SearchOption(
            value: o['id'] as String? ?? '',
            label: o['name'] as String? ?? '',
          ),
      ],
      onChanged: widget.canChange && !_busy
          ? (v) {
              if (v != null && v != c['selected']) _choose(key, v);
            }
          : null,
    );
  }
}
