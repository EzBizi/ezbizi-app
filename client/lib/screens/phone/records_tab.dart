import '../../core/phone_numbers.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import 'recording_playback_bar.dart';
import 'recording_player.dart';

/// Calls, voicemail and texts — layout 4: 25/75 list and detail.
class RecordsTab extends StatefulWidget {
  const RecordsTab({
    super.key,
    required this.api,
    required this.session,
    this.player,
    this.initialFilter = RecordsFilter.calls,
    this.showFilters = true,
    this.reloadToken = 0,
  });

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

  late RecordsFilter _filter = widget.initialFilter;
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
    _load();
  }

  @override
  void dispose() {
    _player.stop();
    if (_ownsPlayer) _player.dispose();
    super.dispose();
  }

  bool get _hasOpen => _openCall != null || _openThread != null;

  List<Object> get _rows {
    switch (_filter) {
      case RecordsFilter.calls:
        return [
          for (final call in _calls)
            if (!call.voicemail) call,
        ];
      case RecordsFilter.voicemail:
        return [
          for (final call in _calls)
            if (call.voicemail) call,
        ];
      case RecordsFilter.sms:
        return _threads;
    }
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

    final calls = widget.api.get('/phone/calls');
    final messages = widget.api.get('/phone/messages');
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
    if (numbersRes.ok) {
      for (final row in (numbersRes.data as List? ?? const [])) {
        if (row is Map<String, dynamic>) {
          final id = row['id'] as String? ?? '';
          labels[id] =
              (row['label'] as String?) ??
              formatPhoneNumber(row['e164'] as String?);
        }
      }
    }

    setState(() {
      _loading = false;
      _labels = labels;
      _calls = [
        for (final row in (callsRes.data as List? ?? const []))
          if (row is Map<String, dynamic>) _Call.fromJson(row),
      ];
      _messages = [
        for (final row in (messagesRes.data as List? ?? const []))
          if (row is Map<String, dynamic>) _Message.fromJson(row),
      ];
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
      _openCall = null;
      _openThread = null;
    });
  }

  @override
  void didUpdateWidget(RecordsTab old) {
    super.didUpdateWidget(old);
    if (old.reloadToken != widget.reloadToken) _load();
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= RecordsTab.splitAbove;
        final paneOnly = !wide && _hasOpen;

        return AsyncBody(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: _calls.isEmpty && _messages.isEmpty
              ? Center(
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
              : wide
              ? Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Expanded(
                      flex: 1,
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
                      flex: 3,
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

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // The whole row goes, not each chip: a sub-tab that IS the filter must
        // not also offer a way to switch away from itself.
        if (widget.showFilters)
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
            child: Wrap(
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
            ),
          ),
        Expanded(
          child: rows.isEmpty
              ? Center(
                  child: Text(
                    'Nothing matches that filter.',
                    style: theme.textTheme.bodyMedium,
                  ),
                )
              : ListView.separated(
                  itemCount: rows.length,
                  separatorBuilder: (_, _) =>
                      Divider(height: 1, color: EzBiziTheme.edge(context)),
                  itemBuilder: (context, i) {
                    final row = rows[i];
                    if (row is _Call) return _callRow(row);
                    return _threadRow(row as _Thread);
                  },
                ),
        ),
      ],
    );
  }

  Widget _callRow(_Call call) {
    final theme = Theme.of(context);
    final selected = _openCall?.id == call.id;
    final who = call.direction == 'inbound' ? call.fromE164 : call.toE164;
    final kind = call.voicemail
        ? 'Voicemail'
        : call.direction == 'inbound'
        ? 'Inbound'
        : 'Outbound';

    return Material(
      key: Key('phone-record-${call.id}'),
      color: selected
          ? theme.colorScheme.primaryContainer
          : theme.colorScheme.surface,
      child: InkWell(
        onTap: () => _selectCall(call),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 44),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        '$kind · $who',
                        style: theme.textTheme.bodyMedium,
                      ),
                    ),
                    if (call.tag != null) _tagChip(call, theme),
                  ],
                ),
                Text(
                  _subtitle(call),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),

                // A failure says why, here, rather than in Twilio's console.
                if (_failure(call) != null)
                  Text(
                    _failure(call)!,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.error,
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
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
      margin: const EdgeInsets.only(left: 8),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: colour?.withValues(alpha: 0.15),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: colour ?? theme.colorScheme.outlineVariant),
      ),
      child: Text(call.tag!, style: theme.textTheme.labelSmall),
    );
  }

  /// A conversation: who, the last thing said, and how many there are.
  Widget _threadRow(_Thread thread) {
    final theme = Theme.of(context);
    final selected = _openThread == thread.peer;
    final latest = thread.latest;

    return Material(
      key: Key('phone-thread-${thread.peer}'),
      color: selected
          ? theme.colorScheme.primaryContainer
          : theme.colorScheme.surface,
      child: InkWell(
        onTap: () => _selectThread(thread),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 44),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        formatPhoneNumber(thread.peer),
                        style: theme.textTheme.bodyMedium,
                      ),
                    ),
                    // Only once there is more than one, because "1" beside a
                    // single message is noise pretending to be information.
                    if (thread.messages.length > 1)
                      Text(
                        '${thread.messages.length}',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                  ],
                ),
                Text(
                  // Said out loud which way it went. Without it the last line
                  // of a thread reads as something they said when it is often
                  // the last thing we said to them.
                  latest.outbound ? 'You: ${latest.body}' : latest.body,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
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
                        ? formatPhoneNumber(thread.peer)
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
            load: () => widget.api.getBytes('/phone/calls/${call.id}/recording'),
          ),
        ],
        if (call.transcript != null && call.transcript!.isNotEmpty) ...[
          const SizedBox(height: 16),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(12),
            color: theme.colorScheme.surfaceContainerHighest.withValues(
              alpha: 0.4,
            ),
            child: Text(call.transcript!, style: theme.textTheme.bodyMedium),
          ),
        ],
      ],
    );
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
    final who = call.direction == 'inbound' ? call.fromE164 : call.toE164;
    if (call.voicemail) return 'Voicemail · $who';
    return call.direction == 'inbound' ? 'Inbound · $who' : 'Outbound · $who';
  }

  String _subtitle(_Call call) {
    final formats = widget.session.me!.display;
    final parts = [
      formatStamp(call.createdAt, '', formats: formats),
      if (call.durationSeconds != null) _clock(call.durationSeconds!),
      if (call.recordingSid != null && call.recordingSid!.isNotEmpty)
        'recording',
    ];
    return parts.where((part) => part.isNotEmpty).join(' · ');
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
  });

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
    tag: json['tag'] as String?,
    tagColor: json['tag_color'] as String?,
    errorCode: json['error_code'] as String?,
    errorMessage: json['error_message'] as String?,
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
}

class _Message {
  const _Message({
    required this.id,
    required this.fromE164,
    required this.toE164,
    required this.body,
    required this.outbound,
    required this.createdAt,
  });

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
  );
}
