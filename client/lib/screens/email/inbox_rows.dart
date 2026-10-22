/// The Gmail-style inbox's wire (design 137), as this screen reads it.
///
///   GET  /email/inbox?folder=&category=&mailbox=&search=&cursor=
///     data: [ { key, subject, snippet, from: {name, address}, received_at,
///               unread, starred, has_attachments, message_count, category,
///               inboxes: [ {mailbox_id, address, message_id, thread_id} ],
///               customer: {type, id, name} | null, snoozed_until? } ]
///     meta: { cursor, errors: [ {mailbox_id, address, message} ],
///             counts: { categories: {primary, people, notifications},
///                       unread_loaded, snoozed } }
///
///   POST /email/inbox/actions
///     { action, items: [ {mailbox_id, message_id} ] (at most 100), until?,
///       folder? }  →  data: { results: [ {mailbox_id, message_id, ok,
///                                          message} ] }
///
/// One row is one mail however many of the organization's inboxes it
/// reached (InboxController::merge, by Message-ID), so every action on a row
/// is sent once per entry in [InboxRow.inboxes]: archiving it in info@ and
/// leaving it in admin@ would bring it straight back as the same row.
library;

import 'package:flutter/foundation.dart';

/// Where one copy of a row's mail lives.
@immutable
class InboxCopy {
  const InboxCopy({
    required this.mailboxId,
    required this.address,
    required this.messageId,
    required this.threadId,
  });

  final String mailboxId;
  final String address;
  final String messageId;
  final String threadId;

  /// `admin@`, the local part a row tag shows.
  String get short {
    final at = address.indexOf('@');
    return at <= 0 ? address : address.substring(0, at + 1);
  }

  Map<String, String> get item => {
    'mailbox_id': mailboxId,
    'message_id': messageId,
  };

  factory InboxCopy.fromJson(Map<String, dynamic> json) => InboxCopy(
    mailboxId: '${json['mailbox_id'] ?? ''}',
    address: json['address'] as String? ?? '',
    messageId: '${json['message_id'] ?? ''}',
    threadId: '${json['thread_id'] ?? ''}',
  );
}

/// The customer or lead who sent it, when this person may see them.
@immutable
class InboxSender {
  const InboxSender({required this.type, required this.id, required this.name});

  /// `customer` or `lead`.
  final String type;
  final String id;
  final String name;

  static InboxSender? fromJson(Object? json) {
    if (json is! Map) return null;
    final id = '${json['id'] ?? ''}';
    if (id.isEmpty) return null;

    return InboxSender(
      type: json['type'] as String? ?? '',
      id: id,
      name: json['name'] as String? ?? '',
    );
  }
}

/// One line of the list: one conversation, across every inbox it reached.
@immutable
class InboxRow {
  const InboxRow({
    required this.key,
    required this.subject,
    required this.snippet,
    required this.fromName,
    required this.fromAddress,
    required this.receivedAt,
    required this.unread,
    required this.starred,
    required this.hasAttachments,
    required this.messageCount,
    required this.category,
    required this.inboxes,
    this.customer,
    this.snoozedUntil,
  });

  final String key;
  final String subject;

  /// Decoded: Gmail sends snippets HTML-escaped ("&#39;").
  final String snippet;
  final String fromName;
  final String fromAddress;
  final String? receivedAt;
  final bool unread;
  final bool starred;
  final bool hasAttachments;
  final int messageCount;
  final String category;
  final List<InboxCopy> inboxes;
  final InboxSender? customer;
  final String? snoozedUntil;

  String get sender => fromName.isNotEmpty ? fromName : fromAddress;

  /// Every copy as an action item — what a star or an archive sends.
  List<Map<String, String>> get items => [for (final c in inboxes) c.item];

  InboxRow copyWith({bool? unread, bool? starred}) => InboxRow(
    key: key,
    subject: subject,
    snippet: snippet,
    fromName: fromName,
    fromAddress: fromAddress,
    receivedAt: receivedAt,
    unread: unread ?? this.unread,
    starred: starred ?? this.starred,
    hasAttachments: hasAttachments,
    messageCount: messageCount,
    category: category,
    inboxes: inboxes,
    customer: customer,
    snoozedUntil: snoozedUntil,
  );

