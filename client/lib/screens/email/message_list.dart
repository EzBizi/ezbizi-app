import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';

import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import 'mail_data.dart';
import 'sender_brand.dart';

/// What is in the inbox — headers, and nothing else.
///
/// Given its rows rather than fetching them, because the screen owns the
/// selection and the paging and this pane is drawn in two different places
/// depending on how wide the window is.
class MessageListPane extends StatelessWidget {
  const MessageListPane({
    super.key,
    required this.mailbox,
    required this.messages,
    required this.loading,
    required this.error,
    required this.onRetry,
    required this.selectedId,
    required this.onOpen,
    required this.meId,
    required this.formats,
    required this.more,
    required this.fetching,
    required this.pageError,
    required this.onMore,
    this.checkedIds = const {},
    this.onToggleChecked,
    this.mailboxes = const [],
    this.showMailboxOnRow = false,
    this.emptyLabel,
  });

  final Mailbox mailbox;
  final List<MessageHeader> messages;

  final bool loading;

  /// The server's own sentence when the inbox could not be read.
  ///
  /// This is the state the whole pane is written around. An inbox that cannot
  /// be reached — an expired Google token, an IMAP host that stopped answering
  /// — used to be indistinguishable from an inbox with nothing in it, and "no
  /// mail" is the most damaging thing a mail window can say wrongly: nobody
  /// investigates an empty list. AsyncBody puts the sentence and a retry where
  /// the rows would have been, so the empty state below is only ever drawn when
  /// the fetch genuinely succeeded.
  final String? error;

  final VoidCallback onRetry;
  final String? selectedId;
  final ValueChanged<MessageHeader> onOpen;

  /// The reader's own account id, so "Seen by" can leave them out of it.
  final String meId;

  final DisplayFormats formats;

  final bool more;
  final bool fetching;
  final String? pageError;
  final VoidCallback onMore;

  /// Ticked rows, for bulk actions. Empty means none.
  final Set<String> checkedIds;

  final ValueChanged<MessageHeader>? onToggleChecked;

  /// All inboxes this list may name, so a combined row can use that
  /// mailbox's shared-read setting rather than the pane's.
  final List<Mailbox> mailboxes;

  /// Combined list: say which inbox the row arrived in.
  final bool showMailboxOnRow;

  /// Empty-state wording. Combined view is "any inbox", not one address.
  final String? emptyLabel;

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: loading,
      error: error,
      onRetry: onRetry,
      child: messages.isEmpty
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(
                  'Nothing in ${emptyLabel ?? mailbox.address} right now.',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              ),
            )
          : ListView.separated(
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 16),
              itemCount: messages.length + (more ? 1 : 0),
              separatorBuilder: (_, _) => const SizedBox(height: 6),
              itemBuilder: (context, index) => index == messages.length
                  ? _more(context)
                  : _row(context, messages[index]),
            ),
    );
  }

  Widget _more(BuildContext context) => Center(
    child: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (pageError != null) ...[
          ErrorNote(pageError!),
          const SizedBox(height: 8),
        ],
        TextButton(
          onPressed: fetching ? null : onMore,
          child: Text(
            fetching
                ? 'Loading…'
                : pageError != null
                ? 'Try again'
                // "Older" rather than a count: the provider is asked page by
                // page and never says how many thousands are behind it, so a
                // number here would be one this end invented.
                : 'Older messages',
          ),
        ),
      ],
    ),
  );

  Widget _row(BuildContext context, MessageHeader message) {
    final theme = Theme.of(context);
    final selected = message.id == selectedId;
    final checked = checkedIds.contains(message.id);

    // Decision 8. When the organization chose private read state, who has
    // opened what is nobody else's business and the line is not drawn at all —
    // not drawn empty, not drawn greyed.
    final owner = _owner(message);
    final seen = owner.sharedRead
        ? seenBySentence(message.seenBy, meId)
        : null;

    return Material(
      color: selected || checked
          ? theme.colorScheme.primary.withValues(alpha: 0.08)
          : Colors.transparent,
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: () => onOpen(message),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _leading(context, message, checked),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Text(
                      message.fromName.isNotEmpty
                          ? message.fromName
                          : message.fromAddress,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: message.unread
                            ? FontWeight.w700
                            : FontWeight.w400,
                        color: theme.colorScheme.onSurface,
                      ),
                    ),
                  ),
                  if (message.messageCount > 1) ...[
                    const SizedBox(width: 8),
                    Text(
                      '${message.messageCount}',
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ],
                  const SizedBox(width: 8),
                  Text(
                    formatStamp(message.receivedAt, 'No date', formats: formats),
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
              const SizedBox(height: 2),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Text(
                      message.subject.isEmpty ? 'No subject' : message.subject,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontWeight: message.unread
                            ? FontWeight.w700
                            : FontWeight.w500,
                      ),
                    ),
                  ),
                  if (message.hasAttachments) ...[
                    const SizedBox(width: 6),
                    Tooltip(
                      message: 'Has an attachment',
                      child: Icon(
                        Icons.attach_file,
                        size: 15,
                        color: theme.textTheme.bodySmall?.color,
                      ),
                    ),
                  ],
                ],
              ),
              if (message.preview.isNotEmpty) ...[
                const SizedBox(height: 2),
                Text(
                  message.preview,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodyMedium,
                ),
              ],
              if (showMailboxOnRow && message.mailboxAddress.isNotEmpty) ...[
                const SizedBox(height: 4),
                Text(
                  message.mailboxAddress,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall,
                ),
              ],
              if (seen != null) ...[
                const SizedBox(height: 6),
                SeenByLine(sentence: seen),
              ],
            ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Mailbox _owner(MessageHeader message) {
    for (final mailbox in mailboxes) {
      if (mailbox.id == message.mailboxId) return mailbox;
    }
    return mailbox;
  }

  Widget _leading(BuildContext context, MessageHeader message, bool checked) {
    final onToggleChecked = this.onToggleChecked;
    final theme = Theme.of(context);

    return InkWell(
      customBorder: const CircleBorder(),
      onTap: onToggleChecked == null ? null : () => onToggleChecked(message),
      child: checked
          ? CircleAvatar(
              radius: 18,
              backgroundColor: theme.colorScheme.primary,
              child: Icon(
                Icons.check,
                size: 20,
                color: theme.colorScheme.onPrimary,
              ),
            )
          : SenderAvatar(
              name: message.fromName,
              email: message.fromAddress,
              authenticated: message.authenticated,
            ),
    );
  }
}

