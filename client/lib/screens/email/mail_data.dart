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
    this.mayRead = true,
    this.scopeType = '',
    this.scopeId = '',
    this.status = '',
    this.statusReason,
    this.canBeOpened = true,
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

  /// The level the inbox hangs off (`organization`, `company`,
  /// `franchise`) and its id. The rail groups inboxes under their company
  /// with these; the names come from `/me`, which already holds them.
  final String scopeType;
  final String scopeId;

  /// `connected`, `unconfigured` or `broken`, as the last attempt found it.
  /// Empty when the server did not say, which is read as connected: an
  /// older answer must not draw a working inbox as broken.
  final String status;

  /// The server's sentence for why it is not working, when it is not.
  final String? statusReason;

  /// Whether this installation can open it at all (`can_be_opened`).
  final bool canBeOpened;

  /// Connected and openable. The rail puts the others in "Not connected".
  bool get connected =>
      canBeOpened && (status.isEmpty || status == 'connected');

  /// What to tell somebody standing in this inbox when it is not connected.
  String get notConnectedSentence =>
      statusReason ?? '$address is not connected yet.';

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
    scopeType: json['scope_type'] as String? ?? '',
    scopeId: '${json['scope_id'] ?? ''}',
    status: json['status'] as String? ?? '',
    statusReason: json['status_reason'] as String? ??
        json['unavailable_reason'] as String?,
    canBeOpened: json['can_be_opened'] != false,
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
