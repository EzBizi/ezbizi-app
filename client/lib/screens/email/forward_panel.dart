import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';
import 'mail_data.dart';

/// Forwarding a message from the inbox it is in (design 137).
///
/// `POST email/mailboxes/{mailbox}/messages/{message}/forward {to[], body?}`.
/// The server puts the note above the original as text and sends it from the
/// inbox, like any new mail. The original's attachments do not travel yet,
/// and the panel says so before anything is sent.
///
/// Pops with the server's sentence ("Forwarded, from info@…").
class ForwardPanel extends StatefulWidget {
  const ForwardPanel({
    super.key,
    required this.api,
    required this.mailboxId,
    required this.message,
    required this.sendAs,
  });

  final ApiClient api;
  final String mailboxId;
  final MessageDetail message;

  /// The inbox it goes out from, named on the button.
  final String sendAs;

  @override
  State<ForwardPanel> createState() => _ForwardPanelState();
}

class _ForwardPanelState extends State<ForwardPanel> {
  final _to = TextEditingController();
  final _body = TextEditingController();

  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _to.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _to.dispose();
    _body.dispose();
    super.dispose();
  }

  List<String> get _recipients => [
    for (final part in _to.text.split(RegExp(r'[,;\s]+')))
      if (part.trim().isNotEmpty) part.trim(),
  ];

  Future<void> _send() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post(
      '/email/mailboxes/${widget.mailboxId}/messages/${widget.message.id}/forward',
      {
        'to': _recipients,
        if (_body.text.trim().isNotEmpty) 'body': _body.text.trim(),
      },
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    final said = response.body['message'];
    Navigator.of(context).pop(
      said is String && said.isNotEmpty
          ? said
          : 'Forwarded, from ${widget.sendAs}.',
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final subject = widget.message.subject.isEmpty
        ? 'No subject'
        : widget.message.subject;

    return SidePanel(
      title: 'Forward',
      subtitle: subject,
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const ValueKey('email-forward-send'),
          onPressed: (_busy || _recipients.isEmpty) ? null : _send,
          child: Text(_busy ? 'Sending…' : 'Forward as ${widget.sendAs}'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            key: const ValueKey('email-forward-to'),
            controller: _to,
            enabled: !_busy,
            autofocus: true,
            keyboardType: TextInputType.emailAddress,
            decoration: const InputDecoration(
              labelText: 'To',
              helperText: 'Addresses separated by commas.',
            ),
          ),
          const SizedBox(height: 16),
          TextField(
            key: const ValueKey('email-forward-body'),
            controller: _body,
            enabled: !_busy,
            minLines: 4,
            maxLines: null,
            keyboardType: TextInputType.multiline,
            decoration: const InputDecoration(
              labelText: 'Add a note (optional)',
              alignLabelWithHint: true,
            ),
          ),
          const SizedBox(height: 10),
          Text(
            'Attachments are not forwarded yet.',
            key: const ValueKey('email-forward-note'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
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
