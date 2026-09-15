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
    this.mailbox,
    this.allInboxes = false,
    this.allAddresses = const [],
    this.onBack,
    this.backTooltip,
  });

  final Mailbox? mailbox;

  /// Combined view: mail from every inbox this person can open.
  final bool allInboxes;

  /// Addresses named on the combined bar, so it still says whose mail
  /// is on screen when there is no single inbox.
  final List<String> allAddresses;

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
    final mailbox = this.mailbox;

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
          Icon(
            allInboxes ? Icons.all_inbox : Icons.inbox_outlined,
            size: 20,
            color: theme.colorScheme.primary,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  allInboxes ? 'ALL INBOXES' : 'THE INBOX YOU ARE IN',
                  style: theme.textTheme.labelSmall,
                ),
                const SizedBox(height: 2),
                // A Wrap rather than a Row: an address and a nickname side by
                // side at 375px is the arrangement that overflows, and dropping
                // the nickname onto its own line costs nothing.
                Wrap(
                  crossAxisAlignment: WrapCrossAlignment.center,
                  spacing: 8,
                  children: [
                    // A COUNT, not the addresses. The rail below lists every
                    // one of them with its nickname, so spelling them out here
                    // was the same information twice — and the second copy is
                    // the one that grows without bound: six inboxes is a header
                    // wider than the pane.
                    if (allInboxes)
                      Text(
                        allAddresses.length == 1
                            ? '1 inbox connected'
                            : '${allAddresses.length} inboxes connected',
                        style: theme.textTheme.titleMedium,
                      )
                    else if (mailbox != null) ...[
                      Text(
                        mailbox.address,
                        style: theme.textTheme.titleMedium,
                      ),
                      if (mailbox.label.isNotEmpty)
                        Text(
                          mailbox.label,
                          style: theme.textTheme.bodyMedium,
                        ),
                    ],
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
/// Gmail folders for one mailbox — Inbox, Starred, Sent, and the rest.
///
/// Only drawn when the mailbox is Gmail. IMAP has no such list, and inventing
/// one would be a second Inbox under another name.
class MailFolder {
  const MailFolder({
    required this.id,
    required this.label,
    required this.icon,
  });

  final String id;
  final String label;
  final IconData icon;
}

/// Every folder this client knows how to name, in the order they are drawn.
///
/// Which of them a given mailbox is actually offered comes from
/// [Mailbox.folderIds] — this list is the vocabulary, not the offer. Renamed
/// from `gmailFolders` when Outlook gained folders: the old name was accurate
/// while Gmail was the only provider with any, and would have been a quiet
/// lie afterwards.
const List<MailFolder> mailFolders = [
  MailFolder(id: 'INBOX', label: 'Inbox', icon: Icons.inbox_outlined),
  MailFolder(id: 'STARRED', label: 'Starred', icon: Icons.star_outline),
  MailFolder(id: 'SENT', label: 'Sent', icon: Icons.send_outlined),
  MailFolder(id: 'DRAFT', label: 'Drafts', icon: Icons.drafts_outlined),
  MailFolder(id: 'TRASH', label: 'Trash', icon: Icons.delete_outline),
  MailFolder(id: 'SPAM', label: 'Spam', icon: Icons.report_outlined),
  MailFolder(id: 'ARCHIVE', label: 'Archive', icon: Icons.archive_outlined),
];

class MailboxRail extends StatelessWidget {
  const MailboxRail({
    super.key,
    required this.mailboxes,
    required this.selectedId,
    required this.onSelected,
    this.allSelected = false,
    this.onSelectAll,
    this.selectedFolder = 'INBOX',
    this.onFolderSelected,
  });

  final List<Mailbox> mailboxes;
  final String? selectedId;
  final ValueChanged<Mailbox> onSelected;

  /// Combined list of every inbox this person can open. Only offered
  /// when there is more than one — combining one inbox is that inbox.
  final bool allSelected;
  final VoidCallback? onSelectAll;

  final String selectedFolder;
  final ValueChanged<String>? onFolderSelected;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    // Which folders to draw, decided by what the mailboxes in view can
    // actually open rather than by one provider's list.
    //
    // In All inboxes that is the union across every mailbox with folders: a
    // folder only Outlook has still lists the Outlook mail, and the screen
    // filters each fetch to the mailboxes that open it. Offering the
    // intersection instead would hide Archive the moment one Gmail inbox was
    // in view, which is a folder disappearing for a reason nobody can see.
    final openable = <String>{
      for (final mailbox in mailboxes)
        if (allSelected || mailbox.id == selectedId)
          if (mailbox.hasFolders) ...mailbox.folderIds,
    };

    final folders = openable.length > 1
        ? [
            for (final folder in mailFolders)
              if (openable.contains(folder.id)) folder,
          ]
        : const <MailFolder>[];
    final showAll = mailboxes.length > 1 && onSelectAll != null;

    return ListView(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 16),
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(8, 4, 8, 10),
          child: Text('INBOXES', style: theme.textTheme.labelSmall),
        ),
        if (showAll) _allRow(context),
        for (final mailbox in mailboxes) _mailboxRow(context, mailbox),
        if (folders.isNotEmpty) ...[
          const SizedBox(height: 16),
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 4, 8, 10),
            child: Text('LABELS', style: theme.textTheme.labelSmall),
          ),
          for (final folder in folders) _folderRow(context, folder),
        ],
      ],
    );
  }

  Widget _allRow(BuildContext context) {
    final theme = Theme.of(context);
    final unread = mailboxes.fold<int>(0, (sum, mailbox) => sum + mailbox.unread);

    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Material(
        color: allSelected
            ? theme.colorScheme.primary.withValues(alpha: 0.10)
            : Colors.transparent,
        borderRadius: BorderRadius.circular(10),
        child: InkWell(
          borderRadius: BorderRadius.circular(10),
          onTap: onSelectAll,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            child: Row(
              children: [
                Icon(
                  Icons.all_inbox,
                  size: 18,
                  color: theme.colorScheme.onSurfaceVariant,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'All inboxes',
                    style: theme.textTheme.titleMedium?.copyWith(
                      fontWeight: allSelected ? FontWeight.w700 : FontWeight.w500,
                    ),
                  ),
                ),
                if (unread > 0) ...[
                  const SizedBox(width: 8),
                  Tooltip(
                    message: '$unread unread',
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
                        '$unread',
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
  }

  Widget _mailboxRow(BuildContext context, Mailbox mailbox) {
    final theme = Theme.of(context);
    final selected = !allSelected && mailbox.id == selectedId;

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
  }

  Widget _folderRow(BuildContext context, MailFolder folder) {
    final theme = Theme.of(context);
    final selected = folder.id == selectedFolder;
    final onFolderSelected = this.onFolderSelected;

    return Padding(
      padding: const EdgeInsets.only(bottom: 2),
      child: Material(
        color: selected
            ? theme.colorScheme.primary.withValues(alpha: 0.10)
            : Colors.transparent,
        borderRadius: BorderRadius.circular(10),
        child: InkWell(
          borderRadius: BorderRadius.circular(10),
          onTap: onFolderSelected == null ? null : () => onFolderSelected(folder.id),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            child: Row(
              children: [
                Icon(folder.icon, size: 18, color: theme.colorScheme.onSurfaceVariant),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    folder.label,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: selected ? FontWeight.w700 : FontWeight.w400,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
