import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';
import 'mail_data.dart';

/// Answering a message — as the INBOX, and saying so before anything is sent.
///
/// ── Decision 7, and why it is a whole panel's worth of care ─────
///
/// *"info replies as info"*. The person typing is not the sender: the customer
/// wrote to a shared address and gets an answer from that address, whoever
/// happened to be on the rota. That is the entire point of the module — it
/// exists so people can answer mail sent to an address that is not theirs.
///
/// The failure it produces if it goes wrong is not a broken screen, it is a
/// reply that has already left the building over the wrong name. So the address
/// is stated in full, in the sentence somebody reads on their way to the Send
/// button, and it is [MessageDetail.replyAs] — the address the SERVER said the
/// reply will go out as — never a value assembled here from whichever mailbox
/// this client believes is selected.
///
/// ── A panel rather than a dialog ────────────────────────────────
///
/// House rule: no pop-ups, a screen or a right-hand panel. It is also the right
/// shape on its own merits — the message being answered stays on screen beside
/// the box, which is what anybody writing a reply is looking at.
class ReplyPanel extends StatefulWidget {
  const ReplyPanel({
    super.key,
    required this.api,
    required this.mailboxId,
    required this.message,
    required this.replyAs,
    this.all = false,
  });

  /// Reply all: the server adds everybody the message was to and copied to
  /// (`all: true`). Copies only work on Gmail inboxes; elsewhere the server
  /// answers 422 with a sentence, shown here.
  final bool all;

  final ApiClient api;
  final String mailboxId;
  final MessageDetail message;

  /// The sending address, already established as present by the screen that
  /// opened this panel — so this widget never has to draw a "replying as
  /// nobody" state, and the button that opens it is the one place that decides
  /// whether replying is possible at all.
  final String replyAs;

  @override
  State<ReplyPanel> createState() => _ReplyPanelState();
}

class _ReplyPanelState extends State<ReplyPanel> {
  final _body = TextEditingController();

  /// Extra copies, comma separated. Sent as `cc` only when filled in.
  final _cc = TextEditingController();

  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    // The Send button lives or dies on whether anything has been typed.
    _body.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _body.dispose();
    _cc.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post(
      '/email/mailboxes/${widget.mailboxId}/messages/${widget.message.id}/reply',
      // The text, and nothing else. Who it goes to, what it is called and which
      // identity it leaves under are the server's to decide — sending them from
      // here would make this client a second place where a reply can be
      // addressed, and the wrong one would look exactly like the right one.
      {
        'body': _body.text.trim(),
        if (_copies.isNotEmpty) 'cc': _copies,
        if (widget.all) 'all': true,
      },
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        // The server's own sentence, in full: a refused reply is one of the
        // few places where knowing WHY — the token expired, the address is no
        // longer connected — is what tells somebody what to do next.
        _error = response.reason;
      });
      return;
    }

    final data = response.data;

    // What actually went out, as reported, so the confirmation is a fact rather
    // than a repetition of what this screen intended.
    final from = data is Map && data['from'] is String
        ? data['from'] as String
        : widget.replyAs;

    if (mounted) Navigator.of(context).pop(from);
  }

  List<String> get _copies => [
    for (final part in _cc.text.split(RegExp(r'[,;\s]+')))
      if (part.trim().isNotEmpty) part.trim(),
  ];

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final message = widget.message;

    return SidePanel(
      title: widget.all ? 'Reply all' : 'Reply',
      subtitle: 'To ${message.from}',
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: (_busy || _body.text.trim().isEmpty) ? null : _send,
          // The address on the button as well as above it. This is the last
          // thing read before the reply leaves, and "Send" alone is the word
          // that lets somebody send from the wrong inbox without ever having
          // been told which one they were in.
          child: Text(_busy ? 'Sending…' : 'Send as ${widget.replyAs}'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: theme.colorScheme.primary.withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(
                color: theme.colorScheme.primary.withValues(alpha: 0.35),
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Replying as ${widget.replyAs}',
                  style: theme.textTheme.titleMedium,
                ),
                if (widget.message.replyToName != null &&
                    widget.message.replyToName!.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(
                    'Under the name ${widget.message.replyToName}.',
                    style: theme.textTheme.bodyMedium,
                  ),
                ],
                const SizedBox(height: 6),
                Text(
                  'This answer goes out as the inbox, not as you. Whoever gets '
                  'it sees that address — your own name and address are not on '
                  'it.',
                  style: theme.textTheme.bodyMedium,
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),
          // Facts, not fields. The recipient and the subject are the server's,
          // and a box somebody can type into is a promise that what they type
          // will be used.
          _fact(context, 'To', [
            message.replyTo.isEmpty
                ? message.fromAddress
                : message.replyTo.join(', '),
            if (widget.all) 'and everybody else on the message',
          ].join(' ')),
          const SizedBox(height: 8),
          _fact(
            context,
            'Subject',
            message.replySubject?.isNotEmpty == true
                ? message.replySubject!
                : (message.subject.isEmpty ? 'No subject' : message.subject),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const ValueKey('email-reply-cc'),
            controller: _cc,
            enabled: !_busy,
            decoration: const InputDecoration(
              labelText: 'Cc',
              helperText: 'Addresses separated by commas. Copies work on Gmail inboxes.',
            ),
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _body,
            enabled: !_busy,
            autofocus: true,
            minLines: 6,
            maxLines: null,
            keyboardType: TextInputType.multiline,
            decoration: const InputDecoration(
              labelText: 'Your reply',
              alignLabelWithHint: true,
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: 14),
            ErrorNote(_error!),
          ],
        ],
      ),
    );
  }

  Widget _fact(BuildContext context, String label, String value) {
    final theme = Theme.of(context);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 64,
          child: Text(label, style: theme.textTheme.labelSmall),
        ),
        Expanded(child: Text(value, style: theme.textTheme.bodyMedium)),
      ],
    );
  }
}
