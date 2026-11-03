import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/deep_links.dart';
import '../../core/device_key_store_io.dart'
    if (dart.library.js_interop) '../../core/device_key_store_web.dart'
    as store;
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/open_destination.dart';
import '../../widgets/side_panel.dart';
import '../customers/customer_detail_panel.dart';
import 'compose_panel.dart';
import 'filters_panel.dart';
import 'forward_panel.dart';
import 'inbox_rows.dart';
import 'mail_data.dart';
import 'message_list.dart';
import 'message_view.dart';
import 'reply_panel.dart';

/// Where the open conversation sits beside the list (design 137 option 1).
enum ReadingPane { right, below, off }

/// Email › Inbox, laid out like Gmail (design 137, options 1 and 4).
///
/// Jose, 2026-10-22: "overhaul the Email - Inbox UI, something more akin to
/// how gmail looks". One list across every inbox this person may open, read
/// from `GET email/inbox`, where the same mail that reached admin@ and info@ is
/// one row tagged with both (see inbox_rows.dart). On a desktop: the rail with
/// Compose, folders and inboxes grouped by company; a rounded search; Primary /
/// Leads & customers / Notifications; one-line rows; a reading pane to the
/// right, below, or off. Under 600px: the Gmail app — avatar rows, ☰ for the
/// rail, a floating Compose, swipe to archive, the reader full screen.
///
/// ── Which inbox a reply goes out as ─────────────────────────────────
///
/// The failure this module is arranged against is answering a customer from
/// the wrong address. The reader names the inbox ("to info@…"), the reply
/// button says "Reply as info@…", and a mail that reached several inboxes
/// offers a From picker over exactly those — the reply is posted to the inbox
/// whose copy is open. Compose on All inboxes asks which inbox it sends from
/// rather than picking one.
class GmailInbox extends StatefulWidget {
  const GmailInbox({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<GmailInbox> createState() => _GmailInboxState();
}

/// Snooze presets, from [now] in the device's own time.
DateTime snoozeLaterToday(DateTime now) {
  final later = now.add(const Duration(hours: 3));
  return DateTime(later.year, later.month, later.day, later.hour);
}

DateTime snoozeTomorrow(DateTime now) =>
    DateTime(now.year, now.month, now.day + 1, 8);

DateTime snoozeNextWeek(DateTime now) {
  final days = (DateTime.monday - now.weekday + 7) % 7;
  return DateTime(now.year, now.month, now.day + (days == 0 ? 7 : days), 8);
}

/// "10:24 AM" today, "Oct 21" this year, the organization's date otherwise.
String inboxStamp(String? iso, DisplayFormats formats, {DateTime? now}) {
  final at = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (at == null) return '';
  final today = (now ?? DateTime.now()).toLocal();
  if (at.year == today.year && at.month == today.month && at.day == today.day) {
    return formats.formatTime(at.hour, at.minute);
  }
  if (at.year == today.year) return '${shortMonths[at.month - 1]} ${at.day}';
  return formats.formatDate(at);
}

/// "Handled by Dana · 9:12", "Handled · Oct 21" when an automation did it.
String handledSentence(Dealt dealt, DisplayFormats formats) {
  final when = inboxStamp(dealt.at, formats);
  final who = dealt.by == null || dealt.by!.isEmpty
      ? 'Handled'
      : 'Handled by ${dealt.by}';
  return when.isEmpty ? who : '$who · $when';
}

class _Folder {
  const _Folder(this.id, this.label, this.icon);
  final String id;
  final String label;
  final IconData icon;
}

const _folders = [
  _Folder('inbox', 'Inbox', Icons.inbox_outlined),
  _Folder('starred', 'Starred', Icons.star_border),
  _Folder('snoozed', 'Snoozed', Icons.schedule),
  _Folder('sent', 'Sent', Icons.send_outlined),
  _Folder('drafts', 'Drafts', Icons.drafts_outlined),
  _Folder('all', 'All mail', Icons.all_inbox_outlined),
];

// All first and the default: the whole inbox, newest first (Jose, 2026-11-03).
const _categories = [
  ('all', 'All', Icons.all_inbox_outlined),
  ('primary', 'Primary', Icons.inbox_outlined),
  ('people', 'Leads & customers', Icons.people_outline),
  ('notifications', 'Notifications', Icons.notifications_none),
];

/// Inboxes under one company, or the "Not connected" group.
class _Group {
  _Group(this.id, this.name);
  final String id;
  final String name;
  final List<Mailbox> inboxes = [];

  /// The known counts summed; null when none of them is known yet.
  int? get unread {
    final known = [for (final m in inboxes) ?m.unread];
    return known.isEmpty ? null : known.fold<int>(0, (sum, n) => sum + n);
  }
}

class _GmailInboxState extends State<GmailInbox> {
  /// Below this the Gmail app layout (option 4).
  static const double _phoneBelow = 600;

  /// From here the rail sits beside the list; under it ☰ opens it in place.
  static const double _railBeside = 1000;
  static const double _railWidth = 256;

  /// The list needs this much beside a reading pane on the right; under it
  /// the pane goes below instead.
  static const double _rightPaneNeeds = 900;

  bool _loadingMailboxes = true;
  String? _mailboxesError;
  String? _emptyBecause;
  List<Mailbox> _mailboxes = const [];

  /// `&mailbox=`, or null for All inboxes.
  String? _mailboxFilter;
  final Set<String> _openGroups = {};
  bool _groupsSeeded = false;

  String _folder = 'inbox';

  /// Folder-action filters (`GET email/filters`), drawn as folders under the
  /// built-in ones; opening one is `GET email/inbox?filter={id}`.
  List<EmailFilter> _filterFolders = const [];

  List<_Folder> get _allFolders => [
    ..._folders,
    for (final f in _filterFolders)
      _Folder('filter:${f.id}', f.name, Icons.folder_outlined),
  ];

  _Folder get _currentFolder => _allFolders.firstWhere(
    (f) => f.id == _folder,
    orElse: () => _folders.first,
  );
  String _category = 'all';
  final TextEditingController _search = TextEditingController();
  String _submittedSearch = '';
  Timer? _debounce;

  /// The cursor this page was asked with (null is the first page), the ones
  /// before it for ‹, and the server's for Older ›.
  String? _cursor;
  final List<String?> _previous = [];
  String? _next;

  int _listRequest = 0;
  bool _loadingList = true;
  String? _listError;
  List<InboxRow> _rows = const [];
  List<({String mailboxId, String message})> _errors = const [];
  Map<String, int> _categoryCounts = const {};
  int? _inboxUnread;
  int? _snoozedCount;
  Set<String> _checked = {};

  /// The sentences from the last action's ok:false results.
  String? _actionNote;

  InboxRow? _openRow;
  String? _fromId;
  MessageDetail? _open;
  bool _loadingMessage = false;
  String? _messageError;
  int _messageRequest = 0;
  Dealt? _dealt;

  ReadingPane _pane = ReadingPane.right;

  /// The rail laid over the list, below [_railBeside].
  bool _drawer = false;

  late final AppLifecycleListener _resume;

  String get _paneKey => 'email_reading_pane_${widget.session.me?.id ?? ''}';

  @override
  void initState() {
    super.initState();
    _pane = switch (store.readItem(_paneKey)) {
      'below' => ReadingPane.below,
      'off' => ReadingPane.off,
      _ => ReadingPane.right,
    };
    DeepLinks.pending.addListener(_followLink);
    // Connecting an inbox happens in the browser; coming back must show it.
    _resume = AppLifecycleListener(onResume: _loadMailboxes);
    _loadMailboxes();
  }

  @override
  void dispose() {
    DeepLinks.pending.removeListener(_followLink);
    _resume.dispose();
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  void _setPane(ReadingPane pane) {
    setState(() => _pane = pane);
    store.writeItem(_paneKey, pane.name, persist: true);
  }

  // ── loading ─────────────────────────────────────────────────────────

  Future<void> _loadMailboxes() async {
    setState(() {
      _loadingMailboxes = _mailboxes.isEmpty;
      _mailboxesError = null;
    });

    final response = await widget.api.get('/email/mailboxes');

    if (!mounted) return;

    setState(() {
      _loadingMailboxes = false;

      if (!response.ok) {
        _mailboxesError = response.message;
        return;
      }

      final meta = response.body['meta'];
      _emptyBecause = meta is Map && meta['empty_because'] is String
          ? meta['empty_because'] as String
          : null;

      final data = response.data;

      // Only inboxes this person may OPEN (Jose, 2026-10-13: Eve saw the
      // ones she may only manage).
      _mailboxes = [
        for (final entry in (data is List ? data : const []))
          if (entry is Map<String, dynamic>) Mailbox.fromJson(entry),
      ].where((mailbox) => mailbox.mayRead).toList();
    });

    if (_mailboxes.isNotEmpty) _loadList();
    _loadFilterFolders();

    // A notification tapped before the inboxes were known waited for them.
    _followLink();
  }

  Future<void> _loadList({String? cursor}) async {
    if (_mailboxes.isEmpty) return;

    final request = ++_listRequest;

    setState(() {
      _loadingList = _rows.isEmpty || cursor != _cursor;
      _listError = null;
    });

    final filterId = _folder.startsWith('filter:')
        ? _folder.substring(7)
        : null;
    final query = <String, String>{
      if (filterId == null) 'folder': _folder,
      'filter': ?filterId,
      if (_folder == 'inbox' && _category != 'all') 'category': _category,
      'mailbox': ?_mailboxFilter,
      if (_submittedSearch.isNotEmpty) 'search': _submittedSearch,
      'cursor': ?cursor,
    };

    final response = await widget.api.get('/email/inbox', query);

    if (!mounted || request != _listRequest) return;

    setState(() {
      _loadingList = false;

      // The server's sentence where the rows would have been, never an empty
      // list: "no mail" is the sentence nobody investigates.
      if (!response.ok) {
        _listError = response.message;
        return;
      }

      final page = InboxPage.fromResponse(response.data, response.body['meta']);
      _rows = page.rows;
      _cursor = cursor;
      _next = page.cursor;
      _errors = page.errors;
      _categoryCounts = page.categories;
      if (_folder == 'inbox' && _submittedSearch.isEmpty) {
        _inboxUnread = page.unreadLoaded;
      }
      _snoozedCount = page.snoozed ?? _snoozedCount;
      _checked = {..._checked.where((k) => _rows.any((r) => r.key == k))};
    });
  }

  /// A new list: anything about the old one (its pages, ticks, the open
  /// conversation) belonged to a different question.
  void _requery(VoidCallback change) {
    setState(() {
      change();
      _cursor = null;
      _previous.clear();
      _next = null;
      _rows = const [];
      _checked = {};
      _actionNote = null;
      _closeReader();
    });
    _loadList();
  }

  void _closeReader() {
    _openRow = null;
    _fromId = null;
    _open = null;
    _messageError = null;
    _loadingMessage = false;
  }

  void _selectFolder(String folder) => _requery(() {
    _folder = folder;
    _drawer = false;
  });

  void _selectMailbox(String? id) => _requery(() {
    _mailboxFilter = id;
    _drawer = false;
  });

  void _selectCategory(String category) {
    if (category == _category) return;
    _requery(() => _category = category);
  }

  void _searchChanged(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), () {
      if (mounted) _runSearch(value);
    });
    setState(() {});
  }

  void _runSearch(String value) {
    _debounce?.cancel();
    final next = value.trim();
    if (next == _submittedSearch) return;
    _requery(() => _submittedSearch = next);
  }

  void _older() {
    final next = _next;
    if (next == null) return;
    _previous.add(_cursor);
    _loadList(cursor: next);
  }

  void _newer() {
    if (_previous.isEmpty) return;
    _loadList(cursor: _previous.removeLast());
  }

  /// Opens the exact email a notification is about (Bar via Jose,
  /// 2026-10-12). The link names the inbox by address; one this person
  /// cannot open is not in the list, so the link is dropped.
  void _followLink() {
    if (_loadingMailboxes || DeepLinks.pending.value?.module != 'email') return;

    final link = DeepLinks.take('email');
    final address = link?.mailbox?.toLowerCase();

    if (link == null || link.type != 'email_message' || address == null) return;

    final mailbox = _mailboxes
        .where((m) => m.address.toLowerCase() == address)
        .firstOrNull;

    if (mailbox == null) return;

    _openConversation(
      InboxRow(
        key: 'link:${mailbox.id}:${link.id}',
        subject: '',
        snippet: '',
        fromName: '',
        fromAddress: '',
        receivedAt: null,
        unread: true,
        starred: false,
        hasAttachments: false,
        messageCount: 1,
        category: 'primary',
        inboxes: [
          InboxCopy(
            mailboxId: mailbox.id,
            address: mailbox.address,
            messageId: link.id,
            threadId: '',
          ),
        ],
      ),
    );
  }

  Mailbox? _mailboxById(String? id) {
    for (final mailbox in _mailboxes) {
      if (mailbox.id == id) return mailbox;
    }
    return null;
  }

  /// Open one conversation, from [fromId]'s copy (or the inbox being viewed,
  /// or the first it reached). Opening is also what records the read.
  Future<void> _openConversation(InboxRow row, {String? fromId}) async {
    final copies = row.inboxes;
    if (copies.isEmpty) return;

    final copy = copies.firstWhere(
      (c) => c.mailboxId == (fromId ?? _mailboxFilter),
      orElse: () => copies.first,
    );

    if (_mailboxById(copy.mailboxId) == null) return;

    final request = ++_messageRequest;

    setState(() {
      _openRow = row;
      _fromId = copy.mailboxId;
      _open = null;
      _loadingMessage = true;
      _messageError = null;
      _dealt = copy.dealt;
    });

    final response = await widget.api.get(
      '/email/mailboxes/${copy.mailboxId}/messages/${copy.messageId}',
      copy.threadId.isEmpty ? null : {'thread': copy.threadId},
    );

    if (!mounted || request != _messageRequest) return;

    setState(() {
      _loadingMessage = false;

      if (!response.ok) {
        _messageError = response.message;
        return;
      }

      final data = response.data;

      if (data is Map<String, dynamic>) {
        _open = MessageDetail.fromJson(data);
        // The message's own word on handled wins over the row's.
        if (data.containsKey('dealt')) _dealt = _open!.dealt;
        _rows = [
          for (final r in _rows)
            r.key == row.key ? r.copyWith(unread: false) : r,
        ];
      } else {
        _messageError =
            'That message came back in a shape this app could '
            'not read. Try opening it again.';
      }
    });
  }

  // ── actions ─────────────────────────────────────────────────────────

  /// Send [action] for [rows], one item per inbox each row reached, at most
  /// 100 to a request. Returns whether every item went through. [apply]
  /// false leaves the list alone (a swipe removes its own row).
  Future<bool> _act(
    String action,
    List<InboxRow> rows, {
    DateTime? until,
    bool apply = true,
  }) async {
    final items = [for (final row in rows) ...row.items];
    if (items.isEmpty) return false;

    final ok = <String>{};
    final sentences = <String>[];

    for (var i = 0; i < items.length; i += 100) {
      final chunk = items.sublist(
        i,
        i + 100 > items.length ? items.length : i + 100,
      );
      final response = await widget.api.post('/email/inbox/actions', {
        'action': action,
        'items': chunk,
        if (until != null) 'until': until.toUtc().toIso8601String(),
        if (action == 'move') 'folder': 'inbox',
      });

      if (!mounted) return false;

      if (!response.ok) {
        sentences.add(response.message);
        break;
      }

      final data = response.data;
      final results = data is Map && data['results'] is List
          ? data['results'] as List
          : const [];

      for (final result in results) {
        if (result is! Map) continue;
        final key = '${result['mailbox_id']}|${result['message_id']}';
        if (result['ok'] == true) {
          ok.add(key);
        } else if (result['message'] is String) {
          sentences.add(result['message'] as String);
        }
      }
    }

    bool went(InboxRow row) =>
        row.inboxes.any((c) => ok.contains('${c.mailboxId}|${c.messageId}'));

    setState(() {
      _actionNote = sentences.isEmpty ? null : sentences.toSet().join(' ');

      if (!apply) return;

      final done = {
        for (final row in rows)
          if (went(row)) row.key,
      };
      final removes = switch (action) {
        'archive' => _folder == 'inbox',
        'trash' => true,
        'snooze' => _folder != 'snoozed',
        'unsnooze' => _folder == 'snoozed',
        'unstar' => _folder == 'starred',
        _ => false,
      };

      _rows = [
        for (final row in _rows)
          if (!done.contains(row.key))
            row
          else if (!removes)
            (switch (action) {
              'star' => row.copyWith(starred: true),
              'unstar' => row.copyWith(starred: false),
              'read' => row.copyWith(unread: false),
              'unread' => row.copyWith(unread: true),
              _ => row,
            }),
      ];

      if (removes && done.contains(_openRow?.key)) _closeReader();
      _checked = {..._checked.difference(done)};
    });

    return sentences.isEmpty &&
        items.every(
          (item) => ok.contains('${item['mailbox_id']}|${item['message_id']}'),
        );
  }

  List<InboxRow> get _checkedRows => [
    for (final row in _rows)
      if (_checked.contains(row.key)) row,
  ];

  Future<void> _snooze(List<InboxRow> rows, DateTime until) =>
      _act('snooze', rows, until: until);

  Future<void> _pickSnooze(List<InboxRow> rows) async {
    final until = await showSidePanel<DateTime>(
      context,
      builder: (_) => const _SnoozeDatePanel(),
    );
    if (until == null || !mounted) return;
    await _snooze(rows, until);
  }

  // ── replying and writing ────────────────────────────────────────────

  String? _replyRefusal(Mailbox mailbox, MessageDetail message) {
    if (!mailbox.canReply) {
      return mailbox.replyRefusal ??
          'You can read ${mailbox.address} but not answer from it. Whoever '
              'looks after this inbox can give your role permission to reply.';
    }

    if (message.replyAs == null || message.replyAs!.isEmpty) {
      return 'This message came back without the address a reply would go out '
          'as, so there is nothing to answer as yet. Try opening it again.';
    }

    return null;
  }

  Future<void> _reply(
    Mailbox mailbox,
    MessageDetail message, {
    bool all = false,
  }) async {
    final sent = await showSidePanel<String>(
      context,
      builder: (_) => ReplyPanel(
        api: widget.api,
        mailboxId: mailbox.id,
        message: message,
        replyAs: message.replyAs!,
        all: all,
      ),
    );

    if (sent == null || !mounted) return;

    // The address it went out as, not a congratulation.
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text('Sent as $sent.')));

