import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import 'recording_player.dart';

/// Calls, voicemail and texts — layout 4: 25/75 list and detail.
class RecordsTab extends StatefulWidget {
  const RecordsTab({
    super.key,
    required this.api,
    required this.session,
    this.player,
  });

  final ApiClient api;
  final Session session;
  final RecordingPlayer? player;

  static const double splitAbove = 600;

  @override
  State<RecordsTab> createState() => _RecordsTabState();
}

enum _Filter { calls, voicemail, sms }

class _RecordsTabState extends State<RecordsTab> {
  bool _loading = true;
  String? _error;
  List<_Call> _calls = const [];
  List<_Message> _messages = const [];
  Map<String, String> _labels = const {};

  _Filter _filter = _Filter.calls;
  _Call? _openCall;
  _Message? _openMessage;
  String? _playError;
  bool _playing = false;

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

  bool get _hasOpen => _openCall != null || _openMessage != null;

  List<Object> get _rows {
    switch (_filter) {
      case _Filter.calls:
        return [for (final call in _calls) if (!call.voicemail) call];
      case _Filter.voicemail:
        return [for (final call in _calls) if (call.voicemail) call];
      case _Filter.sms:
        return _messages;
    }
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
          labels[id] = (row['label'] as String?) ?? (row['e164'] as String? ?? '');
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
      _openMessage = null;
      _playError = null;
      _playing = false;
    });
  }

  void _selectMessage(_Message message) {
    _player.stop();
    setState(() {
      _openMessage = message;
      _openCall = null;
      _playError = null;
      _playing = false;
    });
  }

  void _clear() {
    _player.stop();
    setState(() {
      _openCall = null;
      _openMessage = null;
      _playError = null;
      _playing = false;
    });
  }

  void _setFilter(_Filter filter) {
    _player.stop();
    setState(() {
      _filter = filter;
      _openCall = null;
      _openMessage = null;
      _playError = null;
      _playing = false;
    });
  }

  Future<void> _play(_Call call) async {
    if (call.recordingSid == null || call.recordingSid!.isEmpty) return;

    setState(() {
      _playError = null;
      _playing = false;
    });

    final bytes = await widget.api.getBytes(
      '/phone/calls/${call.id}/recording',
    );
    if (!mounted) return;

    if (bytes == null) {
      setState(() => _playError = 'The recording could not be played.');
      return;
    }

    try {
      await _player.play(bytes);
    } catch (_) {
      if (!mounted) return;
      setState(() => _playError = 'The recording could not be played.');
      return;
    }
    if (!mounted) return;
    setState(() => _playing = true);
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
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              ChoiceChip(
                label: const Text('Calls'),
                selected: _filter == _Filter.calls,
                onSelected: (_) => _setFilter(_Filter.calls),
              ),
              ChoiceChip(
                label: const Text('Voicemail'),
                selected: _filter == _Filter.voicemail,
                onSelected: (_) => _setFilter(_Filter.voicemail),
              ),
              ChoiceChip(
                label: const Text('SMS'),
                selected: _filter == _Filter.sms,
                onSelected: (_) => _setFilter(_Filter.sms),
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
                  separatorBuilder: (_, _) => Divider(
                    height: 1,
                    color: EzBiziTheme.edge(context),
                  ),
                  itemBuilder: (context, i) {
                    final row = rows[i];
                    if (row is _Call) return _callRow(row);
                    return _messageRow(row as _Message);
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
        'failed' => 'The call could not be connected. Twilio refused it — the '
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
        border: Border.all(
          color: colour ?? theme.colorScheme.outlineVariant,
        ),
      ),
      child: Text(
        call.tag!,
        style: theme.textTheme.labelSmall,
      ),
    );
  }

  Widget _messageRow(_Message message) {
    final theme = Theme.of(context);
    final selected = _openMessage?.id == message.id;
    final who = message.outbound ? message.toE164 : message.fromE164;

    return Material(
      key: Key('phone-record-${message.id}'),
      color: selected
          ? theme.colorScheme.primaryContainer
          : theme.colorScheme.surface,
      child: InkWell(
        onTap: () => _selectMessage(message),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 44),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('SMS · $who', style: theme.textTheme.bodyMedium),
                Text(
                  message.body,
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
    final message = _openMessage;

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
                        : message != null
                        ? 'SMS'
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
              : message != null
              ? _messageDetail(message)
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
    final canPlay =
        call.recordingSid != null && call.recordingSid!.isNotEmpty;

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
      children: [
        Text(
          _callTitle(call),
          style: theme.textTheme.titleMedium,
        ),
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
          const SizedBox(height: 16),
          Align(
            alignment: Alignment.centerLeft,
            child: FilledButton(
              onPressed: () => _play(call),
              child: const Text('Play recording'),
            ),
          ),
          if (_playing) ...[
            const SizedBox(height: 8),
            Text('The recording is playing.', style: theme.textTheme.bodySmall),
          ],
          if (_playError != null) ...[
            const SizedBox(height: 8),
            Text(
              _playError!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ],
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

  Widget _messageDetail(_Message message) {
    final theme = Theme.of(context);
    final formats = widget.session.me!.display;

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
      children: [
        Text(
          message.outbound ? 'Sent to ${message.toE164}' : 'From ${message.fromE164}',
          style: theme.textTheme.titleMedium,
        ),
        const SizedBox(height: 4),
        Text(
          formatStamp(message.createdAt, '', formats: formats),
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 16),
        Text(message.body, style: theme.textTheme.bodyLarge),
      ],
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
