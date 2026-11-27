import 'dart:async';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';

/// A conversation with one colleague, in the Team column of the Call tab.
///
/// ── Why it lives in that column and not in a new screen ──────────
///
/// The reason somebody messages a colleague from here is that they are ON a
/// call, or about to be: "can you take this", "is the van back". A screen that
/// evicted the dial pad or the customer to show the thread would take away the
/// thing the message is about. So the roster becomes the thread and the other
/// two columns are untouched, which is also why Back returns to the roster
/// rather than closing anything.
///
/// ── Sent and seen. Nothing else ──────────────────────────────────
///
/// No delivered, no typing indicator, no read-receipt animation. Each of those
/// is a claim this system cannot honestly make — there is no delivery receipt
/// to report, and a typing indicator would need a live channel that does not
/// exist. "Seen" is the one thing the server genuinely knows, because opening
/// the thread is what records it.
class StaffThread extends StatefulWidget {
  const StaffThread({
    super.key,
    required this.api,
    required this.withUserId,
    required this.withName,
    required this.onBack,
    required this.onRead,
  });

  final ApiClient api;
  final String withUserId;
  final String withName;
  final VoidCallback onBack;

  /// Opening a thread IS reading it, so the unread counts outside are stale
  /// the moment this loads.
  final VoidCallback onRead;

  @override
  State<StaffThread> createState() => _StaffThreadState();
}

class _StaffThreadState extends State<StaffThread> {
  bool _loading = true;
  bool _sending = false;
  String? _error;
  List<_Msg> _messages = const [];

  final _composer = TextEditingController();
  final _scroll = ScrollController();

  @override
  void initState() {
    super.initState();
    _load();
    // ponytail: a 10 s poll, a push subscription if threads get busy.
    // Audit 11-27: an open thread never showed new replies.
    _poll = Timer.periodic(const Duration(seconds: 10), (_) => _refresh());
  }

  Timer? _poll;

  Future<void> _refresh() async {
    if (_loading) return;
    final response = await widget.api.get('/staff-messages', {
      'with': widget.withUserId,
    });
    if (!mounted || !response.ok) return;
    final rows = [
      for (final row in (response.data as List? ?? const []))
        if (row is Map<String, dynamic>) _Msg.fromJson(row),
    ];
    if (rows.length == _messages.length &&
        (rows.isEmpty ||
            (rows.last.body == _messages.last.body &&
                rows.last.readAt == _messages.last.readAt))) {
      return;
    }
    setState(() => _messages = rows);
    widget.onRead();
    _toBottom();
  }

  @override
  void didUpdateWidget(StaffThread old) {
    super.didUpdateWidget(old);

    // Switching colleague without leaving the thread has to refetch, or you
    // are reading one person's messages under another person's name.
    if (old.withUserId != widget.withUserId) {
      _composer.clear();
      _load();
    }
  }

  @override
  void dispose() {
    _poll?.cancel();
    _composer.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _error = null);

    final response = await widget.api.get('/staff-messages', {
      'with': widget.withUserId,
    });

    if (!mounted) return;

    setState(() {
      _loading = false;
      _messages = response.ok
          ? [
              for (final row in (response.data as List? ?? const []))
                if (row is Map<String, dynamic>) _Msg.fromJson(row),
            ]
          : const [];
      _error = response.ok ? null : response.reason;
    });

    if (response.ok) {
      widget.onRead();
      _toBottom();
    }
  }

  void _toBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scroll.hasClients) return;
      _scroll.jumpTo(_scroll.position.maxScrollExtent);
    });
  }

  Future<void> _send() async {
    final body = _composer.text.trim();
    if (body.isEmpty || _sending) return;

    setState(() {
      _sending = true;
      _error = null;
    });

    final response = await widget.api.post('/staff-messages', {
      'to': widget.withUserId,
      'body': body,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _sending = false;
        _error = response.reason;
      });
      return;
    }

    _composer.clear();
    setState(() => _sending = false);
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 6, 12, 6),
          child: Row(
            children: [
              IconButton(
                tooltip: 'Back to the team',
                icon: const Icon(Icons.arrow_back, size: 20),
                onPressed: widget.onBack,
              ),
              Expanded(
                child: Text(
                  widget.withName,
                  style: theme.textTheme.titleSmall,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
        ),
        Divider(height: 1, color: EzBiziTheme.edge(context)),

        if (_error != null)
          Padding(
            padding: const EdgeInsets.all(8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                ErrorNote(_error!),
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton(
                    onPressed: _load,
                    child: const Text('Try again'),
                  ),
                ),
              ],
            ),
          ),

        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : _messages.isEmpty
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Text(
                      'No messages yet.',
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                )
              : ListView.builder(
                  controller: _scroll,
                  padding: const EdgeInsets.fromLTRB(10, 10, 10, 10),
                  itemCount: _messages.length,
                  itemBuilder: (context, i) => _bubble(theme, i),
                ),
        ),

        Divider(height: 1, color: EzBiziTheme.edge(context)),
        Padding(
          padding: const EdgeInsets.fromLTRB(10, 8, 10, 10),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(
                child: TextField(
                  key: const Key('staff-message-composer'),
                  controller: _composer,
                  minLines: 1,
                  maxLines: 4,
                  textInputAction: TextInputAction.send,
                  onSubmitted: (_) => _send(),
                  decoration: const InputDecoration(
                    hintText: 'Message',
                    border: OutlineInputBorder(),
                    isDense: true,
                  ),
                ),
              ),
              const SizedBox(width: 6),
              IconButton(
                tooltip: 'Send',
                icon: const Icon(Icons.send, size: 20),
                onPressed: _sending ? null : _send,
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _bubble(ThemeData theme, int i) {
    final message = _messages[i];

    // "Seen" under the LAST of my messages that has been read, and nowhere
    // else. Marking every one of them would turn a fact into wallpaper, and
    // the question it answers — did that get through — is only ever about the
    // most recent thing said.
    final lastReadMine =
        message.mine &&
        message.readAt != null &&
        !_messages
            .sublist(i + 1)
            .any((m) => m.mine && m.readAt != null);

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Column(
        crossAxisAlignment: message.mine
            ? CrossAxisAlignment.end
            : CrossAxisAlignment.start,
        children: [
          Container(
            constraints: const BoxConstraints(maxWidth: 220),
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
            decoration: BoxDecoration(
              color: message.mine
                  ? theme.colorScheme.primaryContainer
                  : theme.colorScheme.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Text(message.body, style: theme.textTheme.bodySmall),
          ),
          if (lastReadMine)
            Padding(
              padding: const EdgeInsets.only(top: 2, right: 4),
              child: Text(
                'Seen',
                style: theme.textTheme.labelSmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _Msg {
  const _Msg({
    required this.body,
    required this.mine,
    required this.readAt,
  });

  final String body;
  final bool mine;
  final String? readAt;

  factory _Msg.fromJson(Map<String, dynamic> json) => _Msg(
    body: json['body'] as String? ?? '',
    mine: json['mine'] == true,
    readAt: json['read_at'] as String?,
  );
}
