/// What the Email endpoints answer with, as this screen reads it.
///
/// ── The wire this end was built against ─────────────────────────
///
/// The server for this module is being written in parallel, so the shape below
/// is a STATED ASSUMPTION rather than a description of running code. It is
/// written out here, in one file, so that reconciling the two is reading one
/// list rather than grepping a screen:
///
///   GET  /email/mailboxes
///     data: [ { id, address, label, unread, shared_read, can_reply,
///               reply_refusal } ]
///
///   GET  /email/mailboxes/{mailbox}/messages?page=1
///     data: [ { id, from: {name, address}, subject, preview, received_at,
///               unread, has_attachments,
///               seen_by: [ {id, name} ] } ]
///     meta: { page, last_page }
///
///   GET  /email/mailboxes/{mailbox}/messages/{message}
///     — and this request is ALSO what records the read, which is what puts
///       "Seen by" on somebody else's screen. There is deliberately no second
///       call announcing it: two ways of saying the same thing is two states
///       that can disagree.
///     data: { id, subject, from: {name, address}, to: [address],
///             received_at, seen_by: [...],
///             blocks: [...],                      ← message_blocks.dart
///             attachments: [ {name, size} ],
///             reply: { as: {address, name}, to: [address], subject } }
///
///   POST /email/mailboxes/{mailbox}/messages/{message}/reply
///     { body }  →  data: { sent_at, from }
///
/// ── Three things the shape says out loud ────────────────────────
///
/// **`shared_read` is per mailbox, not per organization.** It is a §6 setting
/// and §6 settings cascade franchise → company → organization, so the value in
/// force depends on which part of the business owns the inbox. Resolving it
/// server-side and sending the answer beside the mailbox is what stops this
/// client from implementing the cascade a second time and getting it wrong for
/// a franchise.
///
/// **`reply.as` is the server's answer, never this client's arithmetic.** The
/// failure this whole screen is shaped around is somebody with three inboxes
/// replying from the wrong one. If the client composed the sending address by
/// picking up whichever mailbox it thinks is selected, that bug is one stale
/// variable away. The address on screen is the one the server says the reply
/// will go out as, and where the server does not say, replying is not offered.
///
/// **`reply_refusal` is a sentence, not a key.** R8: a refusal says what to do.
/// The server knows both halves — the role's permission and this organization's
/// grant on this mailbox — so it writes the sentence and this end prints it.
library;

import 'package:flutter/foundation.dart';

import 'message_blocks.dart';

/// A shared inbox somebody has been granted.
///
/// Only inboxes this person may open ever arrive here: per-inbox access is a
/// tenant-scoped grant on the mailbox record, and the list endpoint applies it.
/// There is deliberately no filtering in this client — a list it had to filter
/// would be a list it could get wrong.
@immutable
class Mailbox {
  const Mailbox({
    required this.id,
    required this.address,
    required this.label,
    required this.unread,
    required this.sharedRead,
    required this.canReply,
    required this.replyRefusal,
    this.provider = '',
    this.discoveredFolders,
    this.mayRead = true,
  });

  final String id;

  /// `info@a-1chimney.com`. The inbox's identity, and what the screen shows
  /// everywhere it says which inbox you are in — never [label], which is a
  /// nickname and can be the same for two of them.
  final String address;

  /// What the organization calls it — "Info", "Bookings". May be empty.
  ///
  /// `label` rather than `name`, deliberately: the roles editor draws the same
  /// inboxes nested under this module and calls it that (core/role_inboxes.dart,
  /// `InboxOption.label`). One word for one fact, so the endpoint behind both
  /// does not have to answer twice in two dialects.
  final String label;

  final int unread;

  /// Whether reads are shared, as the §6 cascade resolved it for this mailbox.
  ///
  /// True is what puts "Seen by" on screen. False means the organization chose
  /// private read state, and then who has opened what is nobody else's business
  /// — so the indicator is not drawn at all rather than drawn empty.
  final bool sharedRead;

  final bool canReply;

  /// Why not, in the server's words. Null when [canReply] is true.
  final String? replyRefusal;

  /// `gmail_oauth`, `gmail_workspace`, `imap`, `microsoft`.
  final String provider;

  /// Whether this person may open it. The list also carries inboxes they may
  /// only MANAGE (Settings needs those); the Inbox tab shows only these.
  final bool mayRead;

  /// Gmail folders (Starred, Sent, …) exist on this mailbox.
  bool get isGmail =>
      provider == 'gmail_oauth' || provider == 'gmail_workspace';

  bool get isOutlook => provider == 'microsoft_oauth';

