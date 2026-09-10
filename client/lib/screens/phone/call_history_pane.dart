import '../../core/phone_numbers.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';

/// Recent calls, beside the dial pad.
///
/// ── Why this and not the roster ──────────────────────────────────
///
/// The column used to hold colleagues. But the thing somebody reaches for
/// while standing at a dial pad is almost always a number they have just been
/// speaking to — a customer who rang off, a missed call to return — and that
/// was two tabs away in Records while the pad sat next to a list of people
/// they were not trying to ring.
///
/// The roster has not gone: it is its own sub-tab now, which is where you go
/// when the person IS who you want.
///
/// ── Tapping fills the pad rather than dialling ───────────────────
///
/// One tap that starts a call is one tap away from ringing a customer by
/// accident while scrolling a list. Filling the field puts the number where
/// somebody can read it back before pressing Call, which costs a tap and
/// removes a class of mistake that cannot be undone once it rings.
class CallHistoryPane extends StatefulWidget {
  const CallHistoryPane({
    super.key,
    required this.api,
    required this.onPick,
    this.reloadToken = 0,
  });

  final ApiClient api;

  /// Puts a number in the dial field. Never places the call.
  final void Function(String e164) onPick;

  /// Bumped by the desk when a call ends, so the list that just gained a row
  /// shows it without anybody reloading the screen.
  final int reloadToken;

  @override
  State<CallHistoryPane> createState() => _CallHistoryPaneState();
}

class _CallHistoryPaneState extends State<CallHistoryPane> {
  bool _loading = true;
  String? _error;
  List<_Row> _rows = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(CallHistoryPane old) {
    super.didUpdateWidget(old);
    if (old.reloadToken != widget.reloadToken) _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/phone/calls', {'per_page': '40'});
    if (!mounted) return;

    setState(() {
      _loading = false;
      _error = response.ok ? null : response.reason;
      _rows = response.ok
          ? [
              for (final row in (response.data as List? ?? const []))
                if (row is Map<String, dynamic>) _Row.fromJson(row),
            ]
          : const [];
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 8, 8),
          child: Row(
            children: [
              Expanded(
                child: Text('Recent calls', style: theme.textTheme.titleSmall),
              ),
              IconButton(
                tooltip: 'Refresh',
                visualDensity: VisualDensity.compact,
                icon: const Icon(Icons.refresh, size: 18),
                onPressed: _load,
              ),
            ],
          ),
        ),
        Divider(height: 1, color: EzBiziTheme.edge(context)),
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : _error != null
              ? Padding(
                  padding: const EdgeInsets.all(14),
                  child: Text(_error!, style: theme.textTheme.bodySmall),
                )
              : _rows.isEmpty
              ? Padding(
                  padding: const EdgeInsets.all(14),
                  child: Text(
                    'No calls yet. Ones you make and take show up here.',
                    style: theme.textTheme.bodySmall,
                  ),
                )
              : ListView.builder(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  itemCount: _rows.length,
                  itemBuilder: (context, i) => _tile(_rows[i], theme),
                ),
        ),
      ],
    );
  }

  Widget _tile(_Row row, ThemeData theme) {
    final inbound = row.direction == 'inbound';

    // The number at the OTHER end, which is the one somebody wants to call
    // back. On an inbound call that is who rang us; on an outbound one it is
    // who we rang. Showing our own line would list the same number all day.
    final peer = inbound ? row.from : row.to;

    return ListTile(
      dense: true,
      visualDensity: VisualDensity.compact,
      leading: Icon(
        row.voicemail
            ? Icons.voicemail_outlined
            : inbound
            ? Icons.call_received
            : Icons.call_made,
        size: 18,
        color: row.missed
            ? theme.colorScheme.error
            : theme.colorScheme.onSurfaceVariant,
      ),
      // The colleague's NAME when the other end is one. A call to a teammate
      // is addressed to a Voice identity rather than a number, so this listed
      // rows reading `01a020d0417c714ca5bf7fb3304070e1` until the server
      // started sending who that is. Falls back to formatting the number,
      // which is every ordinary call.
      title: Text(
        row.peerName ?? formatPhoneNumber(peer),
        style: theme.textTheme.bodyMedium,
      ),
      subtitle: Text(
        row.subtitle,
        style: theme.textTheme.bodySmall?.copyWith(
          color: row.missed ? theme.colorScheme.error : null,
        ),
      ),
      onTap: peer.startsWith('+') ? () => widget.onPick(peer) : null,
    );
  }
}

class _Row {
  const _Row({
    this.peerName,
    required this.direction,
    required this.from,
    required this.to,
    required this.status,
    required this.voicemail,
    required this.createdAt,
    required this.errorCode,
  });

  final String direction;
  final String from;
  final String to;
  final String status;
  final bool voicemail;
  final String createdAt;
  final String? errorCode;

  bool get missed => const {
    'no-answer',
    'busy',
    'failed',
    'canceled',
  }.contains(status);

  /// What happened, in the fewest words that stay true.
  String get subtitle {
    if (voicemail) return 'Left a voicemail';

    return switch (status) {
      'no-answer' => 'No answer',
      'busy' => 'Busy',
      'canceled' => 'Cancelled',
      'failed' => errorCode == null ? 'Failed' : 'Failed · $errorCode',
      _ => direction == 'inbound' ? 'Incoming' : 'Outgoing',
    };
  }

  /// Who the other end is, when it is a colleague rather than a number.
  final String? peerName;

  factory _Row.fromJson(Map<String, dynamic> json) => _Row(
    peerName: json['peer_name'] as String?,
    direction: (json['direction'] ?? '').toString(),
    from: (json['from_e164'] ?? '').toString(),
    to: (json['to_e164'] ?? '').toString(),
    status: (json['status'] ?? '').toString(),
    voicemail: json['voicemail'] == true,
    createdAt: (json['created_at'] ?? '').toString(),
    errorCode: json['error_code'] as String?,
  );
}
