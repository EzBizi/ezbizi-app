import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/timestamps.dart';
import '../../widgets/refused.dart';
import 'gmail_inbox.dart' show handledSentence;
import 'inbox_rows.dart';
import 'mail_data.dart';
import 'message_body.dart';
import 'message_html.dart';
import 'message_list.dart';

/// One message, open: the reading pane of design 137.
///
/// Subject, sender, the inbox it reached and when, the customer or lead the
/// sender is (a chip that opens their record), then Reply and Compose with
/// the inbox they go out from. A mail that reached several inboxes is one row,
/// so the from-inbox is a picker over the copies: replying from admin@ is
/// answering admin@'s copy, which is a different message id at the provider.
///
/// Reply (with Cc), Reply all and Forward go to the server's reply and
/// forward endpoints; copies work on Gmail inboxes, and elsewhere the
/// server's 422 sentence is shown in the panel.
class MailMessageView extends StatelessWidget {
  const MailMessageView({
    super.key,
    required this.mailbox,
    required this.message,
    required this.meId,
    required this.formats,
    required this.refusal,
    required this.onReply,
    this.onReplyAll,
    this.onForward,
    required this.dealt,
    required this.onToggleDealt,
    required this.api,
    this.copies = const [],
    this.onFromChanged,
    this.customer,
    this.onOpenCustomer,
    this.onCompose,
  });

  /// The inbox this copy is read from, and the one a reply goes out as.
  final Mailbox mailbox;
  final MessageDetail message;
  final String meId;
  final DisplayFormats formats;
  final String? refusal;
  final VoidCallback onReply;
  final VoidCallback? onReplyAll;

  /// Null when this inbox may not send.
  final VoidCallback? onForward;

  /// Who marked it handled and when, or null.
  final Dealt? dealt;

  /// Toggle it. The screen owns the state, so the pane only reports the press.
  final VoidCallback onToggleDealt;
  final ApiClient api;

  /// Every inbox the row reached. More than one draws the From picker.
  final List<InboxCopy> copies;
  final ValueChanged<String>? onFromChanged;

  final InboxSender? customer;
  final VoidCallback? onOpenCustomer;

  /// Null when this inbox may not send.
  final VoidCallback? onCompose;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final seen = mailbox.sharedRead
        ? seenBySentence(message.seenBy, meId)
        : null;
    final customer = this.customer;

