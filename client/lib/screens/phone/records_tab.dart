import '../../widgets/list_search.dart';
import '../../core/phone_numbers.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/list_table.dart';
import '../../widgets/status_pill.dart';
import 'phone_search.dart';
import 'recording_playback_bar.dart';
import 'recording_player.dart';

/// Calls, voicemail and texts: count tiles over a table, the detail beside it.
///
/// Layout A (Mia, 2026-10-02) replaced the 25/75 list and detail. The table
/// takes the whole width until a row is opened, because eight columns in a
/// quarter of the screen is a list of stacked cards again; once one is open
/// the detail sits beside it on a wide screen and replaces it on a narrow one,
/// as before.
///
/// ── What a call's outcome is ─────────────────────────────────────
///
/// The tiles split the call log by [_Outcome], worked out from the row's own
/// fields because the server sends no single "outcome" — it sends Twilio's
/// status, the dial result in `error_code`, `voicemail`, `answered_by` and the
/// duration. In order:
///
/// 1. `voicemail` → Voicemail. Such a call is also `no-answer`; the message is
///    the thing to act on, so it wins.
/// 2. `ringing` / `in-progress` → still happening. Counted in All only.
/// 3. `answered_by` set → Answered. Somebody here picked it up, whatever the
///    parent leg's status says afterwards.
/// 4. `completed`, no `error_code`, and not zero seconds long → Answered.
/// 5. `blocked` → Didn't connect: refused on purpose, and putting it in Missed
///    would list a robocaller among the people owed a callback.
/// 6. Inbound and not a technical failure (`failed`, or a Twilio numeric error
///    code rather than a `dial:` result) → Missed: they rang, nobody took it.
/// 7. Everything else → Didn't connect: an outbound call that reached nobody
///    (busy, no answer, cancelled, refused) or a call Twilio could not place.
///
/// Missed is therefore narrower than Reports' "missed" (CallReport's Twilio
/// statuses), on purpose: Reports counts calls nobody answered, and this tile
/// is the callback list, which an unanswered call WE placed does not belong on.
class RecordsTab extends StatefulWidget {
  const RecordsTab({
    super.key,
    required this.api,
    required this.session,
    this.player,
    this.initialFilter = RecordsFilter.calls,
    this.showFilters = true,
    this.reloadToken = 0,
    this.loadOnStart = true,
  });

  /// False when mounted hidden in a stack: the first visit's [reloadToken]
  /// bump loads it, so opening Phone does not fetch everything twice.
  final bool loadOnStart;

  final ApiClient api;
  final Session session;
  final RecordingPlayer? player;

  /// Which view this opens on.
  final RecordsFilter initialFilter;

  /// Bumped when this view is opened again while kept alive in a stack.
  ///
  /// It is kept alive so the softphone beside it is not torn down, which means
  /// it would otherwise show whatever it fetched the first time somebody
  /// looked — and these rows change constantly.
  final int reloadToken;

  /// Whether the Calls / Voicemail / SMS chips are shown.
  ///
  /// False where the SUB-TAB is already the filter: leaving the chips there
  /// would let somebody switch to Calls inside a tab labelled Messages, which
  /// is two controls for one fact and a heading that then lies.
  final bool showFilters;

  static const double splitAbove = 600;

  @override
  State<RecordsTab> createState() => _RecordsTabState();
}

/// Which of the three things Records holds is on screen.
///
/// Public because the Phone tab's Messages and Voicemail sub-tabs ARE these
/// views — the same widget with the filter fixed, rather than two more screens
/// reading the same rows a second way and drifting from this one.
enum RecordsFilter { calls, voicemail, sms }

class _RecordsTabState extends State<RecordsTab> {
  bool _loading = true;
  String? _error;
  List<_Call> _calls = const [];
  List<_Message> _messages = const [];
  Map<String, String> _labels = const {};

  /// Which phone account each line belongs to.
  ///
  /// Needed to block a caller from here: a block belongs to the company's
  /// account, and a call row names only the line it arrived on.
  Map<String, String> _connectionOf = const {};

  /// The call whose caller has just been blocked, so the button can say so
  /// rather than looking like it did nothing.
  String? _blockedCallId;

  late RecordsFilter _filter = widget.initialFilter;

  /// What is typed in the search box. Filtered over the pages loaded so far;
  /// "Load older" fetches the next [_pageSize] of calls and of texts.
  static const _pageSize = 200;
  int _page = 1;
  bool _hasOlder = false;
  bool _loadingOlder = false;
  String _query = '';

  /// The tile picked, null for all. Its keys depend on the view: an [_Outcome]
  /// name or `untagged` for calls, `waiting`/`answered`/`today` for texts.
  String? _tile;
  _Call? _openCall;

