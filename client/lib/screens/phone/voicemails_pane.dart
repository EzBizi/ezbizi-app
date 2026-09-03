import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
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

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
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

    // Open on the reader's own box. It is the one they came for, and picking
    // the first alphabetically instead means everybody lands on somebody
    // else's messages.
    final mine = widget.session.me?.id;
    final own = boxes.where((b) => b.ownerId == mine).firstOrNull;

    setState(() {
      _loading = false;
      _boxes = boxes;
      _boxId = own?.id ?? (boxes.isEmpty ? null : boxes.first.id);
    });

    await _loadMessages();
  }

  Future<void> _loadMessages() async {
    final boxId = _boxId;
    if (boxId == null) {
      setState(() => _messages = const []);

      return;
    }

    final response = await widget.api.get('/phone/calls', {
      'box_id': boxId,
      'per_page': '50',
    });
    if (!mounted) return;

    setState(() {
      _messages = response.ok
          ? [
              for (final row in (response.data as List? ?? const []))
                if (row is Map<String, dynamic>) _Voicemail.fromJson(row),
            ]
          : const [];
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

    if (_boxes.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'No voicemail boxes yet. Make one under Settings › Voicemail and '
            'point a line at it — until then callers hear the built-in '
            'sentence and the message is filed against the line.',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall,
          ),
        ),
      );
    }

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
      padding: const EdgeInsets.symmetric(vertical: 6),
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 6, 14, 8),
          child: Text('Boxes', style: theme.textTheme.titleSmall),
        ),
        for (final box in _boxes)
          ListTile(
            dense: true,
            selected: box.id == _boxId,
            title: Text(box.name),
            subtitle: Text(box.who),
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
        spacing: 8,
        runSpacing: 8,
        children: [
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

  Widget _messageList(ThemeData theme) {
    if (_messages.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'Nothing waiting in this box.',
            style: theme.textTheme.bodySmall,
          ),
        ),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.all(12),
      itemCount: _messages.length,
      itemBuilder: (context, i) => _row(_messages[i], theme),
    );
  }

  late final RecordingPlayer _player = widget.player ?? createRecordingPlayer();
  late final bool _ownsPlayer = widget.player == null;

  Future<void> _play(_Voicemail message) async {
    // Fetched through EzBizi with the connection's credentials, never a bare
    // Twilio URL — a recording link that works without signing in is a
    // customer's voicemail anybody can replay.
    final bytes = await widget.api.getBytes(
      '/phone/calls/${message.id}/recording',
    );
    if (!mounted || bytes == null) return;

    try {
      await _player.play(bytes);
    } catch (_) {
      // The transcript is still on screen; a failed play is not a reason to
      // take the words away.
    }
  }

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
                Expanded(
                  child: Text(
                    message.from,
                    style: theme.textTheme.titleSmall,
                  ),
                ),
                if (message.dealt)
                  Text(
                    'Dealt with',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
              ],
            ),

            if (message.recordingSid != null) ...[
              const SizedBox(height: 4),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  icon: const Icon(Icons.play_arrow, size: 18),
                  label: const Text('Play'),
                  onPressed: () => _play(message),
                ),
              ),
            ],

            const SizedBox(height: 8),

            // Shown, not hidden behind a control. A transcript you have to ask
            // for is one nobody reads, and reading is faster than listening.
            Text(
              message.transcript?.trim().isNotEmpty == true
                  ? message.transcript!
                  : 'No transcript. Twilio returns nothing for audio it cannot '
                        'make out, which is not an error — the recording is '
                        'still the record.',
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
  });

  final String id;
  final String name;
  final String ownerType;
  final String? ownerId;
  final String? ownerName;

  /// Whose box this is, in words somebody can act on.
  String get who => switch (ownerType) {
    'user' => ownerName == null ? 'A person' : "${ownerName!}'s",
    'ring_group' => ownerName == null ? 'A ring group' : 'Rings ${ownerName!}',
    'number' => ownerName ?? 'One line',
    'company' => ownerName ?? 'The company',
    _ => 'Shared',
  };

  factory _Box.fromJson(Map<String, dynamic> json) => _Box(
    id: (json['id'] ?? '').toString(),
    name: (json['name'] ?? '').toString(),
    ownerType: (json['owner_type'] ?? '').toString(),
    ownerId: json['owner_id'] as String?,
    ownerName: json['owner_name'] as String?,
  );
}

class _Voicemail {
  const _Voicemail({
    required this.id,
    required this.from,
    required this.transcript,
    required this.recordingSid,
    required this.dealt,
  });

  final String id;
  final String from;
  final String? transcript;
  final String? recordingSid;
  final bool dealt;

  factory _Voicemail.fromJson(Map<String, dynamic> json) => _Voicemail(
    id: (json['id'] ?? '').toString(),
    from: (json['from_e164'] ?? '').toString(),
    transcript: json['transcript'] as String?,
    recordingSid: json['recording_sid'] as String?,
    dealt: json['dealt_at'] != null,
  );
}
