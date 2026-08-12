import 'package:flutter/material.dart';

import 'mail_data.dart';

/// Which inbox you are in — said, not implied.
///
/// ── The failure this bar exists to prevent ──────────────────────
///
/// Somebody who can open `info@`, `bookings@` and `careers@` answering a
/// customer from the wrong one. Everything else on this screen is recoverable;
/// that is not, because it has already left the building.
///
/// A highlighted row in a list on the left is not enough on its own, and at
/// 375px there is no list on the left at all. So the inbox in force is written
/// out above the mail at EVERY width, in the address rather than the nickname —
/// two inboxes can both be called "Info", and only one of them is the one the
/// reply will come from.
class InboxBar extends StatelessWidget {
  const InboxBar({
    super.key,
    required this.mailbox,
    this.onBack,
    this.backTooltip,
  });

  final Mailbox mailbox;

  /// Where the back control goes, on the widths that have one pane. Null on a
  /// wide screen, where nothing has been left behind to go back to.
  final VoidCallback? onBack;

  /// What going back reaches — "Back to the inboxes", "Back to the messages".
  /// A bare "Back" on a screen with three panes says nothing.
  final String? backTooltip;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final onBack = this.onBack;

    return Padding(
      padding: EdgeInsets.fromLTRB(onBack == null ? 20 : 6, 12, 20, 12),
      child: Row(
        children: [
          if (onBack != null)
            IconButton(
              icon: const Icon(Icons.arrow_back),
              tooltip: backTooltip ?? 'Back',
              onPressed: onBack,
            ),
          Icon(Icons.inbox_outlined, size: 20, color: theme.colorScheme.primary),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('THE INBOX YOU ARE IN', style: theme.textTheme.labelSmall),
                const SizedBox(height: 2),
                // A Wrap rather than a Row: an address and a nickname side by
                // side at 375px is the arrangement that overflows, and dropping
                // the nickname onto its own line costs nothing.
                Wrap(
                  crossAxisAlignment: WrapCrossAlignment.center,
                  spacing: 8,
                  children: [
                    Text(mailbox.address, style: theme.textTheme.titleMedium),
                    if (mailbox.label.isNotEmpty)
                      Text(mailbox.label, style: theme.textTheme.bodyMedium),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The inboxes somebody has been granted — a column on a wide screen, and the
/// whole pane on a narrow one.
///
/// One widget for both, rather than a rail and a separate chooser page: they
/// are the same list making the same choice, and two of them is two places for
/// the selected state to be drawn differently.
class MailboxRail extends StatelessWidget {
  const MailboxRail({
    super.key,
    required this.mailboxes,
    required this.selectedId,
    required this.onSelected,
  });

  final List<Mailbox> mailboxes;
  final String? selectedId;
  final ValueChanged<Mailbox> onSelected;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 16),
      itemCount: mailboxes.length + 1,
      itemBuilder: (context, index) {
        if (index == 0) {
          return Padding(
            padding: const EdgeInsets.fromLTRB(8, 4, 8, 10),
            child: Text('INBOXES', style: theme.textTheme.labelSmall),
          );
        }

        final mailbox = mailboxes[index - 1];
        final selected = mailbox.id == selectedId;

        return Padding(
          padding: const EdgeInsets.only(bottom: 4),
          child: Material(
            color: selected
                ? theme.colorScheme.primary.withValues(alpha: 0.10)
                : Colors.transparent,
            borderRadius: BorderRadius.circular(10),
            child: InkWell(
              borderRadius: BorderRadius.circular(10),
              onTap: () => onSelected(mailbox),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            mailbox.address,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.titleMedium?.copyWith(
                              // Selection carried by weight and a tint, never by
                              // colour alone — the same rule the rest of the app
                              // follows for a state somebody has to notice.
                              fontWeight: selected
                                  ? FontWeight.w700
                                  : FontWeight.w500,
                            ),
                          ),
                          if (mailbox.label.isNotEmpty)
                            Text(
                              mailbox.label,
                              style: theme.textTheme.bodyMedium,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                        ],
                      ),
                    ),
                    if (mailbox.unread > 0) ...[
                      const SizedBox(width: 8),
                      // The number, with the word beside it in the tooltip:
                      // a bare badge is a colour-only state to anybody using a
                      // screen reader.
                      Tooltip(
                        message: '${mailbox.unread} unread',
                        child: Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 8,
                            vertical: 2,
                          ),
                          decoration: BoxDecoration(
                            color: theme.colorScheme.primary,
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Text(
                            '${mailbox.unread}',
                            style: TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                              color: theme.colorScheme.onPrimary,
                            ),
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}
