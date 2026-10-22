import 'dart:async';

import '../../core/phone_numbers.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/status_pill.dart';

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
///
/// ── Who to ring back first (UI queue 35, Jose's 1 + 4, 2026-09-30) ──
///
/// The list was numbers: the same one nine times in a row, a customer's
/// number still digits although every call carries its `customer_id`. Now
/// the rows fall under their day, carry the person's name when anybody knows
/// it, fold a run of calls from one number into one row ("×9"), narrow to
/// Missed or Voicemail, and say when a missed call has been returned.
class CallHistoryPane extends StatefulWidget {
  const CallHistoryPane({
    super.key,
    required this.api,
    required this.formats,
    required this.onPick,
    this.reloadToken = 0,
    this.onRows,
  });

  final ApiClient api;

  /// How this organization writes a clock and a date. Required rather than
  /// defaulted, for the reason [formatStamp] gives.
  final DisplayFormats formats;

  /// Puts a number in the dial field. Never places the call.
  final void Function(String e164) onPick;

  /// Bumped by the desk when a call ends, so the list that just gained a row
  /// shows it without anybody reloading the screen.
  final int reloadToken;

  /// Every row as loaded, for the person panel's timeline: one request feeds
  /// both, rather than the panel asking again for calls this list holds.
  final ValueChanged<List<CallRow>>? onRows;

  @override
  State<CallHistoryPane> createState() => _CallHistoryPaneState();
}

class _CallHistoryPaneState extends State<CallHistoryPane> {
  bool _loading = true;
  String? _error;
  List<CallRow> _rows = const [];

  /// all, missed or voicemail.
  String _filter = 'all';