  /// The folders THIS provider can actually open, by the label the API takes.
  ///
  /// ── Not one list for everybody ───────────────────────────────
  ///
  /// The three providers do not have the same folders, and offering a folder
  /// the server will refuse is the same failure as offering one it silently
  /// substitutes — the person picks it and gets a sentence instead of mail.
  ///
  /// Outlook has no Starred: Graph has a flag filter over the inbox, which is
  /// a different query rather than a folder. It does have Archive, which Gmail
  /// does not offer here.
  ///
  /// IMAP is empty on purpose. The server refuses anything but the Inbox
  /// because folder names differ per server and discovering them is real work
  /// (`LIST`, SPECIAL-USE, a quoting guard) — so nothing is drawn rather than
  /// drawing something that refuses.
  /// What the SERVER said this mailbox has, once it has been asked.
  ///
  /// ── Why an asked answer beats the guess below ────────────────
  ///
  /// For Gmail and Outlook the guess is right and always will be — their
  /// folders are fixed, and the server answers from the same constant. For
  /// IMAP the guess cannot be right in principle: the answer depends on the
  /// customer's own mail server, which may have no Archive, may call Sent
  /// "Sent Items", and may have the whole lot in Portuguese.
  ///
  /// Null until asked, and NOT empty. Empty would mean "this mailbox has no
  /// folders", which is a different claim and one this client is not entitled
  /// to make before it has looked.
  final Set<String>? discoveredFolders;

  /// The same mailbox, with what the server said about its folders.
  ///
  /// A copy rather than a setter because [Mailbox] is immutable and should
  /// stay so — the screen swaps the row in its list.
  Mailbox withFolders(Set<String> folders) => Mailbox(
    id: id,
    address: address,
    label: label,
    unread: unread,
    sharedRead: sharedRead,
    canReply: canReply,
    replyRefusal: replyRefusal,
    provider: provider,
    discoveredFolders: folders,
    mayRead: mayRead,
  );

  Set<String> get folderIds => discoveredFolders ?? _guessedFolderIds;

  /// What this provider's folders are, before the server has been asked.
  ///
  /// Right for Gmail and Outlook by construction. For IMAP it is deliberately
  /// only the Inbox: offering six folders that might not exist, and finding
  /// out on the click, is the broken promise this module keeps refusing to
  /// make.
  Set<String> get _guessedFolderIds {
    if (isGmail) {
      return const {'INBOX', 'STARRED', 'SENT', 'DRAFT', 'TRASH', 'SPAM'};
    }

    if (isOutlook) {
      return const {'INBOX', 'SENT', 'DRAFT', 'TRASH', 'SPAM', 'ARCHIVE'};
    }

    return const {'INBOX'};
  }

  /// Whether this mailbox can show the named folder at all.
  bool opensFolder(String folder) => folderIds.contains(folder);

  /// Worth drawing a folder list for — more than just an Inbox.
  bool get hasFolders => folderIds.length > 1;

  factory Mailbox.fromJson(Map<String, dynamic> json) => Mailbox(
    id: '${json['id']}',
    address: json['address'] as String? ?? '',
    label: json['label'] as String? ?? json['display_name'] as String? ?? '',
    unread: json['unread'] as int? ?? 0,
    // Absent means the server did not resolve the setting, and the safe
    // direction is private: drawing "Seen by Marta" in an organization that
    // asked for private read state would leak what somebody read, and not
    // drawing it in one that asked for shared costs an indicator.
    // `read_state_shared` is the Settings-tab name for the same fact.
    sharedRead: json['shared_read'] == true || json['read_state_shared'] == true,
    // No separate reply permission: if they may open it they may answer.
    canReply: json['can_reply'] == true ||
        (json['can_reply'] == null && json['may_read'] == true),
    replyRefusal: json['reply_refusal'] as String? ??
        json['may_read_reason'] as String?,
    provider: json['provider'] as String? ?? '',
    mayRead: json['may_read'] != false,
  );
}

/// Somebody who has this message open, or has had it open.
@immutable
class SeenBy {
  const SeenBy({required this.id, required this.name});

  final String id;
  final String name;

  factory SeenBy.fromJson(Map<String, dynamic> json) =>
      SeenBy(id: '${json['id']}', name: json['name'] as String? ?? '');
}

