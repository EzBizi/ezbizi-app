import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';

/// Starting a conversation — as the INBOX, and saying so before anything leaves.
///
/// ── The same rule as replying, and the same reason ──────────────
///
/// *"info sends as info"*. The person typing is not the sender here either: a
/// message that went out as jenny.smith@ would put a customer's answer in a
/// mailbox only Jenny can see, which is the failure the whole module exists to
/// avoid. So the address is stated above the box and again on the button, and
/// it comes from the MAILBOX record the server sent — never assembled here.
///
/// ── Where this differs from [ReplyPanel] ────────────────────────
///
/// Reply shows the recipient and subject as FACTS, because they belong to the
/// message being answered and the server decides them. Here somebody has to
/// type them, so they are fields — and that is the whole difference. Everything
/// about who the message goes out as is identical, deliberately: two send paths
/// with two different sender rules is how one of them ends up wrong.
///
/// ── A panel rather than a dialog ────────────────────────────────
///
/// House rule: no pop-ups, a screen or a right-hand panel.
class ComposePanel extends StatefulWidget {
  const ComposePanel({
    super.key,
    required this.api,
    required this.mailboxId,
    required this.sendAs,
  });

  final ApiClient api;
  final String mailboxId;

  /// The sending address, from the mailbox record. The button that opens this
  /// panel is the one place that decides whether sending is possible at all,
  /// so this widget never draws a "sending as nobody" state.
  final String sendAs;

  @override
  State<ComposePanel> createState() => _ComposePanelState();
}

class _ComposePanelState extends State<ComposePanel> {
  final _to = TextEditingController();
  final _subject = TextEditingController();
  final _body = TextEditingController();

  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    // Send lives or dies on there being both a recipient and something to say.
    _to.addListener(() => setState(() {}));
    _body.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _to.dispose();
    _subject.dispose();
    _body.dispose();
    super.dispose();
  }

  /// Recipients, split on commas and semicolons.
  ///
  /// Both separators because people paste from mail programs that use either,
  /// and a semicolon silently treated as part of an address produces a refusal
  /// naming an address nobody typed.
  List<String> get _recipients => _to.text
      .split(RegExp(r'[,;]'))
      .map((address) => address.trim())
      .where((address) => address.isNotEmpty)
      .toList();

  bool get _canSend =>
      !_busy && _recipients.isNotEmpty && _body.text.trim().isNotEmpty;

  Future<void> _send() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post(
      '/email/mailboxes/${widget.mailboxId}/messages',
      {
        'to': _recipients,
        'subject': _subject.text.trim(),
        'body': _body.text.trim(),
      },
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        // The server's own sentence, in full — a refused send is one of the
        // places where knowing WHY is what tells somebody what to do next.
        _error = response.reason;
      });
      return;
    }

    if (mounted) Navigator.of(context).pop(widget.sendAs);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final count = _recipients.length;

    return SidePanel(
      title: 'New message',
      subtitle: 'From ${widget.sendAs}',
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: _canSend ? _send : null,
          // The address on the button as well as above it. This is the last
          // thing read before the message leaves, and "Send" alone is the word
          // that lets somebody send from the wrong inbox without ever having
          // been told which one they were in.
          child: Text(_busy ? 'Sending…' : 'Send as ${widget.sendAs}'),
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
                  'Sending as ${widget.sendAs}',
                  style: theme.textTheme.titleMedium,
                ),
                const SizedBox(height: 6),
                Text(
                  'This goes out as the inbox, not as you. Whoever gets it sees '
                  'that address, and their answer comes back here where '
                  'everybody can see it.',
                  style: theme.textTheme.bodyMedium,
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _to,
            enabled: !_busy,
            autofocus: true,
            keyboardType: TextInputType.emailAddress,
            decoration: InputDecoration(
              labelText: 'To',
              hintText: 'name@example.com',
              // Says what the commas do, rather than leaving somebody to find
              // out by having a message go to one person instead of three.
              helperText: count > 1
                  ? '$count recipients — separate them with commas.'
                  : 'Separate several with commas.',
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _subject,
            enabled: !_busy,
            decoration: const InputDecoration(
              labelText: 'Subject',
              // Not required, and the server fills it in rather than sending a
              // blank one — some mail servers treat a subjectless message as
              // suspicious.
              helperText: 'Optional.',
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _body,
            enabled: !_busy,
            minLines: 6,
            maxLines: null,
            keyboardType: TextInputType.multiline,
            decoration: const InputDecoration(
              labelText: 'Message',
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
}
