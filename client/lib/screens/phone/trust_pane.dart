import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/refused.dart';

/// Phone › Settings › Trust — whether carriers believe this business is real.
///
/// ── What this is actually for ────────────────────────────────────
///
/// A number nobody has vouched for gets shown to the person being called as
/// "Scam Likely", or not connected at all. Registering the business with the
/// carriers is what stops that. It is slow, it is out of EzBizi's hands, and
/// the only honest thing to show is where each part has got to.
///
/// ── Why the statuses are sentences and not badges ────────────────
///
/// "pending" is Twilio's word and it means "a carrier is looking at this, and
/// nobody here can hurry it". Rendering that as an amber pill teaches somebody
/// to keep pressing the button. Each row says what the state means and what,
/// if anything, they can do — usually nothing, which is worth saying out loud.
class TrustPane extends StatefulWidget {
  const TrustPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<TrustPane> createState() => _TrustPaneState();
}

class _TrustPaneState extends State<TrustPane> {
  /// The four things carriers vet, in the order they matter to a caller.
  static const _rows = <(String, String, String)>[
    (
      'customer_profile',
      'Business profile',
      'Who this business is: its legal name, address and a person carriers can '
          'contact. Everything else depends on this one.',
    ),
    (
      'shaken_stir',
      'Verified caller ID',
      'Signs outgoing calls so the network can prove they really came from '
          'this number. Without it some phones show a warning.',
    ),
    (
      'cnam',
      'Your name on their screen',
      'Shows the business name instead of just a number when somebody is '
          'called.',
    ),
    (
      'voice_integrity',
      'Call reputation',
      'Keeps these numbers from being flagged as spam by carriers.',
    ),
  ];

  bool _loading = true;
  String? _error;

  List<_Conn> _connections = const [];
  _Conn? _scope;
  Map<String, String> _status = const {};

  bool _saving = false;
  String? _formError;
  String? _note;

  final _name = TextEditingController();
  final _email = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    super.dispose();
  }

  Me get _me => widget.session.me!;

  bool get _canEdit => _me.can('phone.edit');

  String? get _editRefusal =>
      _me.refusal('phone.edit', 'register this business with carriers');

  Future<void> _load() async {
    setState(() {
      _loading = _connections.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/phone/connections');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.reason;
      });
      return;
    }

    final connections = _Conn.listFrom(response.body);

    setState(() {
      _connections = connections;
      _scope ??= connections.isEmpty ? null : connections.first;
      _loading = false;
    });

    await _loadStatus();
  }

  Future<void> _loadStatus() async {
    final scope = _scope;

    if (scope == null) {
      setState(() => _status = const {});
      return;
    }

    final response = await widget.api.get('/phone/connections/${scope.id}/trust');

    if (!mounted) return;

    setState(() {
      _status = response.ok
          ? {
              for (final entry
                  in (response.body['data'] as Map? ?? const {}).entries)
                entry.key.toString(): entry.value?.toString() ?? 'unknown',
            }
          : const {};
      _note = response.ok ? null : response.reason;
    });
  }

  Future<void> _submit() async {
    final scope = _scope;
    if (scope == null) return;

    setState(() {
      _saving = true;
      _formError = null;
    });

    final response = await widget.api.post(
      '/phone/connections/${scope.id}/trust',
      {
        'friendly_name': _name.text.trim(),
        'email': _email.text.trim(),
      },
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _formError = response.reason;
      });
      return;
    }

    setState(() {
      _saving = false;
      _note = 'Sent. Carriers review this themselves; nothing else to do here.';
    });

    await _loadStatus();
  }

  /// Twilio's vocabulary, said as a person would say it.
  (String, String) _explain(String state) => switch (state) {
    'twilio-approved' || 'approved' => (
      'Approved',
      'Done. Nothing more to do.',
    ),
    'pending' || 'in-review' || 'pending-review' => (
      'With the carriers',
      'Submitted and waiting on them. This can take days, and nobody at EzBizi '
          'can hurry it.',
    ),
    'twilio-rejected' || 'rejected' => (
      'Turned down',
      'The carriers refused this. Check the business details below and send it '
          'again.',
    ),
    'noop' || 'not-started' || '' => (
      'Not sent yet',
      'Fill in the business below and send it.',
    ),
    _ => ('Not sent yet', 'Fill in the business below and send it.'),
  };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            ErrorNote(_error!),
            const SizedBox(height: 12),
            TextButton(onPressed: _load, child: const Text('Try again')),
          ],
        ),
      );
    }

    if (_connections.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'Registering a business with the carriers needs a phone account '
          'first. Switch phone service on for a company and come back.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Text('Trust', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'Carriers decide whether a number is trusted before it ever rings. '
          'This is where the business is registered with them.',
          style: theme.textTheme.bodySmall,
        ),

        if (_connections.length > 1) ...[
          const SizedBox(height: 16),
          DropdownButtonFormField<String>(
            initialValue: _scope?.id,
            decoration: const InputDecoration(
              labelText: 'Phone account',
              border: OutlineInputBorder(),
              isDense: true,
            ),
            items: [
              for (final c in _connections)
                DropdownMenuItem(value: c.id, child: Text(c.label)),
            ],
            onChanged: (id) {
              final next = _connections.where((c) => c.id == id).firstOrNull;
              if (next == null) return;
              setState(() => _scope = next);
              _loadStatus();
            },
          ),
        ],

        if (_note != null) ...[
          const SizedBox(height: 12),
          Text(_note!, style: theme.textTheme.bodySmall),
        ],

        const SizedBox(height: 20),

        for (final (key, label, why) in _rows) ...[
          Builder(
            builder: (context) {
              final (state, meaning) = _explain(_status[key] ?? '');

              return Card(
                margin: const EdgeInsets.only(bottom: 8),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(label, style: theme.textTheme.titleSmall),
                          ),
                          Text(state, style: theme.textTheme.labelMedium),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Text(why, style: theme.textTheme.bodySmall),
                      const SizedBox(height: 6),
                      Text(meaning, style: theme.textTheme.bodySmall),
                    ],
                  ),
                ),
              );
            },
          ),
        ],

        const SizedBox(height: 20),
        Text('The business carriers will see', style: theme.textTheme.labelLarge),
        const SizedBox(height: 8),

        TextField(
          controller: _name,
          decoration: const InputDecoration(
            labelText: 'Legal business name',
            border: OutlineInputBorder(),
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _email,
          keyboardType: TextInputType.emailAddress,
          decoration: const InputDecoration(
            labelText: 'Email carriers can reach',
            border: OutlineInputBorder(),
          ),
        ),

        if (_formError != null) ...[
          const SizedBox(height: 12),
          ErrorNote(_formError!),
        ],

        const SizedBox(height: 16),
        Align(
          alignment: Alignment.centerLeft,
          child: Refused(
            reason: _canEdit ? null : _editRefusal,
            child: FilledButton(
              onPressed: (!_canEdit || _saving) ? null : _submit,
              child: Text(_saving ? 'Sending…' : 'Send to the carriers'),
            ),
          ),
        ),
      ],
    );
  }
}

@immutable
class _Conn {
  const _Conn({required this.id, required this.label});

  final String id;
  final String label;

  static List<_Conn> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map(
          (r) => _Conn(
            id: r['id'].toString(),
            label: r['scope_name']?.toString() ?? 'This place',
          ),
        )
        .toList(growable: false);
  }
}