/// "Seen by Marta Vieira", or null when there is nobody to name.
///
/// ── Why the reader is left out ──────────────────────────────────
///
/// This is the substitute for claiming a thread (decision 6): the only thing
/// standing between a customer and two different answers to the same email. It
/// answers "is somebody ELSE on this", so the person reading it is removed from
/// the list — "Seen by you" on every message you open would be noise, and worse,
/// it would train people to ignore the line that matters.
///
/// [meId] is the account id from `/me`, so the comparison is against the same
/// identity the server used when it recorded the read.
String? seenBySentence(List<SeenBy> seen, String meId) {
  final others = [
    for (final person in seen)
      if (person.id != meId && person.name.isNotEmpty) person.name,
  ];

  return switch (others.length) {
    0 => null,
    1 => 'Seen by ${others.first}',
    2 => 'Seen by ${others.first} and ${others[1]}',
    // Two names and a count, rather than a wall of them: the fact that matters
    // is that this one is already being handled, and by whom first.
    _ => 'Seen by ${others.first}, ${others[1]} and ${others.length - 2} others',
  };
}

/// A row in the list — a HEADER, which is all that is ever fetched for a list.
///
/// Nothing here is a body. The owner's line is *"we don't want to store
/// thousands and thousands of emails on our database"*, and the shape of that
/// decision at this end is that the list holds enough to choose from and the
/// message is fetched when it is opened.
@immutable
class MessageHeader {
  const MessageHeader({
    required this.id,
    required this.fromName,
    required this.fromAddress,
    required this.subject,
    required this.preview,
    required this.receivedAt,
    required this.unread,
    required this.hasAttachments,
    required this.seenBy,
    this.messageCount = 1,
    this.mailboxId = '',
    this.mailboxAddress = '',
    this.authenticated,
    this.threadId = '',
  });

  final String id;

  /// The conversation this row is, as the server listed it. Sent back when
  /// the message is opened so Gmail can fetch the conversation once instead
  /// of fetching the message just to learn which conversation it is in.
  /// Empty where the provider does not group.
  final String threadId;
  final String fromName;
  final String fromAddress;
  final String subject;
  final String preview;

  /// ISO-8601, as the server sent it. Formatted for display through
  /// core/timestamps.dart against the organization's own §6 formats, never
  /// here — a date written the way this file felt like writing it is the bug
  /// that file exists to prevent.
  final String? receivedAt;

  final bool unread;
  final bool hasAttachments;
  final List<SeenBy> seenBy;

  /// How many messages are in this conversation. 1 is a lone mail.
  final int messageCount;

  /// Which inbox this row belongs to. Set on every list, so All inboxes
  /// can open, reply and delete against the right mailbox.
  final String mailboxId;

  /// The address of that inbox, for the combined list. Empty on a
  /// single-inbox list, where the bar already names it.
  final String mailboxAddress;

  /// DMARC for this message, if the provider said. Null when it did not.
  final bool? authenticated;

  /// Who it is from, in one line: their name where there is one, and the
  /// address where there is not. Never the name alone with the address thrown
  /// away — in a shared inbox the address is half of knowing who is writing.
  String get from => fromName.isEmpty ? fromAddress : '$fromName <$fromAddress>';

  MessageHeader copyWith({bool? unread}) => MessageHeader(
    id: id,
    fromName: fromName,
    fromAddress: fromAddress,
    subject: subject,
    preview: preview,
    receivedAt: receivedAt,
    unread: unread ?? this.unread,
    hasAttachments: hasAttachments,
    seenBy: seenBy,
    messageCount: messageCount,
    mailboxId: mailboxId,
    mailboxAddress: mailboxAddress,
    authenticated: authenticated,
    threadId: threadId,
  );

  factory MessageHeader.fromJson(
    Map<String, dynamic> json, {
    Mailbox? mailbox,
  }) {
    final from = json['from'] as Map<String, dynamic>? ?? const {};

    return MessageHeader(
      id: '${json['id']}',
      fromName: from['name'] as String? ?? json['from_name'] as String? ?? '',
      fromAddress:
          from['address'] as String? ?? json['from_address'] as String? ?? '',
      subject: json['subject'] as String? ?? '',
      preview: json['preview'] as String? ?? json['snippet'] as String? ?? '',
      receivedAt: json['received_at'] as String? ?? json['sent_at'] as String?,
      unread: json['unread'] == true || json['unread_at_provider'] == true,
      hasAttachments: json['has_attachments'] == true,
      seenBy: readSeenBy(json['seen_by']),
      messageCount: json['message_count'] is int
          ? json['message_count'] as int
          : int.tryParse('${json['message_count'] ?? 1}') ?? 1,
      threadId: '${json['thread_id'] ?? ''}',
      mailboxId: mailbox?.id ?? '${json['mailbox_id'] ?? ''}',
      mailboxAddress: mailbox?.address ??
          json['mailbox_address'] as String? ??
          '',
      authenticated: json['from_authenticated'] == true
          ? true
          : json['from_authenticated'] == false
          ? false
          : null,
    );
  }
}