  /// The counterpart number whose conversation is open, if any.
  ///
  /// Held as the NUMBER rather than a thread object, so the open conversation
  /// survives a refetch: a new text arriving rebuilds every thread, and a
  /// reference to the old one would leave the pane showing a conversation that
  /// no longer gains messages.
  String? _openThread;

  late final RecordingPlayer _player;
  late final bool _ownsPlayer;

  @override
  void initState() {
    super.initState();
    _ownsPlayer = widget.player == null;
    _player = widget.player ?? createRecordingPlayer();
    if (widget.loadOnStart) {
      _load();
    } else {
      _loading = false; // a spinner nobody sees never settles
    }
  }

  @override
  void dispose() {
    _player.stop();
    if (_ownsPlayer) _player.dispose();
    super.dispose();
  }

  bool get _hasOpen => _openCall != null || _openThread != null;

  /// What the open view lists, after its tile.
  ///
  /// Calls now lists every call, voicemails included: the Voicemail TILE is
  /// how they are picked out of it, and a Calls view that dropped them would
  /// show a Voicemail tile counting rows it could never list. The Voicemail
  /// chip stays the voicemail-only list it was, without tiles (every row in it
  /// has the one outcome).
  List<Object> get _rows {
    switch (_filter) {
      case RecordsFilter.calls:
        return [
          for (final call in _calls)
            if (_callMatches(call, _tile) && _callHit(call)) call,
        ];
      case RecordsFilter.voicemail:
        return [
          for (final call in _calls)
            if (call.voicemail && _callHit(call)) call,
        ];
      case RecordsFilter.sms:
        return [
          for (final thread in _threads)
            if (_threadMatches(thread, _tile) && _threadHit(thread)) thread,
        ];
    }
  }

  bool _callHit(_Call call) => phoneSearchHit(_query, [
    call.customerName,
    call.peerName,
    call.answeredByName,
    call.fromE164,
    call.toE164,
    _labels[call.numberId],
    call.tag,
    call.voicemailTranscript,
  ]);

  bool _threadHit(_Thread thread) => phoneSearchHit(_query, [
    thread.peer,
    thread.name,
    for (final message in thread.messages) message.body,
  ]);

  bool _callMatches(_Call call, String? tile) {
    if (tile == null) return true;
    if (tile == 'untagged') return call.tag == null || call.tag!.isEmpty;
    return _outcome(call).name == tile;
  }

  /// Waiting on us: they wrote last. Answered: we did. Today: anything said
  /// today, either way (Mia, 2026-10-02).
  bool _threadMatches(_Thread thread, String? tile) {
    return switch (tile) {
      null => true,
      'waiting' => !thread.latest.outbound,
      'answered' => thread.latest.outbound,
      'today' => _isToday(thread.latest.createdAt),
      _ => true,
    };
  }

  bool _isToday(String iso) {
    final at = DateTime.tryParse(iso)?.toLocal();
    if (at == null) return false;
    final now = DateTime.now();
    return at.year == now.year && at.month == now.month && at.day == now.day;
  }

  /// See the class comment for the rule and why it is in this order.
  _Outcome _outcome(_Call call) {
    if (call.voicemail) return _Outcome.voicemail;
    if (call.status == 'ringing' || call.status == 'in-progress') {
      return _Outcome.live;
    }
    if (call.answeredBy != null && call.answeredBy!.isNotEmpty) {
      return _Outcome.answered;
    }

    final code = call.errorCode ?? '';
    if (call.status == 'completed' &&
        code.isEmpty &&
        call.durationSeconds != 0) {
      return _Outcome.answered;
    }
    if (call.status == 'blocked') return _Outcome.failed;

    final technical =
        call.status == 'failed' ||
        (code.isNotEmpty && !code.startsWith('dial:'));
    if (call.direction == 'inbound' && !technical) return _Outcome.missed;

    return _Outcome.failed;
  }

  /// One row per PERSON, newest first — not one row per text.
  ///
  /// Two messages from the same number used to be two rows saying the same
  /// number twice, which reads as two strangers and hides the thing somebody
  /// is actually looking for: the conversation. Grouped on the counterpart,
  /// which is the other end whichever way the message went — our own line
  /// would collapse the whole day into one row.
  List<_Thread> get _threads {
    final byPeer = <String, List<_Message>>{};

    for (final message in _messages) {
      final peer = message.outbound ? message.toE164 : message.fromE164;
      (byPeer[peer] ??= []).add(message);
    }

    final threads = [
      for (final entry in byPeer.entries)
        _Thread(
          peer: entry.key,
          messages: entry.value
            ..sort((a, b) => a.createdAt.compareTo(b.createdAt)),
        ),
    ];

    threads.sort((a, b) => b.latest.createdAt.compareTo(a.latest.createdAt));

    return threads;
  }