    return LayoutBuilder(
      builder: (context, constraints) {
        // Below 900 the mail gets the screen: icon actions, a header capped at
        // a third (Jose 11-03: "take over the entire screen not half of it").
        final compact = MediaQuery.sizeOf(context).width < 900;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ConstrainedBox(
              // The header scrolls on its own past its cap, so a long subject
              // and the From picker never push the mail off a phone.
              constraints: BoxConstraints(
                maxHeight: constraints.maxHeight * (compact ? 0.3 : 0.55),
              ),
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(20, 16, 20, 12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      message.subject.isEmpty ? 'No subject' : message.subject,
                      style: theme.textTheme.headlineMedium?.copyWith(
                        fontSize: 21,
                      ),
                    ),
                    const SizedBox(height: 12),
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        SenderAvatar(
                          name: message.fromName,
                          email: message.fromAddress,
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                message.from,
                                style: theme.textTheme.bodyMedium,
                              ),
                              Text(
                                'to ${mailbox.address} · '
                                '${formatStamp(message.receivedAt, 'No date', formats: formats)}',
                                style: theme.textTheme.bodySmall,
                              ),
                              if (message.thread.length > 1)
                                Text(
                                  '${message.thread.length} messages',
                                  style: theme.textTheme.bodySmall,
                                ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    if (customer != null) ...[
                      const SizedBox(height: 10),
                      ActionChip(
                        key: const ValueKey('email-customer-chip'),
                        avatar: Icon(
                          customer.type == 'lead'
                              ? Icons.person_search_outlined
                              : Icons.person_outline,
                          size: 18,
                        ),
                        label: Text(
                          '${customer.type == 'lead' ? 'Lead' : 'Customer'} · '
                          '${customer.name.isEmpty ? 'Open record' : customer.name}',
                        ),
                        onPressed: onOpenCustomer,
                      ),
                    ],
                    if (seen != null) ...[
                      const SizedBox(height: 8),
                      SeenByLine(sentence: seen),
                    ],
                    const SizedBox(height: 12),
                    if (copies.length > 1) ...[
                      Wrap(
                        key: const ValueKey('email-from-picker'),
                        spacing: 6,
                        runSpacing: 6,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          Text('From', style: theme.textTheme.labelMedium),
                          for (final copy in copies)
                            ChoiceChip(
                              label: Text(copy.address),
                              selected: copy.mailboxId == mailbox.id,
                              onSelected: (_) =>
                                  onFromChanged?.call(copy.mailboxId),
                            ),
                        ],
                      ),
                      const SizedBox(height: 10),
                    ],
                    if (compact)
                      _iconActions(context)
                    else
                      // Wrap, not Row: "Reply as bookings@…" beside a second control
                      // overflows a 375px pane.
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          Refused(
                            reason: refusal,
                            child: FilledButton.icon(
                              onPressed: refusal == null ? onReply : null,
                              icon: const Icon(Icons.reply, size: 18),
                              label: Text('Reply as ${mailbox.address}'),
                            ),
                          ),
                          if (onReplyAll != null)
                            Refused(
                              reason: refusal,
                              child: OutlinedButton.icon(
                                key: const ValueKey('email-reply-all'),
                                onPressed: refusal == null ? onReplyAll : null,
                                icon: const Icon(Icons.reply_all, size: 18),
                                label: const Text('Reply all'),
                              ),
                            ),
                          if (onForward != null)
                            OutlinedButton.icon(
                              key: const ValueKey('email-forward'),
                              onPressed: onForward,
                              icon: const Icon(Icons.forward, size: 18),
                              label: const Text('Forward'),
                            ),
                          if (onCompose != null)
                            OutlinedButton.icon(
                              key: const ValueKey('email-reader-compose'),
                              onPressed: onCompose,
                              icon: const Icon(Icons.edit_outlined, size: 18),
                              label: const Text('Compose'),
                            ),
                          // Handled, which is NOT the same as read: the inbox's
                          // fact that the customer has been answered.
                          OutlinedButton.icon(
                            key: const ValueKey('email-handled-toggle'),
                            onPressed: onToggleDealt,
                            icon: Icon(
                              dealt != null
                                  ? Icons.check_circle
                                  : Icons.check_circle_outline,
                              size: 18,
                            ),
                            label: Text(
                              dealt != null
                                  ? handledSentence(dealt!, formats)
                                  : 'Mark handled',
                            ),
                          ),
                        ],
                      ),
                  ],
                ),
              ),
            ),
            const Divider(height: 1),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Expanded(child: _conversation(mailbox, message)),
                  if (_files.isNotEmpty)
                    ConstrainedBox(
                      constraints: BoxConstraints(
                        maxHeight: constraints.maxHeight * 0.3,
                      ),
                      child: SingleChildScrollView(
                        child: _attachments(context),
                      ),
                    ),
                ],
              ),
            ),
          ],
        );
      },
    );
  }

  Widget _iconActions(BuildContext context) => Row(
    key: const ValueKey('email-icon-actions'),
    children: [
      Refused(
        reason: refusal,
        child: IconButton(
          key: const ValueKey('email-reply'),
          tooltip: 'Reply as ${mailbox.address}',
          icon: const Icon(Icons.reply),
          onPressed: refusal == null ? onReply : null,
        ),
      ),
      if (onReplyAll != null)
        Refused(
          reason: refusal,
          child: IconButton(
            key: const ValueKey('email-reply-all'),
            tooltip: 'Reply all',
            icon: const Icon(Icons.reply_all),
            onPressed: refusal == null ? onReplyAll : null,
          ),
        ),
      if (onForward != null)
        IconButton(
          key: const ValueKey('email-forward'),
          tooltip: 'Forward',
          icon: const Icon(Icons.forward),
          onPressed: onForward,
        ),
      if (onCompose != null)
        IconButton(
          key: const ValueKey('email-reader-compose'),
          tooltip: 'Compose',
          icon: const Icon(Icons.edit_outlined),
          onPressed: onCompose,
        ),
      IconButton(
        key: const ValueKey('email-handled-toggle'),
        tooltip: dealt != null
            ? handledSentence(dealt!, formats)
            : 'Mark handled',
        icon: Icon(
          dealt != null ? Icons.check_circle : Icons.check_circle_outline,
        ),
        onPressed: onToggleDealt,
      ),
    ],
  );

  Widget _conversation(Mailbox mailbox, MessageDetail message) {
    if (message.thread.length <= 1) {
      return _body(mailbox, message);
    }

    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 28),
      itemCount: message.thread.length,
      separatorBuilder: (_, _) => const Divider(height: 24),
      itemBuilder: (context, index) {
        final part = message.thread[index];
        final theme = Theme.of(context);

        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SenderAvatar(name: part.fromName, email: part.fromAddress),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(part.from, style: theme.textTheme.titleSmall),
                      Text(
                        formatStamp(
                          part.receivedAt,
                          'No date',
                          formats: formats,
                        ),
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            // The web draws HTML in an iframe, which needs a height. Blocks
            // lay out at full length so the thread scrolls as one page; a
            // box of its own trapped the scroll on Eve's iPhone (11-11).
            if (kIsWeb && (part.html ?? '').isNotEmpty)
              SizedBox(height: 420, child: _body(mailbox, part))
            else
              _blocks(mailbox, part, EdgeInsets.zero),
          ],
        );
      },
    );
  }

  Widget _body(Mailbox mailbox, MessageDetail message) {
    final html = message.html;
    if (kIsWeb && html != null && html.isNotEmpty) {
      return MessageHtmlView(html: html);
    }

    return SingleChildScrollView(
      child: _blocks(
        mailbox,
        message,
        const EdgeInsets.fromLTRB(20, 4, 20, 28),
      ),
    );
  }

  Widget _blocks(Mailbox mailbox, MessageDetail message, EdgeInsets padding) =>
      Padding(
        padding: padding,
        child: MessageBody(
          blocks: message.blocks,
          api: api,
          picturePath:
              '/email/mailboxes/${mailbox.id}/messages/${message.id}/picture',
        ),
      );

  /// Files on this message, or on every part of the conversation. Nothing is
  /// stored: a tap streams the bytes through EzBizi as a download.
  List<MailAttachment> get _files {
    if (message.thread.isEmpty) return message.attachments;

    return [for (final part in message.thread) ...part.attachments];
  }

  /// Gmail's chips: a wrapping row, the first four and "+N more" (Eve 11-11:
  /// a list of ten signature logos took her whole screen).
  Widget _attachments(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Divider(height: 1),
        const SizedBox(height: 10),
        _AttachmentChips(
          files: _files,
          label: (file) => file.size == null
              ? file.name
              : '${file.name} · ${_size(file.size!)}',
          onOpen: (file) => _downloadAttachment(context, file),
        ),
      ],
    ),
  );

  Future<void> _downloadAttachment(
    BuildContext context,
    MailAttachment file,
  ) async {
    final token = file.token;
    if (token == null) return;

    final bytes = await api.getBytes(
      '/email/mailboxes/${mailbox.id}/messages/${message.id}/file'
      '?src=${Uri.encodeComponent(token)}'
      '&name=${Uri.encodeComponent(file.name)}',
    );

    if (!context.mounted) return;

    if (bytes == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('That file could not be loaded.')),
      );
      return;
    }

    Files.download(file.name, bytes);
  }

  /// Bytes, in the units a person reads.
  static String _size(int bytes) {
    if (bytes < 1024) return '$bytes bytes';
    if (bytes < 1024 * 1024) return '${(bytes / 1024).round()} KB';

    return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
  }
}

