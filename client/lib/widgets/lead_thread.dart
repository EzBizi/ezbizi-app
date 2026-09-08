import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api_client.dart';
import '../core/phone_numbers.dart';
import '../screens/phone/recording_player.dart';
import 'async_body.dart';

/// What was said on one enquiry: every call and message, oldest first.
///
/// Desktop layout 1 draws this beside the list; mobile layout 4 pushes it full
/// screen. Same widget behind both doors. The header comes from the row the
/// list already holds, so it is on screen before the thread arrives, and the
/// thread is one fetch of `GET /leads/{id}`.
///
/// ── Play is fetch-then-play ──────────────────────────────────────
///
/// Google's recording URL answers only to the account's bearer token, so the
/// server fetches it and streams it back; the client gets bytes and hands them
/// to the same [RecordingPlayer] the Phone module uses. There is no streaming:
/// the whole file arrives before a sound comes out, and a play button silent
/// for three seconds on a twelve-minute call reads as broken. So the control
/// says "Fetching the recording…" while it waits, and that state is tested.
class LeadThread extends StatefulWidget {
  const LeadThread({
    super.key,
    required this.api,
    required this.lead,
    this.player,
    this.onStatusChanged,
    this.onRead,
    this.onStar,
    this.onNote,
  });

  final ApiClient api;

  /// The row from the list: enough for the header without a fetch.
  final Map<String, dynamic> lead;

  /// Tests inject a fake; the widget makes and owns one otherwise.
  final RecordingPlayer? player;

  /// Null hides the status control (somebody who may only read).
  final Future<void> Function(String status)? onStatusChanged;

  /// Called once when the thread opens: this person has now seen the lead.
  final Future<void> Function()? onRead;

  /// Star and note are marks on the lead, shared. Each returns the lead as
  /// the server now holds it (author and time on the note), or null when
  /// the save was refused.
  final Future<Map<String, dynamic>?> Function(bool starred)? onStar;
  final Future<Map<String, dynamic>?> Function(String? note)? onNote;

  @override
  State<LeadThread> createState() => _LeadThreadState();
}

class _LeadThreadState extends State<LeadThread> {
  late final RecordingPlayer _player = widget.player ?? createRecordingPlayer();
  bool get _ownsPlayer => widget.player == null;

  /// The lead as last saved. Starts as the row the list handed over and
  /// follows what onStar / onNote return, so a pushed screen on a phone
  /// shows the note's byline without the list having to rebuild it.
  late Map<String, dynamic> _lead = widget.lead;

  bool _editingNote = false;
  bool _savingNote = false;
  final _note = TextEditingController();

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _conversations = const [];

  /// Whether Google will take a reply: text enquiries only, account still
  /// connected. The server says; the screen never guesses from lead_type.
  bool _canReply = false;

  final _compose = TextEditingController();
  bool _sending = false;
  String? _sendError;

  String? _fetchingId;
  String? _playingId;
  String? _playError;

  static const _statuses = <String, String>{
    'new': 'New',
    'booked': 'Booked',
    'archived': 'Archived',
    'not_a_lead': 'Not a lead',
  };