  Future<void> _load() async {
    setState(() {
      _loading = _calls.isEmpty && _messages.isEmpty;
      _error = null;
    });

    final page = {'per_page': '$_pageSize', 'page': '1'};
    final calls = widget.api.get('/phone/calls', page);
    final messages = widget.api.get('/phone/messages', page);
    final numbers = widget.api.get('/phone/numbers');

    final callsRes = await calls;
    final messagesRes = await messages;
    final numbersRes = await numbers;
    if (!mounted) return;

    if (!callsRes.ok) {
      setState(() {
        _loading = false;
        _error = callsRes.message;
      });
      return;
    }
    if (!messagesRes.ok) {
      setState(() {
        _loading = false;
        _error = messagesRes.message;
      });
      return;
    }

    final labels = <String, String>{};
    final connections = <String, String>{};
    if (numbersRes.ok) {
      for (final row in (numbersRes.data as List? ?? const [])) {
        if (row is Map<String, dynamic>) {
          final id = row['id'] as String? ?? '';
          labels[id] =
              (row['label'] as String?) ??
              formatPhoneNumber(row['e164'] as String?);

          final connection = row['connection_id'] as String?;
          if (connection != null && connection.isNotEmpty) {
            connections[id] = connection;
          }
        }
      }
    }

    setState(() {
      _loading = false;
      _labels = labels;
      _connectionOf = connections;
      _calls = [
        for (final row in (callsRes.data as List? ?? const []))
          if (row is Map<String, dynamic>) _Call.fromJson(row),
      ];
      _messages = [
        for (final row in (messagesRes.data as List? ?? const []))
          if (row is Map<String, dynamic>) _Message.fromJson(row),
      ];
      _page = 1;
      _hasOlder = _calls.length >= _pageSize || _messages.length >= _pageSize;
    });
  }

  Future<void> _loadOlder() async {
    setState(() => _loadingOlder = true);
    final page = {'per_page': '$_pageSize', 'page': '${_page + 1}'};
    final calls = widget.api.get('/phone/calls', page);
    final messages = widget.api.get('/phone/messages', page);
    final callsRes = await calls;
    final messagesRes = await messages;
    if (!mounted) return;
    final more = [
      for (final row
          in (callsRes.ok ? callsRes.data as List? ?? const [] : const []))
        if (row is Map<String, dynamic>) _Call.fromJson(row),
    ];
    final moreTexts = [
      for (final row
          in (messagesRes.ok
              ? messagesRes.data as List? ?? const []
              : const []))
        if (row is Map<String, dynamic>) _Message.fromJson(row),
    ];
    setState(() {
      _loadingOlder = false;
      if (!callsRes.ok || !messagesRes.ok) return;
      _page++;
      // A row that arrived since page 1 shifts the pages by one: skip repeats.
      final haveCalls = {for (final c in _calls) c.id};
      final haveTexts = {for (final m in _messages) m.id};
      _calls = [..._calls, ...more.where((c) => !haveCalls.contains(c.id))];
      _messages = [
        ..._messages,
        ...moreTexts.where((m) => !haveTexts.contains(m.id)),
      ];
      _hasOlder = more.length >= _pageSize || moreTexts.length >= _pageSize;
    });
  }

  void _selectCall(_Call call) {
    _player.stop();
    setState(() {
      _openCall = call;
      _openThread = null;
    });
  }

  void _selectThread(_Thread thread) {
    _player.stop();
    setState(() {
      _openThread = thread.peer;
      _openCall = null;
    });
  }

  void _clear() {
    _player.stop();
    setState(() {
      _openCall = null;
      _openThread = null;
    });
  }

  void _setFilter(RecordsFilter filter) {
    _player.stop();
    setState(() {
      _filter = filter;
      _tile = null;
      _openCall = null;
      _openThread = null;
    });
  }