  /// What is typed in the search box, sent as `q` 400 ms after the last key.
  String _query = '';
  Timer? _searchDebounce;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _searchDebounce?.cancel();
    super.dispose();
  }

  void _search(String value) {
    setState(() => _query = value);
    _searchDebounce?.cancel();
    _searchDebounce = Timer(const Duration(milliseconds: 400), _load);
  }

  /// Whether a call is one the search box asks for.
  ///
  /// CallsController@index (GET /phone/calls) does not read `q` yet, so the
  /// server returns the same newest 40 whatever is typed; this narrows them
  /// until it does. Once the server honours `q` it is a no-op.
  bool _hit(CallRow row) => _searchHit(_query, [
    row.customerName,
    row.callerName,
    row.peerName,
    row.answeredByName,
    row.peer,
  ]);

  @override
  void didUpdateWidget(CallHistoryPane old) {
    super.didUpdateWidget(old);
    if (old.reloadToken != widget.reloadToken) _load();
  }

  Future<void> _load() async {
    final query = _query.trim();
    final response = await widget.api.get('/phone/calls', {
      'per_page': '40',
      if (query.isNotEmpty) 'q': query,
    });
    if (!mounted) return;

    setState(() {
      _loading = false;
      _error = response.ok ? null : response.reason;
      _rows = response.ok
          ? [
              for (final row in (response.data as List? ?? const []))
                if (row is Map<String, dynamic>) CallRow.fromJson(row),
            ]
          : const [];
    });
    // Only an unsearched load feeds the person panel: a searched one is a
    // subset, and its timeline would lose every call the search left out.
    if (query.isEmpty) widget.onRows?.call(_rows);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final missed = _rows.where((r) => r.missed && r.inbound).length;
    final voicemail = _rows.where((r) => r.voicemail).length;
    // A chip that empties while chosen falls back to All.
    final filter = switch (_filter) {
      'missed' when missed > 0 => 'missed',
      'voicemail' when voicemail > 0 => 'voicemail',
      _ => 'all',
    };
    final shown = [
      for (final r in _rows)
        if (switch (filter) {
          'missed' => r.missed && r.inbound,
          'voicemail' => r.voicemail,
          _ => true,
        })
          if (_hit(r)) r,
    ];

    Widget chip(String value, String label, [int? count]) => Padding(
      padding: const EdgeInsets.only(right: 6),
      child: ChoiceChip(
        key: ValueKey('calls-chip-$value'),
        showCheckmark: false,
        visualDensity: VisualDensity.compact,
        selected: filter == value,
        label: Text(count == null ? label : '$label  $count'),
        onSelected: (_) => setState(() => _filter = value),
      ),
    );

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
        // Its own row under the heading: this column is about 210 wide
        // beside the pad, too narrow to share a line with the title.
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
          child: SizedBox(
            height: 40,
            child: TextField(
              key: const ValueKey('comm-call-history-search'),
              onChanged: _search,
              textAlignVertical: TextAlignVertical.center,
              decoration: const InputDecoration(
                isDense: true,
                hintText: 'Search name or number',
                prefixIcon: Icon(Icons.search, size: 18),
                prefixIconConstraints: BoxConstraints(minWidth: 36),
                contentPadding: EdgeInsets.symmetric(horizontal: 8),
                border: OutlineInputBorder(),
              ),
            ),
          ),
        ),
        if (missed > 0 || voicemail > 0)
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  chip('all', 'All'),
                  if (missed > 0) chip('missed', 'Missed', missed),
                  if (voicemail > 0) chip('voicemail', 'Voicemail', voicemail),
                ],
              ),
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
                    _query.trim().isNotEmpty
                        ? 'Nothing matches "${_query.trim()}".'
                        : 'No calls yet. Ones you make and take show up here.',
                    style: theme.textTheme.bodySmall,
                  ),
                )
              : shown.isEmpty && _query.trim().isNotEmpty
              ? Padding(
                  padding: const EdgeInsets.all(14),
                  child: Text(
                    'Nothing matches "${_query.trim()}".',
                    style: theme.textTheme.bodySmall,
                  ),
                )
              : _list(theme, shown),
        ),
      ],
    );
  }

  /// Day headings, then the calls of that day with runs of one kind from
  /// one number folded into the newest of them.
  Widget _list(ThemeData theme, List<CallRow> shown) {
    final items = <Object>[];
    String? day;
    for (final row in shown) {
      final at = row.at;
      final heading = at == null ? '' : _dayHeading(at);
      final last = items.isEmpty ? null : items.last;
      if (last is _Run && heading == day && last.first.sameKindAs(row)) {
        last.more++;
        continue;
      }
      if (heading != day) {
        day = heading;
        if (heading.isNotEmpty) items.add(heading);
      }
      items.add(_Run(row));
    }

    return ListView.builder(
      padding: const EdgeInsets.only(bottom: 4),
      itemCount: items.length,
      itemBuilder: (context, i) {
        final item = items[i];
        if (item is String) return _heading(theme, item);
        return _tile((item as _Run), theme);
      },
    );
  }

  Widget _heading(ThemeData theme, String text) => Container(
    padding: const EdgeInsets.fromLTRB(14, 6, 14, 6),
    color: theme.colorScheme.surfaceContainerHigh,
    child: Text(
      text,
      style: theme.textTheme.labelSmall?.copyWith(
        fontWeight: FontWeight.w800,
        color: theme.colorScheme.onSurfaceVariant,
      ),
    ),
  );

  /// Today, Yesterday, then "Fri 25 Sep".
  static String _dayHeading(DateTime at) {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final day = DateTime(at.year, at.month, at.day);
    final ago = today.difference(day).inDays;
    if (ago == 0) return 'Today';
    if (ago == 1) return 'Yesterday';
    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const months = [
      'Jan',
      'Feb',
      'Mar',
      'Apr',
      'May',
      'Jun',
      'Jul',
      'Aug',
      'Sep',
      'Oct',
      'Nov',
      'Dec',
    ];
    return '${days[at.weekday - 1]} ${at.day} ${months[at.month - 1]}';
  }

  /// A later call out to the number of a missed one: it has been returned.
  CallRow? _returned(CallRow missed) {
    final at = missed.at;
    if (at == null) return null;
    for (final r in _rows) {
      final when = r.at;
      if (!r.inbound && r.peer == missed.peer && when != null) {
        if (when.isAfter(at)) return r;
      }
    }
    return null;
  }

  Widget _tile(_Run run, ThemeData theme) {
    final row = run.first;
    final peer = row.peer;
    final muted = theme.colorScheme.onSurfaceVariant;
    final returned = row.missed && row.inbound ? _returned(row) : null;
    final at = row.at;

    final said = [
      row.subtitle,
      if (returned != null && returned.at != null)
        'called back ${_clock(returned.at!)}',
      ?row.duration,
      ?row.menuNote,
      ?row.answeredByName,
    ].join(' · ');

    return ListTile(
      key: ValueKey('call-${row.id}'),
      dense: true,
      visualDensity: VisualDensity.compact,
      leading: Icon(
        row.voicemail
            ? Icons.voicemail_outlined
            : row.inbound
            ? Icons.call_received
            : Icons.call_made,
        size: 18,
        color: row.missed ? theme.colorScheme.error : muted,
      ),
      // The colleague's NAME when the other end is one. A call to a teammate
      // is addressed to a Voice identity rather than a number, so this listed
      // rows reading `01a020d0417c714ca5bf7fb3304070e1` until the server
      // started sending who that is. Then the customer on file for the
      // number, then the number itself, formatted.
      title: Row(
        children: [
          Flexible(
            child: Text(
              row.peerName ??
                  row.customerName ??
                  row.callerName ??
                  formatPhoneNumber(peer),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodyMedium,
            ),
          ),
          if (run.more > 0) ...[
            const SizedBox(width: 6),
            Text(
              '×${run.more + 1}',
              key: ValueKey('call-run-${row.id}'),
              style: theme.textTheme.labelSmall?.copyWith(
                fontWeight: FontWeight.w800,
                color: muted,
              ),
            ),
          ],
          if (row.peerName != null || row.callerKind == 'colleague') ...[
            const SizedBox(width: 6),
            const StatusPill('Teammate'),
          ] else if (row.customerId != null ||
              row.callerKind == 'customer') ...[
            const SizedBox(width: 6),
            const StatusPill('Customer', tone: Tone.info),
          ] else if (row.callerKind == 'lead') ...[
            const SizedBox(width: 6),
            const StatusPill('Lead', tone: Tone.review),
          ],
        ],
      ),
      subtitle: Text(
        said,
        style: theme.textTheme.bodySmall?.copyWith(
          color: row.missed && returned == null
              ? theme.colorScheme.error
              : null,
        ),
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      ),
      // WHEN, right-aligned. Under a day heading the clock is enough.
      trailing: Text(
        at == null ? '' : _clock(at),
        style: theme.textTheme.bodySmall?.copyWith(color: muted),
      ),
      onTap: peer.startsWith('+') ? () => widget.onPick(peer) : null,
    );
  }

  String _clock(DateTime at) => widget.formats.formatTime(at.hour, at.minute);
}

