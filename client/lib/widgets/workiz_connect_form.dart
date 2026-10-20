import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import 'workiz_session_login.dart';

/// Connect a Workiz account, or replace an existing one's credentials (UI
/// queue 109, Jose 2026-10-19: "u don't get the choice, right now it only
/// shows v1" and "you also can't edit existing ones either").
///
/// The method comes first, because it decides which boxes there are: v1 is an
/// API token and, optionally, its secret (without it EzBizi reads but cannot
/// change jobs); v2 is one API key. A signed-in session (UI queue 120) is the
/// Workiz web login, handed over through the EzBizi browser extension — see
/// [WorkizSessionLogin]. It is its own `workiz_web` connection, so it is not
/// offered while editing an API connection.
///
/// Editing PUTs the same fields to the existing connection. Credentials never
/// come back from the server, so the boxes start empty and say "replace".
class WorkizConnectForm extends StatefulWidget {
  const WorkizConnectForm({
    super.key,
    required this.api,
    required this.onDone,
    this.connectionId,
    this.method,
    this.label,
    this.branches = const [],
  });

  final ApiClient api;

  /// Called after the server accepted it.
  final VoidCallback onDone;

  /// The connection being edited; null connects a new one.
  final String? connectionId;
  final String? method;
  final String? label;

  /// The branches a signed-in session's accounts can be filed on.
  final List<FranchiseSummary> branches;

  @override
  State<WorkizConnectForm> createState() => _WorkizConnectFormState();
}

class _WorkizConnectFormState extends State<WorkizConnectForm> {
  late String _method = widget.method == 'v1' ? 'v1' : 'v2';
  final _token = TextEditingController();
  final _secret = TextEditingController();
  late final _label = TextEditingController(text: widget.label ?? '');
  Map<String, String> _errors = const {};
  String? _failure;
  bool _sending = false;

  bool get _editing => widget.connectionId != null;

  @override
  void dispose() {
    _token.dispose();
    _secret.dispose();
    _label.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _sending = true;
      _errors = const {};
      _failure = null;
    });
    final body = {
      'method': _method,
      'api_token': _token.text.trim(),
      if (_method == 'v1' && _secret.text.trim().isNotEmpty)
        'auth_secret': _secret.text.trim(),
      if (_label.text.trim().isNotEmpty) 'label': _label.text.trim(),
    };
    final response = _editing
        ? await widget.api.put('/social/workiz/${widget.connectionId}', body)
        : await widget.api.post('/social/workiz', body);
    if (!mounted) return;

    if (response.ok) {
      _token.clear();
      _secret.clear();
      setState(() => _sending = false);
      widget.onDone();
      return;
    }

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
    final replace = _editing ? 'Replace ' : '';

    InputDecoration box(String name, String label, String hint) =>
        InputDecoration(
          labelText: label,
          hintText: hint,
          errorText: _errors[name],
          isDense: true,
        );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('How', style: theme.textTheme.labelLarge),
        const SizedBox(height: 6),
        Align(
          alignment: Alignment.centerLeft,
          child: SegmentedButton<String>(
            key: const ValueKey('workiz-method'),
            showSelectedIcon: false,
            segments: [
              const ButtonSegment(value: 'v1', label: Text('API v1')),
              const ButtonSegment(value: 'v2', label: Text('API v2')),
              ButtonSegment(
                value: 'session',
                enabled: !_editing,
                label: const Text('Signed-in session'),
              ),
            ],
            selected: {_method},
            onSelectionChanged: _sending
                ? null
                : (picked) => setState(() {
                    _method = picked.first;
                    _errors = const {};
                  }),
          ),
        ),
        const SizedBox(height: 6),
        if (_method == 'session')
          WorkizSessionLogin(
            api: widget.api,
            branches: widget.branches,
            onConnected: widget.onDone,
          )
        else
          ..._apiFields(theme, box, replace),
      ],
    );
  }

  List<Widget> _apiFields(
    ThemeData theme,
    InputDecoration Function(String, String, String) box,
    String replace,
  ) {
    return [
      Text(
        _method == 'v1'
            ? 'The API Token and Secret from the v1 tab of Workiz › Settings '
                  '› Developer.'
            : 'The API key from the v2 tab of Workiz › Settings › Developer.',
        key: const ValueKey('workiz-where'),
        style: theme.textTheme.bodySmall,
      ),
      const SizedBox(height: 12),
      // Not obscured: pasted from another tab, and a person needs to see
      // whether the paste landed (as ApiKeyConnectForm says).
      TextField(
        key: const ValueKey('workiz-token'),
        controller: _token,
        enabled: !_sending,
        decoration: box(
          'api_token',
          _method == 'v1' ? '${replace}API token' : '${replace}API key',
          _editing ? 'The saved one stays until you replace it' : '',
        ),
      ),
      if (_method == 'v1') ...[
        const SizedBox(height: 8),
        TextField(
          key: const ValueKey('workiz-secret'),
          controller: _secret,
          enabled: !_sending,
          decoration: box(
            'auth_secret',
            '${replace}API secret (optional)',
            'Without it, EzBizi reads jobs but cannot change them',
          ),
        ),
      ],
      const SizedBox(height: 8),
      TextField(
        key: const ValueKey('workiz-label'),
        controller: _label,
        enabled: !_sending,
        decoration: box('label', 'Name (optional)', 'e.g. Springfield'),
      ),
      if (_failure != null) ...[
        const SizedBox(height: 8),
        Text(
          _failure!,
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.error,
          ),
        ),
      ],
      const SizedBox(height: 12),
      Align(
        alignment: Alignment.centerLeft,
        child: FilledButton(
          key: const ValueKey('workiz-submit'),
          onPressed: _sending ? null : _submit,
          child: Text(
            _sending
                ? 'Saving…'
                : _editing
                ? 'Save'
                : 'Connect account',
          ),
        ),
      ),
    ];
  }
}