  @override
  void didUpdateWidget(RecordsTab old) {
    super.didUpdateWidget(old);
    // 0 is "not showing": leaving the tab must not refetch it hidden.
    if (old.reloadToken != widget.reloadToken && widget.reloadToken != 0) {
      _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= RecordsTab.splitAbove;
        final paneOnly = !wide && _hasOpen;
        final beside = wide && _hasOpen;

        return AsyncBody(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: _calls.isEmpty && _messages.isEmpty
              ? Center(
                  key: const ValueKey('tour-phone-records-empty'),
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Text(
                      'There are no calls or messages yet.',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodyLarge,
                    ),
                  ),
                )
              : paneOnly
              ? KeyedSubtree(
                  key: const Key('phone-records-pane'),
                  child: _pane(showBack: true),
                )
              : beside
              ? Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Expanded(
                      flex: 3,
                      child: KeyedSubtree(
                        key: const Key('phone-records-list'),
                        child: _list(),
                      ),
                    ),
                    VerticalDivider(
                      width: 1,
                      thickness: 1,
                      color: EzBiziTheme.edge(context),
                    ),
                    Expanded(
                      flex: 2,
                      child: KeyedSubtree(
                        key: const Key('phone-records-pane'),
                        child: _pane(showBack: false),
                      ),
                    ),
                  ],
                )
              : KeyedSubtree(
                  key: const Key('phone-records-list'),
                  child: _list(),
                ),
        );
      },
    );
  }

  Widget _list() {
    final theme = Theme.of(context);
    final rows = _rows;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Wrap(
            spacing: 12,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [if (widget.showFilters) _filterChips(), _searchField()],
          ),
        ),
        if (_filter == RecordsFilter.calls) ...[
          _callTiles(),
          const SizedBox(height: 12),
        ],
        if (_filter == RecordsFilter.sms) ...[
          _threadTiles(),
          const SizedBox(height: 12),
        ],
        if (rows.isEmpty)
          Padding(
            padding: const EdgeInsets.all(24),
            child: Text(
              _query.trim().isNotEmpty
                  ? 'Nothing matches "${_query.trim()}".'
                  : 'Nothing matches that filter.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyMedium,
            ),
          )
        else if (_filter == RecordsFilter.sms)
          _threadTable(rows.cast<_Thread>())
        else
          _callTable(rows.cast<_Call>()),
        if (_hasOlder)
          Padding(
            padding: const EdgeInsets.only(top: 12),
            child: Center(
              child: TextButton(
                key: const Key('records-load-older'),
                onPressed: _loadingOlder ? null : _loadOlder,
                child: Text(_loadingOlder ? 'Loading…' : 'Load older'),
              ),
            ),
          ),
      ],
    );
  }

  // The chip row goes whole, not each chip: a sub-tab that IS the filter
  // must not also offer a way to switch away from itself.
  Widget _filterChips() => Wrap(
    key: const ValueKey('tour-phone-records-filter'),
    spacing: 8,
    runSpacing: 8,
    children: [
      ChoiceChip(
        label: const Text('Calls'),
        selected: _filter == RecordsFilter.calls,
        onSelected: (_) => _setFilter(RecordsFilter.calls),
      ),
      ChoiceChip(
        label: const Text('Voicemail'),
        selected: _filter == RecordsFilter.voicemail,
        onSelected: (_) => _setFilter(RecordsFilter.voicemail),
      ),
      ChoiceChip(
        label: const Text('SMS'),
        selected: _filter == RecordsFilter.sms,
        onSelected: (_) => _setFilter(RecordsFilter.sms),
      ),
    ],
  );

  /// One box for all three views, keyed per view so a test (and the tour) can
  /// tell which list it searches. Standard field height (Jose, 2026-10-27).
  Widget _searchField() => SizedBox(
    width: 280,
    child: ListSearch(
      key: ValueKey(switch (_filter) {
        RecordsFilter.calls => 'comm-records-calls-search',
        RecordsFilter.voicemail => 'comm-records-voicemail-search',
        RecordsFilter.sms => 'comm-records-messages-search',
      }),
      hint: _filter == RecordsFilter.sms
          ? 'Search name, number or text'
          : 'Search name, number or tag',
      onChanged: (value) => setState(() => _query = value),
    ),
  );

  void _pickTile(String? key) {
    _player.stop();
    setState(() {
      _tile = key;
      _openCall = null;
      _openThread = null;
    });
  }

  /// Counted over every call this person can read: the endpoint returns the
  /// whole log, so the count is the rows', never a page's.
  Widget _callTiles() {
    int count(String key) => _calls.where((c) => _callMatches(c, key)).length;

    return FilterTiles(
      key: const ValueKey('tour-phone-records-tiles'),
      prefix: 'records',
      picked: _tile,
      onPick: _pickTile,
      tiles: [
        FilterTile(
          key: null,
          label: 'All calls',
          count: _calls.length,
          note: 'every call',
        ),
        FilterTile(
          key: _Outcome.answered.name,
          label: 'Answered',
          count: count(_Outcome.answered.name),
          note: 'picked up',
          tone: Tone.ok,
        ),
        FilterTile(
          key: _Outcome.missed.name,
          label: 'Missed',
          count: count(_Outcome.missed.name),
          note: 'nobody answered',
          tone: Tone.bad,
        ),
        FilterTile(
          key: _Outcome.voicemail.name,
          label: 'Voicemail',
          count: count(_Outcome.voicemail.name),
          note: 'left a message',
          tone: Tone.caution,
        ),
        FilterTile(
          key: _Outcome.failed.name,
          label: "Didn't connect",
          count: count(_Outcome.failed.name),
          note: 'busy, no answer, refused',
        ),
        FilterTile(
          key: 'untagged',
          label: 'Not tagged',
          count: count('untagged'),
          note: 'no reason given',
        ),
      ],
    );
  }

  Widget _threadTiles() {
    final threads = _threads;
    int count(String key) =>
        threads.where((t) => _threadMatches(t, key)).length;

    return FilterTiles(
      key: const ValueKey('tour-phone-messages-tiles'),
      prefix: 'messages',
      picked: _tile,
      onPick: _pickTile,
      tiles: [
        FilterTile(
          key: null,
          label: 'Conversations',
          count: threads.length,
          note: 'all',
        ),
        FilterTile(
          key: 'waiting',
          label: 'Waiting on us',
          count: count('waiting'),
          note: 'their message was last',
          tone: Tone.caution,
        ),
        FilterTile(
          key: 'answered',
          label: 'Answered',
          count: count('answered'),
          note: 'we wrote last',
        ),
        FilterTile(
          key: 'today',
          label: 'Today',
          count: count('today'),
          note: 'active today',
        ),
      ],
    );
  }

  /// One call per row (layout A, Mia 2026-10-02): who and which way, the line,
  /// who answered, how it ended, the tag, how long, and when.
  Widget _callTable(List<_Call> calls) {
    return ListTable(
      columns: const [
        ListColumn('Who', flex: 4),
        ListColumn('Line', flex: 2),
        ListColumn('Answered by', flex: 2),
        ListColumn('Status', flex: 3),
        ListColumn('Tag', flex: 2),
        ListColumn('Length', flex: 2, end: true),
        ListColumn('When', flex: 2, end: true),
      ],
      rowKey: (i) => Key('phone-record-${calls[i].id}'),
      onTap: (i) => _selectCall(calls[i]),
      rows: [for (final call in calls) _callCells(call)],
    );
  }

  List<Widget> _callCells(_Call call) {
    final theme = Theme.of(context);
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final inbound = call.direction == 'inbound';
    final number = formatPhoneNumber(inbound ? call.fromE164 : call.toE164);

    // A customer's name first, a colleague's next, the number last — and the
    // number under a name, because the name alone cannot be rung back.
    final name = call.customerName ?? call.peerName;
    final failure = _failure(call);
    final recorded = call.recordingSid != null && call.recordingSid!.isNotEmpty;

    return [
      // Direction rides in the first cell rather than a column of its own:
      // below 720 the first cell is the row's heading, and an arrow on its own
      // line above everything else says nothing.
      Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            inbound ? Icons.call_received : Icons.call_made,
            size: 18,
            color: theme.colorScheme.onSurfaceVariant,
          ),
          const SizedBox(width: 10),
          Flexible(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  name ?? number,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
                Text(
                  [
                    inbound ? 'Inbound' : 'Outbound',
                    if (name != null) number,
                  ].join(' · '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: small,
                ),
              ],
            ),
          ),
        ],
      ),
      Text(
        _labels[call.numberId] ?? '—',
        overflow: TextOverflow.ellipsis,
        style: small,
      ),
      Text(
        call.answeredByName ?? '—',
        overflow: TextOverflow.ellipsis,
        style: small,
      ),
      Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          _statusPill(call),
          // A failure says why, here, rather than in Twilio's console.
          if (failure != null)
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Text(
                failure,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: ToneColours.of(context, Tone.bad).ink,
                ),
              ),
            ),
        ],
      ),
      call.tag == null ? Text('—', style: small) : _tagChip(call, theme),
      Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            call.durationSeconds == null ? '—' : _clock(call.durationSeconds!),
            style: small,
          ),
          if (recorded) ...[
            const SizedBox(width: 4),
            Icon(
              Icons.mic_none,
              size: 14,
              semanticLabel: 'recorded',
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ],
        ],
      ),
      Text(
        formatListStamp(
          call.createdAt,
          '',
          formats: widget.session.me!.display,
        ),
        style: small,
      ),
    ];
  }

  Widget _statusPill(_Call call) {
    return switch (_outcome(call)) {
      _Outcome.answered => const StatusPill('Answered', tone: Tone.ok),
      _Outcome.missed => const StatusPill('Missed', tone: Tone.bad),
      _Outcome.voicemail => const StatusPill('Voicemail', tone: Tone.caution),
      _Outcome.live => StatusPill(
        call.status == 'ringing' ? 'Ringing' : 'On the call',
        tone: Tone.info,
      ),
      _Outcome.failed => StatusPill(
        call.status == 'blocked' ? 'Blocked' : "Didn't connect",
      ),
    };
  }

  /// One conversation per row: who, the last thing said, how many, when.
  Widget _threadTable(List<_Thread> threads) {
    return ListTable(
      columns: const [
        ListColumn('Customer', flex: 3),
        ListColumn('Last message', flex: 5),
        ListColumn('Messages', flex: 1, end: true),
        ListColumn('When', flex: 2, end: true),
      ],
      rowKey: (i) => Key('phone-thread-${threads[i].peer}'),
      onTap: (i) => _selectThread(threads[i]),
      rows: [for (final thread in threads) _threadCells(thread)],
    );
  }

  List<Widget> _threadCells(_Thread thread) {
    final theme = Theme.of(context);
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final latest = thread.latest;
    final number = formatPhoneNumber(thread.peer);
    final name = thread.name;

    return [
      PersonCell(name: name ?? number, sub: name == null ? null : number),
      Text(
        // Said out loud which way it went. Without it the last line of a
        // thread reads as something they said when it is often the last thing
        // we said to them.
        latest.outbound ? 'You: ${latest.body}' : latest.body,
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
        style: small,
      ),
      Text('${thread.messages.length}', style: small),
      Text(
        formatListStamp(
          latest.createdAt,
          '',
          formats: widget.session.me!.display,
        ),
        style: small,
      ),
    ];
  }

  /// The tag, tinted. An untinted tag still reads — colour is what makes a
  /// list scannable, not what makes it legible, so the name is never carried
  /// by the colour alone.
  /// The failure in a sentence, or null when the call was fine.
  ///
  /// Twilio's own message is preferred over anything composed here: a code we
  /// have never seen still explains itself, which a lookup table cannot do.
  String? _failure(_Call call) {
    final code = call.errorCode;
    if (code == null || code.isEmpty) return null;

    if (code.startsWith('dial:')) {
      return switch (code.substring(5)) {
        'busy' => 'The number was busy.',
        'no-answer' => 'No answer.',
        'canceled' || 'cancelled' => 'Cancelled before it connected.',
        'failed' =>
          'The call could not be connected. Twilio refused it — the '
              'usual cause is a caller ID this phone account does not own.',
        final other => 'Did not connect ($other).',
      };
    }

    final message = call.errorMessage;

    return message == null || message.isEmpty
        ? 'Twilio error $code.'
        : 'Twilio error $code: $message';
  }

  Widget _tagChip(_Call call, ThemeData theme) {
    final hex = call.tagColor?.replaceFirst('#', '');
    final value = hex == null ? null : int.tryParse(hex, radix: 16);
    final colour = value == null ? null : Color(0xFF000000 | value);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: colour?.withValues(alpha: 0.15),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: colour ?? theme.colorScheme.outlineVariant),
      ),
      child: Text(call.tag!, style: theme.textTheme.labelSmall),
    );
  }

  Widget _pane({required bool showBack}) {
    final theme = Theme.of(context);
    final call = _openCall;
    final thread = _openThread == null
        ? null
        : _threads.where((row) => row.peer == _openThread).firstOrNull;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Beside the table there is nothing to go back to, so it closes
        // instead: the table is full width again with nothing open.
        if (!showBack)
          Align(
            alignment: Alignment.centerRight,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(8, 8, 8, 0),
              child: IconButton(
                icon: const Icon(Icons.close),
                tooltip: 'Close',
                onPressed: _clear,
              ),
            ),
          ),
        if (showBack)
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 12, 20, 8),
            child: Row(
              children: [
                IconButton(
                  icon: const Icon(Icons.arrow_back),
                  tooltip: 'Back to the list',
                  onPressed: _clear,
                ),
                Expanded(
                  child: Text(
                    call != null
                        ? _callTitle(call)
                        : thread != null
                        ? (thread.name ?? formatPhoneNumber(thread.peer))
                        : 'Records',
                    style: theme.textTheme.titleMedium,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
          ),
        Expanded(
          child: call != null
              ? _callDetail(call)
              : thread != null
              ? _threadDetail(thread)
              : Center(
                  child: Text(
                    'Choose a call or message.',
                    style: theme.textTheme.bodyMedium,
                  ),
                ),
        ),
      ],
    );
  }

  Widget _callDetail(_Call call) {
    final theme = Theme.of(context);
    final formats = widget.session.me!.display;
    final line = _labels[call.numberId] ?? '';
    final canPlay = call.recordingSid != null && call.recordingSid!.isNotEmpty;

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
      children: [
        Text(_callTitle(call), style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          [
            if (line.isNotEmpty) line,
            formatStamp(call.createdAt, '', formats: formats),
            if (call.durationSeconds != null) _clock(call.durationSeconds!),

            // Who took it. An inbound call rings a whole ring group, so the
            // record used to say a call was answered without saying by whom —
            // and a customer ringing back to continue the conversation could
            // not be put through to the person they had it with.
            if (call.answeredByName != null)
              'Answered by ${call.answeredByName}',

            // What they pressed at the menu (Jose, 2026-10-05).
            if (call.extensionDialed != null)
              'Dialed ext ${call.extensionDialed}'
            else if (call.menuKey != null)
              'Pressed ${call.menuKey}',
          ].where((part) => part.isNotEmpty).join(' · '),
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        if (canPlay) ...[
          const SizedBox(height: 12),
          RecordingPlaybackBar(
            key: Key('call-audio-${call.id}'),
            player: _player,
            load: () =>
                widget.api.getBytes('/phone/calls/${call.id}/recording'),
          ),
        ],
        // Two transcripts, and they are not the same thing: the live one is
        // the conversation, the other is the message left after it. A call can
        // have both, and showing one under the other's heading is how the
        // Voicemail screen came to read callers their own IVR prompt back.
        for (final part in [
          ('Call transcript', call.transcript),
          ('Voicemail message', call.voicemailTranscript),
        ])
          if (part.$2 != null && part.$2!.isNotEmpty) ...[
            const SizedBox(height: 16),
            Text(part.$1, style: theme.textTheme.labelMedium),
            const SizedBox(height: 4),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              color: theme.colorScheme.surfaceContainerHighest.withValues(
                alpha: 0.4,
              ),
              child: Text(part.$2!, style: theme.textTheme.bodyMedium),
            ),
          ],

        // Block from HERE, because this is where somebody is standing when
        // they decide to. The settings screen has the same list and a box to
        // type a number into; nobody copies a number out of a call log by
        // hand if the call is already open in front of them.
        //
        // Inbound only. Blocking the number you dialled out to would be
        // blocking a customer from ever reaching you, from a button that
        // looks like it is about this one call.
        if (_canBlock(call)) ...[
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerLeft,
            child: _blockedCallId == call.id
                ? Text(
                    '${formatPhoneNumber(call.fromE164)} is blocked. No phone '
                    'will ring for them.',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  )
                : TextButton.icon(
                    key: Key('block-caller-${call.id}'),
                    // Red: it stops every phone ringing for them.
                    style: TextButton.styleFrom(
                      foregroundColor: Theme.of(context).colorScheme.error,
                    ),
                    icon: const Icon(Icons.block_outlined, size: 18),
                    onPressed: () => _block(call),
                    label: const Text('Block this caller'),
                  ),
          ),
        ],
      ],
    );
  }

  /// Inbound, from a real number, on a line whose account we know, by
  /// somebody allowed to change how calls are routed.
  bool _canBlock(_Call call) {
    return call.direction == 'inbound' &&
        call.fromE164.startsWith('+') &&
        _connectionOf[call.numberId] != null &&
        widget.session.me!.can('phone.edit');
  }

  Future<void> _block(_Call call) async {
    final connection = _connectionOf[call.numberId];
    if (connection == null) return;

    final response = await widget.api.post('/phone/blocked-numbers', {
      'connection_id': connection,
      'e164': call.fromE164,
    });

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.reason)));

      return;
    }

    setState(() => _blockedCallId = call.id);
  }

  /// The conversation, oldest at the top, each side on its own side.
  ///
  /// It reads as a chat because that is what it is. The old pane showed one
  /// message with no idea what came before it, which is the wrong shape for
  /// the question somebody opens this to answer: what have we said to each
  /// other.
  Widget _threadDetail(_Thread thread) {
    final theme = Theme.of(context);
    final formats = widget.session.me!.display;

    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
      itemCount: thread.messages.length,
      itemBuilder: (context, i) {
        final message = thread.messages[i];

        return Align(
          alignment: message.outbound
              ? Alignment.centerRight
              : Alignment.centerLeft,
          child: Container(
            constraints: const BoxConstraints(maxWidth: 420),
            margin: const EdgeInsets.only(bottom: 10),
            padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
            decoration: BoxDecoration(
              color: message.outbound
                  ? theme.colorScheme.primaryContainer
                  : theme.colorScheme.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(12),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(message.body, style: theme.textTheme.bodyMedium),
                const SizedBox(height: 4),
                Text(
                  formatStamp(message.createdAt, '', formats: formats),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  String _callTitle(_Call call) {
    // The colleague's name where there is one. Records listed a call to a
    // teammate as 32 characters of hex, because a client call is addressed to
    // a Voice identity rather than a number.
    final who =
        call.peerName ??
        (call.direction == 'inbound' ? call.fromE164 : call.toE164);

    if (call.voicemail) return 'Voicemail · $who';

    return call.direction == 'inbound' ? 'Inbound · $who' : 'Outbound · $who';
  }

  String _clock(int seconds) {
    final minutes = seconds ~/ 60;
    final rest = seconds % 60;
    return '$minutes:${rest.toString().padLeft(2, '0')}';
  }
}

class _Call {
  const _Call({
    required this.id,
    required this.numberId,
    required this.direction,
    required this.fromE164,
    required this.toE164,
    required this.voicemail,
    required this.durationSeconds,
    required this.recordingSid,
    required this.transcript,
    required this.createdAt,
    this.errorCode,
    this.errorMessage,
    this.tag,
    this.tagColor,
    this.peerName,
    this.answeredByName,
    this.voicemailTranscript,
    this.status = '',
    this.answeredBy,
    this.customerName,
    this.menuKey,
    this.extensionDialed,
  });

  /// What the caller pressed in the menu, and any extension they typed.
  final String? menuKey;
  final String? extensionDialed;

  /// Twilio's status as stored: ringing, in-progress, completed, no-answer,
  /// busy, failed, canceled, or blocked.
  final String status;

  /// The user id of whoever picked up, when one of ours did.
  final String? answeredBy;

  /// The customer whose number this is, when one matches.
  final String? customerName;

  /// Which of the people it rang picked up, when that is known.
  final String? answeredByName;

  /// The message left afterwards, which is a different recording from the call
  /// and a different Twilio transcription.
  final String? voicemailTranscript;

  /// Who the other end is, when it is a colleague rather than a number. A
  /// call to a teammate is addressed to a Voice identity, so without this the
  /// record reads as 32 characters of hex.
  final String? peerName;

  final String id;
  final String numberId;
  final String direction;
  final String fromE164;
  final String toE164;
  final bool voicemail;
  final int? durationSeconds;
  final String? recordingSid;
  final String? transcript;
  final String createdAt;

  /// Why this call failed, as Twilio said it — not as we guessed. A row that
  /// said only "failed" is what turned four separate outbound defects into
  /// four rounds of guessing at the cause.
  final String? errorCode;
  final String? errorMessage;

  /// What the call was about, and the colour picked for it. Shown here rather
  /// than only counted in Reports: a call list you can read at a glance is the
  /// reason anybody bothers to tag a call at all.
  final String? tag;
  final String? tagColor;

  factory _Call.fromJson(Map<String, dynamic> json) => _Call(
    id: json['id'] as String? ?? '',
    numberId: json['number_id'] as String? ?? '',
    direction: json['direction'] as String? ?? '',
    fromE164: json['from_e164'] as String? ?? '',
    toE164: json['to_e164'] as String? ?? '',
    voicemail: json['voicemail'] == true,
    durationSeconds: json['duration_seconds'] as int?,
    recordingSid: json['recording_sid'] as String?,
    transcript: json['transcript'] as String?,
    createdAt: json['created_at'] as String? ?? '',
    peerName: json['peer_name'] as String?,
    answeredByName: json['answered_by_name'] as String?,
    status: json['status'] as String? ?? '',
    answeredBy: json['answered_by'] as String?,
    customerName: json['customer_name'] as String?,
    voicemailTranscript: json['voicemail_transcript'] as String?,
    tag: json['tag'] as String?,
    tagColor: json['tag_color'] as String?,
    errorCode: json['error_code'] as String?,
    errorMessage: json['error_message'] as String?,
    menuKey: json['menu_key'] as String?,
    extensionDialed: json['extension_dialed'] as String?,
  );
}

/// Every text exchanged with one number, in the order they were said.
class _Thread {
  _Thread({required this.peer, required this.messages});

  /// The other end. The same value however the message went, which is what
  /// makes the two directions one conversation.
  final String peer;

  final List<_Message> messages;

  _Message get latest => messages.last;

  /// The newest name any message carries: a number linked to a customer
  /// part-way through a conversation is still that customer's conversation.
  String? get customerName {
    for (final message in messages.reversed) {
      final name = message.customerName;
      if (name != null && name.isNotEmpty) return name;
    }
    return null;
  }

  /// The customer, else whoever the server found behind the number (a
  /// customer with that phone, or a colleague). Null shows the number.
  String? get name {
    final customer = customerName;
    if (customer != null) return customer;
    for (final message in messages.reversed) {
      final name = message.peerName;
      if (name != null && name.isNotEmpty) return name;
    }
    return null;
  }
}

/// How a call ended, as the Records tiles split them.
enum _Outcome { answered, missed, voicemail, failed, live }

class _Message {
  const _Message({
    required this.id,
    required this.fromE164,
    required this.toE164,
    required this.body,
    required this.outbound,
    required this.createdAt,
    this.customerName,
    this.peerName,
  });

  /// The customer the message is filed against, by name, when there is one.
  final String? customerName;

  /// Who has the other number when the message is not filed against anyone.
  final String? peerName;

  final String id;
  final String fromE164;
  final String toE164;
  final String body;
  final bool outbound;
  final String createdAt;

  factory _Message.fromJson(Map<String, dynamic> json) => _Message(
    id: json['id'] as String? ?? '',
    fromE164: json['from_e164'] as String? ?? '',
    toE164: json['to_e164'] as String? ?? '',
    body: json['body'] as String? ?? '',
    outbound: json['direction'] == 'outbound',
    createdAt: json['created_at'] as String? ?? '',
    customerName: json['customer_name'] as String?,
    peerName: json['peer_name'] as String?,
  );
}