/// A run of calls from one number on one day, drawn as its newest.
class _Run {
  _Run(this.first);

  final CallRow first;
  int more = 0;
}

/// One call, as /phone/calls sends it.
class CallRow {
  const CallRow({
    this.id = '',
    this.peerName,
    this.answeredByName,
    this.customerId,
    this.customerName,
    this.callerName,
    this.callerKind,
    this.durationSeconds,
    this.menuKey,
    this.extensionDialed,
    required this.direction,
    required this.from,
    required this.to,
    required this.status,
    required this.voicemail,
    required this.createdAt,
    required this.errorCode,
  });

  final String id;
  final String direction;
  final String from;
  final String to;
  final String status;
  final bool voicemail;
  final String createdAt;
  final String? errorCode;

  bool get inbound => direction == 'inbound';

  /// One run folds calls from one number that went the same way and ended
  /// the same way: three missed calls are "Missed ×3", and the call back
  /// after them stays a row of its own.
  bool sameKindAs(CallRow other) =>
      peer == other.peer &&
      inbound == other.inbound &&
      missed == other.missed &&
      voicemail == other.voicemail;

  /// The number at the OTHER end, which is the one somebody wants to call
  /// back. On an inbound call that is who rang us; on an outbound one it is
  /// who we rang. Showing our own line would list the same number all day.
  String get peer => inbound ? from : to;