/// Gmail-style sender mark: a letter on a colour, or the site's icon when
/// Google's favicon service has one for that domain (Apple, Google Cloud).
///
/// The image is an HTML `<img>` on web ([WebHtmlElementStrategy.prefer]).
/// CanvasKit cannot decode `google.com/s2/favicons` (no CORS), so the
/// letter was all anybody saw. The blue check is Gmail's verified mark
/// for known brands whose mail was not reported as failing DMARC.
class SenderAvatar extends StatelessWidget {
  const SenderAvatar({
    super.key,
    required this.name,
    required this.email,
    this.authenticated,
  });

  final String name;
  final String email;

  /// DMARC for this message, if the provider said. Null when it did not.
  final bool? authenticated;

  String get _letter {
    final source = name.trim().isNotEmpty ? name.trim() : email.trim();
    if (source.isEmpty) return '?';
    return source[0].toUpperCase();
  }

  Color get _color {
    final key = email.isNotEmpty ? email : name;
    var hash = 0;
    for (final unit in key.codeUnits) {
      hash = (hash + unit) & 0x7fffffff;
    }
    const palette = EzBiziTheme.avatarPalette;
    return palette[hash % palette.length];
  }

  @override
  Widget build(BuildContext context) {
    final letter = _letter;
    final url = SenderBrand.faviconUrl(email);
    final verified = SenderBrand.verified(email, authenticated: authenticated);
    final fallback = CircleAvatar(
      radius: 18,
      backgroundColor: _color,
      child: Text(
        letter,
        style: const TextStyle(
          color: Colors.white,
          fontWeight: FontWeight.w600,
          fontSize: 16,
        ),
      ),
    );

    Widget mark = fallback;

    if (url != null && kIsWeb) {
      mark = ClipOval(
        child: SizedBox(
          width: 36,
          height: 36,
          child: Image.network(
            url,
            fit: BoxFit.cover,
            webHtmlElementStrategy: WebHtmlElementStrategy.prefer,
            errorBuilder: (_, _, _) => fallback,
            frameBuilder: (context, child, frame, wasSynchronouslyLoaded) {
              if (wasSynchronouslyLoaded || frame != null) return child;
              return fallback;
            },
          ),
        ),
      );
    }

    if (!verified) return mark;

    return SizedBox(
      width: 36,
      height: 36,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          mark,
          const Positioned(
            right: -1,
            bottom: -1,
            child: Tooltip(
              message: 'Verified sender',
              child: _VerifiedDot(),
            ),
          ),
        ],
      ),
    );
  }
}

class _VerifiedDot extends StatelessWidget {
  const _VerifiedDot();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 14,
      height: 14,
      decoration: BoxDecoration(
        color: const Color(0xFF1A73E8),
        shape: BoxShape.circle,
        border: Border.all(color: Colors.white, width: 1.5),
      ),
      child: const Icon(Icons.check, size: 8, color: Colors.white),
    );
  }
}

/// "Seen by Marta Vieira" — the substitute for claiming a thread.
///
/// Decision 6: ownership of a thread was considered and deferred, so this line
/// is the only thing standing between a customer and two different answers to
/// the same email. It carries an icon as well as words because it has to be
/// noticeable in a scanned list, and words as well as an icon because a symbol
/// alone says nothing to a screen reader.
class SeenByLine extends StatelessWidget {
  const SeenByLine({super.key, required this.sentence});

  final String sentence;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(
          Icons.visibility_outlined,
          size: 14,
          color: theme.colorScheme.secondary,
        ),
        const SizedBox(width: 6),
        Expanded(
          child: Text(
            sentence,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.secondary,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
      ],
    );
  }
}