  factory InboxRow.fromJson(Map<String, dynamic> json) {
    final from = json['from'] is Map ? json['from'] as Map : const {};

    return InboxRow(
      key: '${json['key'] ?? ''}',
      subject: decodeEntities(json['subject'] as String? ?? ''),
      snippet: decodeEntities(json['snippet'] as String? ?? ''),
      fromName: decodeEntities(from['name'] as String? ?? ''),
      fromAddress: from['address'] as String? ?? '',
      receivedAt: json['received_at'] as String?,
      unread: json['unread'] == true,
      starred: json['starred'] == true,
      hasAttachments: json['has_attachments'] == true,
      messageCount: json['message_count'] is int
          ? json['message_count'] as int
          : 1,
      category: json['category'] as String? ?? 'primary',
      inboxes: [
        for (final entry in (json['inboxes'] as List? ?? const []))
          if (entry is Map<String, dynamic>) InboxCopy.fromJson(entry),
      ],
      customer: InboxSender.fromJson(json['customer']),
      snoozedUntil: json['snoozed_until'] as String?,
    );
  }
}

/// One page of `GET email/inbox`.
@immutable
class InboxPage {
  const InboxPage({
    required this.rows,
    required this.cursor,
    required this.errors,
    required this.categories,
    required this.unreadLoaded,
    required this.snoozed,
  });

  final List<InboxRow> rows;
  final String? cursor;

  /// `[mailbox_id, sentence]` for each inbox that could not be read.
  final List<({String mailboxId, String message})> errors;
  final Map<String, int> categories;
  final int? unreadLoaded;
  final int? snoozed;

  factory InboxPage.fromResponse(Object? data, Object? meta) {
    final m = meta is Map ? meta : const {};
    final counts = m['counts'] is Map ? m['counts'] as Map : const {};
    final categories = counts['categories'] is Map
        ? counts['categories'] as Map
        : const {};
    final cursor = m['cursor'];

    return InboxPage(
      rows: [
        for (final entry in (data is List ? data : const []))
          if (entry is Map<String, dynamic>) InboxRow.fromJson(entry),
      ],
      cursor: cursor is String && cursor.isNotEmpty ? cursor : null,
      errors: [
        for (final e in (m['errors'] as List? ?? const []))
          if (e is Map && e['message'] is String)
            (
              mailboxId: '${e['mailbox_id'] ?? ''}',
              message: e['message'] as String,
            )
          else if (e is String)
            (mailboxId: '', message: e),
      ],
      categories: {
        for (final entry in categories.entries)
          if (entry.value is int) '${entry.key}': entry.value as int,
      },
      unreadLoaded: counts['unread_loaded'] as int?,
      snoozed: counts['snoozed'] as int?,
    );
  }
}

/// The HTML entities Gmail leaves in a snippet, turned back into characters.
///
/// Jose's screenshot (2026-10-22) showed "&lt;" and "&#39;" on the rows. Only
/// the named five and numeric references: a snippet is text with markup
/// escaped, never markup.
String decodeEntities(String text) {
  if (!text.contains('&')) return text;

  return text.replaceAllMapped(
    RegExp(r'&(#x[0-9a-fA-F]+|#[0-9]+|amp|lt|gt|quot|apos|nbsp);'),
    (match) {
      final name = match.group(1)!;
      switch (name) {
        case 'amp':
          return '&';
        case 'lt':
          return '<';
        case 'gt':
          return '>';
        case 'quot':
          return '"';
        case 'apos':
          return "'";
        case 'nbsp':
          return ' ';
      }
      final code = name.startsWith('#x')
          ? int.tryParse(name.substring(2), radix: 16)
          : int.tryParse(name.substring(1));
      if (code == null || code <= 0 || code > 0x10FFFF) return match.group(0)!;
      return String.fromCharCode(code);
    },
  );
}