    _loadList(cursor: _cursor);
  }

  Future<void> _forward(Mailbox mailbox, MessageDetail message) async {
    final said = await showSidePanel<String>(
      context,
      builder: (_) => ForwardPanel(
        api: widget.api,
        mailboxId: mailbox.id,
        message: message,
        sendAs: mailbox.address,
      ),
    );

    if (said == null || !mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(said)));
  }

  Future<void> _loadFilterFolders() async {
    final response = await widget.api.get('/email/filters');
    if (!mounted || !response.ok) return;
    final data = response.data;
    final rows = data is Map ? data['filters'] : null;
    setState(() {
      _filterFolders = [
        for (final row in (rows is List ? rows : const []))
          if (row is Map<String, dynamic>) EmailFilter.fromJson(row),
      ].where((f) => f.action == 'folder').toList();
      if (_folder.startsWith('filter:') &&
          !_filterFolders.any((f) => 'filter:${f.id}' == _folder)) {
        _folder = 'inbox';
      }
    });
  }

  /// Filters (`email/filters`). [from] opens the add form with that sender.
  Future<void> _openFilters({String? from}) async {
    final me = widget.session.me;
    final changed = await showSidePanel<bool>(
      context,
      builder: (_) => EmailFiltersPanel(
        api: widget.api,
        organizationName: me?.organization.name ?? 'the organization',
        companies: me?.companies ?? const [],
        branches: me?.franchises ?? const [],
        prefillFrom: from,
      ),
    );
    if (changed == true && mounted) {
      _loadFilterFolders();
      _loadList(cursor: _cursor);
    }
  }

  /// "—" while the server has not counted an inbox yet, never 0; nothing
  /// when it is known to be 0.
  static String? _unreadBadge(int? unread) =>
      unread == null ? '—' : (unread > 0 ? '$unread' : null);

  Future<void> _compose(Mailbox mailbox) async {
    final sent = await showSidePanel<String>(
      context,
      builder: (_) => ComposePanel(
        api: widget.api,
        mailboxId: mailbox.id,
        sendAs: mailbox.address,
      ),
    );

    if (sent == null || !mounted) return;

    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text('Sent as $sent.')));

    _loadList(cursor: _cursor);
  }

  List<Mailbox> get _sendable => [
    for (final m in _mailboxes)
      if (m.canReply && m.connected) m,
  ];

  void _openCustomer(InboxSender who) {
    if (who.type == 'lead') {
      OpenDestination.leadOf(context)?.call(who.id);
      return;
    }

    showSidePanel<void>(
      context,
      builder: (_) => CustomerDetailPanel(
        api: widget.api,
        session: widget.session,
        id: who.id,
      ),
    );
  }

  // ── the rail ────────────────────────────────────────────────────────

  List<_Group> get _groups {
    final me = widget.session.me;
    final companies = {
      for (final c in me?.companies ?? const <CompanySummary>[]) c.id: c.name,
    };
    final ofFranchise = {
      for (final f in me?.franchises ?? const <FranchiseSummary>[])
        f.id: f.companyId,
    };
    final orgName = me?.organization.name ?? 'Organization';

    final byId = <String, _Group>{};
    final notConnected = _Group('not-connected', 'Not connected');

    for (final mailbox in _mailboxes) {
      if (!mailbox.connected) {
        notConnected.inboxes.add(mailbox);
        continue;
      }

      final companyId = switch (mailbox.scopeType) {
        'company' => mailbox.scopeId,
        'franchise' => ofFranchise[mailbox.scopeId],
        _ => null,
      };
      final name = companyId == null ? orgName : companies[companyId];
      final id = companyId == null || name == null ? 'org' : companyId;

      byId
          .putIfAbsent(id, () => _Group(id, name ?? orgName))
          .inboxes
          .add(mailbox);
    }

    final groups = byId.values.toList()
      ..sort((a, b) => a.name.toLowerCase().compareTo(b.name.toLowerCase()));

    return [...groups, if (notConnected.inboxes.isNotEmpty) notConnected];
  }

  Widget _rail(BuildContext context) {
    final theme = Theme.of(context);
    final groups = _groups;

    // Folded closed, as Gmail's labels are — except a lone company, which
    // has nothing to fold away from, and the one holding the chosen inbox.
    if (!_groupsSeeded) {
      _groupsSeeded = true;
      if (groups.length == 1) _openGroups.add(groups.single.id);
    }

    return Material(
      key: const ValueKey('tour-email-inboxes'),
      color: theme.colorScheme.surface,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(8, 12, 8, 24),
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(4, 0, 4, 12),
            child: Align(
              alignment: Alignment.centerLeft,
              child: _composeControl(
                (onPressed) => FilledButton.tonalIcon(
                  key: const ValueKey('tour-email-compose'),
                  onPressed: onPressed,
                  icon: const Icon(Icons.edit_outlined, size: 18),
                  label: const Text('Compose'),
                ),
              ),
            ),
          ),
          for (final folder in _allFolders)
            _railTile(
              key: ValueKey('email-folder-${folder.id}'),
              icon: folder.icon,
              label: folder.label,
              count: switch (folder.id) {
                'inbox' => _inboxUnread,
                'snoozed' => _snoozedCount,
                _ => null,
              },
              selected: _folder == folder.id,
              onTap: () => _selectFolder(folder.id),
            ),
          const SizedBox(height: 12),
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 4, 12, 4),
            child: Text('Inboxes', style: theme.textTheme.labelMedium),
          ),
          if (_mailboxes.length > 1)
            _railTile(
              key: const ValueKey('email-inbox-all'),
              icon: Icons.all_inbox,
              label: 'All inboxes',
              selected: _mailboxFilter == null,
              onTap: () => _selectMailbox(null),
            ),
          for (final group in groups) ...[
            _railTile(
              key: ValueKey('email-group-${group.id}'),
              icon: _openGroups.contains(group.id)
                  ? Icons.expand_more
                  : Icons.chevron_right,
              label: group.id == 'not-connected'
                  ? 'Not connected · ${group.inboxes.length}'
                  : group.name,
              badge: group.id == 'not-connected'
                  ? null
                  : _unreadBadge(group.unread),
              bold: true,
              onTap: () => setState(() {
                if (!_openGroups.remove(group.id)) _openGroups.add(group.id);
              }),
            ),
            if (_openGroups.contains(group.id))
              for (final mailbox in group.inboxes)
                _railTile(
                  key: ValueKey('email-inbox-${mailbox.id}'),
                  icon: mailbox.connected
                      ? Icons.inbox_outlined
                      : Icons.link_off,
                  label: mailbox.address,
                  badge: _unreadBadge(mailbox.unread),
                  indent: 24,
                  selected: _mailboxFilter == mailbox.id,
                  onTap: () => _selectMailbox(mailbox.id),
                ),
          ],
        ],
      ),
    );
  }

  Widget _railTile({
    required Key key,
    required IconData icon,
    required String label,
    required VoidCallback onTap,
    int? count,
    String? badge,
    bool selected = false,
    bool bold = false,
    double indent = 0,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final style = theme.textTheme.bodyMedium?.copyWith(
      fontWeight:
          selected ||
              bold ||
              (count ?? 0) > 0 ||
              (badge != null && badge != '—')
          ? FontWeight.w600
          : null,
      color: selected ? scheme.onSecondaryContainer : null,
    );

    return Padding(
      padding: EdgeInsets.only(left: indent, bottom: 2),
      child: Material(
        color: selected ? scheme.secondaryContainer : scheme.surface,
        shape: const StadiumBorder(),
        child: InkWell(
          key: key,
          customBorder: const StadiumBorder(),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            child: Row(
              children: [
                Icon(icon, size: 18, color: style?.color),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    label,
                    style: style,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                if (badge != null)
                  Text(badge, style: style)
                else if (count != null && count > 0)
                  Text('$count', style: style),
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// Compose from the inbox being viewed, or — on All inboxes, which has no
  /// sending identity — a menu of the inboxes this person may send from.
  /// Nothing is drawn when they may send from none.
  Widget _composeControl(Widget Function(VoidCallback? onPressed) button) {
    final selected = _mailboxById(_mailboxFilter);

    if (selected != null) {
      if (!selected.canReply || !selected.connected) {
        return const SizedBox.shrink();
      }
      return button(() => _compose(selected));
    }

    final sendable = _sendable;
    if (sendable.isEmpty) return const SizedBox.shrink();
    if (sendable.length == 1) return button(() => _compose(sendable.single));

    return MenuAnchor(
      menuChildren: [
        for (final mailbox in sendable)
          MenuItemButton(
            onPressed: () => _compose(mailbox),
            child: Text('From ${mailbox.address}'),
          ),
      ],
      builder: (context, controller, _) => button(
        () => controller.isOpen ? controller.close() : controller.open(),
      ),
    );
  }

  // ── building ────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        if (_loadingMailboxes || _mailboxesError != null) {
          return AsyncBody(
            loading: _loadingMailboxes,
            error: _mailboxesError,
            onRetry: _loadMailboxes,
            child: const SizedBox.shrink(),
          );
        }

        if (_mailboxes.isEmpty) return _noInboxes(context);

        final width = constraints.maxWidth;

        if (width < _phoneBelow) return _phone(context, constraints);

        final beside = width >= _railBeside;
        final content = _desktopContent(
          context,
          width - (beside ? _railWidth + 1 : 0),
          withMenu: !beside,
        );

        return Stack(
          children: [
            Positioned.fill(
              child: beside
                  ? Row(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        SizedBox(width: _railWidth, child: _rail(context)),
                        const VerticalDivider(width: 1, thickness: 1),
                        Expanded(child: content),
                      ],
                    )
                  : content,
            ),
            if (!beside && _drawer) ..._drawerLayer(context, constraints),
          ],
        );
      },
    );
  }

  /// The rail laid over the page, in place — not a route, not a dialog.
  List<Widget> _drawerLayer(BuildContext context, BoxConstraints constraints) {
    final scheme = Theme.of(context).colorScheme;
    final width = constraints.maxWidth * 0.85 < 300
        ? constraints.maxWidth * 0.85
        : 300.0;

    return [
      Positioned.fill(
        child: GestureDetector(
          key: const ValueKey('email-drawer-scrim'),
          onTap: () => setState(() => _drawer = false),
          child: ColoredBox(color: scheme.scrim.withValues(alpha: 0.32)),
        ),
      ),
      Positioned(
        left: 0,
        top: 0,
        bottom: 0,
        width: width,
        child: Material(
          key: const ValueKey('email-drawer'),
          elevation: 8,
          child: _rail(context),
        ),
      ),
    ];
  }

  Widget _searchBar(BuildContext context, {Widget? leading, Widget? trailing}) {
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 6),
      child: Row(
        children: [
          ?leading,
          Expanded(
            child: TextField(
              key: const ValueKey('tour-email-search'),
              controller: _search,
              textInputAction: TextInputAction.search,
              onSubmitted: _runSearch,
              onChanged: _searchChanged,
              decoration: InputDecoration(
                hintText: 'Search mail',
                prefixIcon: const Icon(Icons.search, size: 20),
                suffixIcon:
                    _search.text.isNotEmpty || _submittedSearch.isNotEmpty
                    ? IconButton(
                        tooltip: 'Clear search',
                        icon: const Icon(Icons.close, size: 18),
                        onPressed: () {
                          _search.clear();
                          _runSearch('');
                        },
                      )
                    : null,
                isDense: true,
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(28),
                  borderSide: BorderSide.none,
                ),
                filled: true,
                fillColor: scheme.surfaceContainerHigh,
              ),
            ),
          ),
          ?trailing,
        ],
      ),
    );
  }

  Widget _paneMenu() => MenuAnchor(
    menuChildren: [
      for (final (pane, label) in const [
        (ReadingPane.right, 'Reading pane on the right'),
        (ReadingPane.below, 'Reading pane below'),
        (ReadingPane.off, 'No reading pane'),
      ])
        MenuItemButton(
          key: ValueKey('email-pane-${pane.name}'),
          leadingIcon: Icon(
            _pane == pane
                ? Icons.radio_button_checked
                : Icons.radio_button_unchecked,
            size: 18,
          ),
          onPressed: () => _setPane(pane),
          child: Text(label),
        ),
    ],
    builder: (context, controller, _) => IconButton(
      key: const ValueKey('email-pane-setting'),
      tooltip: 'Reading pane',
      icon: const Icon(Icons.view_sidebar_outlined),
      onPressed: () =>
          controller.isOpen ? controller.close() : controller.open(),
    ),
  );

  /// Shown only while that inbox is the one selected — on All inboxes the
  /// "Not connected" group says it, and a notice there was the bug.
  Widget? _notConnectedNotice(BuildContext context) {
    final selected = _mailboxById(_mailboxFilter);
    if (selected == null || selected.connected) return null;
    final theme = Theme.of(context);

    return Container(
      key: const ValueKey('email-not-connected'),
      margin: const EdgeInsets.fromLTRB(12, 4, 12, 4),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: theme.colorScheme.errorContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          Icon(
            Icons.link_off,
            size: 18,
            color: theme.colorScheme.onErrorContainer,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              selected.notConnectedSentence,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onErrorContainer,
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// meta.errors and the last action's refusals, as one quiet line. An
  /// inbox that is not connected is left out: its group (or its own notice,
  /// when it is the one selected) already says so.
  Widget? _quietLine(BuildContext context) {
    final notConnected = {
      for (final m in _mailboxes)
        if (!m.connected) m.id,
    };
    final sentences = <String>{
      for (final e in _errors)
        if (!notConnected.contains(e.mailboxId)) e.message,
      ?_actionNote,
    };
    if (sentences.isEmpty) return null;
    final theme = Theme.of(context);

    return Padding(
      key: const ValueKey('email-quiet-errors'),
      padding: const EdgeInsets.fromLTRB(16, 2, 16, 6),
      child: Text(
        sentences.join(' '),
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }

  Widget _categoryTabs(BuildContext context, {bool compact = false}) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return SingleChildScrollView(
      key: const ValueKey('tour-email-categories'),
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 8),
      child: Row(
        children: [
          for (final (id, label, icon) in _categories)
            InkWell(
              key: ValueKey('email-category-$id'),
              onTap: () => _selectCategory(id),
              child: Container(
                padding: EdgeInsets.symmetric(
                  horizontal: compact ? 12 : 18,
                  vertical: 10,
                ),
                decoration: BoxDecoration(
                  border: Border(
                    bottom: BorderSide(
                      width: 3,
                      color: _category == id ? scheme.primary : scheme.surface,
                    ),
                  ),
                ),
                child: Row(
                  children: [
                    Icon(
                      icon,
                      size: 18,
                      color: _category == id
                          ? scheme.primary
                          : scheme.onSurfaceVariant,
                    ),
                    const SizedBox(width: 8),
                    Text(
                      label,
                      style: theme.textTheme.labelLarge?.copyWith(
                        color: _category == id ? scheme.primary : null,
                      ),
                    ),
                    if ((_categoryCounts[id] ?? 0) > 0) ...[
                      const SizedBox(width: 6),
                      Text(
                        '${_categoryCounts[id]}',
                        key: ValueKey('email-category-count-$id'),
                        style: theme.textTheme.labelSmall,
                      ),
                    ],
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _toolbar(BuildContext context) {
    final rows = _rows;
    final all = rows.isNotEmpty && _checked.length == rows.length;
    final none = _checked.isEmpty;
    final checked = _checkedRows;
    final anyUnread = checked.any((r) => r.unread);

    return Padding(
      key: const ValueKey('tour-email-toolbar'),
      padding: const EdgeInsets.symmetric(horizontal: 8),
      child: Row(
        children: [
          Checkbox(
            key: const ValueKey('email-select-all'),
            tristate: true,
            value: all ? true : (none ? false : null),
            onChanged: rows.isEmpty
                ? null
                : (_) => setState(() {
                    _checked = all ? {} : {for (final r in rows) r.key};
                  }),
          ),
          if (none)
            IconButton(
              tooltip: 'Refresh',
              icon: const Icon(Icons.refresh),
              onPressed: () => _loadList(cursor: _cursor),
            ),
          if (none)
            IconButton(
              key: const ValueKey('email-filters'),
              tooltip: 'Filters',
              icon: const Icon(Icons.filter_alt_outlined),
              onPressed: _openFilters,
            )
          else ...[
            IconButton(
              tooltip: 'Archive',
              icon: const Icon(Icons.archive_outlined),
              onPressed: () => _act('archive', checked),
            ),
            IconButton(
              tooltip: anyUnread ? 'Mark as read' : 'Mark as unread',
              icon: Icon(
                anyUnread
                    ? Icons.mark_email_read_outlined
                    : Icons.mark_email_unread_outlined,
              ),
              onPressed: () => _act(anyUnread ? 'read' : 'unread', checked),
            ),
            _snoozeMenu(
              () => checked,
              (open) => IconButton(
                tooltip: 'Snooze',
                icon: const Icon(Icons.schedule),
                onPressed: open,
              ),
            ),
            if (_folder != 'inbox')
              IconButton(
                tooltip: 'Move to Inbox',
                icon: const Icon(Icons.move_to_inbox_outlined),
                onPressed: () =>
                    _act(_folder == 'snoozed' ? 'unsnooze' : 'move', checked),
              ),
            IconButton(
              tooltip: 'Delete',
              icon: const Icon(Icons.delete_outline),
              onPressed: () => _act('trash', checked),
            ),
          ],
          const Spacer(),
          if (_previous.isNotEmpty)
            IconButton(
              key: const ValueKey('email-newer'),
              tooltip: 'Newer',
              icon: const Icon(Icons.chevron_left),
              onPressed: _newer,
            ),
          if (_next != null)
            TextButton(
              key: const ValueKey('email-older'),
              onPressed: _older,
              child: const Text('Older ›'),
            ),
        ],
      ),
    );
  }

  Widget _snoozeMenu(
    List<InboxRow> Function() rows,
    Widget Function(VoidCallback open) builder,
  ) {
    return MenuAnchor(
      menuChildren: [
        MenuItemButton(
          key: const ValueKey('email-snooze-later'),
          onPressed: () => _snooze(rows(), snoozeLaterToday(DateTime.now())),
          child: const Text('Later today'),
        ),
        MenuItemButton(
          key: const ValueKey('email-snooze-tomorrow'),
          onPressed: () => _snooze(rows(), snoozeTomorrow(DateTime.now())),
          child: const Text('Tomorrow'),
        ),
        MenuItemButton(
          key: const ValueKey('email-snooze-week'),
          onPressed: () => _snooze(rows(), snoozeNextWeek(DateTime.now())),
          child: const Text('Next week'),
        ),
        MenuItemButton(
          key: const ValueKey('email-snooze-pick'),
          onPressed: () => _pickSnooze(rows()),
          child: const Text('Pick a date…'),
        ),
      ],
      builder: (context, controller, _) => builder(
        () => controller.isOpen ? controller.close() : controller.open(),
      ),
    );
  }

  Widget _desktopContent(
    BuildContext context,
    double width, {
    required bool withMenu,
  }) {
    final notice = _notConnectedNotice(context);
    final quiet = _quietLine(context);
    final open = _openRow != null;
    // Side by side that does not fit opens the mail over the whole pane, with
    // back — not a half-height reader below (Jose 11-03).
    final pane = _pane == ReadingPane.right && width < _rightPaneNeeds
        ? ReadingPane.off
        : _pane;

    final Widget body = switch (pane) {
      ReadingPane.off => open ? _reader(context, back: true) : _list(context),
      ReadingPane.right => Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(child: _list(context)),
          const VerticalDivider(width: 1, thickness: 1),
          Expanded(child: _reader(context, back: false)),
        ],
      ),
      ReadingPane.below =>
        open
            ? Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Expanded(child: _list(context)),
                  const Divider(height: 1),
                  Expanded(child: _reader(context, back: false)),
                ],
              )
            : _list(context),
    };

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _searchBar(
          context,
          leading: withMenu
              ? IconButton(
                  tooltip: 'Folders and inboxes',
                  icon: const Icon(Icons.menu),
                  onPressed: () => setState(() => _drawer = true),
                )
              : null,
          trailing: _paneMenu(),
        ),
        ?notice,
        if (_folder == 'inbox') _categoryTabs(context),
        _toolbar(context),
        ?quiet,
        const Divider(height: 1),
        Expanded(child: body),
      ],
    );
  }

  bool get _showTags => _mailboxFilter == null && _mailboxes.length > 1;

  Widget _list(BuildContext context, {bool phone = false}) {
    final theme = Theme.of(context);
    final formats = widget.session.me!.display;
    final folder = _currentFolder;

    return KeyedSubtree(
      key: const ValueKey('tour-email-list'),
      child: AsyncBody(
        loading: _loadingList,
        error: _listError,
        onRetry: () => _loadList(cursor: _cursor),
        child: _rows.isEmpty
            ? Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Text(
                    _submittedSearch.isNotEmpty
                        ? 'Nothing matches "$_submittedSearch".'
                        : 'Nothing in ${folder.label} right now.',
                    style: theme.textTheme.bodyMedium,
                  ),
                ),
              )
            : ListView.builder(
                padding: EdgeInsets.only(bottom: phone ? 88 : 8),
                itemCount: _rows.length + (phone && _next != null ? 1 : 0),
                itemBuilder: (context, index) {
                  if (index == _rows.length) {
                    return Center(
                      child: TextButton(
                        key: const ValueKey('email-older'),
                        onPressed: _older,
                        child: const Text('Older ›'),
                      ),
                    );
                  }
                  final row = _rows[index];
                  final when = row.snoozedUntil != null
                      ? 'Until ${inboxStamp(row.snoozedUntil, formats)}'
                      : inboxStamp(row.receivedAt, formats);

                  return phone
                      ? _PhoneLine(
                          key: ValueKey('email-row-${row.key}'),
                          row: row,
                          when: when,
                          checked: _checked.contains(row.key),
                          onTap: () => _openConversation(row),
                          onLongPress: () => _toggle(row),
                          onStar: () =>
                              _act(row.starred ? 'unstar' : 'star', [row]),
                          onSwipe: () => _act('archive', [row], apply: false),
                          onSwiped: () => setState(() {
                            _rows = [
                              for (final r in _rows)
                                if (r.key != row.key) r,
                            ];
                            if (_openRow?.key == row.key) _closeReader();
                          }),
                        )
                      : _InboxLine(
                          key: ValueKey('email-row-${row.key}'),
                          row: row,
                          when: when,
                          tags: _showTags
                              ? {
                                  for (final c in row.inboxes) c.short,
                                }.join(' · ')
                              : null,
                          checked: _checked.contains(row.key),
                          selected: _openRow?.key == row.key,
                          onTap: () => _openConversation(row),
                          onTick: () => _toggle(row),
                          onStar: () =>
                              _act(row.starred ? 'unstar' : 'star', [row]),
                          onArchive: () => _act('archive', [row]),
                          onToggleRead: () =>
                              _act(row.unread ? 'read' : 'unread', [row]),
                          formats: formats,
                          snoozeMenu: (builder) =>
                              _snoozeMenu(() => [row], builder),
                        );
                },
              ),
      ),
    );
  }

  void _toggle(InboxRow row) => setState(() {
    final next = {..._checked};
    if (!next.remove(row.key)) next.add(row.key);
    _checked = next;
  });

  Widget _reader(BuildContext context, {required bool back}) {
    final theme = Theme.of(context);
    final row = _openRow;

    if (row == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'Pick a message to read it.',
            style: theme.textTheme.bodyMedium,
          ),
        ),
      );
    }

    final mailbox = _mailboxById(_fromId);
    final open = _open;

    // The theme's surface: the text is drawn in theme colours, so fixed white was unreadable in dark mode (Jose, 2026-10-28).
    return ColoredBox(
      key: const ValueKey('email-reader'),
      color: theme.colorScheme.surface,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
            child: Row(
              children: [
                if (back)
                  IconButton(
                    tooltip: 'Back to the messages',
                    icon: const Icon(Icons.arrow_back),
                    onPressed: () => setState(_closeReader),
                  ),
                IconButton(
                  tooltip: 'Archive',
                  icon: const Icon(Icons.archive_outlined),
                  onPressed: () => _act('archive', [row]),
                ),
                IconButton(
                  tooltip: 'Mark as unread',
                  icon: const Icon(Icons.mark_email_unread_outlined),
                  onPressed: () async {
                    await _act('unread', [row]);
                    if (mounted) setState(_closeReader);
                  },
                ),
                _snoozeMenu(
                  () => [row],
                  (open) => IconButton(
                    tooltip: 'Snooze',
                    icon: const Icon(Icons.schedule),
                    onPressed: open,
                  ),
                ),
                IconButton(
                  tooltip: 'Delete',
                  icon: const Icon(Icons.delete_outline),
                  onPressed: () => _act('trash', [row]),
                ),
                if (open != null)
                  IconButton(
                    key: const ValueKey('email-filter-like-this'),
                    tooltip: 'Filter mail like this',
                    icon: const Icon(Icons.filter_alt_outlined),
                    onPressed: () => _openFilters(from: open.fromAddress),
                  ),
                if (!back)
                  IconButton(
                    tooltip: 'Close',
                    icon: const Icon(Icons.close),
                    onPressed: () => setState(_closeReader),
                  ),
              ],
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: AsyncBody(
              loading: _loadingMessage,
              error: _messageError,
              onRetry: () => _openConversation(row, fromId: _fromId),
              child: open == null || mailbox == null
                  ? const SizedBox.shrink()
                  : MailMessageView(
                      mailbox: mailbox,
                      message: open,
                      meId: widget.session.me!.id,
                      formats: widget.session.me!.display,
                      refusal: _replyRefusal(mailbox, open),
                      onReply: () => _reply(mailbox, open),
                      onReplyAll: () => _reply(mailbox, open, all: true),
                      onForward: mailbox.canReply && mailbox.connected
                          ? () => _forward(mailbox, open)
                          : null,
                      dealt: _dealt,
                      onToggleDealt: () => _toggleDealt(mailbox, open.id),
                      api: widget.api,
                      copies: row.inboxes,
                      onFromChanged: (id) => _openConversation(row, fromId: id),
                      customer: row.customer,
                      onOpenCustomer: _customerAction(row.customer),
                      onCompose: mailbox.canReply && mailbox.connected
                          ? () => _compose(mailbox)
                          : null,
                    ),
            ),
          ),
        ],
      ),
    );
  }

  /// Null where the record cannot be opened from here, so no dead chip.
  VoidCallback? _customerAction(InboxSender? who) {
    if (who == null) return null;
    if (who.type == 'lead' && OpenDestination.leadOf(context) == null) {
      return null;
    }
    return () => _openCustomer(who);
  }

  /// Handled starts from the server's `dealt` (the message, else the copy
  /// opened), and the row's tick follows a change made here.
  Future<void> _toggleDealt(Mailbox mailbox, String messageId) async {
    final was = _dealt != null;
    final path = '/email/mailboxes/${mailbox.id}/messages/$messageId/dealt';
    final response = was
        ? await widget.api.delete(path)
        : await widget.api.post(path, const {});

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.reason)));
      return;
    }

    final data = response.data;
    final now = was
        ? null
        : Dealt.fromJson(data is Map ? data['dealt'] : null) ??
              Dealt(
                at: DateTime.now().toUtc().toIso8601String(),
                by: widget.session.me?.name,
              );

    setState(() {
      _dealt = now;
      final key = _openRow?.key;
      _rows = [
        for (final r in _rows)
          r.key != key
              ? r
              : (now == null
                    ? r.copyWith(clearDealt: true)
                    : r.copyWith(dealt: now)),
      ];
      final open = _openRow;
      if (open != null && open.key == key) {
        _openRow = now == null
            ? open.copyWith(clearDealt: true)
            : open.copyWith(dealt: now);
      }
    });
  }

  // ── the phone (option 4) ────────────────────────────────────────────

  Widget _phone(BuildContext context, BoxConstraints constraints) {
    if (_openRow != null) return _reader(context, back: true);

    final folder = _currentFolder;
    final mailbox = _mailboxById(_mailboxFilter);
    final theme = Theme.of(context);
    final notice = _notConnectedNotice(context);
    final quiet = _quietLine(context);

    return Stack(
      children: [
        Positioned.fill(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _searchBar(
                context,
                leading: IconButton(
                  tooltip: 'Folders and inboxes',
                  icon: const Icon(Icons.menu),
                  onPressed: () => setState(() => _drawer = true),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 2, 16, 2),
                child: Text(
                  mailbox == null
                      ? folder.label
                      : '${folder.label} · ${mailbox.address}',
                  style: theme.textTheme.labelMedium,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              ?notice,
              if (_folder == 'inbox') _categoryTabs(context, compact: true),
              // A long press ticks a row; the bulk actions then sit here.
              if (_checked.isNotEmpty) _toolbar(context),
              ?quiet,
              Expanded(child: _list(context, phone: true)),
            ],
          ),
        ),
        Positioned(
          right: 16,
          bottom: 16,
          child: _composeControl(
            (onPressed) => FloatingActionButton.extended(
              key: const ValueKey('email-compose-fab'),
              heroTag: null,
              onPressed: onPressed,
              icon: const Icon(Icons.edit_outlined),
              label: const Text('Compose'),
            ),
          ),
        ),
        if (_drawer) ..._drawerLayer(context, constraints),
      ],
    );
  }

  // ── nothing to show ─────────────────────────────────────────────────

  Widget _noInboxes(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 460),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              Icons.inbox_outlined,
              size: 34,
              color: Theme.of(context).colorScheme.outline,
            ),
            const SizedBox(height: 12),
            Text(
              _emptyTitle,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 6),
            Text(
              _emptyDetail,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ],
        ),
      ),
    ),
  );

  /// Which empty this is, in the server's words (`empty_because`).
  String get _emptyTitle => switch (_emptyBecause) {
    'none_granted' => 'Your role has not been given an inbox yet.',
    'none_connected' => 'No inbox has been connected yet.',
    _ => 'No inbox belongs to this part of the business yet.',
  };

  String get _emptyDetail => switch (_emptyBecause) {
    'none_granted' =>
      'Inboxes have been set up, but none of them has been given to your '
          'role yet. Somebody who manages mailboxes can do that in '
          'Settings — until then there is nothing here to read.',
    'none_connected' =>
      'Nobody has added a shared inbox to this business yet. Settings is '
          'where the first one is added, and each role is then given the '
          'ones it should be able to read.',
    _ =>
      'An inbox added on another company stays there — switch to that '
          'company, or to the organization, to see it. Settings is where '
          'one is added, and each role is then given the ones it should '
          'be able to read.',
  };
}

