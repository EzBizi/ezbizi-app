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
  });

  final ApiClient api;

  /// The row from the list: enough for the header without a fetch.
  final Map<String, dynamic> lead;

  /// Tests inject a fake; the widget makes and owns one otherwise.
  final RecordingPlayer? player;

  /// Null hides the status control (somebody who may only read).
  final Future<void> Function(String status)? onStatusChanged;

  @override
  State<LeadThread> createState() => _LeadThreadState();
}

class _LeadThreadState extends State<LeadThread> {
  late final RecordingPlayer _player = widget.player ?? createRecordingPlayer();
  bool get _ownsPlayer => widget.player == null;

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _conversations = const [];

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
  }

  @override
  void dispose() {
    _player.stop();
    if (_ownsPlayer) _player.dispose();
    super.dispose();
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
    final lead = widget.lead;

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
                    Text(leadTitle(lead), style: theme.textTheme.titleMedium),
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
      ],
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

    return Container(
      padding: const EdgeInsets.fromLTRB(11, 8, 11, 6),
      decoration: BoxDecoration(
        color: mine
            ? theme.colorScheme.primaryContainer
            : theme.colorScheme.surface,
        border: mine
            ? null
            : Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          if ((c['text'] as String?)?.isNotEmpty == true)
            Text(c['text'] as String, style: theme.textTheme.bodyMedium),
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
                color: theme.colorScheme.onSurfaceVariant,
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
                  : IconButton(
                      key: ValueKey('play-$id'),
                      tooltip: playing
                          ? 'Stop'
                          : _player.playsInApp
                          ? 'Play'
                          : 'Play in your audio player',
                      padding: EdgeInsets.zero,
                      onPressed: () => _play(c),
                      icon: Icon(
                        playing
                            ? Icons.stop_circle_outlined
                            : _player.playsInApp
                            ? Icons.play_circle_outline
                            : Icons.open_in_new,
                        color: theme.colorScheme.primary,
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
