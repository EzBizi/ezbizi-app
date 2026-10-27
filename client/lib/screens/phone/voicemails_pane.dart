import 'dart:async';

import '../../core/phone_numbers.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../core/theme.dart';
import '../../widgets/list_table.dart';
import '../../widgets/status_pill.dart';
import 'recording_playback_bar.dart';
import 'recording_player.dart';

/// Communication › Phone › Voicemail — the boxes, and what is waiting in them.
///
/// ── Why boxes and not one list ───────────────────────────────────
///
/// A flat list of voicemails answers "did anybody ring" and not "did anybody
/// ring ME", which on a shared account is the question. Boxes belong to a
/// person, a ring group, a line or the company, so the list is split the way
/// responsibility already is — and yours is marked, because the first thing
/// somebody wants is their own.
///
/// ── The transcript is shown, not offered ─────────────────────────
///
/// Reading is faster than listening and works in a room where playing audio is
/// rude. It sits under the row rather than behind a control, because a
/// transcript you have to ask for is one nobody reads.
///
/// It is Twilio's transcription and it is often wrong or empty — a blank one
/// is not an error and the recording stays the record, which is why the player
/// is always there beside it rather than only when the text is missing.
///
/// The field is `voicemail_transcript`, which is the MESSAGE. Records shows
/// `transcript` beside it, which is the live transcription of the whole call
/// including the menu prompts the caller sat through. They disagree often and
/// legitimately, and a voicemail falling back to the live one would present a
/// recording of our own greeting as something the caller said.
class VoicemailsPane extends StatefulWidget {
  const VoicemailsPane({
    super.key,
    required this.api,
    required this.session,
    this.player,
    this.reloadToken = 0,
  });

  final ApiClient api;
  final Session session;
  final RecordingPlayer? player;
  final int reloadToken;

  @override
  State<VoicemailsPane> createState() => _VoicemailsPaneState();
}

class _VoicemailsPaneState extends State<VoicemailsPane> {
  bool _loading = true;
  String? _error;

  List<_Box> _boxes = const [];
  List<_Voicemail> _messages = const [];
  String? _boxId;

  /// The picked tile — `todo`, `dealt`, `no_transcript` — or null for all.
  String? _tile;

  /// The server's counts over everything the box holds, not the 50 shown
  /// (Mia, 2026-10-02, layout B). Null until it answers, drawn as a dash.
  Map<String, int>? _tiles;

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
    if (_ownsPlayer) _player.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(VoicemailsPane old) {
    super.didUpdateWidget(old);
    if (old.reloadToken != widget.reloadToken) _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);

