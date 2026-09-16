import 'package:flutter/material.dart';

import '../core/api_client.dart';

/// One field-per-secret connect form, for providers that connect by API key.
///
/// ── Why this is a widget and not a second form ──────────────────
///
/// `integrations_screen.dart` used to say, in a comment: "Deliberately a
/// sentence and not a button: connecting is done where the account is
/// understood, and a second Connect flow here would be a second thing to keep
/// correct." Jose overrode the first half on 2026-09-16 — "make the connection
/// in Integrations" — and the second half is still true, so the form is shared
/// rather than written twice.
///
/// ── Server sentences, never local ones ──────────────────────────
///
/// Every refusal shown here comes from the API's validation errors. The server
/// already knows the difference between a token Airtable rejected and a base it
/// cannot reach, and it says which FIELD — so a second set of rules in Dart
/// would be a second set to keep in step, and the day they disagree the screen
/// is the one people believe.
class ApiKeyConnectForm extends StatefulWidget {
  const ApiKeyConnectForm({
    super.key,
    required this.api,
    required this.path,
    required this.fields,
    required this.submitLabel,
    required this.onConnected,
  });

  final ApiClient api;

  /// Where to POST, e.g. `/social/airtable`.
  final String path;

  /// The secrets to collect, in order: (name the API expects, label, hint).
  final List<(String, String, String)> fields;

  final String submitLabel;
  final VoidCallback onConnected;

  @override
  State<ApiKeyConnectForm> createState() => _ApiKeyConnectFormState();
}

class _ApiKeyConnectFormState extends State<ApiKeyConnectForm> {
  final Map<String, TextEditingController> _controllers = {};
  Map<String, String> _errors = const {};
  String? _failure;
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    for (final (name, _, _) in widget.fields) {
      _controllers[name] = TextEditingController();
    }
  }

  @override
  void dispose() {
    for (final c in _controllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _sending = true;
      _errors = const {};
      _failure = null;
    });

    final response = await widget.api.post(widget.path, {
      for (final entry in _controllers.entries)
        entry.key: entry.value.text.trim(),
    });

    if (!mounted) return;

    if (response.ok) {
      for (final c in _controllers.values) {
        c.clear();
      }
      setState(() => _sending = false);
      widget.onConnected();
      return;
    }

    // 422 carries a sentence PER FIELD. Anything else is one message about the
    // whole attempt — shown apart, because a form that puts a server outage
    // under the token box reads as "your token is wrong".
    final errors =
        (response.body['errors'] as Map<String, dynamic>?) ?? const {};

    setState(() {
      _sending = false;
      _errors = {
        for (final entry in errors.entries)
          entry.key: (entry.value is List && (entry.value as List).isNotEmpty)
              ? '${(entry.value as List).first}'
              : '${entry.value}',
      };
      _failure = _errors.isEmpty ? response.message : null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final (name, label, hint) in widget.fields) ...[
          TextField(
            controller: _controllers[name],
            enabled: !_sending,
            // Not obscured. These are pasted from another tab and a person
            // needs to see whether the paste landed; a masked box turns a
            // mis-paste into "the key is wrong", which is a different fix.
            decoration: InputDecoration(
              labelText: label,
              hintText: hint,
              errorText: _errors[name],
              isDense: true,
            ),
          ),
          const SizedBox(height: 8),
        ],
        if (_failure != null) ...[
          Text(
            _failure!,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
          const SizedBox(height: 8),
        ],
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton(
            onPressed: _sending ? null : _submit,
            child: Text(_sending ? 'Connecting…' : widget.submitLabel),
          ),
        ),
      ],
    );
  }
}
