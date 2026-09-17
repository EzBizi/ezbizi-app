import 'package:flutter/material.dart';

import '../../widgets/search_picker.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';

/// Phone › Settings › Porting — numbers moving in from another carrier.
///
/// ── Why this screen matters more than its size suggests ──────────
///
/// Moving a business's main number is the most anxious thing this module does.
/// It takes days, it is driven by a carrier nobody here can telephone, and
/// until now EzBizi could START one and then show nothing: the row existed,
/// the webhook updated its status for weeks, and no screen read it.
///
/// ── What is honestly automated, and what is not ──────────────────
///
/// Submission and status are. Everything else is a carrier process this screen
/// can only report on — it does not control the losing carrier, cannot hurry
/// them, and has no API for port-OUT at all. Saying so is the point: a screen
/// that implies EzBizi is in charge of a port makes every delay look like our
/// fault, and makes somebody wait here instead of ringing their old provider.
class PortingPane extends StatefulWidget {
  const PortingPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<PortingPane> createState() => _PortingPaneState();
}

class _PortingPaneState extends State<PortingPane> {
  bool _loading = true;
  bool _starting = false;
  bool _open = false;
  String? _error;

  List<_Port> _ports = const [];
  List<_Conn> _connections = const [];
  String? _connectionId;

  final _numbers = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _numbers.dispose();
    super.dispose();
  }

  bool get _canEdit => widget.session.me?.can('phone.edit') ?? false;

  Future<void> _load() async {
    setState(() {
      _loading = _ports.isEmpty && _connections.isEmpty;
      _error = null;
    });

    final results = await Future.wait([
      widget.api.get('/phone/ports'),
      widget.api.get('/phone/connections'),
    ]);

    if (!mounted) return;

    setState(() {
      _loading = false;
      _ports = results[0].ok ? _Port.listFrom(results[0].body) : const [];
      _connections = results[1].ok ? _Conn.listFrom(results[1].body) : const [];
      _error = results[0].ok ? null : results[0].reason;
      _connectionId ??= _connections.isEmpty ? null : _connections.first.id;
    });
  }

  Future<void> _start() async {
    final connectionId = _connectionId;
    if (connectionId == null) return;

    // One per line, so pasting a list from a carrier's letter works without
    // anybody reformatting it.
    final numbers = _numbers.text
        .split(RegExp(r'[\n,;]'))
        .map((n) => n.trim())
        .where((n) => n.isNotEmpty)
        .toList();

    if (numbers.isEmpty) {
      setState(() => _error = 'Give at least one number to port in.');
      return;
    }

    setState(() {
      _starting = true;
      _error = null;
    });

    final response = await widget.api.post('/phone/ports', {
      'connection_id': connectionId,
      'e164s': numbers,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _starting = false;
        _error = response.reason;
      });
      return;
    }

    _numbers.clear();
    setState(() {
      _starting = false;
      _open = false;
    });

    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const Center(child: CircularProgressIndicator());

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Row(
          children: [
            Expanded(
              child: Text('Porting', style: theme.textTheme.titleMedium),
            ),
            if (_canEdit && _connections.isNotEmpty && !_open)
              OutlinedButton.icon(
                icon: const Icon(Icons.call_received, size: 18),
                label: const Text('New port-in'),
                onPressed: () => setState(() => _open = true),
              ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          'Move a number here from another carrier. Submitting and tracking '
          'are automated; the rest is the carriers’ own process, and this '
          'screen reports it rather than driving it.',
          style: theme.textTheme.bodySmall,
        ),

        if (_error != null) ...[
          const SizedBox(height: 16),
          ErrorNote(_error!),

          // When the load is what failed there are no ports and no accounts,
          // so nothing else on this pane is pressable.
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(onPressed: _load, child: const Text('Try again')),
          ),
        ],

        if (_open) ...[const SizedBox(height: 16), _form(theme)],

        const SizedBox(height: 24),

        if (_connections.isEmpty)
          Text(
            'No phone account is connected yet, so there is nowhere to port a '
            'number to.',
            style: theme.textTheme.bodySmall,
          )
        else if (_ports.isEmpty)
          Text(
            'No ports yet. Numbers you already own elsewhere can be moved here '
            'without changing them.',
            style: theme.textTheme.bodySmall,
          )
        else
          for (final port in _ports) _row(port, theme),

        const SizedBox(height: 24),
        _portOutNote(theme),
      ],
    );
  }

  Widget _form(ThemeData theme) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('New port-in', style: theme.textTheme.labelLarge),
          const SizedBox(height: 4),
          Text(
            'The losing carrier decides when this completes. Do not cancel '
            'service with them until it says completed — a disconnected number '
            'cannot be ported, and getting it back is a new number.',
            style: theme.textTheme.bodySmall,
          ),

          if (_connections.length > 1) ...[
            const SizedBox(height: 16),
            SearchPickerField<String>(
              value: _connectionId,
              label: 'Port into',
              border: OutlineInputBorder(),
              options: [
                for (final c in _connections)
                  SearchOption(value: c.id, label: c.label),
              ],
              onChanged: (id) => setState(() => _connectionId = id),
            ),
          ],

          const SizedBox(height: 16),
          TextField(
            controller: _numbers,
            maxLines: 4,
            decoration: const InputDecoration(
              labelText: 'Numbers',
              hintText: '+17865550123\n+17865550124',
              helperText:
                  'One per line. They must all be on the same losing '
                  'account, which is the carriers’ rule, not ours.',
              helperMaxLines: 3,
              border: OutlineInputBorder(),
            ),
          ),

          const SizedBox(height: 16),
          Row(
            children: [
              FilledButton(
                onPressed: _starting ? null : _start,
                child: Text(_starting ? 'Submitting…' : 'Submit port-in'),
              ),
              const SizedBox(width: 12),
              TextButton(
                onPressed: _starting
                    ? null
                    : () => setState(() {
                        _open = false;
                        _error = null;
                      }),
                child: const Text('Cancel'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _row(_Port port, ThemeData theme) {
    return Card(
      key: ValueKey('port:${port.id}'),
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        leading: Icon(
          port.settled ? Icons.check_circle_outline : Icons.sync_outlined,
          color: port.settled ? null : theme.colorScheme.primary,
        ),
        title: Text(port.numbers.join(', ')),
        subtitle: Text(
          port.reason.isEmpty
              ? 'Port-in · ${port.status}'
              : 'Port-in · ${port.status} — ${port.reason}',
          style: theme.textTheme.bodySmall,
        ),
        trailing: Chip(
          label: Text(port.status),
          visualDensity: VisualDensity.compact,
          materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
        ),
      ),
    );
  }

  /// Said plainly rather than offered as a button that would do nothing.
  Widget _portOutNote(ThemeData theme) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Moving a number away', style: theme.textTheme.labelLarge),
          const SizedBox(height: 4),
          Text(
            'Port-out is started by the carrier you are moving TO, not here — '
            'there is no API for it, so EzBizi cannot show its progress and '
            'will not pretend to. Give your new provider the number and the '
            'account details from Connection, and keep the line here until '
            'they confirm, because releasing it first loses the number.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}

class _Conn {
  const _Conn(this.id, this.label);

  final String id;
  final String label;

  static List<_Conn> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        _Conn(
          row['id'].toString(),
          (row['label'] ?? row['account_sid'] ?? 'Phone account').toString(),
        ),
    ];
  }
}

class _Port {
  const _Port({
    required this.id,
    required this.numbers,
    required this.status,
    required this.reason,
    required this.settled,
  });

  final String id;
  final List<String> numbers;
  final String status;
  final String reason;
  final bool settled;

  static List<_Port> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        _Port(
          id: row['id'].toString(),
          numbers: [
            for (final n in (row['e164s'] as List? ?? const [])) n.toString(),
          ],
          status: (row['status'] ?? 'unknown').toString(),
          reason: (row['status_reason'] ?? '').toString(),
          settled: row['settled'] == true,
        ),
    ];
  }
}