  @override
  void initState() {
    super.initState();
    _load();
    // After the first frame: the parent answers by rebuilding its list, and
    // a setState during this widget's own build is an exception, not a mark.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) widget.onRead?.call();
    });
  }

  @override
  void didUpdateWidget(LeadThread old) {
    super.didUpdateWidget(old);
    if (!identical(old.lead, widget.lead)) _lead = widget.lead;
  }

  Future<void> _star() async {
    final fresh = await widget.onStar?.call(_lead['starred'] != true);
    if (!mounted || fresh == null) return;
    setState(() => _lead = fresh);
  }

  Future<void> _saveNote() async {
    setState(() => _savingNote = true);
    final text = _note.text.trim();
    final fresh = await widget.onNote?.call(text.isEmpty ? null : text);
    if (!mounted) return;
    setState(() {
      _savingNote = false;
      if (fresh != null) {
        _lead = fresh;
        _editingNote = false;
      }
    });
  }

  @override
  void dispose() {
    _note.dispose();
    _compose.dispose();
    _player.stop();
    if (_ownsPlayer) _player.dispose();
    super.dispose();
  }

  /// A sentence to a stranger in the customer's name: it goes because a
  /// person pressed Send. Appended to the thread as ours the moment Google
  /// takes it; on refusal the text stays in the box and the reason is shown.
  Future<void> _send() async {
    final text = _compose.text.trim();
    if (text.isEmpty || _sending) return;

    setState(() {
      _sending = true;
      _sendError = null;
    });

    final response = await widget.api.post(
      '/leads/${widget.lead['id']}/messages',
      {'text': text},
    );
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _sending = false;
        _sendError = response.message;
      });
      return;
    }

    setState(() {
      _sending = false;
      _conversations = [
        ..._conversations,
        (response.data as Map<String, dynamic>? ?? const {}),
      ];
      _compose.clear();
    });
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/leads/${widget.lead['id']}');
    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      final data = response.data as Map<String, dynamic>? ?? const {};
      _conversations = List<Map<String, dynamic>>.from(
        (data['conversations'] as List? ?? const [])
            .cast<Map<String, dynamic>>(),
      );
      _canReply = data['can_reply'] == true;
    });
  }

  Future<void> _play(Map<String, dynamic> c) async {
    final id = c['id'] as String;

    if (_playingId == id) {
      _player.stop();
      setState(() => _playingId = null);
      return;
    }

    _player.stop();
    setState(() {
      _playingId = null;
      _playError = null;
      _fetchingId = id;
    });

    final bytes = await widget.api.getBytes(
      '/leads/${widget.lead['id']}/conversations/$id/recording',
    );
    if (!mounted) return;

    if (bytes == null) {
      setState(() {
        _fetchingId = null;
        _playError = 'The recording could not be fetched.';
      });
      return;
    }

    try {
      await _player.play(bytes);
    } on RecordingPlaybackFailure catch (failure) {
      if (!mounted) return;
      setState(() {
        _fetchingId = null;
        _playError = failure.message;
      });
      return;
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _fetchingId = null;
        _playError = 'The recording could not be played.';
      });
      return;
    }
    if (!mounted) return;
    setState(() {
      _fetchingId = null;
      // On a desktop the audio is somebody else's window now (see
      // RecordingPlayer.playsInApp): nothing here is playing, so nothing here
      // can be stopped, and saying "Playing" would be the stub's lie again.
      _playingId = _player.playsInApp ? id : null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final lead = _lead;
    final starred = lead['starred'] == true;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Flexible(
                          child: Text(
                            leadTitle(lead),
                            style: theme.textTheme.titleMedium,
                          ),
                        ),
                        if (widget.onStar != null)
                          IconButton(
                            key: const ValueKey('thread-star'),
                            tooltip: starred ? 'Unstar' : 'Star',
                            iconSize: 18,
                            onPressed: _star,
                            icon: Icon(
                              starred ? Icons.star : Icons.star_border,
                              color: starred
                                  ? theme.colorScheme.primary
                                  : theme.colorScheme.outline,
                            ),
                          ),
                      ],
                    ),
                    // The number on its own line only when the title is a
                    // name; when the title IS the number, saying it twice
                    // adds nothing.
                    if ((lead['contact_name'] as String?)?.trim().isNotEmpty ==
                            true &&
                        (lead['phone'] as String?)?.isNotEmpty == true)
                      Text(
                        formatPhoneNumber(lead['phone'] as String?),
                        style: theme.textTheme.bodySmall,
                      ),
                    Text(
                      leadHeaderLine(lead),
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                    _noteBlock(theme, lead),
                  ],
                ),
              ),
              if (widget.onStatusChanged != null)
                DropdownButton<String>(
                  value: _statuses.containsKey(lead['status'])
                      ? lead['status'] as String
                      : 'new',
                  underline: const SizedBox.shrink(),
                  items: [
                    for (final e in _statuses.entries)
                      DropdownMenuItem(value: e.key, child: Text(e.value)),
                  ],
                  onChanged: (v) {
                    if (v != null && v != lead['status']) {
                      widget.onStatusChanged!(v);
                    }
                  },
                ),
            ],
          ),
        ),
        const Divider(height: 1),
        if (_playError != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: Text(
              _playError!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
        Expanded(
          child: ColoredBox(
            color: theme.colorScheme.surfaceContainerLow,
            child: AsyncBody(
              loading: _loading,
              error: _error,
              onRetry: _load,
              child: _conversations.isEmpty ? _nothing(theme) : _thread(theme),
            ),
          ),
        ),
        // Only somebody who may change the lead may speak for the business,
        // and only where Google will take it. A call lead says why there is
        // no box rather than drawing one that would be refused.
        if (widget.onStatusChanged != null && !_loading) ...[
          const Divider(height: 1),
          if (_canReply)
            _composer(theme)
          else
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
              child: Text(
                'Google only accepts replies to text enquiries. This one came '
                'in as a call, so it is answered by calling back.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
        ],
      ],
    );
  }

  Widget _composer(ThemeData theme) => Padding(
    padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (_sendError != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Text(
              _sendError!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
        Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Expanded(
              child: TextField(
                key: const ValueKey('reply-box'),
                controller: _compose,
                minLines: 1,
                maxLines: 4,
                textInputAction: TextInputAction.newline,
                enabled: !_sending,
                decoration: const InputDecoration(
                  isDense: true,
                  hintText: 'Reply to them',
                  border: OutlineInputBorder(),
                ),
              ),
            ),
            const SizedBox(width: 8),
            FilledButton.icon(
              key: const ValueKey('reply-send'),
              onPressed: _sending ? null : _send,
              icon: _sending
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.send, size: 18),
              label: Text(_sending ? 'Sending…' : 'Send'),
            ),
          ],
        ),
      ],
    ),
  );

  /// One shared line with Edit in place, and a byline: "Maya · 2h ago".
  ///
  /// A shared field that cannot say who wrote it is one people stop
  /// trusting, and the silent overwrite is the worse half; the server stamps
  /// the author and the time on every change.
  Widget _noteBlock(ThemeData theme, Map<String, dynamic> lead) {
    final scheme = theme.colorScheme;
    final note = lead['note'] as String?;
    final canEdit = widget.onNote != null;

    if (_editingNote) {
      return Padding(
        padding: const EdgeInsets.only(top: 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextField(
              key: const ValueKey('note-box'),
              controller: _note,
              minLines: 1,
              maxLines: 4,
              autofocus: true,
              enabled: !_savingNote,
              decoration: const InputDecoration(
                isDense: true,
                hintText: 'A note for whoever picks this up next',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 6),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                TextButton(
                  onPressed: _savingNote
                      ? null
                      : () => setState(() => _editingNote = false),
                  child: const Text('Cancel'),
                ),
                const SizedBox(width: 4),
                FilledButton(
                  key: const ValueKey('note-save'),
                  onPressed: _savingNote ? null : _saveNote,
                  child: Text(_savingNote ? 'Saving…' : 'Save'),
                ),
              ],
            ),
          ],
        ),
      );
    }

    if (note == null || note.isEmpty) {
      if (!canEdit) return const SizedBox.shrink();
      return Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          key: const ValueKey('note-add'),
          onPressed: () {
            _note.clear();
            setState(() => _editingNote = true);
          },
          icon: const Icon(Icons.edit_outlined, size: 16),
          label: const Text('Add a note'),
        ),
      );
    }

    final by = (lead['note_by'] as Map?)?['name'] as String?;
    final when = leadWhen(lead['note_updated_at'] as String?);
    final byline = [?by, if (when.isNotEmpty) when].join(' · ');

    return Container(
      margin: const EdgeInsets.only(top: 9),
      padding: const EdgeInsets.fromLTRB(10, 7, 6, 7),
      decoration: BoxDecoration(
        color: scheme.secondaryContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Note',
            style: theme.textTheme.labelSmall?.copyWith(
              fontWeight: FontWeight.w700,
              color: scheme.onSecondaryContainer,
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  note,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.onSecondaryContainer,
                  ),
                ),
                if (byline.isNotEmpty)
                  Text(
                    byline,
                    key: const ValueKey('note-byline'),
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: scheme.onSecondaryContainer.withValues(
                        alpha: 0.75,
                      ),
                    ),
                  ),
              ],
            ),
          ),
          if (canEdit)
            TextButton(
              key: const ValueKey('note-edit'),
              onPressed: () {
                _note.text = note;
                setState(() => _editingNote = true);
              },
              child: const Text('Edit'),
            ),
        ],
      ),
    );
  }

  Widget _nothing(ThemeData theme) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Text(
        'Nothing said yet. Google has not recorded a call or message on this '
        'enquiry.',
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
        textAlign: TextAlign.center,
      ),
    ),
  );

  Widget _thread(ThemeData theme) => ListView.builder(
    padding: const EdgeInsets.all(14),
    itemCount: _conversations.length,
    itemBuilder: (context, i) {
      final c = _conversations[i];
      final mine = c['from'] == 'ADVERTISER';
      final who = mine ? 'You' : 'They';
      final when = _when(c['occurred_at'] as String?);

      final Widget body;
      if (c['channel'] == 'PHONE_CALL') {
        body = _callRow(theme, c, who: who, when: when);
      } else {
        body = _message(theme, c, mine: mine, who: who, when: when);
      }

      return Align(
        alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 480),
          child: Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: body,
          ),
        ),
      );
    },
  );

  Widget _message(
    ThemeData theme,
    Map<String, dynamic> c, {
    required bool mine,
    required String who,
    required String when,
  }) {
    final attachments = (c['attachments'] as List? ?? const []).cast<String>();

    // Approved colour layout 3 / 6 (c31b50ad): ours is `primary` with
    // `onPrimary` text, the loudest this palette allows without red; theirs
    // stays `surface` with an outline all round. No leading-edge accent bar
    // anywhere, on Jose's ruling of 2026-09-08. `primary` inverts between the
    // themes (dark teal / white in light, bright cyan / near-black in dark),
    // so both are pinned in lead_thread_colour_test.dart.
    final scheme = theme.colorScheme;
    final ink = mine ? scheme.onPrimary : scheme.onSurface;

    return Container(
      padding: const EdgeInsets.fromLTRB(11, 8, 11, 6),
      decoration: BoxDecoration(
        color: mine ? scheme.primary : scheme.surface,
        border: mine ? null : Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          if ((c['text'] as String?)?.isNotEmpty == true)
            Text(
              c['text'] as String,
              style: theme.textTheme.bodyMedium?.copyWith(color: ink),
            ),
          if (attachments.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  for (var i = 0; i < attachments.length; i++)
                    _attachment(theme, attachments[i], i + 1),
                ],
              ),
            ),
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              '$who · $when',
              style: theme.textTheme.labelSmall?.copyWith(
                color: mine
                    ? scheme.onPrimary.withValues(alpha: 0.8)
                    : scheme.onSurfaceVariant,
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// A photo the enquirer sent. Google hosts it; a thumbnail when it loads
  /// and a plain link when it does not, because whether that host answers
  /// without a token is not something to assume on their behalf.
  Widget _attachment(ThemeData theme, String url, int n) => InkWell(
    onTap: () =>
        launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication),
    borderRadius: BorderRadius.circular(6),
    child: ClipRRect(
      borderRadius: BorderRadius.circular(6),
      child: SizedBox(
        width: 44,
        height: 44,
        child: Image.network(
          url,
          fit: BoxFit.cover,
          errorBuilder: (_, _, _) => ColoredBox(
            color: theme.colorScheme.surfaceContainerHighest,
            child: Center(child: Text('$n', style: theme.textTheme.labelSmall)),
          ),
        ),
      ),
    ),
  );

  Widget _callRow(
    ThemeData theme,
    Map<String, dynamic> c, {
    required String who,
    required String when,
  }) {
    final id = c['id'] as String;
    final fetching = _fetchingId == id;
    final playing = _playingId == id;
    final hasRecording = c['has_recording'] == true;

    return Container(
      padding: const EdgeInsets.fromLTRB(11, 9, 11, 9),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (hasRecording)
            SizedBox(
              width: 34,
              height: 34,
              child: fetching
                  ? const Padding(
                      padding: EdgeInsets.all(8),
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  // A filled disc: primaryContainer at rest, primary while
                  // playing, per the approved colour layout.
                  : Material(
                      color: playing
                          ? theme.colorScheme.primary
                          : theme.colorScheme.primaryContainer,
                      shape: const CircleBorder(),
                      clipBehavior: Clip.antiAlias,
                      child: IconButton(
                        key: ValueKey('play-$id'),
                        tooltip: playing
                            ? 'Stop'
                            : _player.playsInApp
                            ? 'Play'
                            : 'Play in your audio player',
                        padding: EdgeInsets.zero,
                        iconSize: 20,
                        onPressed: () => _play(c),
                        icon: Icon(
                          playing
                              ? Icons.stop
                              : _player.playsInApp
                              ? Icons.play_arrow
                              : Icons.open_in_new,
                          color: playing
                              ? theme.colorScheme.onPrimary
                              : theme.colorScheme.onPrimaryContainer,
                        ),
                      ),
                    ),
            ),
          if (hasRecording) const SizedBox(width: 10),
          Flexible(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  'Call · ${formatDuration(c['duration_ms'] as num?)}',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
                Text(
                  fetching
                      ? 'Fetching the recording…'
                      : playing
                      ? 'Playing'
                      : hasRecording
                      ? (_player.playsInApp
                            ? '$who called · $when'
                            : '$who called · $when · opens in your audio player')
                      : '$who called · $when · no recording',
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  String _when(String? iso) => leadWhen(iso);
}

/// The title of a lead: the name, or the number somebody would call back.
String leadTitle(Map<String, dynamic> lead) {
  final name = (lead['contact_name'] as String?)?.trim();
  if (name != null && name.isNotEmpty) return name;
  final phone = lead['phone'] as String?;
  if (phone != null && phone.isNotEmpty) return formatPhoneNumber(phone);
  return 'Unnamed enquiry';
}

/// "Fireplace chimney · Google Ads · Connecticut".
///
/// The service segment is OMITTED when Google sent none, not replaced with
/// "No service given". 1,576 of 23,875 live leads carry none (measured
/// 2026-09-08). Nobody can supply what Google did not send, so naming the
/// absence spends a line on a fact no reader can act on. That is a deliberate
/// departure from Your account, where a blank is something the reader can
/// fill in and silence reads as a failed load.
///
/// Never the category: 23,686 of those leads are classified "home inspector"
/// by Google, for a chimney company. A wrong statement about the business,
/// shown to the business.
String leadHeaderLine(Map<String, dynamic> lead) {
  final service = humaniseService(lead['service'] as String?);
  final place =
      (lead['franchise'] as Map?)?['name'] as String? ??
      (lead['company'] as Map?)?['name'] as String?;

  return [
    ?service,
    'Google Ads',
    if (place != null && place.isNotEmpty) place,
  ].join(' · ');
}

/// fireplace_chimney → "Fireplace chimney". Null in, null out.
///
/// ponytail: mechanical sentence-case, and it has a ceiling. An acronym comes
/// out wrong — hvac_repair → "Hvac repair" — and reads as the business having
/// misspelled its own trade. Measured across 23,875 live leads on 2026-09-08
/// there is exactly one value on the whole tenant, fireplace_chimney, so there
/// is nothing to map yet. Add a map from a real tenant's real list when one
/// arrives, not from guessed examples.
String? humaniseService(String? id) {
  final s = id?.trim();
  if (s == null || s.isEmpty) return null;
  final words = s.replaceAll('_', ' ').toLowerCase();
  return words[0].toUpperCase() + words.substring(1);
}

/// Google's word for the channel, and ours. PHONE_CALL is a call; everything
/// else Google records on a lead (MESSAGE, SMS, WHATSAPP, EMAIL, BOOKING) is
/// something somebody typed. None of the wire values go on screen, and not
/// a1leads' "LS Ads" / "LS Text" either — that is Rodrigo's shorthand.
String channelWord(String? channel) =>
    channel == 'PHONE_CALL' ? 'Call' : 'Text';

/// 125000 → "2:05".
String formatDuration(num? ms) {
  final seconds = ((ms ?? 0) / 1000).floor();
  final m = seconds ~/ 60;
  final s = (seconds % 60).toString().padLeft(2, '0');
  return '$m:$s';
}

/// Relative for anything recent, absolute once it stops being "today".
String leadWhen(String? iso) {
  final at = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (at == null) return '';

  final ago = DateTime.now().difference(at);

  if (ago.inMinutes < 1) return 'just now';
  if (ago.inMinutes < 60) return '${ago.inMinutes}m ago';
  if (ago.inHours < 24) return '${ago.inHours}h ago';
  if (ago.inDays < 7) return '${ago.inDays}d ago';

  return '${at.year}-${at.month.toString().padLeft(2, '0')}-'
      '${at.day.toString().padLeft(2, '0')}';
}