  DateTime? get at => DateTime.tryParse(createdAt)?.toLocal();

  bool get missed =>
      const {'no-answer', 'busy', 'failed', 'canceled'}.contains(status);

  /// What happened, in the fewest words that stay true.
  String get subtitle {
    if (voicemail) return 'Left a voicemail';

    return switch (status) {
      'no-answer' => inbound ? 'Missed' : 'No answer',
      'busy' => 'Busy',
      'canceled' => 'Cancelled',
      'failed' => errorCode == null ? 'Failed' : 'Failed · $errorCode',
      _ => inbound ? 'Incoming' : 'Outgoing',
    };
  }

  /// "4 min", "0:48" under a minute; null when nobody spoke.
  String? get duration {
    final s = durationSeconds;
    if (s == null || s <= 0 || missed) return null;
    if (s < 60) return '0:${s.toString().padLeft(2, '0')}';
    return '${(s / 60).round()} min';
  }

  /// Who the other end is, when it is a colleague rather than a number.
  final String? peerName;

  /// Which of the people it rang picked up. Null on a call nobody answered,
  /// and on every call placed before the server started recording it.
  final String? answeredByName;

  /// The customer on file for the number, matched by the server.
  final String? customerId;
  final String? customerName;

  /// Who has the number when the exact match found nobody: a customer by
  /// another spelling, a lead or a colleague (`customer`, `lead`,
  /// `colleague`). Jose, 2026-10-09.
  final String? callerName;
  final String? callerKind;

  final int? durationSeconds;

  /// What the caller pressed in the menu, and any extension they typed.
  final String? menuKey;
  final String? extensionDialed;

  /// "pressed 1", "dialed ext 0101": Jose, 2026-10-05, "what client pressed
  /// on the ivr menu". Null on a call that went through no menu.
  String? get menuNote => extensionDialed != null
      ? 'dialed ext $extensionDialed'
      : menuKey == null
      ? null
      : 'pressed $menuKey';

  factory CallRow.fromJson(Map<String, dynamic> json) => CallRow(
    id: (json['id'] ?? '').toString(),
    peerName: json['peer_name'] as String?,
    answeredByName: json['answered_by_name'] as String?,
    customerId: json['customer_id'] as String?,
    customerName: json['customer_name'] as String?,
    callerName: json['caller_name'] as String?,
    callerKind: json['caller_kind'] as String?,
    durationSeconds: (json['duration_seconds'] as num?)?.toInt(),
    menuKey: json['menu_key'] as String?,
    extensionDialed: json['extension_dialed'] as String?,
    direction: (json['direction'] ?? '').toString(),
    from: (json['from_e164'] ?? '').toString(),
    to: (json['to_e164'] ?? '').toString(),
    status: (json['status'] ?? '').toString(),
    voicemail: json['voicemail'] == true,
    createdAt: (json['created_at'] ?? '').toString(),
    errorCode: json['error_code'] as String?,
  );
}

/// Whether any of [fields] holds [query], ignoring case. A query of digits
/// and phone punctuation also matches a number however it is stored, so
/// "(203) 555" finds +12035550199.
bool _searchHit(String query, Iterable<String?> fields) {
  final q = query.trim().toLowerCase();
  if (q.isEmpty) return true;
  final digits = q.replaceAll(RegExp(r'\D'), '');
  final phoneish = digits.length >= 3 && RegExp(r'^[\d\s()+.-]+$').hasMatch(q);
  for (final field in fields) {
    if (field == null || field.isEmpty) continue;
    final value = field.toLowerCase();
    if (value.contains(q)) return true;
    if (phoneish && value.replaceAll(RegExp(r'\D'), '').contains(digits)) {
      return true;
    }
  }
  return false;
}