class _AttachmentChips extends StatefulWidget {
  const _AttachmentChips({
    required this.files,
    required this.label,
    required this.onOpen,
  });

  final List<MailAttachment> files;
  final String Function(MailAttachment) label;
  final void Function(MailAttachment) onOpen;

  @override
  State<_AttachmentChips> createState() => _AttachmentChipsState();
}

class _AttachmentChipsState extends State<_AttachmentChips> {
  static const _shown = 4;
  bool _all = false;

  @override
  Widget build(BuildContext context) {
    final files = widget.files;
    final more = files.length - _shown;
    final visible = _all || more <= 0 ? files : files.take(_shown);

    return Wrap(
      key: const ValueKey('email-attachments'),
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final file in visible)
          ActionChip(
            avatar: const Icon(Icons.attach_file, size: 16),
            label: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 220),
              child: Text(widget.label(file), overflow: TextOverflow.ellipsis),
            ),
            tooltip: file.token == null ? null : 'Download ${file.name}',
            onPressed: file.token == null ? null : () => widget.onOpen(file),
          ),
        if (more > 0 && !_all)
          ActionChip(
            key: const ValueKey('email-attachments-more'),
            label: Text('+$more more'),
            onPressed: () => setState(() => _all = true),
          ),
      ],
    );
  }
}