/// One desktop row: tick, star, sender (+ count), inbox tags, subject and
/// grey snippet, time — or, under the pointer, Archive / Mark read / Snooze.
class _InboxLine extends StatefulWidget {
  const _InboxLine({
    super.key,
    required this.row,
    required this.when,
    required this.tags,
    required this.checked,
    required this.selected,
    required this.onTap,
    required this.onTick,
    required this.onStar,
    required this.onArchive,
    required this.onToggleRead,
    required this.snoozeMenu,
    required this.formats,
  });

  final DisplayFormats formats;
  final InboxRow row;
  final String when;
  final String? tags;
  final bool checked;
  final bool selected;
  final VoidCallback onTap;
  final VoidCallback onTick;
  final VoidCallback onStar;
  final VoidCallback onArchive;
  final VoidCallback onToggleRead;
  final Widget Function(Widget Function(VoidCallback open) builder) snoozeMenu;

  @override
  State<_InboxLine> createState() => _InboxLineState();
}

class _InboxLineState extends State<_InboxLine> {
  bool _hover = false;

  @override
  Widget build(BuildContext context) {
    final row = widget.row;
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final weight = row.unread ? FontWeight.w700 : FontWeight.w400;
    final base = theme.textTheme.bodyMedium?.copyWith(fontWeight: weight);
    final grey = theme.textTheme.bodyMedium?.copyWith(
      color: scheme.onSurfaceVariant,
    );

    final background = widget.selected || widget.checked
        ? scheme.secondaryContainer
        : row.unread
        ? scheme.surface
        : scheme.surfaceContainerLow;

    return MouseRegion(
      onEnter: (_) => setState(() => _hover = true),
      onExit: (_) => setState(() => _hover = false),
      child: Material(
        color: background,
        child: InkWell(
          onTap: widget.onTap,
          child: Container(
            height: 44,
            decoration: BoxDecoration(
              border: Border(bottom: BorderSide(color: scheme.outlineVariant)),
            ),
            padding: const EdgeInsets.only(left: 4, right: 8),
            child: LayoutBuilder(
              builder: (context, constraints) => Row(
                children: [
                  Checkbox(
                    key: ValueKey('email-tick-${row.key}'),
                    value: widget.checked,
                    visualDensity: VisualDensity.compact,
                    materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    onChanged: (_) => widget.onTick(),
                  ),
                  IconButton(
                    key: ValueKey('email-star-${row.key}'),
                    tooltip: row.starred ? 'Unstar' : 'Star',
                    visualDensity: VisualDensity.compact,
                    icon: Icon(
                      row.starred ? Icons.star : Icons.star_border,
                      size: 20,
                      color: row.starred
                          ? scheme.tertiary
                          : scheme.onSurfaceVariant,
                    ),
                    onPressed: widget.onStar,
                  ),
                  SizedBox(
                    // Narrower beside a reading pane, so the subject keeps room.
                    width: constraints.maxWidth < 700 ? 120 : 190,
                    child: Text.rich(
                      TextSpan(
                        children: [
                          TextSpan(text: row.sender, style: base),
                          if (row.messageCount > 1)
                            TextSpan(text: ' ${row.messageCount}', style: grey),
                        ],
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  if (widget.tags case final tags? when tags.isNotEmpty)
                    Container(
                      key: ValueKey('email-tags-${row.key}'),
                      margin: const EdgeInsets.only(right: 8),
                      padding: const EdgeInsets.symmetric(
                        horizontal: 6,
                        vertical: 2,
                      ),
                      decoration: BoxDecoration(
                        color: scheme.surfaceContainerHighest,
                        borderRadius: BorderRadius.circular(4),
                      ),
                      constraints: const BoxConstraints(maxWidth: 120),
                      child: Text(
                        tags,
                        style: theme.textTheme.labelSmall,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  Expanded(
                    child: Text.rich(
                      TextSpan(
                        children: [
                          TextSpan(
                            text: row.subject.isEmpty
                                ? '(no subject)'
                                : row.subject,
                            style: base,
                          ),
                          if (row.snippet.isNotEmpty)
                            TextSpan(text: ' — ${row.snippet}', style: grey),
                        ],
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  if (row.dealt != null)
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 4),
                      child: Tooltip(
                        message: handledSentence(row.dealt!, widget.formats),
                        child: Icon(
                          Icons.check_circle,
                          key: ValueKey('email-handled-${row.key}'),
                          size: 16,
                          color: scheme.primary,
                        ),
                      ),
                    ),
                  if (row.hasAttachments)
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 6),
                      child: Icon(
                        Icons.attach_file,
                        size: 16,
                        color: scheme.onSurfaceVariant,
                      ),
                    ),
                  SizedBox(
                    width: _hover ? 120 : (row.snoozedUntil != null ? 96 : 64),
                    child: _hover
                        ? Row(
                            mainAxisAlignment: MainAxisAlignment.end,
                            children: [
                              IconButton(
                                key: ValueKey('email-quick-archive-${row.key}'),
                                tooltip: 'Archive',
                                visualDensity: VisualDensity.compact,
                                icon: const Icon(
                                  Icons.archive_outlined,
                                  size: 20,
                                ),
                                onPressed: widget.onArchive,
                              ),
                              IconButton(
                                key: ValueKey('email-quick-read-${row.key}'),
                                tooltip: row.unread
                                    ? 'Mark as read'
                                    : 'Mark as unread',
                                visualDensity: VisualDensity.compact,
                                icon: Icon(
                                  row.unread
                                      ? Icons.mark_email_read_outlined
                                      : Icons.mark_email_unread_outlined,
                                  size: 20,
                                ),
                                onPressed: widget.onToggleRead,
                              ),
                              widget.snoozeMenu(
                                (open) => IconButton(
                                  key: ValueKey(
                                    'email-quick-snooze-${row.key}',
                                  ),
                                  tooltip: 'Snooze',
                                  visualDensity: VisualDensity.compact,
                                  icon: const Icon(Icons.schedule, size: 20),
                                  onPressed: open,
                                ),
                              ),
                            ],
                          )
                        : Text(
                            widget.when,
                            textAlign: TextAlign.right,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: base?.copyWith(fontSize: 12),
                          ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// One phone row, the Gmail app's: avatar, sender, time, subject, snippet;
/// swipe either way to archive.
class _PhoneLine extends StatelessWidget {
  const _PhoneLine({
    super.key,
    required this.row,
    required this.when,
    required this.checked,
    required this.onTap,
    required this.onLongPress,
    required this.onStar,
    required this.onSwipe,
    required this.onSwiped,
  });

  final InboxRow row;
  final String when;
  final bool checked;
  final VoidCallback onTap;
  final VoidCallback onLongPress;
  final VoidCallback onStar;

  /// Archives; true when every inbox it reached took it.
  final Future<bool> Function() onSwipe;
  final VoidCallback onSwiped;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final weight = row.unread ? FontWeight.w700 : FontWeight.w400;

    Widget swipeBackground(Alignment alignment) => Container(
      color: scheme.primaryContainer,
      alignment: alignment,
      padding: const EdgeInsets.symmetric(horizontal: 24),
      child: Icon(Icons.archive_outlined, color: scheme.onPrimaryContainer),
    );

    return Dismissible(
      key: ValueKey('email-swipe-${row.key}'),
      background: swipeBackground(Alignment.centerLeft),
      secondaryBackground: swipeBackground(Alignment.centerRight),
      confirmDismiss: (_) => onSwipe(),
      onDismissed: (_) => onSwiped(),
      child: Material(
        color: checked ? scheme.secondaryContainer : scheme.surface,
        child: InkWell(
          onTap: onTap,
          onLongPress: onLongPress,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 8, 10),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SenderAvatar(name: row.fromName, email: row.fromAddress),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              row.messageCount > 1
                                  ? '${row.sender} ${row.messageCount}'
                                  : row.sender,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.bodyLarge?.copyWith(
                                fontWeight: weight,
                              ),
                            ),
                          ),
                          if (row.dealt != null)
                            Padding(
                              padding: const EdgeInsets.only(left: 6),
                              child: Icon(
                                Icons.check_circle,
                                key: ValueKey('email-handled-${row.key}'),
                                size: 14,
                                color: scheme.primary,
                              ),
                            ),
                          const SizedBox(width: 8),
                          Text(
                            when,
                            style: theme.textTheme.bodySmall?.copyWith(
                              fontWeight: weight,
                            ),
                          ),
                        ],
                      ),
                      Text(
                        row.subject.isEmpty ? '(no subject)' : row.subject,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          fontWeight: weight,
                        ),
                      ),
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              row.snippet,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.bodySmall?.copyWith(
                                color: scheme.onSurfaceVariant,
                              ),
                            ),
                          ),
                          IconButton(
                            key: ValueKey('email-star-${row.key}'),
                            tooltip: row.starred ? 'Unstar' : 'Star',
                            visualDensity: VisualDensity.compact,
                            icon: Icon(
                              row.starred ? Icons.star : Icons.star_border,
                              size: 20,
                              color: row.starred
                                  ? scheme.tertiary
                                  : scheme.onSurfaceVariant,
                            ),
                            onPressed: onStar,
                          ),
                        ],
                      ),
                    ],
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

/// Pick a date (and a time of day) to snooze until — a side panel, not a
/// dialog.
class _SnoozeDatePanel extends StatefulWidget {
  const _SnoozeDatePanel();

  @override
  State<_SnoozeDatePanel> createState() => _SnoozeDatePanelState();
}

class _SnoozeDatePanelState extends State<_SnoozeDatePanel> {
  late DateTime _day = DateTime.now().add(const Duration(days: 1));
  int _hour = 8;

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now();
    final until = DateTime(_day.year, _day.month, _day.day, _hour);

    return SidePanel(
      title: 'Snooze until',
      width: 380,
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const ValueKey('email-snooze-confirm'),
          onPressed: until.isAfter(now)
              ? () => Navigator.of(context).pop(until)
              : null,
          child: const Text('Snooze'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CalendarDatePicker(
            initialDate: _day,
            firstDate: DateTime(now.year, now.month, now.day),
            lastDate: DateTime(now.year + 1, now.month, now.day),
            onDateChanged: (day) => setState(() => _day = day),
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            children: [
              for (final (hour, label) in const [
                (8, 'Morning, 8:00'),
                (13, 'Afternoon, 1:00'),
                (18, 'Evening, 6:00'),
              ])
                ChoiceChip(
                  label: Text(label),
                  selected: _hour == hour,
                  onSelected: (_) => setState(() => _hour = hour),
                ),
            ],
          ),
        ],
      ),
    );
  }
}