    final response = await widget.api.get('/phone/voicemail-boxes');
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.reason;
      });

      return;
    }

    final boxes = [
      for (final row in (response.data as List? ?? const []))
        if (row is Map<String, dynamic>) _Box.fromJson(row),
    ];

    // Opens on EVERYTHING, and a box is a filter you choose.
    //
    // It used to open on the reader's own box, which was wrong in the way that
    // is hardest to see: a box only holds messages if a LINE points at it, and
    // on this account not one line did. So every box was empty, the nineteen
    // real voicemails were unreachable from this screen, and an empty box
    // looks exactly like a quiet week. Defaulting to all of them means the
    // screen shows what exists before it shows how it is filed.
    setState(() {
      _loading = false;
      _boxes = boxes;
      _boxId = null;
    });

    await _loadMessages();
  }

  Future<void> _loadMessages() async {
    final boxId = _boxId;

    // No box chosen is EVERY voicemail, not none. `voicemail_only` asks the
    // server for the same set the box filter would have narrowed.
    final query = {
      ...boxId == null ? {'voicemail_only': '1'} : {'box_id': boxId},
      'per_page': '50',
      if (_query.trim().isNotEmpty) 'q': _query.trim(),

      // The tile narrows the rows on the server, so "To deal with" shows the
      // 50 newest waiting rather than whichever of the newest 50 are waiting.
      ...switch (_tile) {
        'todo' => {'dealt': '0'},
        'dealt' => {'dealt': '1'},
        'no_transcript' => {'transcript': '0'},
        _ => const <String, String>{},
      },
    };

    final response = await widget.api.get('/phone/calls', query);
    if (!mounted) return;

    final meta = response.ok ? response.body['meta'] : null;
    final tiles = meta is Map ? meta['tiles'] : null;

    setState(() {
      _messages = response.ok
          ? [
              for (final row in (response.data as List? ?? const []))
                if (row is Map<String, dynamic>) _Voicemail.fromJson(row),
            ]
          : const [];
      _tiles = tiles is Map
          ? {
              for (final entry in tiles.entries)
                entry.key.toString(): (entry.value as num?)?.toInt() ?? 0,
            }
          : null;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());

    final theme = Theme.of(context);

    if (_error != null) {
      return Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(_error!, style: theme.textTheme.bodyMedium),
            TextButton(onPressed: _load, child: const Text('Try again')),
          ],
        ),
      );
    }

    // No guard on an empty box list any more.
    //
    // This used to return "No voicemail boxes yet" and stop, which meant an
    // account with messages and no boxes was shown nothing at all — the same
    // mistake as filtering by a box before checking whether any line pointed
    // at one. Boxes are a way to narrow the list, not a precondition for
    // having one.

    return LayoutBuilder(
      builder: (context, box) {
        final wide = box.maxWidth >= 700;

        if (!wide) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _boxChips(theme),
              Divider(height: 1, color: EzBiziTheme.edge(context)),
              Expanded(child: _messageList(theme)),
            ],
          );
        }

        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox(width: 260, child: _boxList(theme)),
            VerticalDivider(
              width: 1,
              thickness: 1,
              color: EzBiziTheme.edge(context),
            ),
            Expanded(child: _messageList(theme)),
          ],
        );
      },
    );
  }

  Widget _boxList(ThemeData theme) {
    return ListView(
      key: const ValueKey('tour-phone-voicemail-boxes'),
      padding: const EdgeInsets.symmetric(vertical: 6),
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 6, 14, 8),
          child: Text('Boxes', style: theme.textTheme.titleSmall),
        ),
        ListTile(
          dense: true,
          key: const Key('voicemail-box-all'),
          selected: _boxId == null,
          title: const Text('All voicemails'),
          onTap: () {
            setState(() => _boxId = null);
            _loadMessages();
          },
        ),
        for (final box in _boxes)
          ListTile(
            dense: true,
            key: Key('voicemail-box-${box.id}'),
            selected: box.id == _boxId,
            title: Text(box.name),

            // Only when it says something the name does not. A box called
            // "A-1 Chimney Mailbox" subtitled "The company" is the same fact
            // twice, and it reads as a label that failed to load.
            subtitle: box.who == null ? null : Text(box.who!),
            onTap: () {
              setState(() => _boxId = box.id);
              _loadMessages();
            },
          ),
      ],
    );
  }

  Widget _boxChips(ThemeData theme) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
      child: Wrap(
        key: const ValueKey('tour-phone-voicemail-box-chips'),
        spacing: 8,
        runSpacing: 8,
        children: [
          // The way back to every box, which the wide list has as its first
          // row and the chips lacked: once a box was picked on a phone, All
          // was unreachable (the mockup's "All mailboxes" chip).
          ChoiceChip(
            key: const Key('voicemail-chip-all'),
            label: const Text('All mailboxes'),
            selected: _boxId == null,
            onSelected: (_) {
              setState(() => _boxId = null);
              _loadMessages();
            },
          ),
          for (final box in _boxes)
            ChoiceChip(
              label: Text(box.name),
              selected: box.id == _boxId,
              onSelected: (_) {
                setState(() => _boxId = box.id);
                _loadMessages();
              },
            ),
        ],
      ),
    );
  }

  /// Tiles over the cards (layout B): the cards stay as they were, with the
  /// counts on top. A tile counts the whole box, so it stays put while the
  /// list under it narrows.
  Widget _tileRow() {
    return FilterTiles(
      key: const ValueKey('tour-phone-voicemail-tiles'),
      prefix: 'voicemail',
      picked: _tile,
      onPick: (key) {
        setState(() => _tile = key);
        _loadMessages();
      },
      tiles: [
        FilterTile(
          key: null,
          label: 'All',
          count: _tiles?['all'],
          note: 'messages',
        ),
        FilterTile(
          key: 'todo',
          label: 'To deal with',
          count: _tiles?['todo'],
          note: 'nobody has marked them',
          tone: Tone.caution,
        ),
        FilterTile(
          key: 'dealt',
          label: 'Dealt with',
          count: _tiles?['dealt'],
          note: 'marked done',
        ),
        FilterTile(
          key: 'no_transcript',
          label: 'No transcript',
          count: _tiles?['no_transcript'],
          note: 'listen to it',
        ),
      ],
    );
  }

  void _search(String value) {
    setState(() => _query = value);
    _searchDebounce?.cancel();
    _searchDebounce = Timer(
      const Duration(milliseconds: 400),
      _loadMessages,
    );
  }

  /// The 50 rows on screen, narrowed by the search box as well.
  ///
  /// CallsController@index (GET /phone/calls) does not read `q` yet, so the
  /// server returns the same newest 50 whatever is typed; this filter is what
  /// narrows them until it does. Once the server honours `q` it is a no-op.
  List<_Voicemail> get _shown => [
    for (final message in _messages)
      if (_searchHit(_query, [
        message.name,
        message.from,
        message.transcript,
      ]))
        message,
  ];

  Widget _messageList(ThemeData theme) {
    return Column(
      key: const ValueKey('tour-phone-voicemail-list'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 12, 12, 0),
          child: Align(
            alignment: Alignment.centerLeft,
            child: _searchBox(
              key: const ValueKey('comm-voicemail-messages-search'),
              hint: 'Search name, number or transcript',
              onChanged: _search,
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 12, 12, 0),
          child: _tileRow(),
        ),
        if (_messages.any((m) => m.transcript?.trim().isNotEmpty != true))
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
            child: Text(
              (widget.session.me?.can('phone.edit') ?? false)
                  ? 'Some voicemails have no transcript. Turn on "Write down '
                        'voicemail messages" under Communication → Settings → '
                        'General; it covers every line, and Twilio charges for '
                        'each message transcribed. The recording is the record '
                        'either way.'
                  : 'Some voicemails have no transcript. An administrator can '
                        'switch transcription on in Communication settings. The '
                        'recording is the record either way.',
              key: const Key('voicemail-transcript-note'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
        Expanded(child: _cards(theme)),
      ],
    );
  }

  Widget _cards(ThemeData theme) {
    final shown = _shown;

    if (_query.trim().isNotEmpty && shown.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'Nothing matches "${_query.trim()}".',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall,
          ),
        ),
      );
    }

    // A tile with nothing under it is not an empty box: the box has messages,
    // just none in this state.
    if (_messages.isEmpty && _tile != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'No messages in this state.',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall,
          ),
        ),
      );
    }

    if (_messages.isEmpty) {
      final box = _boxes.where((b) => b.id == _boxId).firstOrNull;

      // Empty because nothing routes here, or empty because nobody rang —
      // those look identical and mean opposite things. The first is a setting
      // somebody has to change; the second is a quiet week.
      final unrouted = box != null && box.lineCount == 0;

      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            unrouted
                ? 'No line sends voicemail to this box yet, so nothing can '
                      'arrive in it. Point a line at it under Settings › '
                      'Numbers.'
                : _boxId == null
                ? 'No voicemails yet.'
                : 'Nothing waiting in this box.',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall,
          ),
        ),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.all(12),
      itemCount: shown.length,
      itemBuilder: (context, i) => _row(shown[i], theme),
    );
  }

  late final RecordingPlayer _player = widget.player ?? createRecordingPlayer();
  late final bool _ownsPlayer = widget.player == null;

  Widget _row(_Voicemail message, ThemeData theme) {
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                // Who left it, with the number under (Jose, 2026-10-09).
                Expanded(
                  child: message.name == null
                      ? Text(
                          formatPhoneNumber(message.from),
                          style: theme.textTheme.titleSmall,
                        )
                      : Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(message.name!, style: theme.textTheme.titleSmall),
                            Text(
                              formatPhoneNumber(message.from),
                              style: theme.textTheme.bodySmall?.copyWith(
                                color: theme.colorScheme.onSurfaceVariant,
                              ),
                            ),
                          ],
                        ),
                ),

                // WHEN it was left. A list of messages with no times in it
                // cannot be worked through — "ring them back" depends on
                // whether they rang an hour ago or last Tuesday.
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: Text(
                    formatListStamp(
                      message.createdAt,
                      '',
                      formats: widget.session.me!.display,
                    ),
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ),
                // Both states named, as the tiles name them, so a card read
                // under All says which tile it is counted in.
                message.dealt
                    ? const StatusPill('Dealt with', tone: Tone.ok)
                    : const StatusPill('To deal with', tone: Tone.caution),
              ],
            ),

            if (message.recordingSid != null) ...[
              const SizedBox(height: 4),

              // The shared transport, not a Play button. Listening to a
              // voicemail means going back four seconds because somebody said
              // a number too fast, and a bare Play makes that mean starting
              // the whole message again.
              RecordingPlaybackBar(
                key: Key('voicemail-audio-${message.id}'),
                player: _player,
                dense: true,
                // Fetched through EzBizi with the connection's credentials,
                // never a bare Twilio URL — a recording link that works
                // without signing in is a customer's voicemail anybody can
                // replay.
                load: () => widget.api.getBytes(
                  '/phone/calls/${message.id}/recording',
                ),
              ),
            ],

            const SizedBox(height: 8),

            // ── NAME THE SWITCH, DO NOT GUESS AT THE CAUSE ──────
            //
            // This used to offer two possibilities and settle neither:
            // transcription is off for this line, OR Twilio could not make
            // the audio out. A reader cannot act on either, and the far
            // likelier one by a mile is the first — measured 2026-09-14, all
            // thirteen numbers have voicemail transcription off, and 0 of 92
            // voicemails carry a transcript.
            //
            // Jose read it as a defect: "Voicemail tab does not show the
            // transcripts... but voicemail tab on records does." Records was
            // showing a different field — the LIVE transcript of the whole
            // call, menu prompts and all — which is why one had text and the
            // other did not. Neither was broken. Nobody had turned the
            // setting on, and nothing on screen said where it was.
            //
            // Same shape as the empty Dial pad: whoever can fix it is told
            // what to fix, and whoever cannot is told what to ask for.
            //
            // It names GENERAL rather than Voicemail because the switch moved
            // there the same day: per line, it was thirteen visits to thirteen
            // screens, which is why it had never been turned on once. Jose
            // asked for one switch — "all on or all off at the same time, not
            // per line" — and a sentence pointing at the old place would send
            // somebody to a screen that no longer decides it.
            //
            // ponytail: still a guess in the rare case where transcription IS
            // on and Twilio returned nothing. Say which only when a row can
            // carry its own line's setting; today the list does not.
            //
            // Shown, not hidden behind a control. A transcript you have to ask
            // for is one nobody reads, and reading is faster than listening.
            //
            // Jose, 2026-10-09: the sentence repeated under all 148 cards. It
            // is said once above the list (_transcriptNote); a card says only
            // that it has none.
            Text(
              message.transcript?.trim().isNotEmpty == true
                  ? message.transcript!
                  : 'No transcript',
              style: theme.textTheme.bodyMedium?.copyWith(
                color: message.transcript?.trim().isNotEmpty == true
                    ? null
                    : theme.colorScheme.onSurfaceVariant,
                fontStyle: message.transcript?.trim().isNotEmpty == true
                    ? null
                    : FontStyle.italic,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Box {
  const _Box({
    required this.id,
    required this.name,
    required this.ownerType,
    required this.ownerId,
    required this.ownerName,
    required this.lineCount,
  });

  final String id;
  final String name;
  final String ownerType;
  final String? ownerId;
  final String? ownerName;

  /// How many lines send voicemail here. Zero means the box cannot fill.
  final int lineCount;

  /// Whose box this is, or null when the name already says it.
  ///
  /// It used to answer for every case, so a company box with no named owner
  /// got "The company" printed under a name that already said which company —
  /// which reads as a subtitle that failed to load rather than as a fact.
  String? get who => switch (ownerType) {
    'user' => ownerName == null ? null : "${ownerName!}'s",
    'ring_group' => ownerName == null ? null : 'Rings ${ownerName!}',
    'number' => ownerName,
    _ => null,
  };

  factory _Box.fromJson(Map<String, dynamic> json) => _Box(
    id: (json['id'] ?? '').toString(),
    name: (json['name'] ?? '').toString(),
    ownerType: (json['owner_type'] ?? '').toString(),
    ownerId: json['owner_id'] as String?,
    ownerName: json['owner_name'] as String?,
    lineCount: (json['line_count'] as num?)?.toInt() ?? 0,
  );
}

class _Voicemail {
  const _Voicemail({
    required this.id,
    required this.from,
    required this.transcript,
    required this.recordingSid,
    required this.dealt,
    required this.createdAt,
    this.name,
  });

  final String id;
  final String from;

  /// Who has the number: the customer on file, else the server's lookup
  /// (another spelling, a lead, a colleague). Null shows the number alone.
  final String? name;
  /// The MESSAGE, written down — not the call it was left on.
  ///
  /// `transcript` is live transcription of the whole conversation, which on a
  /// line with a menu is our own greeting, the recording notice and the IVR
  /// prompt before the caller says a word. Both used to be one column, so this
  /// screen read callers their own menu back to them.
  final String? transcript;
  final String? recordingSid;
  final bool dealt;

  /// When it was left. A voicemail with no time on it cannot be triaged.
  final String createdAt;

  factory _Voicemail.fromJson(Map<String, dynamic> json) => _Voicemail(
    id: (json['id'] ?? '').toString(),
    from: (json['from_e164'] ?? '').toString(),
    createdAt: (json['created_at'] ?? '').toString(),
    transcript: json['voicemail_transcript'] as String?,
    recordingSid: json['recording_sid'] as String?,
    dealt: json['dealt_at'] != null,
    name:
        json['customer_name'] as String? ??
        json['caller_name'] as String? ??
        json['peer_name'] as String?,
  );
}

/// The list's search box, sized to sit in the pane's header beside its
/// buttons: 40 high, the height of the controls around it.
Widget _searchBox({
  required Key key,
  required String hint,
  required ValueChanged<String> onChanged,
}) => SizedBox(
  width: 260,
  child: TextField(
    key: key,
    onChanged: onChanged,
    textAlignVertical: TextAlignVertical.center,
    decoration: InputDecoration(
      isDense: true,
      hintText: hint,
      prefixIcon: const Icon(Icons.search, size: 18),
      prefixIconConstraints: const BoxConstraints(minWidth: 36),
      border: const OutlineInputBorder(),
    ),
  ),
);


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
