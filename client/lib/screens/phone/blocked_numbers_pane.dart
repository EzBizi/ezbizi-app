import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/refused.dart';

/// Phone › Settings › Blocked callers — who the phone will not ring for.
///
/// ── What "blocked" has to mean ───────────────────────────────────
///
/// The handsets stay quiet. The check runs on the public voice webhook before
/// anything routes, so a blocked call is refused by Twilio and never becomes a
/// ringing phone. A list that only filtered this screen would let the call
/// ring every desk in the ring group and then hide it afterwards, which is the
/// opposite of the point and looks like it works because the screen agrees
/// with itself.
///
/// ── Why the whole company and not one line ───────────────────────
///
/// Somebody blocks a caller because that caller is a nuisance, not because
/// they are a nuisance on one number. Blocking per line means doing it once
/// per line and finding out you missed one at seven in the morning.
///
/// ── Why the call is still listed after it is blocked ─────────────
///
/// "Why did nobody ring me back" needs a findable answer. A block that also
/// erased the evidence is indistinguishable from the phone being broken, so
/// the call is recorded as blocked and shows in Records.
class BlockedNumbersPane extends StatefulWidget {
  const BlockedNumbersPane({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<BlockedNumbersPane> createState() => _BlockedNumbersPaneState();
}

class _BlockedNumbersPaneState extends State<BlockedNumbersPane> {
  bool _loading = true;
  String? _error;
  String? _formError;
  bool _saving = false;

  String? _connectionId;
  List<_Blocked> _rows = const [];

  final _number = TextEditingController();
  final _reason = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _number.dispose();
    _reason.dispose();
    super.dispose();
  }

  Me get _me => widget.session.me!;

  bool get _canEdit => _me.can('phone.edit');

  String? get _editRefusal =>
      _me.refusal('phone.edit', 'change who is blocked');

  Future<void> _load() async {
    setState(() {
      _loading = _rows.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/phone/blocked-numbers');

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _error = null;
      _rows = _Blocked.listFrom(response.body);
    });

    await _loadConnection();
  }

  /// The company's phone account, which is what a block belongs to.
  Future<void> _loadConnection() async {
    final response = await widget.api.get('/phone/connections');
    if (!mounted || !response.ok) return;

    for (final row in (response.data as List? ?? const [])) {
      if (row is! Map<String, dynamic>) continue;
      setState(() => _connectionId = row['id'] as String?);

      return;
    }
  }

  /// Accept what somebody would actually type, and send E.164.
  ///
  /// The endpoint refuses anything that is not `+` and digits, which is right
  /// — but a person copying a number off a missed call has `(305) 555-0911`
  /// on their clipboard, and being told that is invalid is being told to go
  /// and reformat it by hand. Ten digits is assumed to be US; anything else
  /// has to carry its own country code, because guessing one would block a
  /// number in a country nobody meant.
  static String? _e164(String typed) {
    final digits = typed.replaceAll(RegExp(r'[^0-9+]'), '');

    if (digits.startsWith('+')) {
      return digits.length >= 8 ? digits : null;
    }
    if (digits.length == 10) return '+1$digits';
    if (digits.length == 11 && digits.startsWith('1')) return '+$digits';

    return null;
  }

  Future<void> _add() async {
    final id = _connectionId;
    if (id == null) return;

    final e164 = _e164(_number.text.trim());

    if (e164 == null) {
      setState(
        () => _formError =
            'That does not look like a phone number. Ten digits for a US '
            'number, or the full number starting with + for anywhere else.',
      );

      return;
    }

    setState(() {
      _saving = true;
      _formError = null;
    });

    final reason = _reason.text.trim();
    final response = await widget.api.post('/phone/blocked-numbers', {
      'connection_id': id,
      'e164': e164,
      if (reason.isNotEmpty) 'reason': reason,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _formError = response.reason;
      });

      return;
    }

    _number.clear();
    _reason.clear();
    setState(() => _saving = false);
    await _load();
  }

  Future<void> _unblock(_Blocked row) async {
    final response = await widget.api.delete(
      '/phone/blocked-numbers/${row.id}',
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _formError = response.reason);

      return;
    }

    await _load();
  }

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

    return ListView(
      key: const ValueKey('blocked-numbers-pane'),
      padding: const EdgeInsets.all(24),
      children: [
        Text('Blocked callers', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'These callers are turned away before any phone rings. It applies to '
          'every line this company answers, and the call still shows in '
          'Records so you can see it happened.',
          style: theme.textTheme.bodySmall,
        ),

        if (_formError != null) ...[
          const SizedBox(height: 12),
          ErrorNote(_formError!),
        ],

        const SizedBox(height: 16),

        Refused(
          reason: _canEdit ? null : _editRefusal,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    flex: 2,
                    child: TextField(
                      key: const ValueKey('blocked-number'),
                      controller: _number,
                      enabled: _canEdit,
                      keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(
                        labelText: 'Number to block',
                        hintText: '(305) 555-0911',
                        border: OutlineInputBorder(),
                        isDense: true,
                      ),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    flex: 3,
                    child: TextField(
                      key: const ValueKey('blocked-reason'),
                      controller: _reason,
                      enabled: _canEdit,
                      decoration: const InputDecoration(
                        labelText: 'Why (optional)',
                        hintText: 'Robocall, four times this week',
                        border: OutlineInputBorder(),
                        isDense: true,
                      ),
                      onSubmitted: (_) => _canEdit ? _add() : null,
                    ),
                  ),
                  const SizedBox(width: 12),
                  FilledButton(
                    onPressed: (!_canEdit || _saving || _connectionId == null)
                        ? null
                        : _add,
                    child: Text(_saving ? 'Blocking…' : 'Block'),
                  ),
                ],
              ),
            ],
          ),
        ),

        const SizedBox(height: 24),

        if (_rows.isEmpty)
          Text(
            'Nobody is blocked. You can also block a caller straight from a '
            'call in Records, which saves typing the number out.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          )
        else
          for (final row in _rows)
            Card(
              margin: const EdgeInsets.only(bottom: 8),
              child: ListTile(
                key: ValueKey('blocked-${row.id}'),
                leading: const Icon(Icons.block_outlined),
                title: Text(row.e164),
                subtitle: Text(
                  [
                    if (row.reason != null && row.reason!.isNotEmpty)
                      row.reason!,
                    if (row.blockedByName != null)
                      'Blocked by ${row.blockedByName}',
                  ].join(' · '),
                ),
                trailing: Refused(
                  reason: _canEdit ? null : _editRefusal,
                  child: TextButton(
                    onPressed: _canEdit ? () => _unblock(row) : null,
                    child: const Text('Unblock'),
                  ),
                ),
              ),
            ),
      ],
    );
  }
}

class _Blocked {
  const _Blocked({
    required this.id,
    required this.e164,
    this.reason,
    this.blockedByName,
  });

  final String id;
  final String e164;
  final String? reason;
  final String? blockedByName;

  static List<_Blocked> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return rows.whereType<Map<String, dynamic>>().map((row) {
      return _Blocked(
        id: (row['id'] ?? '').toString(),
        e164: (row['e164'] ?? '').toString(),
        reason: row['reason'] as String?,
        blockedByName: row['blocked_by_name'] as String?,
      );
    }).toList();
  }
}