/// A file that came with the message.
///
/// Bytes are not stored here. [token] is the sealed handle the download
/// endpoint needs; without it the row is a name only.
@immutable
class MailAttachment {
  const MailAttachment({required this.name, required this.size, this.token});

  final String name;

  /// Bytes, or null where the provider did not say.
  final int? size;

  /// Sealed attachment id. Null when this connection cannot fetch the file.
  final String? token;

  factory MailAttachment.fromJson(Map<String, dynamic> json) => MailAttachment(
    name: json['name'] as String? ?? '',
    size: json['size'] as int? ?? json['bytes'] as int?,
    token: json['token'] as String?,
  );
}

/// One message, opened.
@immutable
class MessageDetail {
  const MessageDetail({
    required this.id,
    required this.subject,
    required this.fromName,
    required this.fromAddress,
    required this.to,
    required this.receivedAt,
    required this.seenBy,
    required this.blocks,
    this.html,
    required this.attachments,
    required this.replyAs,
    required this.replyToName,
    required this.replyTo,
    required this.replySubject,
    this.thread = const [],
  });

  final String id;
  final String subject;
  final String fromName;
  final String fromAddress;
  final List<String> to;
  final String? receivedAt;
  final List<SeenBy> seenBy;

  /// Fallback body when there is no HTML (plain text, or a non-web build).
  final List<MailBlock> blocks;

  /// Sanitised HTML, Gmail-close. Null when the message was only plain text.
  /// Drawn in a sandboxed iframe — never parsed as Flutter widgets.
  final String? html;

  final List<MailAttachment> attachments;

  /// The address a reply goes out AS, exactly as the server stated it.
  ///
  /// Null when the server said nothing, and then the screen does not offer to
  /// reply. That is not defensiveness for its own sake: *"info replies as
  /// info"* is the rule, and a reply composed against an address this client
  /// guessed is the one way to break it silently.
  final String? replyAs;

  /// The name that address sends under, where the organization set one.
  final String? replyToName;

  final List<String> replyTo;
  final String? replySubject;

  /// The other messages in this conversation, oldest first. Empty when
  /// this is the only one.
  final List<MessageDetail> thread;

  String get from => fromName.isEmpty ? fromAddress : '$fromName <$fromAddress>';

  factory MessageDetail.fromJson(Map<String, dynamic> json) {
    final from = json['from'] as Map<String, dynamic>? ?? const {};
    final reply = json['reply'] as Map<String, dynamic>? ?? const {};
    final as = reply['as'] as Map<String, dynamic>? ?? const {};

    return MessageDetail(
      id: '${json['id']}',
      subject: json['subject'] as String? ?? '',
      fromName: from['name'] as String? ?? json['from_name'] as String? ?? '',
      fromAddress:
          from['address'] as String? ?? json['from_address'] as String? ?? '',
      to: _addresses(json['to']),
      receivedAt: json['received_at'] as String? ?? json['sent_at'] as String?,
      seenBy: readSeenBy(json['seen_by']),
      blocks: readMailBlocks(json['blocks']),
      html: json['html'] as String?,
      attachments: [
        for (final entry in (json['attachments'] as List? ?? const []))
          if (entry is Map<String, dynamic>) MailAttachment.fromJson(entry),
      ],
      replyAs: as['address'] as String?,
      replyToName: as['name'] as String?,
      replyTo: _addresses(reply['to']),
      replySubject: reply['subject'] as String?,
      thread: [
        for (final entry in (json['thread'] as List? ?? const []))
          if (entry is Map<String, dynamic>) MessageDetail.fromJson(entry),
      ],
    );
  }
}

List<SeenBy> readSeenBy(Object? json) {
  final people = <SeenBy>[];

  for (final entry in (json as List? ?? const [])) {
    if (entry is Map<String, dynamic>) {
      people.add(SeenBy.fromJson(entry));
      continue;
    }

    // Live API: a list of names (or "You" for the reader). "You" is already
    // the server leaving the reader out of the sentence colleagues see.
    if (entry is String && entry.isNotEmpty && entry != 'You') {
      people.add(SeenBy(id: entry, name: entry));
    }
  }

  return people;
}

List<String> _addresses(Object? json) => [
  for (final entry in (json as List? ?? const []))
    if (entry is String) entry,
];
